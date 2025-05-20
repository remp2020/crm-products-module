<?php

namespace Crm\ProductsModule\Scenarios;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Models\Criteria\ScenarioParams\StringLabeledArrayParam;
use Crm\ApplicationModule\Models\Criteria\ScenariosCriteriaInterface;
use Crm\ProductsModule\Repositories\DistributionCentersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

class HasProductWithDistributionCenterCriteria implements ScenariosCriteriaInterface
{
    const KEY = 'has_product_with_distribution_center';

    private $distributionCentersRepository;

    private $translator;

    public function __construct(
        DistributionCentersRepository $distributionCentersRepository,
        Translator $translator,
    ) {
        $this->distributionCentersRepository = $distributionCentersRepository;
        $this->translator = $translator;
    }

    public function params(): array
    {
        $options = $this->distributionCentersRepository->all()->fetchPairs('code', 'name');

        return [
            new StringLabeledArrayParam(self::KEY, $this->label(), $options),
        ];
    }

    public function addConditions(Selection $selection, array $paramValues, ActiveRow $criterionItemRow): bool
    {
        $values = $paramValues[self::KEY];
        $selection->where(
            'payment:payment_items.product.distribution_center IN (?)',
            $values->selection,
        );
        return true;
    }

    public function label(): string
    {
        return $this->translator->translate('products.admin.scenarios.has_product_with_distribution_center.label');
    }
}
