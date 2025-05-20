<?php
declare(strict_types=1);

namespace Crm\ProductsModule\Scenarios\TriggerHandlers;

use Crm\ApplicationModule\Models\Scenario\TriggerData;
use Crm\ApplicationModule\Models\Scenario\TriggerHandlerInterface;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Exception;

class NewOrderTriggerHandler implements TriggerHandlerInterface
{
    public function __construct(
        private readonly OrdersRepository $ordersRepository,
    ) {
    }

    public function getName(): string
    {
        return 'New order';
    }

    public function getKey(): string
    {
        return 'new_order';
    }

    public function getEventType(): string
    {
        return 'new-order';
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

        $orderId = $data['order_id'];
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
            'order_status' => $order->status,
            'payment_id' => $payment->id,
            'subscription_id' => $payment->subscription_id ?? null,
        ]);
    }
}
