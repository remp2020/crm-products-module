<?php

namespace Crm\ProductsModule\Scenarios;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Models\Criteria\ScenarioParams\StringLabeledArrayParam;
use Crm\ApplicationModule\Models\Criteria\ScenariosCriteriaInterface;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ScenariosModule\Events\ConditionCheckException;
use Crm\ScenariosModule\Scenarios\ScenariosTriggerCriteriaInterface;
use Crm\ScenariosModule\Scenarios\ScenariosTriggerCriteriaRequirementsInterface;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

class OrderStatusOnScenarioEnterCriteria implements ScenariosCriteriaInterface, ScenariosTriggerCriteriaInterface, ScenariosTriggerCriteriaRequirementsInterface
{
    const KEY = 'order_status_on_scenario_enter';

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
        return true;
    }

    public function getInputParams(): array
    {
        return ['order_status'];
    }

    public function evaluate($jobParameters, array $paramValues): bool
    {
        if (!isset($jobParameters->order_status)) {
            throw new ConditionCheckException("Missing order_status job parameter.");
        }
        $orderStatus = $jobParameters->order_status;
        $values = $paramValues[self::KEY];

        if (in_array($orderStatus, $values->selection, true)) {
            return true;
        }
        return false;
    }

    public function label(): string
    {
        return $this->translator->translate('products.admin.scenarios.order_status_on_scenario_enter.label');
    }
}
