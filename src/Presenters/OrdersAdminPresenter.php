<?php

namespace Crm\ProductsModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\PreviousNextPaginator;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\Forms\CheckoutFormFactory;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Repository\OrdersRepository;
use Crm\ProductsModule\Repository\ProductsRepository;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\RadioList;
use Tomaj\Form\Renderer\BootstrapInlineRenderer;
use Tomaj\Form\Renderer\BootstrapRenderer;

class OrdersAdminPresenter extends AdminPresenter
{
    private $ordersRepository;

    private $paymentsRepository;

    private $productsRepository;

    private $postalFeeService;

    public $checkoutFormFactory;

    /** @persistent */
    public $payment_status;

    /** @persistent */
    public $order_status;

    public function __construct(
        OrdersRepository $ordersRepository,
        PaymentsRepository $paymentsRepository,
        ProductsRepository $productsRepository,
        CheckoutFormFactory $checkoutFormFactory,
        PostalFeeService $postalFeeService
    ) {
        parent::__construct();
        $this->ordersRepository = $ordersRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->productsRepository = $productsRepository;
        $this->checkoutFormFactory = $checkoutFormFactory;
        $this->postalFeeService = $postalFeeService;
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $orders = $this->getFilteredOrders();

        $pnp = new PreviousNextPaginator();
        $this->addComponent($pnp, 'paginator');
        $paginator = $pnp->getPaginator();
        $paginator->setItemsPerPage($this->onPage);

        $orders = $orders->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $pnp->setActualItemCount(count($orders));

        $this->template->orders = $orders;
        $this->template->totalCount = $this->ordersRepository->totalCount(true);
    }

    /**
     * @admin-access-level write
     */
    public function renderNew($paymentId)
    {
        $payment = $this->paymentsRepository->find($paymentId);
        if (!$payment) {
            $this->redirect('default');
        }
        foreach ($payment->related('orders') as $order) {
            $this->flashMessage($this->translator->translate('products.admin.orders.default.warnings.order_for_payment_exists'), 'warning');
            $this->redirect('show', $order->id);
        }
        $this->template->payment = $payment;
    }

    /**
     * @admin-access-level read
     */
    public function renderShow($id)
    {
        $order = $this->ordersRepository->find($id);

        $this->template->order = $order;
    }

    public function createComponentAdminFilterForm()
    {
        $form = new Form;
        $form->setRenderer(new BootstrapInlineRenderer());
        $form->addText('text', $this->translator->translate('products.admin.orders.default.fields.order_id_vs') . ':')
            ->setAttribute('autofocus');

        $products = $this->productsRepository->getTable()->fetchPairs('id', 'name');
        $form->addMultiSelect('products', $this->translator->translate('products.admin.orders.default.fields.products'), $products)
            ->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->addSelect('payment_status', $this->translator->translate('products.admin.orders.default.fields.payment_state'), $this->paymentsRepository->getStatusPairs())->setPrompt('--');

        $form->addSelect('order_status', $this->translator->translate('products.admin.orders.default.fields.order_state'), $this->ordersRepository->getStatusPairs())->setPrompt('--');

        $form->addSubmit('send', $this->translator->translate('products.admin.orders.default.fields.filter'))
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-filter"></i> ' . $this->translator->translate('products.admin.orders.default.fields.filter'));
        $presenter = $this;
        $form->addSubmit('cancel', $this->translator->translate('products.admin.orders.default.fields.cancel_filter'))->onClick[] = function () use ($presenter) {
            $presenter->redirect('OrdersAdmin:Default', [
                'text' => '',
                'payment_status' => '',
                'order_status' => '',
                'products' => [],
            ]);
        };

        $form->onSuccess[] = [$this, 'adminFilterSubmitted'];
        $form->setDefaults([
            'text' => $this->text,
            'payment_status' => $this->payment_status,
            'order_status' => $this->order_status,
            'products' => $this->getParameter('products'),
        ]);
        return $form;
    }

    public function createComponentCheckoutForm()
    {
        $payment = $this->paymentsRepository->find($this->getParameter('paymentId'));
        $cart = $this->createCartFromPayment($this->getParameter('paymentId'));

        $form = $this->checkoutFormFactory->create($cart, null, $payment);
        $form->setRenderer(new BootstrapRenderer());
        $this->checkoutFormFactory->onSave = function ($payment) {
            $this->redirect('default');
        };
        return $form;
    }

    /**
     * @admin-access-level write
     */
    public function handleCountryChange($value)
    {
        $cart = $this->createCartFromPayment($this->getParameter('paymentId'));
        if ($this['checkoutForm']['postal_fee'] instanceof RadioList) {
            $options = $this->postalFeeService->getAvailablePostalFeesOptions($value, $cart);
            $this['checkoutForm']['postal_fee']
                ->setItems($options)
                ->setDefaultValue($this->postalFeeService->getDefaultPostalFee($value, $options));
        }

        $this->redrawControl('postalFeesSnippet');
        $this->redrawControl('cart');
    }

    /**
     * @admin-access-level write
     */
    public function handlePostalFeeChange()
    {
        $value = $this->request->getPost('value');
        $this['checkoutForm']['postal_fee']
            ->setDefaultValue($value);

        $this->redrawControl('cart');
    }

    private function getFilteredOrders()
    {
        $orders = $this->ordersRepository->all();
        if (isset($this->params['payment_status'])) {
            $orders->where('payment.status', $this->params['payment_status']);
        }

        if (isset($this->params['order_status'])) {
            $orders->where('orders.status', $this->params['order_status']);
        }

        if (isset($this->params['text'])) {
            $orders->where('payment.variable_symbol LIKE ? OR orders.id LIKE ?', "%{$this->params['text']}%", "%{$this->params['text']}%");
        }

        if ($this->getParameter('products')) {
            if ($this->getParameter('products')) {
                $orders->where('payment:payment_items.product_id', $this->getParameter('products'));
            }
        }

        return $orders;
    }

    private function createCartFromPayment(int $paymentId): array
    {
        $payment = $this->paymentsRepository->find($paymentId);
        $cart = [];
        foreach ($payment->related('payment_items')->where('type = ?', ProductPaymentItem::TYPE) as $paymentItem) {
            $cart[$paymentItem->product_id] = $paymentItem->count;
        }

        return $cart;
    }
}
