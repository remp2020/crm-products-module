<?php

namespace Crm\ProductsModule\Presenters;

use Crm\AdminModule\Components\DateFilterFormFactory;
use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\Components\Graphs\GoogleBarGraphGroup\GoogleBarGraphGroupControlFactoryInterface;
use Crm\ApplicationModule\Models\Graphs\Criteria;
use Crm\ApplicationModule\Models\Graphs\GraphDataItem;
use Crm\ApplicationModule\UI\Form;
use Crm\ProductsModule\Components\ProductStats\ProductStatsFactory;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Nette\Application\Attributes\Persistent;
use Nette\DI\Attributes\Inject;
use Nette\Utils\DateTime;
use Tomaj\Form\Renderer\BootstrapInlineRenderer;

class DashboardPresenter extends AdminPresenter
{
    #[Persistent]
    public $dateFrom;

    #[Persistent]
    public $dateTo;

    #[Persistent]
    public $productStatsMode;

    #[Inject]
    public ProductStatsFactory $productStatsFactory;

    public function startup()
    {
        parent::startup();
        $this->dateFrom = $this->dateFrom ?? DateTime::from('-2 months')->format('Y-m-d');
        $this->dateTo = $this->dateTo ?? DateTime::from('today')->format('Y-m-d');
        $this->productStatsMode = $this->productStatsMode ?? ProductStatsFactory::MODE_ALL;
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
    }

    public function createComponentDateFilterForm(DateFilterFormFactory $dateFilterFormFactory)
    {
        $form = $dateFilterFormFactory->create($this->dateFrom, $this->dateTo);
        $form->onSuccess[] = function ($form, $values) {
            $this->dateFrom = $values['date_from'];
            $this->dateTo = $values['date_to'];
            $this->redirect($this->action);
        };
        return $form;
    }

    public function createComponentGoogleProductsStatsGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $this->getSession()->close();
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('paid_at')
            ->setWhere("AND payments.id IN (SELECT id FROM payments WHERE status = 'paid')")
            ->setGroupBy('products.id')
            ->setJoin(
                "LEFT JOIN payment_items ON payments.id = payment_items.payment_id AND payment_items.type = '" . ProductPaymentItem::TYPE . "' " .
                'LEFT JOIN products ON product_id = products.id'
            )
            ->setSeries('products.name')
            ->setValueField('SUM(payment_items.count)')
            ->setStart($this->dateFrom)
            ->setEnd($this->dateTo));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('products.admin.dashboard.products.title'))
            ->setGraphHelp($this->translator->translate('products.admin.dashboard.products.tooltip'))
            ->addGraphDataItem($graphDataItem)
            ->setFrom($this->dateFrom)
            ->setTo($this->dateTo);

        return $control;
    }

    public function createComponentGoogleProductTagsStatsGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $this->getSession()->close();
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('paid_at')
            ->setWhere("AND payments.id IN (SELECT id FROM payments WHERE status = 'paid')")
            ->setGroupBy('product_tags.tag_id')
            ->setJoin(
                "LEFT JOIN payment_items ON payments.id = payment_items.payment_id AND payment_items.type = '" . ProductPaymentItem::TYPE . "' " .
                'LEFT JOIN products ON product_id = products.id ' .
                'LEFT JOIN product_tags ON products.id = product_tags.product_id ' .
                'LEFT JOIN tags ON product_tags.tag_id = tags.id '
            )
            ->setSeries('tags.name')
            ->setValueField('SUM(payment_items.count)')
            ->setStart($this->dateFrom)
            ->setEnd($this->dateTo));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('products.admin.dashboard.product_tags.title'))
            ->setGraphHelp($this->translator->translate('products.admin.dashboard.product_tags.tooltip'))
            ->addGraphDataItem($graphDataItem)
            ->setFrom($this->dateFrom)
            ->setTo($this->dateTo);

        return $control;
    }

    public function createComponentGoogleOrdersCountGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $this->getSession()->close();
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('orders')
            ->setJoin(
                "INNER JOIN payments ON payments.id = orders.payment_id AND payments.status = 'paid'"
            )
            ->setTimeField('created_at')
            ->setValueField('COUNT(orders.id)')
            ->setStart($this->dateFrom)
            ->setEnd($this->dateTo));
        $graphDataItem->setName($this->translator->translate('products.admin.dashboard.orders_count.title'));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('products.admin.dashboard.orders_count.title'))
            ->setGraphHelp($this->translator->translate('products.admin.dashboard.orders_count.tooltip'))
            ->addGraphDataItem($graphDataItem)
            ->setFrom($this->dateFrom)
            ->setTo($this->dateTo);

        return $control;
    }

    public function createComponentGoogleProductsPaidSumGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $this->getSession()->close();
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('paid_at')
            ->setWhere("AND payments.status = 'paid'")
            ->setJoin(
                "INNER JOIN payment_items ON payments.id = payment_items.payment_id AND payment_items.type = '" . ProductPaymentItem::TYPE . "'"
            )
            ->setValueField('SUM(payment_items.count * payment_items.amount)')
            ->setStart($this->dateFrom)
            ->setEnd($this->dateTo));
        $graphDataItem->setName($this->translator->translate('products.admin.dashboard.products_paid_sum.title'));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('products.admin.dashboard.products_paid_sum.title'))
            ->setGraphHelp($this->translator->translate('products.admin.dashboard.products_paid_sum.tooltip'))
            ->addGraphDataItem($graphDataItem)
            ->setFrom($this->dateFrom)
            ->setTo($this->dateTo);

        return $control;
    }

    public function createComponentGoogleProductsAveragePaidSumGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $this->getSession()->close();
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('paid_at')
            ->setJoin(
                "INNER JOIN (
                    SELECT payments.id, SUM(payment_items.count * payment_items.amount) as sum FROM payments
                        INNER JOIN payment_items ON payments.id = payment_items.payment_id AND payment_items.type = '" . ProductPaymentItem::TYPE . "'
                    WHERE status = 'paid'
                    GROUP BY payments.id
                ) products_paid_amounts ON products_paid_amounts.id = payments.id"
            )
            ->setValueField('AVG(products_paid_amounts.sum)')
            ->setStart($this->dateFrom)
            ->setEnd($this->dateTo));
        $graphDataItem->setName($this->translator->translate('products.admin.dashboard.products_average_paid_sum.title'));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('products.admin.dashboard.products_average_paid_sum.title'))
            ->setGraphHelp($this->translator->translate('products.admin.dashboard.products_average_paid_sum.tooltip'))
            ->addGraphDataItem($graphDataItem)
            ->setFrom($this->dateFrom)
            ->setTo($this->dateTo);

        return $control;
    }

    public function createComponentGoogleAverageProductsCountGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $this->getSession()->close();
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('paid_at')
            ->setJoin(
                "INNER JOIN (
                    SELECT payments.id, SUM(payment_items.count) as sum FROM payments
                        INNER JOIN payment_items ON payments.id = payment_items.payment_id AND payment_items.type = '" . ProductPaymentItem::TYPE . "'
                    WHERE status = 'paid'
                    GROUP BY payments.id
                ) products_count ON products_count.id = payments.id"
            )
            ->setValueField('AVG(products_count.sum)')
            ->setStart($this->dateFrom)
            ->setEnd($this->dateTo));
        $graphDataItem->setName($this->translator->translate('products.admin.dashboard.products_average_count.title'));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('products.admin.dashboard.products_average_count.title'))
            ->setGraphHelp($this->translator->translate('products.admin.dashboard.products_average_count.tooltip'))
            ->addGraphDataItem($graphDataItem)
            ->setFrom($this->dateFrom)
            ->setTo($this->dateTo);

        return $control;
    }

    public function createComponentGoogleAverageVariousProductsCountGraph(GoogleBarGraphGroupControlFactoryInterface $factory)
    {
        $this->getSession()->close();
        $graphDataItem = new GraphDataItem();
        $graphDataItem->setCriteria((new Criteria())
            ->setTableName('payments')
            ->setTimeField('paid_at')
            ->setJoin(
                "INNER JOIN (
                    SELECT payments.id, COUNT(payment_items.id) as count FROM payments
                        INNER JOIN payment_items ON payments.id = payment_items.payment_id AND payment_items.type = '" . ProductPaymentItem::TYPE . "'
                    WHERE status = 'paid'
                    GROUP BY payments.id
                ) products_various_count ON products_various_count.id = payments.id"
            )
            ->setValueField('AVG(products_various_count.count)')
            ->setStart($this->dateFrom)
            ->setEnd($this->dateTo));
        $graphDataItem->setName($this->translator->translate('products.admin.dashboard.products_various_average_count.title'));

        $control = $factory->create();
        $control->setGraphTitle($this->translator->translate('products.admin.dashboard.products_various_average_count.title'))
            ->setGraphHelp($this->translator->translate('products.admin.dashboard.products_various_average_count.tooltip'))
            ->addGraphDataItem($graphDataItem)
            ->setFrom($this->dateFrom)
            ->setTo($this->dateTo);

        return $control;
    }

    public function createComponentProductStatsModeForm()
    {
        $form = new Form;
        $form->setRenderer(new BootstrapInlineRenderer());
        $form->addSelect('product_stats_mode', $this->translator->translate('products.admin.products.stats.form.mode'), $this->productStatsFactory->getProductModesPairs());

        $form->addSubmit('send', $this->translator->translate('products.admin.products.stats.form.filter'))
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-filter"></i> ' . $this->translator->translate('products.admin.products.stats.form.filter'));

        $form->setDefaults([
            'product_stats_mode' => $this->productStatsMode,
        ]);
        $form->onSuccess[] = function ($form, $values) {
            $this->productStatsMode = $values['product_stats_mode'];
            $this->redirect($this->action);
        };
        return $form;
    }

    public function createComponentTableProductsStatsGraph()
    {
        return $this->productStatsFactory->create($this->productStatsMode);
    }
}
