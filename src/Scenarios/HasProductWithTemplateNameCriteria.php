<?php

namespace Crm\ProductsModule\Scenarios;

use Crm\ApplicationModule\Criteria\ScenarioParams\StringLabeledArrayParam;
use Crm\ApplicationModule\Criteria\ScenariosCriteriaInterface;
use Crm\ProductsModule\Repository\ProductTemplatesRepository;
use Kdyby\Translation\Translator;
use Nette\Database\Table\IRow;
use Nette\Database\Table\Selection;

class HasProductWithTemplateNameCriteria implements ScenariosCriteriaInterface
{
    const KEY = 'has_product_with_template_name';

    private $productTemplatesRepository;

    private $translator;

    public function __construct(
        ProductTemplatesRepository $productTemplatesRepository,
        Translator $translator
    ) {
        $this->productTemplatesRepository = $productTemplatesRepository;
        $this->translator = $translator;
    }

    public function params(): array
    {
        $options = $this->productTemplatesRepository->all()->fetchPairs('id', 'name');

        return [
            new StringLabeledArrayParam(
                self::KEY,
                $this->label(),
                $options
            ),
        ];
    }

    public function addConditions(Selection $selection, array $paramValues, IRow $criterionItemRow): bool
    {
        $values = $paramValues[self::KEY];
        $selection->where('payment:payment_items.product.product_template.id IN (?)', $values->selection);
        return true;
    }

    public function label(): string
    {
        return $this->translator->translate('products.admin.scenarios.has_product_with_template_name.label');
    }
}
