<?php

namespace Crm\ProductsModule\Tests;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentItemsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repository\DistributionCentersRepository;
use Crm\ProductsModule\Repository\OrdersRepository;
use Crm\ProductsModule\Repository\ProductsRepository;
use Crm\ProductsModule\Scenarios\HasProductWithDistributionCenterCriteria;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\UsersRepository;
use Faker\Provider\Uuid;
use Nette\Utils\DateTime;

class HasProductWithDistributionCenterCriteriaTest extends DatabaseTestCase
{
    /** @var PaymentsRepository */
    private $paymentsRepository;

    /** @var OrdersRepository */
    private $ordersRepository;

    /** @var DistributionCentersRepository */
    private $distributionCentersRepository;

    private $centers = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->ordersRepository = $this->getRepository(OrdersRepository::class);
        $this->distributionCentersRepository = $this->getRepository(DistributionCentersRepository::class);
    }

    protected function requiredRepositories(): array
    {
        return [
            PaymentsRepository::class,
            UsersRepository::class,
            OrdersRepository::class,
            PaymentGatewaysRepository::class,
            ProductsRepository::class,
            PaymentItemsRepository::class,
            DistributionCentersRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
        ];
    }

    private function getDistributionCenter(string $name)
    {
        if (isset($this->centers[$name])) {
            return $this->centers[$name];
        }

        return $this->centers[$name] = $this->distributionCentersRepository->add($name, $name);
    }

    public function dataProvider()
    {
        return [
            [
                'shouldHave' => ['fhb', 'dennikn'],
                'has' => ['fhb'],
                'result' => true,
            ],
            [
                'shouldHave' => ['fhb', 'dennikn'],
                'has' => ['foo', 'bar'],
                'result' => false,
            ],
            [
                'shouldHave' => ['dibuk'],
                'has' => ['fhb', 'dibuk'],
                'result' => true,
            ],
            [
                'shouldHave' => ['fhb'],
                'has' => [],
                'result' => false,
            ],
        ];
    }

    /**
     * @dataProvider dataProvider
     */
    public function testHasPaymentWithDistributionCenter(array $shouldHave, array $has, bool $result): void
    {
        [$orderSelection, $orderRow] = $this->prepareData($has);

        $hasProductWithDistributionCenterCriteria = $this->inject(HasProductWithDistributionCenterCriteria::class);

        $shouldHaveIds = [];
        foreach ($shouldHave as $distributionCenter) {
            $shouldHaveIds[] = $this->getDistributionCenter($distributionCenter)->code;
        }

        $values = (object)['selection' => $shouldHaveIds];
        $hasProductWithDistributionCenterCriteria->addConditions($orderSelection, [
            HasProductWithDistributionCenterCriteria::KEY => $values
        ], $orderRow);

        if ($result) {
            $this->assertNotFalse($orderSelection->fetch());
        } else {
            $this->assertFalse($orderSelection->fetch());
        }
    }

    private function prepareData(array $withDistributionCenters): array
    {
        /** @var UserManager $userManager */
        $userManager = $this->inject(UserManager::class);
        $userRow = $userManager->addNewUser('test@example.com');

        $gatewayRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $gatewayRow = $gatewayRepository->add('test', 'test', 10, true, true);

        /** @var ProductsRepository $productsRepository */
        $productsRepository = $this->getRepository(ProductsRepository::class);

        $products = [];
        if (!empty($withDistributionCenters)) {
            foreach ($withDistributionCenters as $withDistributionCenter) {
                $distributionCenter = $this->getDistributionCenter($withDistributionCenter);
                $uuid = Uuid::uuid();
                $products[] = new ProductPaymentItem($productsRepository->insert([
                    'name' => 'Product name ' . $uuid,
                    'code' => 'product_name_' . $uuid,
                    'price' => 13,
                    'vat' => 10,
                    'user_label' => 'Product name',
                    'distribution_center' => $distributionCenter->code,
                    'bundle' => 0,
                    'created_at' => new DateTime(),
                    'modified_at' => new DateTime(),
                ]), 1);
            }
        }

        $paymentItemContainer = new PaymentItemContainer();
        if (!empty($products)) {
            $paymentItemContainer = $paymentItemContainer->addItems($products);
        }

        $payment = $this->paymentsRepository->add(
            null,
            $gatewayRow,
            $userRow,
            $paymentItemContainer,
            null,
            0.01 // fake amount so we don't have to care about payment items
        );

        $order = $this->ordersRepository->add(
            $payment,
            null,
            null,
            null,
            null,
            null
        );

        $selection = $this->ordersRepository
            ->getTable()
            ->where(['orders.id' => $order->id]);

        return [$selection, $order];
    }
}
