<?php

namespace Crm\ProductsModule\Tests;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Repositories\ProductTemplatesRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\ProductsModule\Scenarios\HasProductWithTemplateNameCriteria;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UsersRepository;
use Faker\Provider\Uuid;
use Nette\Utils\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;

class HasProductWithTemplateNameTest extends DatabaseTestCase
{
    /** @var PaymentsRepository */
    private $paymentsRepository;

    /** @var OrdersRepository */
    private $ordersRepository;

    /** @var ProductTemplatesRepository */
    private $productTemplatesRepository;

    private $templates = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->ordersRepository = $this->getRepository(OrdersRepository::class);
        $this->productTemplatesRepository = $this->getRepository(ProductTemplatesRepository::class);
    }

    protected function requiredRepositories(): array
    {
        return [
            PaymentsRepository::class,
            UsersRepository::class,
            OrdersRepository::class,
            PaymentGatewaysRepository::class,
            ProductsRepository::class,
            ProductTemplatesRepository::class,
            PaymentItemsRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
        ];
    }

    private function getProductTemplate(string $name)
    {
        if (isset($this->templates[$name])) {
            return $this->templates[$name];
        }

        return $this->templates[$name] = $this->productTemplatesRepository->add($name);
    }

    public static function dataProvider()
    {
        return [
            [
                'shouldHave' => ['coupon'],
                'has' => ['coupon'],
                'result' => true,
            ],
            [
                'shouldHave' => ['book'],
                'has' => ['coupon'],
                'result' => false,
            ],
            [
                'shouldHave' => ['coupon'],
                'has' => [],
                'result' => false,
            ],
            [
                'shouldHave' => ['coupon', 'ebook'],
                'has' => ['ebook', 'book'],
                'result' => true,
            ],
            [
                'shouldHave' => ['coupon', 'ebook'],
                'has' => ['foo', 'bar'],
                'result' => false,
            ],
        ];
    }

    #[DataProvider('dataProvider')]
    public function testHasPaymentWithTemplateName(array $shouldHave, array $has, bool $result): void
    {
        [$paymentSelection, $paymentRow] = $this->prepareData($has);

        $hasProductWithTemplateCriteria = $this->inject(HasProductWithTemplateNameCriteria::class);

        $shouldHaveIds = [];
        foreach ($shouldHave as $templateName) {
            $shouldHaveIds[] = $this->getProductTemplate($templateName)->id;
        }

        $values = (object)['selection' => $shouldHaveIds];
        $hasProductWithTemplateCriteria->addConditions($paymentSelection, [
            HasProductWithTemplateNameCriteria::KEY => $values,
        ], $paymentRow);

        if ($result) {
            $this->assertNotNull($paymentSelection->fetch());
        } else {
            $this->assertNull($paymentSelection->fetch());
        }
    }

    private function prepareData(array $withProductTemplates): array
    {
        /** @var UserManager $userManager */
        $userManager = $this->inject(UserManager::class);
        $userRow = $userManager->addNewUser('test@example.com');

        $gatewayRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $gatewayRow = $gatewayRepository->add('test', 'test', 10, true, true);

        /** @var ProductsRepository $productsRepository */
        $productsRepository = $this->getRepository(ProductsRepository::class);

        $products = [];
        if (!empty($withProductTemplates)) {
            foreach ($withProductTemplates as $withProductTemplate) {
                $productTemplate = $this->getProductTemplate($withProductTemplate);
                $uuid = Uuid::uuid();
                $products[] = new ProductPaymentItem($productsRepository->insert([
                    'name' => 'Product name ' . $uuid,
                    'code' => 'product_name_' . $uuid,
                    'price' => 13,
                    'vat' => 10,
                    'user_label' => 'Product name',
                    'product_template_id' => $productTemplate->id,
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
            0.01, // fake amount so we don't have to care about payment items
        );

        $order = $this->ordersRepository->add(
            $payment,
            null,
            null,
            null,
            null,
            null,
        );

        $selection = $this->ordersRepository
            ->getTable()
            ->where(['orders.id' => $order->id]);

        return [$selection, $order];
    }
}
