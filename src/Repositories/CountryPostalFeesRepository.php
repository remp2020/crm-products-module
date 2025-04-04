<?php

namespace Crm\ProductsModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Crm\ApplicationModule\Models\Database\Selection;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class CountryPostalFeesRepository extends Repository
{
    protected $tableName = 'country_postal_fees';

    final public function add($countryId, $postalFeeId, $sorting = 10, $default = false, $active = true)
    {
        if ($default) {
            $this->getTable()->where(['country_id' => $countryId])->update(['default' => false]);
        }

        return $this->insert([
            'country_id' => $countryId,
            'postal_fee_id' => $postalFeeId,
            'sorting' => $sorting,
            'default' => $default,
            'active' => $active,
            'created_at' => new DateTime(),
            'updated_at' => new DateTime(),
        ]);
    }

    final public function update(ActiveRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
    }

    final public function setActive(ActiveRow $row)
    {
        return $this->getTable()->where(['id' => $row->id])->update(['active' => true]);
    }

    final public function setInactive(ActiveRow $row)
    {
        return $this->getTable()->where(['id' => $row->id])->update(['active' => false]);
    }

    final public function exists($countryId, $postalFeeId)
    {
        return $this->getTable()->where(['country_id' => $countryId, 'postal_fee_id' => $postalFeeId])->count('*') > 0;
    }

    final public function findAllAvailableCountryPairs()
    {
        return $this->getTable()
            ->where(['country_postal_fees.active' => true])
            ->select('country.id AS country_id, country.name AS country_name')
            ->order('-country.sorting DESC, country.name')
            ->fetchPairs('country_id', 'country_name');
    }

    final public function findAllAvailableCountryIsoPairs(): array
    {
        return $this->getTable()
            ->where(['country_postal_fees.active' => true])
            ->select('country.iso_code AS country_iso_code, country.name AS country_name')
            ->order('-country.sorting DESC, country.name')
            ->fetchPairs('country_iso_code', 'country_name');
    }

    final public function findAllByPostalFeeId($postalFeeId)
    {
        return $this->getTable()->where('postal_fee_id', $postalFeeId)->fetchAll();
    }

    final public function findActiveByCountry(int $countryId): Selection
    {
        return $this->getTable()
            ->where([
                'country_id' => $countryId,
                'active' => 1,
            ]);
    }

    final public function getByCountryAndPostalFee(int $countryId, int $postalFeeId): Selection
    {
        return $this->getTable()->where([
            'country_id' => $countryId,
            'postal_fee_id' => $postalFeeId,
        ]);
    }
}
