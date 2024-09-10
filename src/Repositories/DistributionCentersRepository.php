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

    final public function add($code, $name)
    {
        return $this->insert([
            'code' => $code,
            'name' => $name
        ]);
    }
}
