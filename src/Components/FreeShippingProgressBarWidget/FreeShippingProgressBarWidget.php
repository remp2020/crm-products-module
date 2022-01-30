<?php

namespace Crm\ProductsModule\Components;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\ProductsModule\Model\ProductsTrait;
use Crm\ProductsModule\PostalFeeCondition\PostalFeeNumericConditionInterface;
use Crm\ProductsModule\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Repository\ProductsRepository;
use Crm\UsersModule\Repository\CountriesRepository;

class FreeShippingProgressBarWidget extends BaseWidget
{
    use ProductsTrait;

    private $templateName = 'free_shipping_progress_bar.latte';

    private $postalFeeService;

    private $countriesRepository;

    private $productsRepository;

    public function __construct(
        WidgetManager $widgetManager,
        PostalFeeService $postalFeeService,
        CountriesRepository $countriesRepository,
        ProductsRepository $productsRepository
    ) {
        parent::__construct($widgetManager);

        $this->postalFeeService = $postalFeeService;
        $this->countriesRepository = $countriesRepository;
        $this->productsRepository = $productsRepository;
    }

    public function identifier()
    {
        return 'freeshippingprogressbarwidget';
    }

    public function render(array $cartProducts = [], int $countryId = null)
    {
        if (!$countryId) {
            $countryId = $this->countriesRepository->defaultCountry()->id;
        }

        $freeCountryPostalFees = $this->postalFeeService->getFreePostalPostalFeeForCondition($countryId, 'code');
        if ($freeCountryPostalFees->count('*') === 0) {
            return;
        }

        $products = $this->productsRepository->findByIds(array_keys($cartProducts));
        if (!$this->hasDelivery($products)) {
            return;
        }

        $userId = null;
        if ($this->getPresenter()->getUser()->isLoggedIn()) {
            $userId = $this->getPresenter()->getUser()->getId();
        }

        $reachedCondition = null;
        $reachedConditionRow = null;
        $unreachedConditions = [];
        $unreachedConditionRows = [];

        foreach ($freeCountryPostalFees as $freeCountryPostalFee) {
            $countryPostalFeeConditionRow = $freeCountryPostalFee->related('country_postal_fee_conditions')->fetch();

            $condition = $this->postalFeeService->getRegisteredConditionByCode($countryPostalFeeConditionRow->code);

            if ($condition->isReached($cartProducts, $countryPostalFeeConditionRow->value, $userId)) {
                $reachedCondition = $condition;
                $reachedConditionRow = $countryPostalFeeConditionRow;
                break;
            }
            if ($condition instanceof PostalFeeNumericConditionInterface) {
                $unreachedConditions[] = $condition;
                $unreachedConditionRows[] = $countryPostalFeeConditionRow;
            }
        }

        if ($reachedCondition) {
            $condition = $reachedCondition;
            $countryPostalFeeConditionRow = $reachedConditionRow;
            $this->template->isReached = true;
            $this->template->condition = $reachedCondition;
        } elseif (count($unreachedConditions) > 0) {
            $condition = $unreachedConditions[0];
            $countryPostalFeeConditionRow = $unreachedConditionRows[0];
            $this->template->isReached = false;
            $this->template->condition = $condition;
        } else {
            return;
        }

        if ($condition instanceof PostalFeeNumericConditionInterface) {
            $this->template->actualValue = $condition->getActualValue($cartProducts);
            $this->template->desiredValue = $countryPostalFeeConditionRow->value;
        }

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
