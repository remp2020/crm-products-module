<?php

namespace Crm\ProductsModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ProductsModule\Forms\CountryPostalFeesFormFactory;
use Crm\ProductsModule\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Repository\CountryPostalFeesRepository;
use Crm\UsersModule\Repository\CountriesRepository;
use Nette\Application\UI\Form;
use Nette\Database\Table\IRow;

class CountryPostalFeesPresenter extends AdminPresenter
{
    private $countryPostalFeesRepository;

    private $countriesRepository;

    private $postalFeeService;

    private $countryPostalFeeFormFactory;

    public function __construct(
        CountryPostalFeesRepository $countryPostalFeesRepository,
        CountriesRepository $countriesRepository,
        PostalFeeService $postalFeeService,
        CountryPostalFeesFormFactory $countryPostalFeeFormFactory
    ) {
        parent::__construct();

        $this->countryPostalFeesRepository = $countryPostalFeesRepository;
        $this->countriesRepository = $countriesRepository;
        $this->postalFeeService = $postalFeeService;
        $this->countryPostalFeeFormFactory = $countryPostalFeeFormFactory;
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $countries = $this->countriesRepository->all();
        $this->template->countries = $countries;
        $this->template->postalFeeService = $this->postalFeeService;
        $this->template->form = $this['countryPostalFeeForm'];
    }

    public function createComponentCountryPostalFeeForm(): Form
    {
        $form = $this->countryPostalFeeFormFactory->create($this->params['id'] ?? null);
        $this->countryPostalFeeFormFactory->onAlreadyExist = function (IRow $countryPostalFeeRow) {
            $this->flashMessage($this->translator->translate('products.admin.country_postal_fees.default.error_already_exists'), 'error');
            $this->redirect('default');
        };

        $this->countryPostalFeeFormFactory->onSave = function (IRow $countryPostalFeeRow) {
            $this->flashMessage($this->translator->translate('products.admin.country_postal_fees.default.successfully_added'));
            $this->redirect('default');
        };

        return $form;
    }

    /**
     * @admin-access-level write
     */
    public function handleDelete($id)
    {
        $countryPostalFee = $this->countryPostalFeesRepository->find($id);
        $countryPostalFee->related('country_postal_fee_conditions', 'country_postal_fee_id')->delete();
        $this->countryPostalFeesRepository->delete($countryPostalFee);
        $this->flashMessage($this->translator->translate('products.admin.country_postal_fees.default.successfully_deleted'));
        $this->redirect('default');
    }

    /**
     * @admin-access-level write
     */
    public function handleInactive($id)
    {
        $countryPostalFee = $this->countryPostalFeesRepository->find($id);
        $this->countryPostalFeesRepository->setInactive($countryPostalFee);
        $this->redirect('default');
    }

    /**
     * @admin-access-level write
     */
    public function handleActive($id)
    {
        $countryPostalFee = $this->countryPostalFeesRepository->find($id);
        $this->countryPostalFeesRepository->setActive($countryPostalFee);
        $this->redirect('default');
    }

    /**
     * @admin-access-level write
     */
    public function handleAdd($countryId)
    {
        $this['countryPostalFeeForm']->setDefaults(['country_id' => $countryId]);
        $this->payload->isModal = true;
        $this->redrawControl('formModal');
    }

    /**
     * @admin-access-level write
     */
    public function handleEdit($id)
    {
        $this->payload->isModal = true;
        $this->redrawControl('formModal');
    }

    /**
     * @admin-access-level write
     */
    public function handleChangeCondition($condition)
    {
        if (!$condition) {
            $component = $this['countryPostalFeeForm']->getComponent('condition_value', false);
            if ($component) {
                $this['countryPostalFeeForm']->removeComponent($component);
            }

            $this->redrawControl('conditionSnippet');
            return;
        }

        $condition = $this->postalFeeService->getRegisteredConditionByCode($condition);
        $this['countryPostalFeeForm']->addComponent(
            $condition->getInputControl(),
            'condition_value'
        );

        $this->redrawControl('conditionSnippet');
    }
}
