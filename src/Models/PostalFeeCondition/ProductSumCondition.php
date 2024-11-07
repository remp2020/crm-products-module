<?php

namespace Crm\ProductsModule\Models\PostalFeeCondition;

use Contributte\Translation\Translator;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Nette\Application\UI\Form;
use Nette\ComponentModel\IComponent;
use Nette\Forms\Controls\TextInput;

class ProductSumCondition implements PostalFeeConditionInterface, PostalFeeNumericConditionInterface
{
    public const CONDITION_CODE = 'products_sum';

    private $translator;

    private $productsRepository;

    public function __construct(
        Translator $translator,
        ProductsRepository $productsRepository
    ) {
        $this->translator = $translator;
        $this->productsRepository = $productsRepository;
    }

    public function getLabel(): string
    {
        return $this->translator->translate('products.admin.country_postal_fees.conditions.products_sum.label');
    }

    public function isReached(array $products, ?string $value, int $userId = null): bool
    {
        return $this->sumProductsFromCart($products) >= (float)$value;
    }

    public function getActualValue(array $products): float
    {
        return $this->sumProductsFromCart($products);
    }

    public function getInputControl(): IComponent
    {
        $textInput = (new TextInput('products.data.country_postal_fees.fields.condition_value'))
            ->setRequired(true)
            ->addRule(Form::FLOAT, $this->translator->translate('products.admin.country_postal_fees.conditions.products_sum.validation_integer'))
            ->addRule(Form::MIN, $this->translator->translate('products.admin.country_postal_fees.conditions.products_sum.validation_min'), 0);

        $textInput->getControlPrototype()
            ->addAttributes(['class' => 'form-control']);

        return $textInput;
    }

    private function sumProductsFromCart(array $products): float
    {
        $sum = 0;
        foreach ($products as $productId => $count) {
            $productRow = $this->productsRepository->find($productId);
            $sum += $productRow->price * $count;
        }

        return $sum;
    }
}
