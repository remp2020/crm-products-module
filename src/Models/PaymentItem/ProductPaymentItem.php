<?php

namespace Crm\ProductsModule\Models\PaymentItem;

use Crm\PaymentsModule\Models\PaymentItem\PaymentItemInterface;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemTrait;
use Nette\Database\Table\ActiveRow;

class ProductPaymentItem implements PaymentItemInterface
{
    use PaymentItemTrait;

    public const TYPE = 'product';

    private ActiveRow $product;

    public function __construct(ActiveRow $product, int $count)
    {
        $this->product = $product;
        $this->count = $count;
        $this->name = $product->name;
        $this->price = $product->price;
        $this->vat = $product->vat;
    }

    public function forcePrice(float $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function forceVat(int $vat): static
    {
        $this->vat = $vat;
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
            'product_id' => $this->product->id,
        ];
    }

    public function meta(): array
    {
        return [];
    }
}
