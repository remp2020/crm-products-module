<?php
declare(strict_types=1);

namespace Crm\ProductsModule\DataProviders;

use Crm\PaymentsModule\DataProviders\OneStopShopCountryResolutionDataProviderInterface;
use Crm\PaymentsModule\Models\OneStopShop\CountryResolution;
use Crm\PaymentsModule\Models\OneStopShop\CountryResolutionType;
use Crm\PaymentsModule\Models\OneStopShop\OneStopShopCountryConflictException;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\UsersModule\Repositories\CountriesRepository;

final class OneStopShopCountryResolutionDataProvider implements OneStopShopCountryResolutionDataProviderInterface
{
    public function __construct(
        private CountriesRepository $countriesRepository,
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

        $shippingCountry = null;
        if (isset($formParams['shipping_country_id'])) {
            $shippingCountry = $this->countriesRepository->find($formParams['shipping_country_id']);
        }

        $invoiceCountry = null;
        if (isset($formParams['billing_address']['country_id'])) {
            $invoiceCountry = $this->countriesRepository->find($formParams['billing_address']['country_id']);
        }

        if ($invoiceCountry && $shippingCountry && $invoiceCountry->id !== $shippingCountry->id) {
            throw new OneStopShopCountryConflictException("Conflicting shipping country [{$shippingCountry->iso_code}] and invoice country [{$invoiceCountry->iso_code}]");
        }

        if ($invoiceCountry) {
            return new CountryResolution($invoiceCountry, CountryResolutionType::INVOICE_ADDRESS);
        }
        if ($shippingCountry) {
            return new CountryResolution($shippingCountry, CountryResolutionType::USER_SELECTED);
        }

        return null;
    }
}
