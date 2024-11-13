<?php

namespace Crm\ProductsModule\Models\PostalFeeCondition;

use Crm\ApplicationModule\Models\Database\Selection;
use Crm\ProductsModule\Repositories\CountryPostalFeesRepository;
use Nette\Database\Table\ActiveRow;

class PostalFeeService
{
    private $conditions;

    private $countryPostalFeesRepository;

    public function __construct(
        CountryPostalFeesRepository $countryPostalFeesRepository
    ) {
        $this->countryPostalFeesRepository = $countryPostalFeesRepository;
    }

    public function registerCondition(string $code, PostalFeeConditionInterface $postalFeeCondition): void
    {
        $this->conditions[$code] = $postalFeeCondition;
    }

    /**
     * @return PostalFeeConditionInterface[]
     */
    public function getRegisteredConditions(): array
    {
        return $this->conditions;
    }

    public function getRegisteredConditionByCode(string $code): PostalFeeConditionInterface
    {
        if ($this->conditions[$code]) {
            return $this->conditions[$code];
        }

        throw new \Exception("Country postal fee condition with code: '{$code}' is not registered.");
    }

    public function getAvailablePostalFeesOptions(int $countryId, array $cart, int $userId = null)
    {
        $countryPostalFeesSelection = $this->countryPostalFeesRepository
            ->findActiveByCountry($countryId)
            ->order('sorting');

        $result = [];
        foreach ($countryPostalFeesSelection as $countryPostalFee) {
            /** @var ActiveRow $countryPostalFee */
            $conditions = $countryPostalFee->related('country_postal_fee_conditions')
                ->where('country_postal_fee_conditions.code IN (?)', array_keys($this->getRegisteredConditions()));
            if ($conditions && $conditions->count() > 0) {
                foreach ($conditions as $condition) {
                    /** @var PostalFeeConditionInterface $resolver */
                    $resolver = $this->conditions[$condition->code];
                    if ($resolver->isReached($cart, $condition->value, $userId)) {
                        // unset the original postal fee so that the new postal fee complies is appended to end of array
                        unset($result[$countryPostalFee->postal_fee->code]);
                        $result[$countryPostalFee->postal_fee->code] = $countryPostalFee->postal_fee;
                    }
                }
            } else {
                if (!isset($result[$countryPostalFee->postal_fee->code]) || $result[$countryPostalFee->postal_fee->code]->amount > $countryPostalFee->postal_fee->amount) {
                    $result[$countryPostalFee->postal_fee->code] = $countryPostalFee->postal_fee;
                }
            }
        }

        return array_combine(
            array_column($result, 'id'),
            array_values($result)
        );
    }

    public function getFreePostalPostalFeeForCondition(int $countryId): Selection
    {
        return $this->countryPostalFeesRepository->findActiveByCountry($countryId)
            ->where('postal_fee.amount', 0)
            ->order('ABS(:country_postal_fee_conditions.value)');
    }

    public function getDefaultPostalFee(int $countryId, array $postalFees): ActiveRow
    {
        $freePostalFees = array_filter($postalFees, function ($item) {
            return $item->amount === 0.0;
        });
        $nonFreePostalFees = array_filter($postalFees, function ($item) {
            return $item->amount > 0;
        });

        $countryPostalFeesPairs = [];
        foreach ($postalFees as $postalFee) {
            /** @var ActiveRow $postalFee */
            $countryPostalFeesPairs[$postalFee->id] = $postalFee->related('country_postal_fees')
                ->where('country_id', $countryId)
                ->fetch();
        }

        if (sizeof($freePostalFees) === 0) {
            foreach ($nonFreePostalFees as $nonFreePostalFee) {
                if ($countryPostalFeesPairs[$nonFreePostalFee->id]->default == 1) {
                    return $nonFreePostalFee;
                }
            }

            return current($nonFreePostalFees);
        }

        foreach ($freePostalFees as $freePostalFee) {
            if ($countryPostalFeesPairs[$freePostalFee->id]->default == 1) {
                return $freePostalFee;
            }
        }

        return current($freePostalFees);
    }
}
