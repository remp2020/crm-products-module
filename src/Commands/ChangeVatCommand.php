<?php
declare(strict_types=1);

namespace Crm\ProductsModule\Commands;

use Crm\ApplicationModule\Commands\DecoratedCommandTrait;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemHelper;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class ChangeVatCommand extends Command
{
    use DecoratedCommandTrait;

    public function __construct(
        private ApplicationConfig $applicationConfig,
        private PaymentItemsRepository $paymentItemsRepository,
        private ProductsRepository $productsRepository,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('products:change_vat')
            ->setDescription('Changes VAT to products, payment items (product type) and related payments.')
            ->addOption(
                'original-vat',
                null,
                InputOption::VALUE_REQUIRED,
                "VAT of products to be changed.",
            )
            ->addOption(
                'target-vat',
                null,
                InputOption::VALUE_REQUIRED,
                "VAT to be applied to products.",
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                "Outputs changes. Doesn't change anything. Use with --verbose for full output.",
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                "Forces the execution without interactive mode (for cron)",
            );
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $originalVat = $input->getOption('original-vat');
        if ($originalVat === null) {
            $output->writeln('<error>ERR</error>: Option --original-vat is required.');
            return Command::FAILURE;
        }

        $targetVat = $input->getOption('target-vat');
        if ($targetVat === null) {
            $output->writeln('<error>ERR</error>: Option --target-vat is required.');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $verbose = (bool) $input->getOption('verbose'); // this is already defined by Symfony command
        $force = (bool) $input->getOption('force');
        $currency = $this->applicationConfig->get('currency');

        $products = $this->productsRepository->getTable()
            ->where('vat = ?', $originalVat)
            ->order('id')
            ->fetchAll();
        $productsCount = count($products);

        if ($productsCount) {
            $output->writeln("Listing products to be changed to {$targetVat}% VAT:");
            foreach ($products as $product) {
                $output->writeln("  * <info>{$product->name}</info> ($product->price {$currency}) / {$product->code}");
            }
        } else {
            $output->writeln("No product has {$originalVat}% VAT anymore.");
        }

        $paymentItemsQuery = $this->paymentItemsRepository->getTable()
            ->where('vat = ?', $originalVat)
            ->where('type = ?', ProductPaymentItem::TYPE)
            ->where('payment.status = ?', PaymentStatusEnum::Form->value)
            ->order('payment_items.id')
            ->limit(1000);

        $paymentItemsCount = (clone $paymentItemsQuery)->count('*');
        $output->writeln("There are <info>{$paymentItemsCount}</info> payment items with {$originalVat}% VAT to be updated.");

        if (!$dryRun && !$force) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "\n********\n<comment>Do you wish to proceed?</comment> This will update <comment>{$productsCount}</comment> products, and <comment>{$paymentItemsCount}</comment> payment items of unpaid payments with {$originalVat}% VAT. (y/N) ",
                false,
            );

            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        // ********************************************************************
        $output->write("Updating products (in transaction): ");
        $this->productsRepository->getTransaction()->start();
        try {
            foreach ($products as $product) {
                $this->productsRepository->update($product, [
                    'vat' => $targetVat,
                ]);
            }

            if ($dryRun) {
                $this->productsRepository->getTransaction()->rollback();
            } else {
                $this->productsRepository->getTransaction()->commit();
            }
            $output->writeln("<comment>OK</comment>");
        } catch (\Exception $e) {
            $output->writeln("<error>ERR</error>: {$e->getMessage()} (rolling back)");
            $this->productsRepository->getTransaction()->rollback();
            throw $e;
        }

        // ********************************************************************
        $output->write("Updating payment items (in transaction): ");

        $lastId = 0;
        if ($verbose) {
            $output->writeln('');
        }

        $this->paymentItemsRepository->getTransaction()->start();
        try {
            while (true) {
                $paymentItems = (clone $paymentItemsQuery)->where('payment_items.id > ?', $lastId)->fetchAll();
                if (!count($paymentItems)) {
                    break;
                }

                foreach ($paymentItems as $paymentItem) {
                    $lastId = $paymentItem->id;
                    $targetAmountWithoutVat = PaymentItemHelper::getPriceWithoutVAT($paymentItem->amount, $targetVat);

                    if ($verbose) {
                        $output->write("  * Recalculating #{$paymentItem->id} {$paymentItem->amount} {$currency} ($paymentItem->amount_without_vat -> <info>$targetAmountWithoutVat</info>): ");
                    }

                    $this->paymentItemsRepository->update($paymentItem, [
                        'vat' => $targetVat,
                        'amount_without_vat' => $targetAmountWithoutVat,
                    ], true);

                    if ($verbose) {
                        $output->writeln('OK');
                    }
                }
            }

            if ($dryRun) {
                $this->paymentItemsRepository->getTransaction()->rollback();
            } else {
                $this->paymentItemsRepository->getTransaction()->commit();
            }

            if (!$verbose) {
                $output->writeln('OK');
            }
        } catch (\Exception $e) {
            $output->writeln("<error>ERR</error>: {$e->getMessage()} (rolling back)");
            $this->paymentItemsRepository->getTransaction()->rollback();
            throw $e;
        }

        if ($dryRun) {
            $output->writeln('Rolling everything back, this was a <comment>dry run</comment>.');
        }

        return Command::SUCCESS;
    }
}
