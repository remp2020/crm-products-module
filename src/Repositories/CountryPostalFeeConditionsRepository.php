<?php

namespace Crm\ProductsModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;

class CountryPostalFeeConditionsRepository extends Repository
{
    protected $tableName = 'country_postal_fee_conditions';

    /**
     * @param ActiveRow $countryPostalFeeRow
     * @param string $code - Unique identification of condition
     * @param string $value - Represents configurable value that is checked during the condition evaluation
     *
     * @return bool|int|ActiveRow
     */
    final public function add(ActiveRow $countryPostalFeeRow, string $code, ?string $value = null)
    {
        return $this->insert([
            'country_postal_fee_id' => $countryPostalFeeRow->id,
            'code' => $code,
            'value' => $value,
            'created_at' => new \DateTime(),
        ]);
    }

    final public function getByCountryPostalFeeAndCode(ActiveRow $countryPostalFeeRow, string $code)
    {
        return $this->getTable()->where([
            'country_postal_fee_id' => $countryPostalFeeRow->id,
            'code' => $code,
        ])->fetch();
    }
}
