<?php

namespace Crm\ProductsModule\Tests;

use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Tests\PaymentsTestCase;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Scenarios\ActualOrderStatusCriteria;
use PHPUnit\Framework\Attributes\DataProvider;

class ActualOrderStatusCriteriaTest extends PaymentsTestCase
{
    public function requiredRepositories(): array
    {
        return array_merge(parent::requiredRepositories(), [
            OrdersRepository::class,
        ]);
    }

    public static function dataProvider()
    {
        return [
            [
                'hasStatus' => OrdersRepository::STATUS_NEW,
                'shouldHaveStatus' => [OrdersRepository::STATUS_NEW],
                'result' => true,
            ],
            [
                'hasStatus' => OrdersRepository::STATUS_NEW,
                'shouldHaveStatus' => [OrdersRepository::STATUS_NEW, OrdersRepository::STATUS_CONFIRMED],
                'result' => true,
            ],
            [
                'hasStatus' => OrdersRepository::STATUS_NEW,
                'shouldHaveStatus' => [OrdersRepository::STATUS_PAID, OrdersRepository::STATUS_CONFIRMED],
                'result' => false,
            ],
        ];
    }

    #[DataProvider('dataProvider')]
    public function testActualOrderStatus(string $hasStatus, array $shouldHaveStatus, bool $result)
    {
        [$orderSelection, $orderRow] = $this->prepareData($hasStatus);

        $hasOrderCriteria = $this->inject(ActualOrderStatusCriteria::class);
        $values = (object)['selection' => $shouldHaveStatus];
        $hasOrderCriteria->addConditions($orderSelection, [ActualOrderStatusCriteria::KEY => $values], $orderRow);

        if ($result) {
            $this->assertNotEmpty($orderSelection->fetch());
        } else {
            $this->assertEmpty($orderSelection->fetch());
        }
    }

    private function prepareData(string $withStatus)
    {
        /** @var PaymentsRepository $paymentsRepository */
        $paymentsRepository = $this->getRepository(PaymentsRepository::class);
        /** @var OrdersRepository $ordersRepository */
        $ordersRepository = $this->getRepository(OrdersRepository::class);

        $user = $this->getUser();

        $payment = $paymentsRepository->add(
            $this->getSubscriptionType(),
            $this->getPaymentGateway(),
            $user,
            new PaymentItemContainer(),
            null,
            10,
        );

        $orderRow = $ordersRepository->add(
            $payment,
            null,
            null,
            null,
            null,
        );

        $ordersRepository->update($orderRow, [
            'status' => $withStatus,
        ]);

        $orderSelection = $ordersRepository->getTable()->where('id = ?', $orderRow->id);
        return [$orderSelection, $orderRow];
    }
}
