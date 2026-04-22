<?php

declare(strict_types=1);

namespace Crm\ProductsModule\Commands;

use Crm\ApplicationModule\Commands\DecoratedCommandTrait;
use Crm\ProductsModule\Seeders\AddressTypesSeeder;
use Crm\UsersModule\Repositories\AddressesRepository;
use Crm\UsersModule\Repositories\CantDeleteAddressException;
use Nette\Database\Explorer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupDuplicateShopAddressesCommand extends Command
{
    use DecoratedCommandTrait;

    public function __construct(
        private readonly Explorer $database,
        private readonly AddressesRepository $addressesRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('products:cleanup_duplicate_shop_addresses')
            ->setDescription('Soft-deletes duplicate shop addresses, keeping the most recently updated one per user.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Actually perform the soft-delete. Without this option the command runs in dry-run mode and only reports what would be deleted.',
            )
            ->addUsage('          # dry-run: report duplicates without deleting')
            ->addUsage('--force   # soft-delete all duplicate shop addresses')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = !$input->getOption('force');

        if ($dryRun) {
            $this->comment('Running in dry-run mode. Use --force to actually soft-delete duplicates.');
        } else {
            $this->comment('Running in force mode. Duplicate shop addresses will be soft-deleted.');
        }

        // For each group of identical shop addresses (same user + same address fields),
        // keep the most recently updated one and soft-delete the rest.
        $duplicateGroups = $this->database->query(<<<SQL
            SELECT
                GROUP_CONCAT(id ORDER BY updated_at DESC, id DESC) AS ids,
                COUNT(*) AS cnt
            FROM addresses
            WHERE deleted_at IS NULL
              AND type = ?
            GROUP BY
                user_id,
                COALESCE(first_name, ''),
                COALESCE(last_name, ''),
                COALESCE(street, ''),
                COALESCE(number, ''),
                COALESCE(city, ''),
                COALESCE(zip, ''),
                COALESCE(country_id, 0)
            HAVING COUNT(*) > 1
        SQL, AddressTypesSeeder::PRODUCTS_SHOP_ADDRESS_TYPE)->fetchAll();

        if (count($duplicateGroups) === 0) {
            $this->line('No duplicate shop addresses found.');
            return Command::SUCCESS;
        }

        $wouldDelete = 0;
        $wouldSkip = 0;
        $deleted = 0;
        $skipped = 0;

        foreach ($duplicateGroups as $group) {
            $ids = explode(',', $group['ids']);
            array_shift($ids); // keep the most recently updated address

            foreach ($ids as $id) {
                $id = (int) $id;
                $this->output->write("  * Address ID <info>" . str_pad((string) $id, 6, ' ', STR_PAD_LEFT) . "</info>");
                $address = $this->addressesRepository->find($id);
                if (!$address) {
                    $this->warn(': Address not found.');
                    continue;
                }
                $this->output->write(' / user ID <info>' . str_pad((string) $address->user_id, 6, ' ', STR_PAD_LEFT) . '</info>: ');

                if ($dryRun) {
                    $check = $this->addressesRepository->canDelete($address);
                    if ($check['canDelete'] === false) {
                        $this->error("Would skip soft-delete of address.");
                        $this->comment("    * Reason: {$check['message']}");
                        $wouldSkip++;
                    } else {
                        $this->line('Would soft-delete address.');
                        $wouldDelete++;
                    }
                    continue;
                }

                try {
                    $this->addressesRepository->softDelete($address);
                    $this->comment("Address soft-deleted.");
                    $deleted++;
                } catch (CantDeleteAddressException $e) {
                    $this->error("Soft-delete of address skipped.");
                    $this->comment("    * Reason: {$e->getMessage()}");
                    $skipped++;
                }
            }
        }

        if ($dryRun) {
            $this->line("Dry-run complete. Would soft-delete: <info>{$wouldDelete}</info> addresses. Would skip: <comment>{$wouldSkip}</comment> addresses.");
        } else {
            $this->line("Done. Soft-deleted: <info>{$deleted}</info> addresses. Skipped: <comment>{$skipped}</comment> addresses.");
        }

        return Command::SUCCESS;
    }
}
