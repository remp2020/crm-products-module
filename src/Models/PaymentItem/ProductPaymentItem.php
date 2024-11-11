<?php

namespace Crm\ProductsModule\Models\PaymentItem;

use Crm\PaymentsModule\Models\PaymentItem\PaymentItemInterface;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemTrait;
use Nette\Database\Table\ActiveRow;

final class ProductPaymentItem implements PaymentItemInterface
{
    use PaymentItemTrait;

    public const TYPE = 'product';

    public function __construct(
        readonly private ActiveRow $product,
        int $count,
        array $meta = [],
    ) {
        $this->name = $product->name;
        $this->price = $product->price;
        $this->vat = $product->vat;
        $this->count = $count;
        $this->meta = $meta;
    }

    public function forceName($name): self
    {
        $this->name = $name;
        return $this;
    }

    public function data(): array
    {
        return [
            'product_id' => $this->product->id,
        ];
    }

    public function getProduct(): ActiveRow
    {
        return $this->product;
    }

    public static function fromPaymentItem(ActiveRow $paymentItem): static
    {
        if ($paymentItem->type !== self::TYPE) {
            throw new \RuntimeException("Invalid type of payment item [{$paymentItem->type}], must be [" . self::TYPE . "]");
        }
        if (!$paymentItem->product) {
            throw new \RuntimeException("No associated product for payment_item #[{$paymentItem->id}], product is required for given type");
        }

        return new ProductPaymentItem(
            $paymentItem->product,
            $paymentItem->count,
            self::loadMeta($paymentItem),
        );
    }
}
