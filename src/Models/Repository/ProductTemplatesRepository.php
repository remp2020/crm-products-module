<?php

namespace Crm\ProductsModule\Repository;

use Crm\ApplicationModule\Repository;

class ProductTemplatesRepository extends Repository
{
    protected $tableName = 'product_templates';

    final public function all()
    {
        return $this->getTable();
    }

    final public function add($name)
    {
        return $this->insert([
            'name' => $name
        ]);
    }
}
