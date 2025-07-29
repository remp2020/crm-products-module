<?php

namespace Crm\ProductsModule\Components\FreeShippingProgressBarWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeMessageConditionInterface;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeNumericConditionInterface;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Models\ProductsTrait;
use Crm\ProductsModule\Repositories\DistributionCentersRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\UsersModule\Repositories\CountriesRepository;

class FreeShippingProgressBarWidget extends BaseLazyWidget
{
    use ProductsTrait;

    private $templateName = 'free_shipping_progress_bar.latte';

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        private readonly PostalFeeService $postalFeeService,
        private readonly CountriesRepository $countriesRepository,
        private readonly ProductsRepository $productsRepository,
        private readonly DistributionCentersRepository $distributionCentersRepository,
    ) {
        parent::__construct($lazyWidgetManager);
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

        $freeCountryPostalFees = $this->postalFeeService->getFreePostalPostalFeeForCondition($countryId, $cartProducts);
        if (count($freeCountryPostalFees) === 0) {
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
            if (!$countryPostalFeeConditionRow) {
                return;
            }

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
            $this->template->percentage = round($this->template->actualValue / $this->template->desiredValue * 100);
        }
        if ($condition instanceof PostalFeeMessageConditionInterface) {
            $this->template->postalFeeConditionValue = $countryPostalFeeConditionRow->value;
            $this->template->cartProducts = $cartProducts;
        }

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
