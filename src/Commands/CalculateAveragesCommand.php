<?php

namespace Crm\ProductsModule\Commands;

use Crm\ApplicationModule\Commands\DecoratedCommandTrait;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Models\PaymentItem\PostalFeePaymentItem;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\SegmentModule\Models\SegmentFactory;
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

    public function __construct(
        private readonly Explorer $database,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly SegmentFactory $segmentFactory,
        private readonly UserStatsRepository $userStatsRepository,
        private readonly UsersRepository $usersRepository,
        private readonly UserMetaRepository $userMetaRepository,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('products:calculate_averages')
            ->setDescription('Calculate product-related averages')
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                "Force deleting existing data in 'user_stats' table and 'user_meta' table (where data was originally stored). If users are provided (--user_id or --segment_code option), only values for provided user(s) are deleted.",
            )
            ->addOption(
                'user_id',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "Compute average values for given user(s) only. If this option is used, --segment_code is ignored.",
            )
            ->addOption(
                'segment_code',
                null,
                InputOption::VALUE_REQUIRED,
                "Compute average values for users in provided segment. This option is ignored, if `--user_id` is used.",
            )
            ->addOption(
                'calculated_period',
                null,
                InputOption::VALUE_REQUIRED,
                "Sets the period for which should be averages calculated. Default: last 730 days.",
            )
            ->addUsage('                                         # no options, all stats are calculated')
            ->addUsage('--user_id=123 --user_id=456              # stats for users with ID #123 and #456 are calculated')
            ->addUsage('--segment_code=active_users              # stats for users in segment `active_users`')
            ->addUsage('--delete                                 # all existing data is removed and recalculated')
            ->addUsage('--delete --user_id=123 --user_id=456     # data of users with ID #123 and #456 is removed and recalculated')
            ->addUsage('--delete --segment_code=active_users     # data of users in segment `active_users` is removed and recalculated')
        ;
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

        $output->write('  * Calculating averages for ');
        $userIDs = $input->getOption('user_id');
        $segmentCode = $input->getOption('segment_code');

        // check user IDs (--user_id has priority over --segment_code)
        if (!empty($userIDs)) {
            $this->line('provided user IDs: ' . implode(', ', $userIDs));
        } elseif ($segmentCode !== null) {
            $segment = $this->segmentFactory->buildSegment($segmentCode);
            $segmentCount = $segment->totalCount();
            $this->line("provided segment: [{$segmentCode}] with {$segmentCount} users.");
            $userIDs = $segment->getIds();
        }
        if (empty($userIDs)) {
            $this->line("all users.");
        }

        if ($input->getOption('delete')) {
            $this->line("Deleting old values from 'user_stats' and 'user_meta' tables.");

            $userStats = $this->userStatsRepository->getTable()
                ->where('key IN (?)', $keys);
            if (!empty($userIDs)) {
                $userStats->where('user_id IN (?)', $userIDs);
            }
            $userStats->delete();

            $userMeta = $this->userMetaRepository->getTable()
                ->where('key IN (?)', $keys);
            if (!empty($userIDs)) {
                $userMeta->where('user_id IN (?)', $userIDs);
            }
            $userMeta->delete();
        }

        foreach ($keys as $key) {
            $this->line("  * filling up 0s for '<info>{$key}</info>' stat");

            if (!empty($userIDs)) {
                foreach ($userIDs as $userID) {
                    $this->database->query(<<<SQL
                        INSERT IGNORE INTO `user_stats` (`user_id`,`key`,`value`, `created_at`, `updated_at`)
                        VALUES (?, ?, 0, NOW(), NOW())
                    SQL, $userID, $key);
                }
            } else {
                $this->database->query(<<<SQL
                    -- fill empty values for new users
                    INSERT IGNORE INTO `user_stats` (`user_id`,`key`,`value`, `created_at`, `updated_at`)
                    SELECT users.id, ?, 0, NOW(), NOW()
                    FROM `users`
                    LEFT JOIN user_stats ON user_id = users.id AND `key` = ?
                    WHERE user_stats.id IS NULL;
                SQL, $key, $key);
            }
        }

        if (!empty($userIDs)) {
            $this->computeProductPaymentCounts(userIDs: $userIDs);
            $this->computeProductPaymentAmounts(userIDs: $userIDs);
        } else {
            foreach ($this->userIdIntervals() as $interval) {
                $this->computeProductPaymentCounts(interval: $interval);
                $this->computeProductPaymentAmounts(interval: $interval);
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

    private function computeProductPaymentCounts(array $userIDs = null, array $interval = null): void
    {
        if (isset($userIDs)) {
            $this->line("  * computing '<info>product_payments</info>' for provided user IDs");
            $userIdsCondition = "`payments`.`user_id` IN (?)";
            $userIdsParams = [$userIDs];
        } elseif (isset($interval)) {
            $this->line("  * computing '<info>product_payments</info>' for user IDs between [<info>{$interval[0]}</info>, <info>{$interval[1]}</info>]");
            $userIdsCondition = "`payments`.`user_id` BETWEEN ? AND ?";
            $userIdsParams = $interval;
        } else {
            throw new \RuntimeException('Either array of user IDs (e.g. [1,2,3,4,...]) or interval of user IDs (e.g. [1,1000]) need to be provided');
        }

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
            WHERE `payment_items`.`type` IN (?, ?)
              AND {$userIdsCondition}
            GROUP BY `payments`.`user_id`
        SQL, self::PAYMENT_STATUSES, $paymentPaidAt, $productType, $postalFeeType, ...$userIdsParams)
            ->fetchPairs('user_id', 'product_payments_count');

        $this->userStatsRepository->upsertUsersValues('product_payments', $userProductPaymentCounts);
    }

    private function computeProductPaymentAmounts(array $userIDs = null, array $interval = null): void
    {
        if (isset($userIDs)) {
            $this->line("  * computing '<info>product_payments_amount</info>' for provided user IDs");
            $userIdsCondition = "`payments`.`user_id` IN (?)";
            $userIdsParams = [$userIDs];
        } elseif (isset($interval)) {
            $this->line("  * computing '<info>product_payments_amount</info>' for user IDs between [<info>{$interval[0]}</info>, <info>{$interval[1]}</info>]");
            $userIdsCondition = "`payments`.`user_id` BETWEEN ? AND ?";
            $userIdsParams = $interval;
        } else {
            throw new \RuntimeException('Either array of user IDs (e.g. [1,2,3,4,...]) or interval of user IDs (e.g. [1,1000]) need to be provided');
        }

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
            WHERE `payment_items`.`type` IN (?, ?)
              AND {$userIdsCondition}
            GROUP BY `payments`.`user_id`
        SQL, self::PAYMENT_STATUSES, $paymentPaidAt, $productType, $postalFeeType, ...$userIdsParams)
            ->fetchPairs('user_id', 'product_payments_amount');

        $this->userStatsRepository->upsertUsersValues('product_payments_amount', $userProductPaymentAmount);
    }
}
