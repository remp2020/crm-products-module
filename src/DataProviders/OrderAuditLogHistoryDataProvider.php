<?php
declare(strict_types=1);

namespace Crm\ProductsModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\AuditLogHistoryDataProviderInterface;
use Crm\ApplicationModule\Models\DataProvider\AuditLogHistoryDataProviderItem;
use Crm\ApplicationModule\Models\DataProvider\AuditLogHistoryItemChangeIndicatorEnum;
use Crm\ApplicationModule\Repositories\AuditLogRepository;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Nette\Utils\Json;

class OrderAuditLogHistoryDataProvider implements AuditLogHistoryDataProviderInterface
{
    private const WATCHED_COLUMNS = [
        'status',
        'shipping_address_id',
        'billing_address_id',
        'licence_address_id',
        'postal_fee_id',
        'note',
        'status',
        'kika_delivery_point_id',
    ];
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
    ) {
    }

    public function provide(string $tableName, string $signature): array
    {
        if ($tableName !== 'orders') {
            return [];
        }

        $history = $this->auditLogRepository->getByTableAndSignature($tableName, $signature)
            ->order('created_at ASC, id ASC')
            ->fetchAll();

        $results = [];
        foreach ($history as $item) {
            $itemKey = strval($item->created_at) . $item->user?->id;
            $auditLogHistoryDataProviderItem = $results[$itemKey] ?? new AuditLogHistoryDataProviderItem(
                $item->created_at,
                $item->operation,
                $item->user,
            );

            $changes = Json::decode($item->data, true);
            if (isset($changes['to']['status']) && $changes['to']['status'] === OrdersRepository::STATUS_PAID) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.order_audit_log_history.status_change.paid',
                );
                $auditLogHistoryDataProviderItem->setChangeIndicator(
                    AuditLogHistoryItemChangeIndicatorEnum::Success,
                );
            } elseif (isset($changes['to']['status']) && $changes['to']['status'] === OrdersRepository::STATUS_CONFIRMED) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.order_audit_log_history.status_change.confirmed',
                );
                $auditLogHistoryDataProviderItem->setChangeIndicator(
                    AuditLogHistoryItemChangeIndicatorEnum::Info,
                );
            } elseif (isset($changes['to']['status']) && $changes['to']['status'] === OrdersRepository::STATUS_DELIVERED) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.order_audit_log_history.status_change.delivered',
                );
                $auditLogHistoryDataProviderItem->setChangeIndicator(
                    AuditLogHistoryItemChangeIndicatorEnum::Success,
                );
            } elseif (isset($changes['to']['status']) && $changes['to']['status'] === OrdersRepository::STATUS_NEW) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.order_audit_log_history.status_change.new',
                );
                $auditLogHistoryDataProviderItem->setChangeIndicator(
                    AuditLogHistoryItemChangeIndicatorEnum::Primary,
                );
            } elseif (isset($changes['to']['status']) && $changes['to']['status'] === OrdersRepository::STATUS_PAYMENT_REFUNDED) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.order_audit_log_history.status_change.payment_refunded',
                );
                $auditLogHistoryDataProviderItem->setChangeIndicator(
                    AuditLogHistoryItemChangeIndicatorEnum::Warning,
                );
            } elseif (isset($changes['to']['status']) && $changes['to']['status'] === OrdersRepository::STATUS_PAYMENT_FAILED) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.order_audit_log_history.status_change.payment_failed',
                );
                $auditLogHistoryDataProviderItem->setChangeIndicator(
                    AuditLogHistoryItemChangeIndicatorEnum::Warning,
                );
            }

            // if there are no messages, but we have changes, add a default message
            if ($item->operation === 'update' && empty($auditLogHistoryDataProviderItem->getMessages())) {
                // Filter only watched columns
                $changedColumns = array_intersect(array_keys($changes['to']), self::WATCHED_COLUMNS);
                if (!empty($changedColumns)) {
                    $auditLogHistoryDataProviderItem->addMessage(
                        'subscriptions.data_provider.payment_audit_log_history.columns_changed',
                        ['columns' => implode(', ', $changedColumns)],
                    );
                }
            }

            $results[$itemKey] = $auditLogHistoryDataProviderItem;
        }

        return array_values($results);
    }
}
