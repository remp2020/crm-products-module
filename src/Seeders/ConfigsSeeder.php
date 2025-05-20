<?php

namespace Crm\ProductsModule\Seeders;

use Crm\ApplicationModule\Builder\ConfigBuilder;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Repositories\ConfigCategoriesRepository;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsTrait;
use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\ProductsModule\Models\Config;
use Nette\Localization\Translator;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigsSeeder implements ISeeder
{
    use ConfigsTrait;

    private $translator;

    private $configBuilder;

    private $configCategoriesRepository;

    private $configsRepository;

    public function __construct(
        Translator $translator,
        ConfigBuilder $configBuilder,
        ConfigCategoriesRepository $configCategoriesRepository,
        ConfigsRepository $configsRepository,
    ) {
        $this->translator = $translator;
        $this->configBuilder = $configBuilder;
        $this->configCategoriesRepository = $configCategoriesRepository;
        $this->configsRepository = $configsRepository;
    }

    public function seed(OutputInterface $output)
    {
        $category = $this->getCategory($output, 'products.config.category', 'fas fa-shopping-cart', 140);

        $this->addConfig(
            $output,
            $category,
            'shop_host',
            ApplicationConfig::TYPE_STRING,
            'products.config.shop_host.name',
            'products.config.shop_host.description',
            null,
            100,
        );

        $this->addConfig(
            $output,
            $category,
            'shop_title',
            ApplicationConfig::TYPE_TEXT,
            'products.config.shop_title.name',
            'products.config.shop_title.description',
            $this->translator->translate('products.config.shop_title.value'),
            100,
        );

        $this->addConfig(
            $output,
            $category,
            'shop_header_block',
            ApplicationConfig::TYPE_TEXT,
            'products.config.shop_header_block.name',
            'products.config.shop_header_block.description',
            null,
            200,
        );

        $this->addConfig(
            $output,
            $category,
            'shop_og_image_url',
            ApplicationConfig::TYPE_STRING,
            'products.config.shop_og_image_url.name',
            'products.config.shop_og_image_url.description',
            null,
            300,
        );

        $this->addConfig(
            $output,
            $category,
            'shop_terms_and_conditions_url',
            ApplicationConfig::TYPE_STRING,
            'products.config.shop_terms_and_conditions_url.name',
            'products.config.shop_terms_and_conditions_url.description',
            null,
            400,
        );

        $category = $this->getCategory($output, 'subscriptions.config.users.category', 'fa fa-user', 300);
        $this->addConfig(
            $output,
            $category,
            Config::ORDER_BLOCK_ANONYMIZATION,
            ApplicationConfig::TYPE_BOOLEAN,
            'products.config.users.prevent_anonymization.name',
            'products.config.users.prevent_anonymization.description',
            true,
            120,
        );

        $this->addConfig(
            $output,
            $category,
            Config::ORDER_BLOCK_ANONYMIZATION_WITHIN_DAYS,
            ApplicationConfig::TYPE_INT,
            'products.config.users.prevent_anonymization_within_days.name',
            'products.config.users.prevent_anonymization_within_days.description',
            45,
            120,
        );
    }
}
