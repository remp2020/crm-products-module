<?php

namespace Crm\ProductsModule\Tests;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Scenarios\HasOrderCriteria;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UsersRepository;

class HasOrderCriteriaTest extends DatabaseTestCase
{
    /** @var PaymentsRepository */
    private $paymentsRepository;

    /** @var OrdersRepository */
    private $ordersRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->ordersRepository = $this->getRepository(OrdersRepository::class);
    }

    protected function requiredRepositories(): array
    {
        return [
            PaymentsRepository::class,
            UsersRepository::class,
            OrdersRepository::class,
            PaymentGatewaysRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
        ];
    }

    public function testHasOrderWithOrder(): void
    {
        [$paymentSelection, $paymentRow] = $this->prepareData(true);

        $hasOrderCriteria = new HasOrderCriteria();
        $values = (object)['selection' => true];
        $hasOrderCriteria->addConditions($paymentSelection, ['has_order' => $values], $paymentRow);

        $this->assertNotEmpty($paymentSelection->fetch());
    }

    public function testHasOrderWithoutOrder(): void
    {
        [$paymentSelection, $paymentRow] = $this->prepareData(false);

        $hasOrderCriteria = new HasOrderCriteria();
        $values = (object)['selection' => true];
        $hasOrderCriteria->addConditions($paymentSelection, ['has_order' => $values], $paymentRow);

        $this->assertEmpty($paymentSelection->fetch());
    }

    private function prepareData(bool $withOrder): array
    {
        /** @var UserManager $userManager */
        $userManager = $this->inject(UserManager::class);
        $userRow = $userManager->addNewUser('test@example.com');

        $gatewayRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $gatewayRow = $gatewayRepository->add('test', 'test', 10, true, true);

        $payment = $this->paymentsRepository->add(
            null,
            $gatewayRow,
            $userRow,
            new PaymentItemContainer(),
            null,
            0.01, // fake amount so we don't have to care about payment items
        );

        $selection = $this->paymentsRepository
            ->getTable()
            ->where(['payments.id' => $payment->id]);

        if ($withOrder) {
            $this->ordersRepository->add(
                $payment->id,
                null,
                null,
                null,
                null,
            );
        }

        return [$selection, $payment];
    }
}
