<?php

namespace Crm\ProductsModule\Scenarios;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Models\Criteria\ScenarioParams\StringLabeledArrayParam;
use Crm\ApplicationModule\Models\Criteria\ScenariosCriteriaInterface;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

class ActualOrderStatusCriteria implements ScenariosCriteriaInterface
{
    const KEY = 'order_status';

    private $ordersRepository;

    private $translator;

    public function __construct(
        OrdersRepository $ordersRepository,
        Translator $translator,
    ) {
        $this->ordersRepository = $ordersRepository;
        $this->translator = $translator;
    }

    public function params(): array
    {
        $statuses = $this->ordersRepository->getStatusPairs();

        return [
            new StringLabeledArrayParam(self::KEY, $this->label(), $statuses),
        ];
    }

    public function addConditions(Selection $selection, array $paramValues, ActiveRow $criterionItemRow): bool
    {
        $values = $paramValues[self::KEY];
        $selection->where('status IN (?)', $values->selection);

        return true;
    }

    public function label(): string
    {
        return $this->translator->translate('products.admin.scenarios.actual_order_status.label');
    }
}
