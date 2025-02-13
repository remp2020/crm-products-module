<?php

namespace Crm\ProductsModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderInterface;
use Crm\ApplicationModule\UI\Form;
use Nette\Database\Table\ActiveRow;

interface ProductTemplatePropertiesDataProviderInterface extends DataProviderInterface
{
    public function provide(array $params): Form;

    public function beforeUpdate(ActiveRow $product, ActiveRow $templateProperty);

    public function afterSave(ActiveRow $product, ActiveRow $templateProperty);
}
