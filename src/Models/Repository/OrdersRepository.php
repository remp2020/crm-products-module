<?php

namespace Crm\ProductsModule\Repository;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Repository;
use Crm\ApplicationModule\Repository\AuditLogRepository;
use Crm\ProductsModule\Events\NewOrderEvent;
use Crm\ProductsModule\Events\OrderStatusChangeEvent;
use League\Event\Emitter;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class OrdersRepository extends Repository
{
    const STATUS_NEW = 'new'; // order was creates
    const STATUS_PAID = 'paid'; // order was paid (finished payment)
    const STATUS_PENDING = 'pending'; // order was sent to distribution center
    const STATUS_CONFIRMED = 'confirmed'; // order was registered to distribution center
    const STATUS_SENT = 'sent'; // order was sent to user from distribution center
    const STATUS_DELIVERED = 'delivered'; // order was delivered to user
    const STATUS_RETURNED = 'returned'; // order was returned to distribution center
    const STATUS_NOT_SENT = 'not-sent'; // communication with distribution center failed
    const STATUS_PAYMENT_FAILED = 'payment-failed'; // payment failed
    const STATUS_PAYMENT_REFUNDED = 'payment-refunded'; // payment was refunded
    const STATUS_IMPORTED = 'imported'; // imported payment paid in 3rd party system

    protected $tableName = 'orders';

    private $cacheRepository;

    private $emitter;

    private $hermesEmitter;

    public function __construct(
        Context $database,
        AuditLogRepository $auditLogRepository,
        CacheRepository $cacheRepository,
        Emitter $emitter,
        \Tomaj\Hermes\Emitter $hermesEmitter
    ) {
        parent::__construct($database);
        $this->auditLogRepository = $auditLogRepository;
        $this->database = $database;
        $this->cacheRepository = $cacheRepository;
        $this->emitter = $emitter;
        $this->hermesEmitter = $hermesEmitter;
    }

    final public function all()
    {
        return $this->getTable()->order('created_at DESC');
    }

    final public function add(
        $paymentId,
        $shippingAddressId,
        $licenceAddressId,
        $billingAddressId,
        $postalFee,
        $note = null,
        $additionalColumns = []
    ) {
        $order = $this->insert(array_merge([
            'payment_id' => $paymentId,
            'shipping_address_id' => $shippingAddressId,
            'licence_address_id' => $licenceAddressId,
            'billing_address_id' => $billingAddressId,
            'postal_fee_id' => $postalFee ? $postalFee->id : null,
            'note' => $note,
            'status' => static::STATUS_NEW,
            'created_at' => new \DateTime(),
            'updated_at' => new \DateTime(),
        ], $additionalColumns));

        $this->emitter->emit(new NewOrderEvent($order));
        $this->hermesEmitter->emit(new HermesMessage('new-order', [
            'order_id' => $order->id
        ]));

        return $order;
    }

    final public function update(IRow &$row, $data)
    {
        $statusChanged = false;
        $data['updated_at'] = new \DateTime();
        if ($row->status !== $data['status']) {
            $statusChanged = true;
        }

        $result = parent::update($row, $data);

        if ($statusChanged) {
            $this->emitter->emit(new OrderStatusChangeEvent($row->id));
            $this->hermesEmitter->emit(new HermesMessage('order-status-change', [
                'order_id' => $row->id,
                'order_status' => $data['status'],
            ]), HermesMessage::PRIORITY_DEFAULT);
        }

        return $result;
    }

    final public function getStatusPairs()
    {
        return [
            self::STATUS_NEW => self::STATUS_NEW,
            self::STATUS_PAID => self::STATUS_PAID,
            self::STATUS_PENDING => self::STATUS_PENDING,
            self::STATUS_CONFIRMED => self::STATUS_CONFIRMED,
            self::STATUS_SENT => self::STATUS_SENT,
            self::STATUS_DELIVERED => self::STATUS_DELIVERED,
            self::STATUS_RETURNED => self::STATUS_RETURNED,
            self::STATUS_NOT_SENT => self::STATUS_NOT_SENT,
        ];
    }

    final public function findByPayment($payment)
    {
        return $this->getTable()->where(['payment_id' => $payment->id])->fetch();
    }

    final public function getByUser($userId, $status = [])
    {
        $orders = $this->getTable()->where(['payment.user_id' => $userId])->order('created_at DESC');
        if (!empty($status)) {
            $orders->where(['orders.status' => (array)$status]);
        }

        return $orders;
    }

    final public function hasOrderAfter(int $userId, DateTime $after): bool
    {
        return $this->getTable()
                ->where([
                    'payment.user_id' => $userId,
                    'orders.created_at > ?' => $after,
                ])
                ->count('*') > 0;
    }

    final public function totalCount($allowCached = false, $forceCacheUpdate = false)
    {
        $callable = function () {
            return parent::totalCount();
        };
        if ($allowCached) {
            return $this->cacheRepository->loadAndUpdate(
                'orders_count',
                $callable,
                \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
                $forceCacheUpdate
            );
        }
        return $callable();
    }
}
