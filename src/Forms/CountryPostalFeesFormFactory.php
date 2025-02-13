<?php

namespace Crm\ProductsModule\Forms;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Helpers\PriceHelper;
use Crm\ApplicationModule\UI\Form;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeConditionInterface;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Repositories\CountryPostalFeeConditionsRepository;
use Crm\ProductsModule\Repositories\CountryPostalFeesRepository;
use Crm\ProductsModule\Repositories\PostalFeesRepository;
use Crm\UsersModule\Repositories\CountriesRepository;
use Nette\Database\Table\ActiveRow;

class CountryPostalFeesFormFactory
{
    public $onAlreadyExist;

    public $onSave;

    public function __construct(
        private readonly CountriesRepository $countriesRepository,
        private readonly CountryPostalFeesRepository $countryPostalFeesRepository,
        private readonly CountryPostalFeeConditionsRepository $countryPostalFeeConditionsRepository,
        private readonly PostalFeesRepository $postalFeesRepository,
        private readonly PostalFeeService $postalFeeService,
        private readonly Translator $translator,
        private readonly PriceHelper $priceHelper,
    ) {
    }

    public function create(int $id = null)
    {
        $form = new Form();
        $form->setTranslator($this->translator);
        $form->getElementPrototype()->addAttributes(['class' => 'ajax form-horizontal']);

        $form->addHidden('id', $id);

        $countries = $form->addSelect(
            'country_id',
            'products.data.country_postal_fees.fields.country_id',
            $this->countriesRepository->all()->fetchPairs('id', 'name')
        );
        $countries->getControlPrototype()->addAttributes(['class' => 'select2 form-control']);

        $postalFees = $form->addSelect(
            'postal_fee_id',
            'products.data.country_postal_fees.fields.postal_fee_id',
            $this->getPostalFees()
        );
        $postalFees->getControlPrototype()->addAttributes([
            'class' => 'select2',
            'style' => 'width: 100%',
        ]);

        $form->addText('sorting', 'products.data.country_postal_fees.fields.sorting')
            ->addRule(Form::INTEGER, 'products.admin.country_postal_fees.default.submit')
            ->addRule(Form::FILLED, 'products.admin.country_postal_fees.default.sorting_required')
            ->setDefaultValue(100);
        $form->addCheckbox('active', 'products.data.country_postal_fees.fields.active')
            ->setDefaultValue(true);
        $form->addCheckbox('default', 'products.data.country_postal_fees.fields.default');

        $registeredConditions = $this->postalFeeService->getRegisteredConditions();
        $conditions = array_map(function (PostalFeeConditionInterface $condition) {
            return $condition->getLabel();
        }, $registeredConditions);

        $form->addSelect(
            'condition',
            'products.data.country_postal_fees.fields.condition',
            $conditions,
        )->setPrompt('----');

        if ($id) {
            $countryPostalFeeRow = $this->countryPostalFeesRepository->find($id);
            $defaults = [
                'country_id' => $countryPostalFeeRow->country_id,
                'postal_fee_id' => $countryPostalFeeRow->postal_fee_id,
                'sorting' => $countryPostalFeeRow->sorting,
                'active' => $countryPostalFeeRow->active,
                'default' => $countryPostalFeeRow->default,
            ];

            $relatedCondition = $countryPostalFeeRow->related('country_postal_fee_conditions')
                ->fetch();
            if ($relatedCondition) {
                $condition = $this->postalFeeService->getRegisteredConditionByCode($relatedCondition->code);
                $defaults['condition'] = $relatedCondition->code;

                $inputControl = $condition->getInputControl();
                if ($inputControl) {
                    $form['condition_value'] = $inputControl;
                    $defaults['condition_value'] = $relatedCondition->value;
                }
            }
            $form->setDefaults($defaults);
        }

        $form->addSubmit('submit', 'products.admin.country_postal_fees.default.submit');

        $form->onSuccess[] = [$this, 'formSucceeded'];

        return $form;
    }

    private function getPostalFees(): array
    {
        $postalFees = $this->postalFeesRepository->all()->order('id');
        $result = [];
        foreach ($postalFees as $postalFee) {
            $result[$postalFee->id] = sprintf(
                '%s / %s',
                $postalFee->title,
                $this->priceHelper->getFormattedPrice($postalFee->amount)
            );
        }
        return $result;
    }

    public function formSucceeded(Form $form, $values): void
    {
        // data from dynamically added inputs missing
        $rawHttpValues = $form->getHttpData();
        $values['condition_value'] = $rawHttpValues['condition_value'] ?? null;

        $countryPostalFeeSelection = $this->countryPostalFeesRepository
            ->getByCountryAndPostalFee($values['country_id'], $values['postal_fee_id']);

        if ($values['id']) {
            $countryPostalFeeSelection->where(['country_postal_fees.id != ?' => $values['id']]);
        }

        if ($values['condition']) {
            $countryPostalFeeSelection->where(':country_postal_fee_conditions.code', $values['condition']);
        }

        $countryPostalFeeRow = $countryPostalFeeSelection->fetch();
        if ($countryPostalFeeRow) {
            $this->onAlreadyExist->__invoke($countryPostalFeeRow);
        }

        $countryPostalFeeRow = $this->countryPostalFeesRepository->getTransaction()->wrap(function () use ($values): ActiveRow {
            if ($values['id']) {
                $countryPostalFeeRow = $this->countryPostalFeesRepository->find($values['id']);
                if (!$countryPostalFeeRow) {
                    throw new \UnexpectedValueException("Missing country postal fee with id: {$values['id']}");
                }

                $this->countryPostalFeesRepository->update($countryPostalFeeRow, [
                    'country_id' => $values['country_id'],
                    'postal_fee_id' => $values['postal_fee_id'],
                    'sorting' => $values['sorting'],
                    'default' => $values['default'],
                    'active' => $values['active']
                ]);
            } else {
                $countryPostalFeeRow = $this->countryPostalFeesRepository->add(
                    $values['country_id'],
                    $values['postal_fee_id'],
                    $values['sorting'],
                    $values['default'],
                    $values['active']
                );
            }

            $relatedConditions = $countryPostalFeeRow->related('country_postal_fee_conditions');
            foreach ($relatedConditions as $relatedCondition) {
                $this->countryPostalFeeConditionsRepository->delete($relatedCondition);
            }

            if ($values['condition']) {
                $this->countryPostalFeeConditionsRepository->add(
                    $countryPostalFeeRow,
                    $values['condition'],
                    $values['condition_value'] ?? null,
                );
            }

            return $countryPostalFeeRow;
        });

        $this->onSave->__invoke($countryPostalFeeRow);
    }
}
