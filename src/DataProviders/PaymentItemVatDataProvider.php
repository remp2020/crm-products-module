<?php
declare(strict_types=1);

namespace Crm\ProductsModule\DataProviders;

use Crm\PaymentsModule\DataProviders\PaymentItemVatDataProviderInterface;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemInterface;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;

final class PaymentItemVatDataProvider implements PaymentItemVatDataProviderInterface
{
    public function getVat(PaymentItemInterface $paymentItem): ?float
    {
        if ($paymentItem instanceof ProductPaymentItem) {
            return (float) $paymentItem->getProduct()->vat;
        }
        return null;
    }
}
