<?php

namespace Crm\ProductsModule\Models\PostalFeeCondition;

use Crm\ProductsModule\Repositories\CountryPostalFeesRepository;
use Nette\Database\Table\ActiveRow;

class PostalFeeService
{
    /** @var PostalFeeConditionInterface[] */
    private array $conditions;

    /** @var PostalFeeSystemConditionInterface[] */
    private array $systemConditions;

    public function __construct(
        private readonly CountryPostalFeesRepository $countryPostalFeesRepository,
    ) {
    }

    public function registerCondition(string $code, PostalFeeConditionInterface $postalFeeCondition): void
    {
        $this->conditions[$code] = $postalFeeCondition;
    }

    public function registerSystemCondition(string $code, PostalFeeSystemConditionInterface $postalFeeCondition): void
    {
        $this->systemConditions[$code] = $postalFeeCondition;
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

    /**
     * @return array<array<string>>
     */
    public function getPostalFeeAdminMessages(): array
    {
        $messages = [];
        foreach ($this->systemConditions as $postalFeeCondition) {
            foreach ($postalFeeCondition->getAffectedPostalFees() as $postalFeeCode) {
                $messages[$postalFeeCode][] = $postalFeeCondition->getAdminMessage();
            }
        }

        return $messages;
    }

    public function getAvailablePostalFeesOptions(int $countryId, array $cart, int $userId = null)
    {
        $countryPostalFeesSelection = $this->countryPostalFeesRepository
            ->findActiveByCountry($countryId)
            ->order('sorting');

        $postalFees = [];
        foreach ($countryPostalFeesSelection as $countryPostalFee) {
            /** @var ActiveRow $countryPostalFee */
            $conditions = $countryPostalFee->related('country_postal_fee_conditions')
                ->where('country_postal_fee_conditions.code IN (?)', array_keys($this->getRegisteredConditions()));
            if ($conditions && $conditions->count() > 0) {
                foreach ($conditions as $condition) {
                    /** @var PostalFeeConditionInterface $resolver */
                    $resolver = $this->conditions[$condition->code];
                    if ($resolver->isReached($cart, $condition, $userId)) {
                        // unset the original postal fee so that the new postal fee complies is appended to end of array
                        unset($postalFees[$countryPostalFee->postal_fee->code]);
                        $postalFees[$countryPostalFee->postal_fee->code] = $countryPostalFee->postal_fee;
                    }
                }
            } else {
                if (!isset($postalFees[$countryPostalFee->postal_fee->code]) || $postalFees[$countryPostalFee->postal_fee->code]->amount > $countryPostalFee->postal_fee->amount) {
                    $postalFees[$countryPostalFee->postal_fee->code] = $countryPostalFee->postal_fee;
                }
            }
        }

        foreach ($postalFees as $postalFee) {
            foreach ($this->systemConditions as $condition) {
                if (!$condition->isAvailable($cart, $postalFee, $userId)) {
                    unset($postalFees[$postalFee->code]);
                }
            }
        }

        return array_combine(
            array_column($postalFees, 'id'),
            array_values($postalFees),
        );
    }

    public function getFreePostalPostalFeeForCondition(int $countryId, array $cart): array
    {
        $countryPostalFees = $this->countryPostalFeesRepository->findActiveByCountry($countryId)
            ->where('postal_fee.amount', 0)
            ->order('ABS(:country_postal_fee_conditions.value)')
            ->fetchAll();

        foreach ($countryPostalFees as $i => $countryPostalFee) {
            foreach ($this->systemConditions as $condition) {
                if (!$condition->isAvailable($cart, $countryPostalFee->postal_fee)) {
                    unset($countryPostalFees[$i]);
                }
            }
        }

        return $countryPostalFees;
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
