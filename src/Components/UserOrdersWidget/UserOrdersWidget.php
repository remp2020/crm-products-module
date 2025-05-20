<?php

namespace Crm\ProductsModule\Components\UserOrdersWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Nette\Localization\Translator;

class UserOrdersWidget extends BaseLazyWidget
{
    private $templateName = 'user_orders_widget.latte';

    private $translator;

    private $ordersRepository;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        Translator $translator,
        OrdersRepository $ordersRepository,
    ) {
        parent::__construct($lazyWidgetManager);

        $this->translator = $translator;
        $this->ordersRepository = $ordersRepository;
    }

    public function identifier()
    {
        return 'userorderswidget';
    }

    public function render($user)
    {
        $statusMap = [
            OrdersRepository::STATUS_PAID => $this->translator->translate('products.data.orders.statuses.paid'),
            OrdersRepository::STATUS_NOT_SENT => $this->translator->translate('products.data.orders.statuses.not_sent'),
            OrdersRepository::STATUS_PENDING => $this->translator->translate('products.data.orders.statuses.pending'),
            OrdersRepository::STATUS_CONFIRMED => $this->translator->translate('products.data.orders.statuses.confirmed'),
            OrdersRepository::STATUS_SENT => $this->translator->translate('products.data.orders.statuses.sent'),
            OrdersRepository::STATUS_DELIVERED => $this->translator->translate('products.data.orders.statuses.delivered'),
            OrdersRepository::STATUS_RETURNED => $this->translator->translate('products.data.orders.statuses.returned'),
        ];

        $this->template->orders = $this->ordersRepository->getByUser($user->id, array_keys($statusMap));
        $this->template->statusMap = $statusMap;

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
