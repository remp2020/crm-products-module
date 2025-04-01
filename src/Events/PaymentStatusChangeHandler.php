<?php

namespace Crm\ProductsModule\Events;

use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\ProductsModule\Repositories\OrdersRepository;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Nette\Database\Table\ActiveRow;
use Tracy\Debugger;

class PaymentStatusChangeHandler extends AbstractListener
{
    private $ordersRepository;

    public function __construct(
        OrdersRepository $ordersRepository,
    ) {
        $this->ordersRepository = $ordersRepository;
    }

    public function handle(EventInterface $event)
    {
        if (!($event instanceof PaymentChangeStatusEvent)) {
            throw new \Exception('Invalid type of event received, PaymentChangeStatusEvent expected: ' . get_class($event));
        }

        /** @var ActiveRow $payment */
        $payment = $event->getPayment();
        $order = $payment->related('orders')->fetch();

        if (!$order) {
            // this is not order payment
            return;
        }

        switch ($payment->status) {
            case PaymentStatusEnum::Paid->value:
            case PaymentStatusEnum::Prepaid->value:
                if (in_array($order->status, [OrdersRepository::STATUS_NEW, OrdersRepository::STATUS_PAYMENT_FAILED], true)) {
                    $this->ordersRepository->update($order, ['status' => OrdersRepository::STATUS_PAID]);
                }
                break;

            case PaymentStatusEnum::Fail->value:
            case PaymentStatusEnum::Timeout->value:
                $this->ordersRepository->update($order, ['status' => OrdersRepository::STATUS_PAYMENT_FAILED]);
                break;

            case PaymentStatusEnum::Refund->value:
                $this->ordersRepository->update($order, ['status' => OrdersRepository::STATUS_PAYMENT_REFUNDED]);
                break;

            case PaymentStatusEnum::Imported->value:
                $this->ordersRepository->update($order, ['status' => OrdersRepository::STATUS_IMPORTED]);
                break;

            default:
                Debugger::log("Unknown payment status: {$payment->status}. Payment ID: {$payment->id} Order ID: {$order->id}", Debugger::EXCEPTION);
                break;
        }
    }
}
