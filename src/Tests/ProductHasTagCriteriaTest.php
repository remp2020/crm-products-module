<?php

declare(strict_types=1);

namespace Crm\ProductsModule\Tests;

use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Repositories\ProductTagsRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\ProductsModule\Repositories\TagsRepository;
use Crm\ProductsModule\Scenarios\ProductHasTagCriteria;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\UsersRepository;
use Exception;
use Faker\Provider\Uuid;
use Nette\Utils\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;

class ProductHasTagCriteriaTest extends DatabaseTestCase
{
    private PaymentsRepository $paymentsRepository;
    private OrdersRepository $ordersRepository;
    private TagsRepository $tagsRepository;
    private ProductTagsRepository $productTagsRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->ordersRepository = $this->getRepository(OrdersRepository::class);
        $this->tagsRepository = $this->getRepository(TagsRepository::class);
        $this->productTagsRepository = $this->getRepository(ProductTagsRepository::class);
    }

    protected function requiredRepositories(): array
    {
        return [
            PaymentsRepository::class,
            PaymentItemsRepository::class,
            PaymentGatewaysRepository::class,
            ProductsRepository::class,
            TagsRepository::class,
            ProductTagsRepository::class,
            OrdersRepository::class,
            UsersRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [];
    }

    public static function dataProviderForTestProductHasTagCriteria(): array
    {
        return [
            'MatchingTag_ShouldReturnTrue' => [
                "productTags" => ['ebook', 'bestseller'],
                "testedTags" => [
                    ['ebook', 'premium'],
                ],
                "expectedResult" => true,
            ],
            'DifferentTag_ShouldReturnFalse' => [
                "productTags" => ['ebook', 'bestseller'],
                "testedTags" => [
                    ['premium'],
                ],
                "expectedResult" => false,
            ],
            'EmptyTestedTags_ShouldThrowException' => [
                "productTags" => ['ebook', 'bestseller'],
                "testedTags" => [
                    [],
                ],
                "expectedResult" => null,
            ],
            'ChainedMatchingTags_ShouldReturnTrue' => [
                "productTags" => ['ebook', 'bestseller'],
                "testedTags" => [
                    ['ebook', 'premium'],
                    ['bestseller'],
                ],
                "expectedResult" => true,
            ],
            'ChainedPartiallyDifferentTags_ShouldReturnFalse' => [
                "productTags" => ['ebook', 'bestseller'],
                "testedTags" => [
                    ['premium'],
                    ['bestseller'],
                ],
                "expectedResult" => false,
            ],
        ];
    }

    #[DataProvider('dataProviderForTestProductHasTagCriteria')]
    public function testProductHasTagCriteria(
        array $productTags,
        array $testedTags,
        ?bool $expectedResult,
    ): void {
        [$orderSelection, $orderRow] = $this->prepareData($productTags);

        /** @var ProductHasTagCriteria $criteria */
        $criteria = $this->inject(ProductHasTagCriteria::class);

        if ($expectedResult === null) {
            $this->expectException(Exception::class);
        }

        foreach ($testedTags as $tags) {
            $values = (object)['selection' => $tags];
            $criteria->addConditions(
                $orderSelection,
                [ProductHasTagCriteria::KEY => $values],
                $orderRow,
            );
        }

        if ($expectedResult === true) {
            $this->assertNotNull($orderSelection->fetch());
        } elseif ($expectedResult === false) {
            $this->assertNull($orderSelection->fetch());
        }
    }

    private function prepareData(array $productTags): array
    {
        /** @var UserManager $userManager */
        $userManager = $this->inject(UserManager::class);
        $userRow = $userManager->addNewUser('test@example.com');

        /** @var PaymentGatewaysRepository $gatewayRepository */
        $gatewayRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $gatewayRow = $gatewayRepository->add('test', 'test', 10, true, true);

        /** @var ProductsRepository $productsRepository */
        $productsRepository = $this->getRepository(ProductsRepository::class);

        $uuid = Uuid::uuid();
        $product = $productsRepository->insert([
            'name' => 'Product name ' . $uuid,
            'code' => 'product_name_' . $uuid,
            'price' => 13,
            'vat' => 10,
            'user_label' => 'Product name',
            'bundle' => 0,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
        ]);

        // Create tags and associate them with the product
        $tagIds = [];
        foreach ($productTags as $tagCode) {
            $tag = $this->tagsRepository->findByCode($tagCode);
            if (!$tag) {
                $tag = $this->tagsRepository->add($tagCode, $tagCode, 'fa-tag');
            }
            $tagIds[] = $tag->id;
        }
        $this->productTagsRepository->setProductTags($product, $tagIds);

        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItem(new ProductPaymentItem($product, 1));

        $payment = $this->paymentsRepository->add(
            subscriptionType: null,
            paymentGateway: $gatewayRow,
            user: $userRow,
            paymentItemContainer: $paymentItemContainer,
            amount: 13,
        );

        $order = $this->ordersRepository->add(
            paymentId: $payment,
            shippingAddressId: null,
            licenceAddressId: null,
            billingAddressId: null,
            postalFee: null,
        );

        $orderSelection = $this->ordersRepository->getTable()->where('orders.id', $order->id);
        return [$orderSelection, $order];
    }
}
