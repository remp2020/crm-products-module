<?php

namespace Crm\ProductsModule\Events;

use Crm\ProductsModule\Models\Manager\ProductManager;
use Crm\ProductsModule\Models\PaymentItem\PaymentItemHelper;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Exception;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Nette\Database\Table\ActiveRow;

class OrderStatusChangeEventHandler extends AbstractListener
{
    /**
     * @var array<string>
     */
    private array $skippedPostalFees = [];

    public function __construct(
        private readonly PaymentItemHelper $paymentItemHelper,
        private readonly ProductManager $productManager,
    ) {
    }

    /**
     * @param array<string> $postalFees Array of postal fee codes to skip
     */
    public function setSkippedPostalFees(array $postalFees): self
    {
        $this->skippedPostalFees = $postalFees;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function handle(EventInterface $event): void
    {
        if (!$event instanceof OrderEventInterface) {
            throw new Exception(
                "Invalid event type received, 'OrderEventInterface' expected: " . $event::class
            );
        }

        $order = $event->getOrder();

        if ($order->status !== OrdersRepository::STATUS_PAID) {
            return;
        }

        $postalFee = $order->postal_fee;

        if ($this->isPostalFeeSkipped($postalFee)) {
            return;
        }

        $this->decreaseProductStock($order->payment);
    }

    private function isPostalFeeSkipped(ActiveRow $postalFee): bool
    {
        if (empty($this->skippedPostalFees)) {
            return false;
        }

        return in_array($postalFee->code, $this->skippedPostalFees, true);
    }

    private function decreaseProductStock(ActiveRow $payment): void
    {
        $this->paymentItemHelper->unBundleProducts(
            payment: $payment,
            callback: fn ($product, $itemCount) => $this->productManager->decreaseStock($product, $itemCount)
        );
    }
}
