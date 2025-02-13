<?php

namespace Crm\ProductsModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderInterface;
use Crm\ApplicationModule\UI\Form;

interface ProductsFormDataProviderInterface extends DataProviderInterface
{
    public function provide(array $params): Form;
}
