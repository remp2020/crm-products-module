<?php

namespace Crm\ProductsModule\Models;

use Nette\Application\UI\Form;
use Nette\Forms\Container;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Rule;
use Nette\Forms\Rules;

trait CheckoutFormTrait
{
    public function conditionallyToggle(BaseControl $control, array $values, string $toggleId): void
    {
        $conditionAdded = false;
        foreach ($control->getRules() as $rule) {
            if ($rule->branch instanceof Rules && isset($rule->branch->getToggles()[$toggleId])) {
                $rule->arg = array_unique(array_merge($rule->arg, $values));
                $conditionAdded = true;
                break;
            }
        };

        if (!$conditionAdded) {
            $control
                ->addCondition(Form::IsIn, $values)
                ->toggle($toggleId, false);
        }
    }

    protected function amendContainerControlRules(BaseControl $baseControl, Container $container, array $values): void
    {
        /** @var BaseControl $control */
        foreach ($container->getControls() as $control) {
            // Get the original rules and reset the element.
            $rules = $control->getRules()->getIterator()->getArrayCopy();
            $control->getRules()->reset();

            // Add base condition to exclude values
            $condition = $control->addConditionOn($baseControl, Form::IsNotIn, $values);

            // Restore the original rules under the new branch.
            $this->amendValidationRules($condition, $rules);
        }
    }

    protected function amendValidationRules($baseCondition, $rules): void
    {
        /** @var Rule $rule */
        foreach ($rules as $rule) {
            // Branch is used for conditional rules. In case we find another condition, we go deeper.
            if ($rule->branch) {
                $branchCondition = $baseCondition->addConditionOn($rule->control, $rule->validator, $rule->arg);
                $this->amendValidationRules($branchCondition, $rule->branch->getIterator()->getArrayCopy());
            } else {
                $baseCondition->addRule($rule->validator, $rule->message, $rule->arg);
            }
        }
    }
}
