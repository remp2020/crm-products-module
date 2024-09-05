<?php

namespace Crm\ProductsModule\Models\PaymentItem;

use Crm\PaymentsModule\Models\PaymentItem\PaymentItemInterface;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemTrait;
use Nette\Database\Table\ActiveRow;

final class PostalFeePaymentItem implements PaymentItemInterface
{
    use PaymentItemTrait;

    public const TYPE = 'postal_fee';

    public static function fromPaymentItem(ActiveRow $paymentItem): static
    {
        $item = new self(
            $paymentItem->postal_fee,
            $paymentItem->vat,
            $paymentItem->count,
            self::loadMeta($paymentItem),
        );

        $item->forcePrice($paymentItem->amount)
            ->forceName($paymentItem->name);
        return $item;
    }

    public function __construct(
        private readonly ActiveRow $postalFee,
        float $vat,
        int $count = 1,
        array $meta = [],
    ) {
        $this->name = $this->postalFee->title;
        $this->price = $postalFee->amount;
        $this->vat = $vat;
        $this->count = $count;
        $this->meta = $meta;
    }

    public function forcePrice(float $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function forceName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function data(): array
    {
        return [
            'postal_fee_id' => $this->postalFee->id,
        ];
    }
}
