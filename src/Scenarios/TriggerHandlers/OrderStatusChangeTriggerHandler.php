<?php
declare(strict_types=1);

namespace Crm\ProductsModule\Scenarios\TriggerHandlers;

use Crm\ApplicationModule\Models\Scenario\TriggerData;
use Crm\ApplicationModule\Models\Scenario\TriggerHandlerInterface;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Exception;

class OrderStatusChangeTriggerHandler implements TriggerHandlerInterface
{
    public function __construct(
        private readonly OrdersRepository $ordersRepository,
    ) {
    }

    public function getName(): string
    {
        return 'Order status change';
    }

    public function getKey(): string
    {
        return 'order_status_change';
    }

    public function getEventType(): string
    {
        return 'order-status-change';
    }

    public function getOutputParams(): array
    {
        return ['user_id', 'order_id', 'order_status', 'payment_id', 'subscription_id'];
    }

    public function handleEvent(array $data): TriggerData
    {
        if (!isset($data['order_id'])) {
            throw new Exception("'order_id' is missing");
        }
        if (!isset($data['order_status'])) {
            throw new Exception("'order_status' is missing");
        }

        $orderId = $data['order_id'];
        $orderStatus = $data['order_status'];

        $order = $this->ordersRepository->find($orderId);
        if (!$order) {
            throw new Exception(sprintf(
                "Order with ID=%s does not exist",
                $orderId,
            ));
        }

        $payment = $order->payment;

        return new TriggerData($payment->user_id, [
            'user_id' => $payment->user_id,
            'order_id' => $order->id,
            'order_status' => $orderStatus,
            'payment_id' => $payment->id,
            'subscription_id' => $payment->subscription_id ?? null,
        ]);
    }
}
