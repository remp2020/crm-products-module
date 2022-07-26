<?php

namespace Crm\ProductsModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\Graphs\GoogleLineGraphGroupControlFactoryInterface;
use Crm\ApplicationModule\Components\PreviousNextPaginator;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Graphs\Criteria;
use Crm\ApplicationModule\Graphs\GraphDataItem;
use Crm\PaymentsModule\Repository\PaymentItemsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\Forms\ProductsFormFactory;
use Crm\ProductsModule\Manager\ProductManager;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repository\ProductsRepository;
use Crm\ProductsModule\Repository\TagsRepository;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\IRow;
use Tomaj\Form\Renderer\BootstrapInlineRenderer;

class ProductsAdminPresenter extends AdminPresenter
{
    private $productsRepository;

    private $productsFormFactory;

    private $paymentItemsRepository;

    private $tagsRepository;

    private $configRepository;

    private $distributionConfiguration;

    /** @persistent */
    public $tags = [];

    private $productManager;

    public function __construct(
        ProductsRepository $productsRepository,
        ProductsFormFactory $productsFormFactory,
        PaymentItemsRepository $paymentItemsRepository,
        TagsRepository $tagsRepository,
        ConfigsRepository $configRepository,
        ProductManager $productManager
    ) {
        parent::__construct();
        $this->productsRepository = $productsRepository;
        $this->productsFormFactory = $productsFormFactory;
        $this->paymentItemsRepository = $paymentItemsRepository;
        $this->tagsRepository = $tagsRepository;
        $this->configRepository = $configRepository;
        $this->productManager = $productManager;
    }

    /**
     * @param string $key
     * @param array $distributionLevels - array of numeric values starting with zero and minimal length of 3 element
     */
    public function setDistributionConfiguration(string $key, array $distributionLevels)
    {
        if (count($distributionLevels) < 3) {
            throw new \UnexpectedValueException('Required at least 3 elements of $distributionLevels array.');
        }

        sort($distributionLevels);
        if ($distributionLevels[0] !== 0) {
            array_unshift($distributionLevels, 0);
        }

        $this->distributionConfiguration[$key] = $distributionLevels;
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $products = $this->productsRepository->all($this->text, $this->tags);

        $pnp = new PreviousNextPaginator();
        $this->addComponent($pnp, 'paginator');
        $paginator = $pnp->getPaginator();
        $paginator->setItemsPerPage($this->onPage);

        $products = $products->limit($paginator->getLength(), $paginator->getOffset())->fetchAll();
        $pnp->setActualItemCount(count($products));

        $this->template->allProductsCount = $this->productsRepository->totalCount(true);
        $this->template->products = $products;
    }

    /**
     * @admin-access-level read
     */
    public function renderShow($id)
    {
        $product = $this->productsRepository->find($id);
        if (!$product) {
            $this->flashMessage($this->translator->translate('products.admin.products.messages.product_not_found'), 'danger');
            $this->redirect('default');
        }

        $levels = $this->distributionConfiguration['user_payment_amount'] ?? [0, 0.01, 3, 6, 10, 20, 50, 100, 200, 300];
        $this->template->amountSpentDistributionLevels = $levels;
        $this->template->amountSpentDistribution = $this->productsRepository->userAmountSpentDistribution($levels, $product->id);

        $levels = $this->distributionConfiguration['user_payment_count'] ?? [0, 1, 3, 5, 8, 13, 21, 34];
        $this->template->paymentCountDistributionLevels = $levels;
        $this->template->paymentCountDistribution = $this->productsRepository->userPaymentCountsDistribution($levels, $product->id);

        $levels = $this->distributionConfiguration['product_shop_count'] ?? [0, 1, 3, 5, 8, 13, 21, 34];
        $this->template->shopCountsDistributionLevels = $levels;
        $this->template->shopCountsDistribution = $this->productsRepository->productShopCountsDistribution($levels, $product->id);

        $levels = $this->distributionConfiguration['product_days_from_last_order'] ?? [0, 7, 14, 31, 93, 186, 365, 99999];
        $this->template->shopDaysDistribution = $this->productsRepository->productDaysFromLastOrderDistribution($levels, $product->id);

        $this->template->product = $product;

        $this->template->soldCount = $this->getProductSalesCount($product);
    }

    /**
     * @admin-access-level write
     */
    public function renderNew()
    {
    }

    /**
     * @admin-access-level write
     */
    public function handleDelete($productId)
    {
        $product = $this->productsRepository->find($productId);
        $this->productsRepository->softDelete($product);

        $this->flashMessage(
            $this->translator->translate(
                'products.admin.products.messages.product_deleted',
                null,
                ['product' => $product->name]
            )
        );
        $this->redirect('this');
    }

    private function getProductSalesCount(IRow $product)
    {
        return $this->paymentItemsRepository->getTable()
            ->where('product_id', $product->id)
            ->where('payment.status', PaymentsRepository::STATUS_PAID)
            ->fetchField('COALESCE(SUM(`count`), 0)');
    }

    /**
     * @admin-access-level read
     */
    public function renderUserList(int $id, string $type, float $fromLevel, float $toLevel = null)
    {
        $product = $this->productsRepository->find($id);
        if (!$product) {
            $this->flashMessage($this->translator->translate('products.admin.products.messages.product_not_found'), 'danger');
            $this->redirect('default');
        }
        $this->template->product = $product;
        $this->template->fromLevel = $fromLevel;
        $this->template->toLevel = $toLevel;
        $this->template->type = $type;

        if ($type == 'amountSpent') {
            $this->template->users = $this->productsRepository->userAmountSpentDistributionList($fromLevel, $toLevel, $id);
        } elseif ($type == 'paymentCounts') {
            $this->template->users = $this->productsRepository->userPaymentCountsDistributionList($fromLevel, $toLevel, $id);
        } elseif ($type == 'shopDays') {
            $this->template->users = $this->productsRepository->productDaysFromLastOrderDistributionList($fromLevel, $toLevel, $id);
        } elseif ($type == 'shopCounts') {
            $this->template->users = $this->productsRepository->productShopCountsDistributionList($fromLevel, $toLevel, $id);
        } else {
            $this->redirect('show', $id);
        }
    }

    /**
     * @admin-access-level write
     */
    public function renderEdit($id)
    {
        $product = $this->productsRepository->find($id);
        if (!$product) {
            $this->flashMessage($this->translator->translate('products.admin.products.messages.product_not_found'), 'danger');
            $this->redirect('default');
        }
        $product = $this->productManager->syncProductWithDistributionCenter($product);
        $this->template->product = $product;
    }

    protected function createComponentProductsForm()
    {
        $id = $this->getParameter('id');
        $form = $this->productsFormFactory->create($id);

        $this->productsFormFactory->onSave = function (ActiveRow $product) {
            $this->flashMessage($this->translator->translate('products.admin.products.messages.product_created'));
            $this->redirect('Show', $product->id);
        };
        $this->productsFormFactory->onUpdate = function (ActiveRow $product) {
            $this->flashMessage($this->translator->translate('products.admin.products.messages.product_updated'));
            $this->redirect('Show', $product->id);
        };

        return $form;
    }

    protected function createComponentSaleGraph(GoogleLineGraphGroupControlFactoryInterface $factory)
    {
        $product = $this->productsRepository->find($this->params['id']);

        $graphDataItem1 = new GraphDataItem();
        $graphDataItem1->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setJoin("LEFT JOIN payment_items ON payments.id = payment_items.payment_id AND payment_items.type = '" . ProductPaymentItem::TYPE . "'")
            ->setTimeField('created_at')
            ->setWhere('AND payments.status = \'paid\' AND payment_items.product_id=' . $product->id)
            ->setValueField('SUM(payment_items.count)')
            ->setStart('-1 month'))
            ->setName('Product ' . $product->user_label);

        $control = $factory->create()
            ->setGraphTitle($this->translator->translate('products.admin.products.stats.sold_products'))
            ->setGraphHelp($this->translator->translate('products.admin.products.stats.sold_products_in_time'))
            ->addGraphDataItem($graphDataItem1);

        return $control;
    }

    public function createComponentAdminFilterForm()
    {
        $form = new Form;
        $form->setRenderer(new BootstrapInlineRenderer());
        $form->addText('text', $this->translator->translate('system.filter'), 50)
            ->setAttribute('placeholder', $this->translator->translate('products.admin.products.default.admin_filter_form.text.placeholder'))
            ->setAttribute('autofocus');

        $form->addMultiSelect(
            'tags',
            $this->translator->translate('products.admin.products.default.admin_filter_form.tags'),
            $this->tagsRepository->all()->fetchPairs('id', 'code')
        )->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->addSubmit('send', $this->translator->translate('system.filter'))
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-filter"></i> ' . $this->translator->translate('system.filter'));
        $presenter = $this;
        $form->addSubmit('cancel', $this->translator->translate('system.cancel_filter'))
            ->onClick[] = function () use ($presenter) {
                $presenter->redirect('ProductsAdmin:Default', ['text' => '', 'tags' => []]);
            };

        $form->setDefaults((array)$this->params);

        $form->onSuccess[] = [$this, 'adminFilterSubmited'];
        return $form;
    }

    public function adminFilterSubmited(Form $form, array $values)
    {
        $this->redirect('Default', $values);
    }
}
