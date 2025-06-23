<?php

namespace Crm\ProductsModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ApplicationModule\UI\Form;
use Crm\ProductsModule\Forms\CountryPostalFeesFormFactory;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Repositories\CountryPostalFeesRepository;
use Crm\ProductsModule\Repositories\PostalFeesRepository;
use Crm\UsersModule\Repositories\CountriesRepository;
use Nette\Database\Table\ActiveRow;

class CountryPostalFeesPresenter extends AdminPresenter
{
    public function __construct(
        private readonly PostalFeesRepository $postalFeesRepository,
        private readonly CountryPostalFeesRepository $countryPostalFeesRepository,
        private readonly CountriesRepository $countriesRepository,
        private readonly PostalFeeService $postalFeeService,
        private readonly CountryPostalFeesFormFactory $countryPostalFeeFormFactory,
    ) {
        parent::__construct();
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $countries = $this->countriesRepository->all();
        $this->template->countries = $countries;
        $this->template->postalFeeService = $this->postalFeeService;
        $this->template->postalFeeMapping = $this->postalFeesRepository->all()->fetchPairs('id', 'code');
        $this->template->postalFeeAdminMessages = $this->postalFeeService->getPostalFeeAdminMessages();
        $this->template->form = $this['countryPostalFeeForm'];
    }

    public function createComponentCountryPostalFeeForm(): Form
    {
        $form = $this->countryPostalFeeFormFactory->create($this->params['id'] ?? null);
        $this->countryPostalFeeFormFactory->onAlreadyExist = function (ActiveRow $countryPostalFeeRow) {
            $this->flashMessage($this->translator->translate('products.admin.country_postal_fees.default.error_already_exists'), 'error');
            $this->redirect('default');
        };

        $this->countryPostalFeeFormFactory->onSave = function (ActiveRow $countryPostalFeeRow) {
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
        $conditionValueComponent = $condition->getInputControl();
        if ($conditionValueComponent) {
            $this['countryPostalFeeForm']->addComponent(
                $condition->getInputControl(),
                'condition_value',
            );
        }

        $this->redrawControl('conditionSnippet');
    }
}
