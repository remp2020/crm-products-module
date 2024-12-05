<?php

namespace Crm\ProductsModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;

class DistributionCentersRepository extends Repository
{
    const DISTRIBUTION_CENTER_FHB_GROUP = 'kika';
    const DISTRIBUTION_CENTER_DIBUK = 'dibuk';
    const DISTRIBUTION_CENTER_DENNIKN = 'dennikn';
    const DISTRIBUTION_CENTER_MIGUEL = 'miguel';

    protected $tableName = 'distribution_centers';

    final public function all()
    {
        return $this->getTable();
    }

    final public function add(string $code, string $name, bool $requireLicence = false)
    {
        return $this->insert([
            'code' => $code,
            'name' => $name,
            'require_licence' => $requireLicence,
        ]);
    }

    final public function findByCode(string $code)
    {
        return $this->getTable()->where(['code' => $code])->fetch();
    }
}
