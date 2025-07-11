<?php
declare(strict_types=1);

namespace Crm\ProductsModule\Components\OrderStatusWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Localization\Translator;

class OrderStatusWidget extends BaseLazyWidget
{
    private string $templateName = 'order_status_widget.latte';

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        private readonly OrdersRepository $ordersRepository,
        private readonly Translator $translator,
    ) {
        parent::__construct($lazyWidgetManager);
    }

    public function render(ActiveRow $payment): void
    {
        $order = $this->ordersRepository->findByPayment($payment);
        
        if (!$order) {
            return;
        }

        $statusMap = [
            OrdersRepository::STATUS_NEW => $this->translator->translate('products.data.orders.statuses.new'),
            OrdersRepository::STATUS_PAID => $this->translator->translate('products.data.orders.statuses.paid'),
            OrdersRepository::STATUS_NOT_SENT => $this->translator->translate('products.data.orders.statuses.not_sent'),
            OrdersRepository::STATUS_PENDING => $this->translator->translate('products.data.orders.statuses.pending'),
            OrdersRepository::STATUS_CONFIRMED => $this->translator->translate('products.data.orders.statuses.confirmed'),
            OrdersRepository::STATUS_SENT => $this->translator->translate('products.data.orders.statuses.sent'),
            OrdersRepository::STATUS_DELIVERED => $this->translator->translate('products.data.orders.statuses.delivered'),
            OrdersRepository::STATUS_RETURNED => $this->translator->translate('products.data.orders.statuses.returned'),
            OrdersRepository::STATUS_PAYMENT_FAILED => $this->translator->translate('products.data.orders.statuses.payment_failed'),
            OrdersRepository::STATUS_PAYMENT_REFUNDED => $this->translator->translate('products.data.orders.statuses.payment_refunded'),
            OrdersRepository::STATUS_IMPORTED => $this->translator->translate('products.data.orders.statuses.imported'),
        ];

        $this->template->order = $order;
        $this->template->statusMap = $statusMap;
        $this->template->setFile(__DIR__ . DIRECTORY_SEPARATOR . $this->templateName);
        $this->template->render();
    }
}
