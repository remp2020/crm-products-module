<?php

namespace Crm\ProductsModule\Commands;

use Crm\ApplicationModule\Commands\DecoratedCommandTrait;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Models\PaymentItem\PostalFeePaymentItem;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UserStatsRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use DateInterval;
use DateTime;
use Nette\Database\Explorer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Calculates average and total amounts of money spent in products module and stores it in user's stats table.
 *
 * This stats data is mainly used by admin widget TotalUserPayments.
 */
class CalculateAveragesCommand extends Command
{
    use DecoratedCommandTrait;

    private const PAYMENT_STATUSES = [PaymentsRepository::STATUS_PAID, PaymentsRepository::STATUS_PREPAID];

    private ?int $calculatedPeriod = null;
    private ?DateTime $startDate = null;

    private Explorer $database;
    private PaymentsRepository $paymentsRepository;
    private UserStatsRepository $userStatsRepository;
    private UsersRepository $usersRepository;
    private UserMetaRepository $userMetaRepository;

    public function __construct(
        Explorer $database,
        PaymentsRepository $paymentsRepository,
        UserStatsRepository $userStatsRepository,
        UsersRepository $usersRepository,
        UserMetaRepository $userMetaRepository
    ) {
        parent::__construct();
        $this->database = $database;
        $this->paymentsRepository = $paymentsRepository;
        $this->userStatsRepository = $userStatsRepository;
        $this->usersRepository = $usersRepository;
        $this->userMetaRepository = $userMetaRepository;
    }

    protected function configure()
    {
        $this->setName('products:calculate_averages')
            ->setDescription('Calculate product-related averages')
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                "Force deleting existing data in 'user_stats' table and 'user_meta' table (where data was originally stored)"
            )
            ->addOption(
                'user_id',
                null,
                InputOption::VALUE_REQUIRED,
                "Compute average values for given user only."
            )
            ->addOption(
                'calculated_period',
                null,
                InputOption::VALUE_REQUIRED,
                "Sets the period for which should be averages calculated. Default: last 730 days.",
            );
    }

    public function setCalculatedPeriod(int $days): void
    {
        $this->calculatedPeriod = $days;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = ['product_payments', 'product_payments_amount'];

        // if set, option calculated_period overrides config and default value (no period)
        $calculatedPeriod = $input->getOption('calculated_period');
        if ($calculatedPeriod !== null) {
            $this->calculatedPeriod = (int) $calculatedPeriod;
        }

        if ($this->calculatedPeriod !== null) {
            $interval = new DateInterval("P{$this->calculatedPeriod}D");
            $this->startDate = (new DateTime())->sub($interval);
        } else {
            $firstPaidAt = $this->paymentsRepository->getTable()->where(['paid_at IS NOT NULL'])->order('paid_at ASC')->limit(1)->fetch();
            $this->startDate = $firstPaidAt?->paid_at;
        }

        if ($this->startDate === null) {
            $this->error('Unable to find paid payments. Nothing done.');
            return Command::FAILURE;
        }
        $this->line('  * Including all product payments paid after: <comment>' . $this->startDate->format(DATE_RFC3339) . '</comment>.');

        if ($input->getOption('delete')) {
            $this->line("Deleting old values from 'user_stats' and 'user_meta' tables.");

            $this->userStatsRepository->getTable()
                ->where('key IN (?)', $keys)
                ->delete();

            $this->userMetaRepository->getTable()
                ->where('key IN (?)', $keys)
                ->delete();
        }

        $userId = $input->getOption('user_id');

        foreach ($keys as $key) {
            $this->line("  * filling up 0s for '<info>{$key}</info>' stat");

            if ($userId) {
                $this->database->query(<<<SQL
                INSERT IGNORE INTO `user_stats` (`user_id`,`key`,`value`, `created_at`, `updated_at`)
                VALUES (?, ?, 0, NOW(), NOW())
SQL, $userId, $key);
            } else {
                $this->database->query(<<<SQL
                -- fill empty values for new users
                INSERT IGNORE INTO `user_stats` (`user_id`,`key`,`value`, `created_at`, `updated_at`)
                SELECT `id`, ?, 0, NOW(), NOW()
                FROM `users`;
SQL, $key);
            }
        }

        if ($userId) {
            $interval = [$userId, $userId];
            $this->computeProductPaymentCounts(...$interval);
            $this->computeProductPaymentAmounts(...$interval);
        } else {
            foreach ($this->userIdIntervals() as $interval) {
                $this->computeProductPaymentCounts(...$interval);
                $this->computeProductPaymentAmounts(...$interval);
            }
        }

        return Command::SUCCESS;
    }


    private function userIdIntervals(): array
    {
        $windowSize = 100000;

        $minId = $this->usersRepository->getTable()->min('id');
        $maxId = $this->usersRepository->getTable()->max('id');

        $intervals = [];
        $i = $minId;
        while ($i <= $maxId) {
            $nextI = $i + $windowSize;
            $intervals[] = [$i, $nextI - 1];
            $i = $nextI;
        }
        return $intervals;
    }

    private function computeProductPaymentCounts($minUserId, $maxUserId): void
    {
        $this->line("  * computing '<info>product_payments</info>' for user IDs between [<info>{$minUserId}</info>, <info>{$maxUserId}</info>]");

        $paymentPaidAt = $this->startDate;
        $productType = ProductPaymentItem::TYPE;
        $postalFeeType = PostalFeePaymentItem::TYPE;

        $userProductPaymentCounts = $this->database->query(<<<SQL
            SELECT
                `payments`.`user_id` AS `user_id`,
                COUNT(DISTINCT(`payments`.`id`)) AS `product_payments_count`
            FROM `payment_items`
            INNER JOIN `payments`
                ON `payments`.`id` = `payment_items`.`payment_id`
                AND `payments`.`status` IN (?)
                AND `payments`.`paid_at` > ?
            WHERE `payment_items`.`type` IN (?, ?) AND `payments`.`user_id` BETWEEN ? AND ?
            GROUP BY `payments`.`user_id`
        SQL, self::PAYMENT_STATUSES, $paymentPaidAt, $productType, $postalFeeType, $minUserId, $maxUserId)
            ->fetchPairs('user_id', 'product_payments_count');

        $this->userStatsRepository->upsertUsersValues('product_payments', $userProductPaymentCounts);
    }

    private function computeProductPaymentAmounts($minUserId, $maxUserId): void
    {
        $this->line("  * computing '<info>product_payments_amount</info>' for user IDs between [<info>{$minUserId}</info>, <info>{$maxUserId}</info>]");

        $paymentPaidAt = $this->startDate;
        $productType = ProductPaymentItem::TYPE;
        $postalFeeType = PostalFeePaymentItem::TYPE;

        $userProductPaymentAmount = $this->database->query(<<<SQL
            SELECT
                `payments`.`user_id` AS `user_id`,
                COALESCE(SUM(`payment_items`.`amount` * `payment_items`.`count`), 0) AS `product_payments_amount`
            FROM `payment_items`
            INNER JOIN `payments`
                ON `payments`.`id` = `payment_items`.`payment_id`
                AND `payments`.`status` IN (?)
                AND `payments`.`paid_at` > ?
            WHERE `payment_items`.`type` IN (?, ?) AND `payments`.`user_id` BETWEEN ? AND ?
            GROUP BY `payments`.`user_id`
        SQL, self::PAYMENT_STATUSES, $paymentPaidAt, $productType, $postalFeeType, $minUserId, $maxUserId)
            ->fetchPairs('user_id', 'product_payments_amount');

        $this->userStatsRepository->upsertUsersValues('product_payments_amount', $userProductPaymentAmount);
    }
}
