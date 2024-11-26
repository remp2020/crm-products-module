<?php

namespace Crm\ProductsModule;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Application\CommandsContainerInterface;
use Crm\ApplicationModule\Application\Managers\AssetsManager;
use Crm\ApplicationModule\Application\Managers\LayoutManager;
use Crm\ApplicationModule\Application\Managers\SeederManager;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Models\Config\ConfigsCache;
use Crm\ApplicationModule\Models\Criteria\ScenariosCriteriaStorage;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Models\Event\EventsStorage;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Models\Menu\MenuContainerInterface;
use Crm\ApplicationModule\Models\Menu\MenuItem;
use Crm\ApplicationModule\Models\Scenario\TriggerManager;
use Crm\ApplicationModule\Models\User\UserDataRegistrator;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManagerInterface;
use Crm\PaymentsModule\DataProviders\OneStopShopCountryResolutionDataProviderInterface;
use Crm\PaymentsModule\DataProviders\PaymentItemVatDataProviderInterface;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\ProductsModule\Commands\CalculateAveragesCommand;
use Crm\ProductsModule\Commands\ChangeVatCommand;
use Crm\ProductsModule\Components\AvgProductsPaymentWidget\AvgProductsPaymentWidget;
use Crm\ProductsModule\Components\FreeShippingProgressBarWidget\FreeShippingProgressBarWidget;
use Crm\ProductsModule\Components\ProductItemsListWidget\ProductItemsListWidget;
use Crm\ProductsModule\Components\RecommendedProductsWidget\RecommendedProductsWidget;
use Crm\ProductsModule\Components\TotalShopPaymentsWidget\TotalShopPaymentsWidget;
use Crm\ProductsModule\Components\UserOrdersWidget\UserOrdersWidget;
use Crm\ProductsModule\DataProviders\OneStopShopCountryResolutionDataProvider;
use Crm\ProductsModule\DataProviders\OrdersUserDataProvider;
use Crm\ProductsModule\DataProviders\PaymentFormDataProvider;
use Crm\ProductsModule\DataProviders\PaymentItemTypesFilterDataProvider;
use Crm\ProductsModule\DataProviders\PaymentItemVatDataProvider;
use Crm\ProductsModule\DataProviders\PaymentsAdminFilterFormDataProvider;
use Crm\ProductsModule\Events\NewOrderEvent;
use Crm\ProductsModule\Events\OrderStatusChangeEvent;
use Crm\ProductsModule\Events\OrderStatusChangeEventHandler;
use Crm\ProductsModule\Events\PaymentStatusChangeHandler;
use Crm\ProductsModule\Events\PreNotificationEventHandler;
use Crm\ProductsModule\Events\ProductSaveEvent;
use Crm\ProductsModule\Models\ProductsCache;
use Crm\ProductsModule\Models\TagsCache;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\ProductsModule\Repositories\TagsRepository;
use Crm\ProductsModule\Scenarios\ActualOrderStatusCriteria;
use Crm\ProductsModule\Scenarios\HasOrderCriteria;
use Crm\ProductsModule\Scenarios\HasProductWithDistributionCenterCriteria;
use Crm\ProductsModule\Scenarios\HasProductWithTemplateNameCriteria;
use Crm\ProductsModule\Scenarios\OrderScenarioConditionalModel;
use Crm\ProductsModule\Scenarios\OrderStatusOnScenarioEnterCriteria;
use Crm\ProductsModule\Scenarios\TriggerHandlers\NewOrderTriggerHandler;
use Crm\ProductsModule\Scenarios\TriggerHandlers\OrderStatusChangeTriggerHandler;
use Crm\ProductsModule\Seeders\AddressTypesSeeder;
use Crm\ProductsModule\Seeders\ConfigsSeeder;
use Crm\UsersModule\Components\AddressWidget\AddressWidget;
use Crm\UsersModule\Events\PreNotificationEvent;
use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;
use Nette\DI\Container;
use Symfony\Component\Console\Output\OutputInterface;

class ProductsModule extends CrmModule
{
    public function __construct(
        Container $container,
        Translator $translator,
        private ApplicationConfig $applicationConfig,
        private ConfigsCache $configsCache,
        private ProductsCache $productsCache,
        private ProductsRepository $productsRepository,
        private TagsCache $tagsCache,
        private TagsRepository $tagsRepository
    ) {
        parent::__construct($container, $translator);
    }

    public function registerAdminMenuItems(MenuContainerInterface $menuContainer)
    {
        $mainMenu = new MenuItem(
            $this->translator->translate('products.menu.shop'),
            '#products',
            'fa fa-shekel-sign',
            550
        );
        $menuItem1 = new MenuItem(
            $this->translator->translate('products.menu.products'),
            ':Products:ProductsAdmin:default',
            'fa fa-cube',
            500
        );
        $menuItem2 = new MenuItem(
            $this->translator->translate('products.menu.tags'),
            ':Products:TagsAdmin:default',
            'fa fa-tag',
            600
        );
        $menuItem3 = new MenuItem(
            $this->translator->translate('products.menu.orders'),
            ':Products:OrdersAdmin:default',
            'fa fa-paper-plane',
            700
        );
        $menuItem4 = new MenuItem(
            $this->translator->translate('products.menu.postal_fees'),
            ':Products:PostalFees:default',
            'fa fa-rocket',
            943
        );
        $menuItem5 = new MenuItem(
            $this->translator->translate('products.menu.country_postal_fees'),
            ':Products:CountryPostalFees:default',
            'fa fa-globe',
            944
        );
        $menuItem6 = new MenuItem(
            $this->translator->translate('products.menu.sort_shop_products'),
            ':Products:ProductsAdmin:sortShopProducts',
            'fa fa-sort',
            945
        );

        $mainMenu->addChild($menuItem1);
        $mainMenu->addChild($menuItem2);
        $mainMenu->addChild($menuItem3);
        $mainMenu->addChild($menuItem4);
        $mainMenu->addChild($menuItem5);
        $mainMenu->addChild($menuItem6);

        $menuContainer->attachMenuItem($mainMenu);

        // dashboard menu item

        $menuItem = new MenuItem(
            $this->translator->translate('products.menu.stats'),
            ':Products:Dashboard:default',
            'fa fa-shekel-sign',
            350
        );
        $menuContainer->attachMenuItemToForeignModule('#dashboard', $mainMenu, $menuItem);
    }

    public function registerCommands(CommandsContainerInterface $commandsContainer)
    {
        $commandsContainer->registerCommand($this->getInstance(CalculateAveragesCommand::class));
        $commandsContainer->registerCommand($this->getInstance(ChangeVatCommand::class));
    }

    public function registerFrontendMenuItems(MenuContainerInterface $menuContainer)
    {
        $menuItem = new MenuItem($this->translator->translate('products.menu.orders'), ':Products:Orders:My', '', 150);
        $menuContainer->attachMenuItem($menuItem);
    }

    public function registerLazyEventHandlers(LazyEventEmitter $emitter)
    {
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            PaymentStatusChangeHandler::class
        );

        $emitter->addListener(
            OrderStatusChangeEvent::class,
            OrderStatusChangeEventHandler::class
        );

        $emitter->addListener(
            PreNotificationEvent::class,
            PreNotificationEventHandler::class
        );
    }

    public function registerScenariosTriggers(TriggerManager $triggerManager): void
    {
        $triggerManager->registerTriggerHandler($this->getInstance(NewOrderTriggerHandler::class));
        $triggerManager->registerTriggerHandler($this->getInstance(OrderStatusChangeTriggerHandler::class));
    }

    public function registerUserData(UserDataRegistrator $dataRegistrator)
    {
        $dataRegistrator->addUserDataProvider($this->getInstance(OrdersUserDataProvider::class));
    }

    public function registerRoutes(RouteList $router)
    {
        $shopHost = $this->configsCache->get('shop_host');
        if (!$shopHost) {
            $shopHost = $this->applicationConfig->get('shop_host');
            $this->configsCache->add('shop_host', $shopHost);
        }

        // if shop host is not defined, cache routes with `<module>/<presenter>` url `products/shop`
        if ($shopHost) {
            $shopHost = "//" . $shopHost;
        } else {
            $shopHost = "products/shop";
        }

        foreach ($this->tagsCache->all() as $tag) {
            if (empty($tag)) {
                continue;
            }
            $router->addRoute($shopHost . "/<tagCode {$tag->code}>", "Products:Shop:tag");
        }

        $router->addRoute($shopHost . "/product/<code>", 'Products:Shop:show', Route::ONE_WAY);
        $router->addRoute($shopHost . '/<action show>/<id \d+>/<code>', 'Products:Shop:show');
        $router->addRoute($shopHost . '/<action show>/<id \d+>', 'Products:Shop:show');
        $router->addRoute($shopHost . '/<action show>/<code>', 'Products:Shop:show');
        $router->addRoute($shopHost . '/<action>[/<id>]', 'Products:Shop:default');
    }

    public function cache(OutputInterface $output, array $tags = [])
    {
        if (empty($tags)) {
            $productCount = $this->productsRepository->getTable()->count('*');
            if ($productCount) {
                $this->productsCache->removeAll();
                foreach ($this->productsRepository->getTable() as $product) {
                    $this->productsCache->add($product->id, $product->code);
                    $output->writeln("  * adding product <info>{$product->code}</info>");
                }
            }

            $tagsCount = $this->tagsRepository->getTable()->count('*');
            if ($tagsCount) {
                $this->tagsCache->removeAll();
                foreach ($this->tagsRepository->all() as $tag) {
                    $this->tagsCache->add($tag->id, $tag->code);
                    $output->writeln("  * adding tag <info>{$tag->code}</info>");
                }
            }
        }
    }

    public function registerLayouts(LayoutManager $layoutManager)
    {
        $layoutManager->registerLayout(
            'shop',
            realpath(__DIR__ . '/templates/@shop_layout.latte')
        );
    }

    public function registerAssets(AssetsManager $assetsManager)
    {
        $assetsManager->copyAssets(__DIR__ . '/assets/dist/', 'layouts/products/dist/');
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(AddressTypesSeeder::class));
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'payments.dataprovider.payments_filter_form',
            $this->getInstance(PaymentsAdminFilterFormDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'payments.dataprovider.payment_form',
            $this->getInstance(PaymentFormDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'payments.dataprovider.dashboard',
            $this->getInstance(PaymentItemTypesFilterDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            OneStopShopCountryResolutionDataProviderInterface::PATH,
            $this->getInstance(OneStopShopCountryResolutionDataProvider::class),
            20,
        );
        $dataProviderManager->registerDataProvider(
            PaymentItemVatDataProviderInterface::PATH,
            $this->getInstance(PaymentItemVatDataProvider::class),
        );
    }

    public function registerEvents(EventsStorage $eventsStorage)
    {
        $eventsStorage->register('new_order', NewOrderEvent::class, true);
        $eventsStorage->register('order_status_change', OrderStatusChangeEvent::class, true);
        $eventsStorage->register('product_save', ProductSaveEvent::class);
    }

    public function registerLazyWidgets(LazyWidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'payments.admin.payment_item_listing',
            ProductItemsListWidget::class
        );
        $widgetManager->registerWidget(
            'payments.admin.total_user_payments',
            TotalShopPaymentsWidget::class
        );
        $widgetManager->registerWidget(
            'segment.detail.statspanel.row',
            AvgProductsPaymentWidget::class
        );
        $widgetManager->registerWidget(
            'admin.products.order.address',
            AddressWidget::class
        );
        $widgetManager->registerWidget(
            'products.frontend.orders_my',
            UserOrdersWidget::class
        );

        $widgetManager->registerWidget(
            'products.shop.cart',
            FreeShippingProgressBarWidget::class
        );

        $widgetManager->registerWidget(
            'products.shop.show.title',
            FreeShippingProgressBarWidget::class
        );

        $widgetManager->registerWidget(
            'products.shop.product_list.title',
            FreeShippingProgressBarWidget::class
        );

        $widgetManager->registerWidget(
            'products.shop.show.bottom',
            RecommendedProductsWidget::class
        );
    }

    public function registerScenariosCriteria(ScenariosCriteriaStorage $scenariosCriteriaStorage)
    {
        $scenariosCriteriaStorage->registerConditionModel(
            'order',
            $this->getInstance(OrderScenarioConditionalModel::class)
        );
        $scenariosCriteriaStorage->register(
            'payment',
            'has_order',
            $this->getInstance(HasOrderCriteria::class)
        );
        $scenariosCriteriaStorage->register(
            'order',
            ActualOrderStatusCriteria::KEY,
            $this->getInstance(ActualOrderStatusCriteria::class)
        );
        $scenariosCriteriaStorage->register(
            'trigger',
            OrderStatusOnScenarioEnterCriteria::KEY,
            $this->getInstance(OrderStatusOnScenarioEnterCriteria::class)
        );
        $scenariosCriteriaStorage->register(
            'order',
            HasProductWithTemplateNameCriteria::KEY,
            $this->getInstance(HasProductWithTemplateNameCriteria::class)
        );
        $scenariosCriteriaStorage->register(
            'order',
            HasProductWithDistributionCenterCriteria::KEY,
            $this->getInstance(HasProductWithDistributionCenterCriteria::class)
        );
    }
}
