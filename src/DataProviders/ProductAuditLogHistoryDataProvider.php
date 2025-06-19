<?php
declare(strict_types=1);

namespace Crm\ProductsModule\DataProviders;

use Crm\ApplicationModule\Helpers\LocalizedDateHelper;
use Crm\ApplicationModule\Helpers\PriceHelper;
use Crm\ApplicationModule\Models\DataProvider\AuditLogHistoryDataProviderInterface;
use Crm\ApplicationModule\Models\DataProvider\AuditLogHistoryDataProviderItem;
use Crm\ApplicationModule\Repositories\AuditLogRepository;
use Nette\Utils\Json;

class ProductAuditLogHistoryDataProvider implements AuditLogHistoryDataProviderInterface
{
    private const WATCHED_COLUMNS = [
        'code',
        'catalog_price',
        'has_discount_rate',
        'user_label',
        'visible',
        'ean',
        'image_url',
        'images',
        'product_template_id',
        'has_delivery',
        'deleted_at',
    ];
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly PriceHelper $priceHelper,
        private readonly LocalizedDateHelper $localizedDateHelper,
    ) {
    }

    public function provide(string $tableName, string $signature): array
    {
        if ($tableName !== 'products') {
            return [];
        }

        $history = $this->auditLogRepository->getByTableAndSignature($tableName, $signature)
            ->order('created_at ASC, id ASC')
            ->fetchAll();

        // filter out items that are related to stock changes
        $history = array_filter($history, function ($item) {
            $data = Json::decode($item->data, true);
            if (isset($data['to']['stock-='])) {
                return false;
            }
            return true;
        });

        $results = [];
        foreach ($history as $item) {
            $itemKey = strval($item->created_at) . $item->user?->id;
            $auditLogHistoryDataProviderItem = $results[$itemKey] ?? new AuditLogHistoryDataProviderItem(
                $item->created_at,
                $item->operation,
                $item->user,
            );

            $changes = Json::decode($item->data, true);

            if (isset($changes['from']['name'])) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.product_audit_log_history.name_change',
                    [
                        'from' => $changes['from']['name'],
                        'to' => $changes['to']['name'],
                    ],
                );
            }

            if (isset($changes['from']['price'])) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.product_audit_log_history.price_change',
                    [
                        'from' => $this->priceHelper->getFormattedPrice($changes['from']['price']),
                        'to' => $this->priceHelper->getFormattedPrice($changes['to']['price']),
                    ],
                );
            }

            if (isset($changes['from']['vat'])) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.product_audit_log_history.vat_change',
                    [
                        'from' => $changes['from']['vat'],
                        'to' => $changes['to']['vat'],
                    ],
                );
            }

            if (isset($changes['from']['available_at'])) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.product_audit_log_history.available_at_change',
                    [
                        'from' => $this->localizedDateHelper->process($changes['from']['available_at'], false, false),
                        'to' => $this->localizedDateHelper->process($changes['to']['available_at'], false, false),
                    ],
                );
            }

            if (isset($changes['from']['description'])) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.product_audit_log_history.description_change',
                );
            }

            if (isset($changes['from']['distribution_center'])) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.product_audit_log_history.distribution_center_change',
                    [
                        'from' => $changes['from']['distribution_center'],
                        'to' => $changes['to']['distribution_center'],
                    ],
                );
            }

            if (isset($changes['from']['sorting'])) {
                $auditLogHistoryDataProviderItem->addMessage(
                    'products.data_provider.product_audit_log_history.sorting_change',
                );
            }

            // if there are no messages, but we have changes, add a default message
            if ($item->operation === 'update' && empty($auditLogHistoryDataProviderItem->getMessages())) {
                // Filter only watched columns
                $changedColumns = array_intersect(array_keys($changes['to']), self::WATCHED_COLUMNS);
                if (!empty($changedColumns)) {
                    $auditLogHistoryDataProviderItem->addMessage(
                        'products.data_provider.product_audit_log_history.columns_changed',
                        [
                            'columns' => implode(', ', $changedColumns),
                        ],
                    );
                }
            }

            $results[$itemKey] = $auditLogHistoryDataProviderItem;
        }

        return array_values($results);
    }
}
