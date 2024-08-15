<?php
declare(strict_types=1);

namespace Crm\ProductsModule\DataProviders;

use Crm\PaymentsModule\DataProviders\OneStopShopCountryResolutionDataProviderInterface;
use Crm\PaymentsModule\Models\OneStopShop\CountryResolution;
use Crm\PaymentsModule\Models\OneStopShop\CountryResolutionTypeEnum;
use Crm\PaymentsModule\Models\OneStopShop\OneStopShopCountryConflictException;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\UsersModule\Repositories\CountriesRepository;

final class OneStopShopCountryResolutionDataProvider implements OneStopShopCountryResolutionDataProviderInterface
{
    public function __construct(
        private CountriesRepository $countriesRepository,
        private OrdersRepository $ordersRepository,
    ) {
    }

    public function provide(array $params): ?CountryResolution
    {
        $user = $params['user'] ?? null;
        $selectedCountryCode = $params['selectedCountryCode'] ?? null;
        $paymentAddress = $params['paymentAddress'] ?? null;
        /** @var ?PaymentItemContainer $paymentItemContainer */
        $paymentItemContainer = $params['paymentItemContainer'] ?? null;
        $formParams = $params['formParams'] ?? [];
        $payment = $params['payment'] ?? [];

        $shippingCountry = null;
        $invoiceCountry = null;

        if ($payment) {
            $order = $this->ordersRepository->findByPayment($payment);
            if ($order) {
                $shippingCountry = $order->shipping_address?->country;
                $invoiceCountry = $order->billing_address?->country;
            }
        }

        if (!$shippingCountry && isset($formParams['shipping_country_id'])) {
            $shippingCountry = $this->countriesRepository->find($formParams['shipping_country_id']);
        }

        if (!$invoiceCountry && isset($formParams['billing_address']['country_id'])) {
            $invoiceCountry = $this->countriesRepository->find($formParams['billing_address']['country_id']);
        }

        if ($invoiceCountry && $shippingCountry && $invoiceCountry->id !== $shippingCountry->id) {
            throw new OneStopShopCountryConflictException("Conflicting shipping country [{$shippingCountry->iso_code}] and invoice country [{$invoiceCountry->iso_code}]");
        }

        if ($invoiceCountry) {
            return new CountryResolution($invoiceCountry, CountryResolutionTypeEnum::InvoiceAddress);
        }
        if ($shippingCountry) {
            return new CountryResolution($shippingCountry, CountryResolutionTypeEnum::PaymentAddress);
        }

        return null;
    }
}
