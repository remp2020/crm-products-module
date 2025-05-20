<?php

namespace Crm\ProductsModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\UsersModule\Repositories\AddressTypesRepository;
use Symfony\Component\Console\Output\OutputInterface;

class AddressTypesSeeder implements ISeeder
{
    public const PRODUCTS_SHOP_ADDRESS_TYPE = 'shop';
    public const PRODUCTS_LICENCE_ADDRESS_TYPE = 'licence';

    private $addressTypesRepository;

    public function __construct(
        AddressTypesRepository $addressTypesRepository,
    ) {
        $this->addressTypesRepository = $addressTypesRepository;
    }

    public function seed(OutputInterface $output)
    {
        $types = [
            self::PRODUCTS_SHOP_ADDRESS_TYPE => 'Doručovacia adressa pre obchod',
            self::PRODUCTS_LICENCE_ADDRESS_TYPE => 'Licenčná adressa pre obchod',
        ];

        foreach ($types as $type => $title) {
            if ($this->addressTypesRepository->findBy('type', $type)) {
                $output->writeln("  * address type <info>{$type}</info> exists");
            } else {
                $this->addressTypesRepository->insert([
                    'type' => $type,
                    'title' => $title,
                ]);
                $output->writeln("  <comment>* address type <info>{$type}</info> created</comment>");
            }
        }
    }
}
