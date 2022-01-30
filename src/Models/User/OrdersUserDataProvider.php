<?php

namespace Crm\ProductsModule\User;

use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\User\UserDataProviderInterface;
use Crm\ProductsModule\Model\Config;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repository\OrdersRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\User\AddressesUserDataProvider;
use Nette\Localization\ITranslator;
use Nette\Utils\DateTime;
use Tracy\Debugger;

class OrdersUserDataProvider implements UserDataProviderInterface
{
    private $ordersRepository;
    private $configsRepository;
    private $subscriptionsRepository;
    private $translator;

    public function __construct(
        OrdersRepository $ordersRepository,
        ConfigsRepository $configsRepository,
        SubscriptionsRepository $subscriptionsRepository,
        ITranslator $translator
    ) {
        $this->ordersRepository = $ordersRepository;
        $this->configsRepository = $configsRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->translator = $translator;
    }

    public static function identifier(): string
    {
        return 'orders';
    }

    public function data($userId)
    {
        return [];
    }

    public function download($userId)
    {
        $orders = $this->ordersRepository->getByUser($userId);

        $results = [];
        foreach ($orders as $order) {
            $result = [
                'status' => $order->status,
                'created_at' => $order->created_at->format(\DateTime::RFC3339),
                'postal_service' => $order->postal_fee ? $order->postal_fee->title : null,
            ];

            $result['products'] = [];
            foreach ($order->payment->related('payment_items')->where('type = ?', ProductPaymentItem::TYPE) as $paymentItem) {
                $result['products'][] = [
                    'name' => $paymentItem->name,
                    'price' => $paymentItem->amount,
                    'count' => $paymentItem->count,
                ];
            }

            $results[] = $result;
        }

        return $results;
    }

    public function downloadAttachments($userId)
    {
        return [];
    }

    /**
     * Protect Addresses used by Orders against removal
     *
     * @param $userId
     * @return array
     * @throws \Exception
     */
    public function protect($userId): array
    {
        $exclude = [];
        foreach ($this->ordersRepository->getByUser($userId)->fetchAll() as $order) {
            $exclude[] = $order->shipping_address_id;
            $exclude[] = $order->licence_address_id;
            $exclude[] = $order->billing_address_id;
        }

        return [AddressesUserDataProvider::identifier() => array_unique(array_filter($exclude), SORT_NUMERIC)];
    }

    public function delete($userId, $protectedData = [])
    {
        return false;
    }

    public function canBeDeleted($userId): array
    {
        $config = $this->configsRepository->loadByName(Config::ORDER_BLOCK_ANONYMIZATION);

        if ($config && $config->value) {
            $configRow = $this->configsRepository->loadByName(Config::ORDER_BLOCK_ANONYMIZATION_WITHIN_DAYS);
            if ($configRow && is_numeric($configRow->value) && $configRow->value >= 0) {
                $deleteThreshold = new DateTime("-{$configRow->value} days");
            } elseif (empty($configRow->value) === true) {
                $deleteThreshold = new DateTime();
            } else {
                Debugger::log("Unexpected value for config option (" . Config::ORDER_BLOCK_ANONYMIZATION_WITHIN_DAYS . "): {$configRow->value}");
                return [false, $this->translator->translate('products.data_provider.delete.unexpected_configuration_value')];
            }

            if ($this->ordersRepository->hasOrderAfter($userId, $deleteThreshold)) {
                return [false, $this->translator->translate('products.data_provider.delete.active_order')];
            }
        }

        return [true, null];
    }
}
