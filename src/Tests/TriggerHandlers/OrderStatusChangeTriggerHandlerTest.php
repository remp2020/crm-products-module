<?php
declare(strict_types=1);

namespace Crm\ProductsModule\Tests\TriggerHandlers;

use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Scenarios\TriggerHandlers\OrderStatusChangeTriggerHandler;
use Crm\ProductsModule\Tests\BaseTestCase;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\UsersModule\Repositories\AddressesRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Exception;

class OrderStatusChangeTriggerHandlerTest extends BaseTestCase
{
    private SubscriptionTypeBuilder $subscriptionTypeBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);
    }

    protected function requiredRepositories(): array
    {
        return [
            ...parent::requiredRepositories(),
            AddressesRepository::class,
            OrdersRepository::class,
        ];
    }

    public function testKey(): void
    {
        /** @var OrderStatusChangeTriggerHandler $orderStatusChangeTriggerHandler */
        $orderStatusChangeTriggerHandler = $this->inject(OrderStatusChangeTriggerHandler::class);
        $this->assertSame('order_status_change', $orderStatusChangeTriggerHandler->getKey());
    }

    public function testEventType(): void
    {
        /** @var OrderStatusChangeTriggerHandler $orderStatusChangeTriggerHandler */
        $orderStatusChangeTriggerHandler = $this->inject(OrderStatusChangeTriggerHandler::class);
        $this->assertSame('order-status-change', $orderStatusChangeTriggerHandler->getEventType());
    }

    public function testHandleEvent(): void
    {
        $subscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('test')
            ->setLength(31)
            ->setPrice(1)
            ->setActive(1)
            ->save();

        /** @var PaymentGatewaysRepository $paymentGatewaysRepository */
        $paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $gateway = $paymentGatewaysRepository->add('Gateway 1', 'gateway1');

        /** @var UsersRepository $usersRepository */
        $usersRepository = $this->getRepository(UsersRepository::class);
        $user = $usersRepository->add('usr1@crm.press', 'nbu12345');

        /** @var SubscriptionsRepository $subscriptionsRepository */
        $subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $subscription = $subscriptionsRepository->add(
            $subscriptionType,
            isRecurrent: false,
            isPaid: true,
            user: $user,
        );

        /** @var PaymentsRepository $paymentsRepository */
        $paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $payment = $paymentsRepository->add(
            $subscriptionType,
            $gateway,
            $user,
            new PaymentItemContainer(),
            amount: 1,
        );
        $paymentsRepository->addSubscriptionToPayment($subscription, $payment);

        /** @var OrdersRepository $ordersRepository */
        $ordersRepository = $this->getRepository(OrdersRepository::class);
        $order = $ordersRepository->add(
            $payment->id,
            shippingAddressId: null,
            licenceAddressId: null,
            billingAddressId: null,
            postalFee: null,
        );

        /** @var OrderStatusChangeTriggerHandler $orderStatusChangeTriggerHandler */
        $orderStatusChangeTriggerHandler = $this->inject(OrderStatusChangeTriggerHandler::class);
        $triggerData = $orderStatusChangeTriggerHandler->handleEvent([
            'order_id' => $order->id,
            'order_status' => OrdersRepository::STATUS_CONFIRMED,
        ]);

        $this->assertSame($user->id, $triggerData->userId);
        $this->assertSame(array_keys($triggerData->payload), $orderStatusChangeTriggerHandler->getOutputParams());
        $this->assertSame([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_status' => OrdersRepository::STATUS_CONFIRMED,
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
        ], $triggerData->payload);
    }

    public function testHandleEventWithoutSubscription(): void
    {
        $subscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('test')
            ->setLength(31)
            ->setPrice(1)
            ->setActive(1)
            ->save();

        /** @var PaymentGatewaysRepository $paymentGatewaysRepository */
        $paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $gateway = $paymentGatewaysRepository->add('Gateway 1', 'gateway1');

        /** @var UsersRepository $usersRepository */
        $usersRepository = $this->getRepository(UsersRepository::class);
        $user = $usersRepository->add('usr1@crm.press', 'nbu12345');

        /** @var PaymentsRepository $paymentsRepository */
        $paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $payment = $paymentsRepository->add(
            $subscriptionType,
            $gateway,
            $user,
            new PaymentItemContainer(),
            amount: 1,
        );

        /** @var OrdersRepository $ordersRepository */
        $ordersRepository = $this->getRepository(OrdersRepository::class);
        $order = $ordersRepository->add(
            $payment->id,
            shippingAddressId: null,
            licenceAddressId: null,
            billingAddressId: null,
            postalFee: null,
        );

        /** @var OrderStatusChangeTriggerHandler $orderStatusChangeTriggerHandler */
        $orderStatusChangeTriggerHandler = $this->inject(OrderStatusChangeTriggerHandler::class);
        $triggerData = $orderStatusChangeTriggerHandler->handleEvent([
            'order_id' => $order->id,
            'order_status' => OrdersRepository::STATUS_CONFIRMED,
        ]);

        $this->assertSame($user->id, $triggerData->userId);
        $this->assertSame(array_keys($triggerData->payload), $orderStatusChangeTriggerHandler->getOutputParams());
        $this->assertSame([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_status' => OrdersRepository::STATUS_CONFIRMED,
            'payment_id' => $payment->id,
            'subscription_id' => null,
        ], $triggerData->payload);
    }

    public function testHandleEventMissingOrderId(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'order_id' is missing");

        /** @var OrderStatusChangeTriggerHandler $orderStatusChangeTriggerHandler */
        $orderStatusChangeTriggerHandler = $this->inject(OrderStatusChangeTriggerHandler::class);
        $orderStatusChangeTriggerHandler->handleEvent([
            'order_status' => OrdersRepository::STATUS_PAID,
        ]);
    }

    public function testHandleEventMissingOrderStatus(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'order_status' is missing");

        /** @var OrderStatusChangeTriggerHandler $orderStatusChangeTriggerHandler */
        $orderStatusChangeTriggerHandler = $this->inject(OrderStatusChangeTriggerHandler::class);
        $orderStatusChangeTriggerHandler->handleEvent([
            'order_id' => 1,
        ]);
    }

    public function testHandleEventMissingOrder(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Order with ID=1 does not exist");

        /** @var OrderStatusChangeTriggerHandler $orderStatusChangeTriggerHandler */
        $orderStatusChangeTriggerHandler = $this->inject(OrderStatusChangeTriggerHandler::class);
        $orderStatusChangeTriggerHandler->handleEvent([
            'order_id' => 1,
            'order_status' => OrdersRepository::STATUS_PAID,
        ]);
    }
}
