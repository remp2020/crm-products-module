<?php

namespace Crm\ProductsModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Crm\ApplicationModule\Repositories\AuditLogRepository;
use Crm\ApplicationModule\Repositories\CacheRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\Models\Distribution\AmountSpentDistribution;
use Crm\ProductsModule\Models\Distribution\PaymentCountsDistribution;
use Crm\ProductsModule\Models\Distribution\ProductDaysFromLastOrderDistribution;
use Crm\ProductsModule\Models\Distribution\ProductShopCountsDistribution;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use DateTime;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;
use Nette\Utils\Strings;

class ProductsRepository extends Repository
{
    protected $tableName = 'products';

    private $amountSpentDistribution;

    private $paymentCountDistribution;

    private $productDaysFromLastOrderDistribution;

    private $productShopCountsDistribution;

    private $cacheRepository;

    public function __construct(
        Explorer $database,
        AuditLogRepository $auditLogRepository,
        AmountSpentDistribution $amountSpentDistribution,
        PaymentCountsDistribution $paymentCountDistribution,
        ProductDaysFromLastOrderDistribution $productDaysFromLastOrderDistribution,
        ProductShopCountsDistribution $productShopCountsDistribution,
        CacheRepository $cacheRepository
    ) {
        parent::__construct($database);
        $this->auditLogRepository = $auditLogRepository;
        $this->amountSpentDistribution = $amountSpentDistribution;
        $this->paymentCountDistribution = $paymentCountDistribution;
        $this->productDaysFromLastOrderDistribution = $productDaysFromLastOrderDistribution;
        $this->productShopCountsDistribution = $productShopCountsDistribution;
        $this->cacheRepository = $cacheRepository;
    }

    final public function insert($data)
    {
        if (isset($data['code'])) {
            $data['code'] = Strings::webalize($data['code']);
        }

        return parent::insert($data);
    }

    final public function update(ActiveRow &$row, $data)
    {
        // webalize `code` only if it was changed to something new
        // we don't want to break current URLs
        if (isset($data['code']) && $data['code'] !== $row->code) {
            $data['code'] = Strings::webalize($data['code']);
        }

        $data['modified_at'] = new \DateTime();
        return parent::update($row, $data);
    }

    final public function find($id): ?\Crm\ApplicationModule\Models\Database\ActiveRow
    {
        /** @var \Crm\ApplicationModule\Models\Database\ActiveRow $result */
        $result = $this->getTable()->where(['id' => $id, 'deleted_at' => null])->fetch();
        return $result;
    }

    final public function all(string $search = null, array $tags = []): Selection
    {
        $all = $this->getTable()
            ->where('deleted_at', null)
            ->order('-products.sorting DESC, name ASC');

        if (empty($tags) && ($search === null || empty(trim($search)))) {
            return $all;
        }

        $conditions = [];
        if (!empty($search)) {
            $searchText = "%{$search}%";
            $conditions = [
                'products.name LIKE ?' => $searchText,
                'products.user_label LIKE ?' => $searchText,
                ':product_properties.value LIKE ? AND :product_properties.product_template_property.code = "author"' => $searchText,
            ];

            // check if searched text is number (replace comma with period; otherwise is_numeric won't work)
            $searchNum = str_replace(',', '.', $search);
            if (is_numeric($searchNum)) {
                $searchFloat = (float) $searchNum;
                $conditions = array_merge($conditions, [
                    'price = ?' => $searchFloat,
                    'catalog_price = ?' => $searchFloat,
                ]);
            }
        }

        if (!empty($tags)) {
            $all->where(':product_tags.tag_id IN (?)', $tags);
        }

        return $all->whereOr($conditions);
    }

    final public function getByCode($code)
    {
        return $this->getTable()->where(['code' => $code, 'deleted_at' => null])->fetch();
    }

    final public function getShopProducts($visibleOnly = true, $availableOnly = true, $tag = null, $order = 'sorting')
    {
        $where = [
            'products.shop' => true,
            'products.deleted_at' => null,
        ];
        if ($visibleOnly === true) {
            $where['products.visible'] = true;
        }
        if ($availableOnly === true) {
            $where['products.stock > ?'] = 0;
        }
        if (isset($tag)) {
            $where[':product_tags.tag_id'] = $tag->id;
        }

        return $this->getTable()->where($where)->order($order);
    }

    final public function relatedProducts(ActiveRow $product, $limit = 4)
    {
        return $this->getShopProducts(true, true, null, 'RAND()')
            ->where('id != ?', $product->id)
            ->limit($limit);
    }

    final public function mostSoldProducts(DateTime $from = null, DateTime $to = null)
    {
        $products = $this->getShopProducts(true, true, null, 'sold_count DESC')
            ->select('products.*, SUM(:payment_items.count) AS sold_count')
            ->where([
                ':payment_items.type' => ProductPaymentItem::TYPE,
                ':payment_items.payment.status' => PaymentsRepository::STATUS_PAID,
                'products.deleted_at' => null
            ])
            ->group('products.id');

        if ($from) {
            $products->where(':payment_items.payment.paid_at >= ?', $from);
        }
        if ($to) {
            $products->where(':payment_items.payment.paid_at < ?', $to);
        }

        return $products;
    }

    final public function findByIds($ids)
    {
        return $this->getTable()->where([
            'id' => (array)$ids,
            'deleted_at' => null
        ])->fetchAll();
    }

    final public function updateSorting($newSorting, $oldSorting = null)
    {
        if ($newSorting == $oldSorting) {
            return;
        }

        if ($oldSorting !== null) {
            $this->getTable()->where('sorting > ?', $oldSorting)->update(['sorting-=' => 1]);
        }

        $this->getTable()->where('sorting >= ?', $newSorting)->update(['sorting+=' => 1]);
    }

    /**
     * Updates sorting of products using id => sorting pairs array
     *
     * @param array $sorting
     */
    final public function resortProducts(array $sorting): void
    {
        foreach ($sorting as $id => $position) {
            $this->getTable()
                ->where('id', $id)
                ->update(['sorting' => $position]);
        }
    }

    final public function userAmountSpentDistribution($levels, $productId)
    {
        return $this->amountSpentDistribution->distribution($productId, $levels);
    }

    final public function userAmountSpentDistributionList($fromLevel, $toLevel, $productId)
    {
        return $this->amountSpentDistribution->distributionList($productId, $fromLevel, $toLevel);
    }

    final public function userPaymentCountsDistribution($levels, $productId)
    {
        return $this->paymentCountDistribution->distribution($productId, $levels);
    }

    final public function userPaymentCountsDistributionList($fromLevel, $toLevel, $productId)
    {
        return $this->paymentCountDistribution->distributionList($productId, $fromLevel, $toLevel);
    }

    final public function productDaysFromLastOrderDistribution($levels, $productId)
    {
        return $this->productDaysFromLastOrderDistribution->distribution($productId, $levels);
    }

    final public function productDaysFromLastOrderDistributionList($fromlevel, $toLevel, $productId)
    {
        return $this->productDaysFromLastOrderDistribution->distributionList($productId, $fromlevel, $toLevel);
    }

    final public function productShopCountsDistribution($levels, $productId)
    {
        return $this->productShopCountsDistribution->distribution($productId, $levels);
    }

    final public function productShopCountsDistributionList($fromlevel, $toLevel, $productId)
    {
        return $this->productShopCountsDistribution->distributionList($productId, $fromlevel, $toLevel);
    }

    final public function decreaseStock(ActiveRow &$product, $count = 1)
    {
        $this->update($product, ['stock-=' => $count]);
    }

    final public function exists($code)
    {
        return $this->getTable()->where('code', $code)->count('*') > 0;
    }


    final public function stats(DateTime $from = null, DateTime $to = null): Selection
    {
        $selection = $this->getTable()
            ->select('SUM(:payment_items.count) AS product_count, SUM(:payment_items.amount * :payment_items.count) AS product_amount, products.id AS product_id')
            ->where(':payment_items.type = ?', ProductPaymentItem::TYPE)
            ->where(':payment_items.payment.status = ?', PaymentsRepository::STATUS_PAID)
            ->group('products.id');

        if ($to === null) {
            $to = new DateTime();
        }

        if ($from !== null) {
            $selection->where(':payment_items.payment.paid_at BETWEEN ? AND ?', $from, $to);
        }

        return $selection;
    }

    final public function softDelete(ActiveRow $segment)
    {
        $this->update($segment, [
            'deleted_at' => new \DateTime(),
            'modified_at' => new \DateTime(),
        ]);
    }

    final public function totalCount($allowCached = false, $forceCacheUpdate = false): int
    {
        $callable = function () {
            return parent::totalCount();
        };
        if ($allowCached) {
            return (int) $this->cacheRepository->loadAndUpdate(
                'products_count',
                $callable,
                \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
                $forceCacheUpdate
            );
        }
        return $callable();
    }
}
