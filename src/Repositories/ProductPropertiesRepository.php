<?php

namespace Crm\ProductsModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;

class ProductPropertiesRepository extends Repository
{
    protected $tableName = 'product_properties';

    final public function add($product, $productTemplatePropertyId, $value)
    {
        return $this->insert([
            'product_id' => $product->id,
            'product_template_property_id' => $productTemplatePropertyId,
            'value' => $value,
        ]);
    }

    final public function upsert(ActiveRow $product, ActiveRow $productTemplateProperty, $value)
    {
        $productProperty = $this->getTable()->where([
            'product_id' => $product->id,
            'product_template_property_id' => $productTemplateProperty->id,
        ])->fetch();

        if ($productProperty) {
            return $this->update($productProperty, [
                'value' => $value,
            ]);
        }

        return $this->add($product, $productTemplateProperty->id, $value);
    }

    final public function setProductProperties($product, $properties)
    {
        $this->getTable()->where(['product_id' => $product->id])->delete();
        foreach ($properties as $propertyId => $propertyValue) {
            $this->add($product, $propertyId, $propertyValue);
        }
    }

    final public function getPropertyByCode($product, $propertyCode)
    {
        $properties = $product->related('product_properties');
        foreach ($properties as $property) {
            if ($property->product_template_property->code == $propertyCode) {
                return $property->value;
            }
        }
        return null;
    }

    final public function getProductByPropertyCodeAndValue(string $propertyCode, string $propertyValue)
    {
        return $this->getTable()->where([
            'product_template_property.code' => $propertyCode,
            'product_properties.value' => $propertyValue,
        ])->fetch()?->product;
    }
}
