<?php
declare(strict_types=1);

namespace Crm\ProductsModule\Events;

use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\UsersModule\Events\NotificationContext;
use Crm\UsersModule\Events\PreNotificationEvent;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class PreNotificationEventHandler extends AbstractListener
{
    private array $enabledNotificationHermesTypes = [];

    public function __construct(
        private PaymentsRepository $paymentsRepository,
        private PaymentItemsRepository $paymentItemsRepository,
    ) {
    }

    public function handle(EventInterface $event)
    {
        if (!($event instanceof PreNotificationEvent)) {
            throw new \Exception('PreNotificationEvent object expected, instead ' . get_class($event) . ' received');
        }

        // validate notification context hermes type
        $notificationContext = $event->getNotificationContext();
        if (!$notificationContext) {
            return;
        }
        $hermesMessageType = $notificationContext->getContextValue(NotificationContext::HERMES_MESSAGE_TYPE);
        if (!$hermesMessageType) {
            return;
        }
        if (!in_array($hermesMessageType, $this->enabledNotificationHermesTypes, true)) {
            return;
        }

        // add order info to notification data
        $notificationEvent = $event->getNotificationEvent();
        $params = $notificationEvent->getParams();

        if (isset($params['payment']['id'])) {
            $payment = $this->paymentsRepository->find($params['payment']['id']);
            if (!$payment) {
                return;
            }

            $params['payment_items'] = [];
            $paymentItems = $this->paymentItemsRepository->getByPayment($payment);
            foreach ($paymentItems as $paymentItem) {
                $params['payment_items'][] = [
                    'type' => $paymentItem->type,
                    'name' => $paymentItem->name,
                    'count' => $paymentItem->count,
                    'amount' => $paymentItem->amount,
                    'vat' => $paymentItem->vat,
                ];
            }
        }

        $notificationEvent->setParams($params);
    }

    /**
     * Invoice will be attached to any NotificationEvent having NotificationContext with given hermes types
     *
     * @param string ...$notificationHermesTypes
     */
    public function enableForNotificationHermesTypes(string ...$notificationHermesTypes): void
    {
        $this->enabledNotificationHermesTypes = $notificationHermesTypes;
    }
}
