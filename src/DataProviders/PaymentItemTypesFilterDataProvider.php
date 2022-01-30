<?php

namespace Crm\ProductsModule\DataProvider;

use Crm\ApplicationModule\DataProvider\DataProviderException;
use Crm\PaymentsModule\DataProvider\PaymentItemTypesFilterDataProviderInterface;
use Crm\ProductsModule\PaymentItem\ProductPaymentItem;
use Nette\Localization\ITranslator;

class PaymentItemTypesFilterDataProvider implements PaymentItemTypesFilterDataProviderInterface
{
    private $filterKey = ProductPaymentItem::TYPE;

    private $translator;

    public function __construct(
        ITranslator $translator
    ) {
        $this->translator = $translator;
    }

    public function provide(array $params)
    {
        if (!isset($params['paymentItemTypes'])) {
            throw new DataProviderException('missing [paymentItemTypes] within data provider params');
        }
        if (!is_array($params['paymentItemTypes'])) {
            throw new DataProviderException('invalid type of provided form: ' . get_class($params['paymentItemTypes']));
        }

        if (!isset($params['paymentItemTypesDefaultFilter'])) {
            throw new DataProviderException('missing [paymentItemTypesDefaultFilter] within data provider params');
        }
        if (!is_array($params['paymentItemTypesDefaultFilter'])) {
            throw new DataProviderException('invalid type of provided form: ' . get_class($params['paymentItemTypesDefaultFilter']));
        }

        $params['paymentItemTypes'][$this->filterKey] = $this->translator->translate("products.data_provider.payment_item_types_filter.key.{$this->filterKey}");

        return $params;
    }

    public function filter($selectedTypes)
    {
        if (in_array($this->filterKey, $selectedTypes)) {
            return "payment_items.type = '{$this->filterKey}'";
        }

        return null;
    }
}
