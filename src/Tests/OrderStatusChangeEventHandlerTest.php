<?php

declare(strict_types=1);

namespace Crm\ProductsModule\Tests;

use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Events\OrderStatusChangeEvent;
use Crm\ProductsModule\Events\OrderStatusChangeEventHandler;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Repositories\PostalFeesRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\UsersModule\Repositories\AddressesRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use DateTime;
use Nette\Database\Table\ActiveRow;

class OrderStatusChangeEventHandlerTest extends BaseTestCase
{
    private OrderStatusChangeEventHandler $eventHandler;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var OrderStatusChangeEventHandler $handler */
        $handler = $this->inject(OrderStatusChangeEventHandler::class);
        $this->eventHandler = $handler;
    }

    protected function requiredRepositories(): array
    {
        return [
            ...parent::requiredRepositories(),
            AddressesRepository::class,
            OrdersRepository::class,
            PostalFeesRepository::class,
            ProductsRepository::class,
        ];
    }

    public function testOrderNotPaid(): void
    {
        $order = $this->createTestOrder(OrdersRepository::STATUS_NEW);
        $event = new OrderStatusChangeEvent($order);

        $this->eventHandler->handle($event);

        /** @var ProductsRepository $productsRepository */
        $productsRepository = $this->getRepository(ProductsRepository::class);
        $product = $productsRepository->find($order->payment->related('payment_items')->fetch()->product_id);

        $this->assertEquals(10, $product->stock);
    }

    public function testSkippedPostalFee(): void
    {
        /** @var PostalFeesRepository $postalFeesRepository */
        $postalFeesRepository = $this->getRepository(PostalFeesRepository::class);
        $postalFee = $postalFeesRepository->add('skipped_fee', 'Skipped Fee', 5);

        $order = $this->createTestOrder(OrdersRepository::STATUS_PAID, $postalFee);
        $event = new OrderStatusChangeEvent($order);

        $this->eventHandler->setSkippedPostalFees(['skipped_fee'])->handle($event);

        /** @var ProductsRepository $productsRepository */
        $productsRepository = $this->getRepository(ProductsRepository::class);
        $product = $productsRepository->find($order->payment->related('payment_items')->fetch()->product_id);

        $this->assertEquals(10, $product->stock);
    }

    public function testDecreaseStockSuccessful(): void
    {
        /** @var PostalFeesRepository $postalFeesRepository */
        $postalFeesRepository = $this->getRepository(PostalFeesRepository::class);
        $postalFee = $postalFeesRepository->add('standard_fee', 'Standard Fee', 5);

        $order = $this->createTestOrder(OrdersRepository::STATUS_PAID, $postalFee);
        $event = new OrderStatusChangeEvent($order);

        $this->eventHandler->handle($event);

        /** @var ProductsRepository $productRepository */
        $productRepository = $this->getRepository(ProductsRepository::class);
        $product = $productRepository->find($order->payment->related('payment_items')->fetch()->product_id);

        $this->assertEquals(8, $product->stock);
    }

    public function testEmptyPostalFeeCode(): void
    {
        /** @var PostalFeesRepository $postalFeesRepository */
        $postalFeesRepository = $this->getRepository(PostalFeesRepository::class);
        $postalFee = $postalFeesRepository->add('', 'Empty Code Fee', 5);

        $order = $this->createTestOrder(OrdersRepository::STATUS_PAID, $postalFee);
        $event = new OrderStatusChangeEvent($order);

        $this->eventHandler->setSkippedPostalFees(['different_fee'])->handle($event);

        /** @var ProductsRepository $productRepository */
        $productRepository = $this->getRepository(ProductsRepository::class);
        $product = $productRepository->find($order->payment->related('payment_items')->fetch()->product_id);

        $this->assertEquals(8, $product->stock);
    }

    private function createTestOrder(string $status, ?ActiveRow $postalFee = null): ActiveRow
    {
        $paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $gateway = $paymentGatewaysRepository->add('Gateway 1', 'gateway1');

        /** @var UsersRepository $usersRepository */
        $usersRepository = $this->getRepository(UsersRepository::class);
        $user = $usersRepository->add('usr1@crm.press', 'nbu12345');

        /** @var ProductsRepository $productsRepository */
        $productsRepository = $this->getRepository(ProductsRepository::class);
        $product = $productsRepository->insert([
            'name' => 'Test Product',
            'code' => 'test-product',
            'user_label' => 'Test Product',
            'vat' => '20.0',
            'price' => 10,
            'stock' => 10,
            'bundle' => false,
            'created_at' => new DateTime(),
            'modified_at' => new DateTime(),
        ]);

        $paymentItemContainer = new PaymentItemContainer();
        $paymentItemContainer->addItem(new ProductPaymentItem($product, count: 2));

        /** @var PaymentsRepository $paymentsRepository */
        $paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $payment = $paymentsRepository->add(
            subscriptionType: null,
            paymentGateway: $gateway,
            user: $user,
            paymentItemContainer: $paymentItemContainer,
            amount: 20,
        );

        /** @var OrdersRepository $ordersRepository */
        $ordersRepository = $this->getRepository(OrdersRepository::class);
        $order = $ordersRepository->add(
            paymentId: $payment->id,
            shippingAddressId: null,
            licenceAddressId: null,
            billingAddressId: null,
            postalFee: $postalFee,
        );

        $ordersRepository->update($order, ['status' => $status]);

        return $this->getRepository(OrdersRepository::class)->find($order->id);
    }
}
