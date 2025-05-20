<?php
declare(strict_types=1);

namespace Crm\ProductsModule\Tests;

use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Scenarios\OrderScenarioConditionalModel;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\UsersModule\Repositories\UsersRepository;
use Exception;

class OrderScenarioConditionalModelTest extends BaseTestCase
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
            OrdersRepository::class,
        ];
    }

    public function testItemQuery(): void
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

        $orderScenarioConditionalModel = new OrderScenarioConditionalModel($ordersRepository);
        $selection = $orderScenarioConditionalModel->getItemQuery((object) [
            'order_id' => $order->id,
        ]);

        $this->assertCount(1, $selection->fetchAll());
    }

    public function testItemQueryWithWrongId(): void
    {
        /** @var OrdersRepository $ordersRepository */
        $ordersRepository = $this->getRepository(OrdersRepository::class);

        $orderScenarioConditionalModel = new OrderScenarioConditionalModel($ordersRepository);
        $selection = $orderScenarioConditionalModel->getItemQuery((object) [
            'order_id' => 1,
        ]);

        $this->assertEmpty($selection->fetchAll());
    }

    public function testItemQueryWithoutMandatoryJobParameter(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Order scenario conditional model requires 'order_id' job param.");

        /** @var OrdersRepository $ordersRepository */
        $ordersRepository = $this->getRepository(OrdersRepository::class);

        $orderScenarioConditionalModel = new OrderScenarioConditionalModel($ordersRepository);
        $orderScenarioConditionalModel->getItemQuery((object) []);
    }
}
