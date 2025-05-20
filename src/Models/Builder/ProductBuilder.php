<?php

namespace Crm\ProductsModule\Models\Builder;

use Crm\ApplicationModule\Builder\Builder;
use Crm\ProductsModule\Repositories\ProductBundlesRepository;
use Crm\ProductsModule\Repositories\ProductPropertiesRepository;
use Crm\ProductsModule\Repositories\ProductTagsRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Nette\Database\Explorer;

class ProductBuilder extends Builder
{
    protected $tableName = 'products';

    private $productsRepository;
    private $productBundlesRepository;
    private $productPropertiesRepository;
    private $productTagsRepository;

    private $bundleItems = [];

    private $productProperties = [];

    private $productTags = [];

    public function __construct(
        Explorer $database,
        ProductsRepository $productsRepository,
        ProductBundlesRepository $productBundlesRepository,
        ProductPropertiesRepository $productPropertiesRepository,
        ProductTagsRepository $productTagsRepository,
    ) {
        parent::__construct($database);

        $this->productsRepository = $productsRepository;
        $this->productBundlesRepository = $productBundlesRepository;
        $this->productPropertiesRepository = $productPropertiesRepository;
        $this->productTagsRepository = $productTagsRepository;
    }

    public function fromArray(array $data)
    {
        foreach ($data as $key => $value) {
            $this->set($key, $value);
        }
        return $this;
    }

    public function isValid()
    {
        return true;
    }

    public function setBundleItems($bundleItems)
    {
        $this->bundleItems = $bundleItems;
        return $this;
    }

    public function setTemplateProperties($properties)
    {
        $this->productProperties = $properties;
        return $this;
    }

    public function setProductsTags($tags)
    {
        $this->productTags = $tags;
        return $this;
    }

    protected function setDefaults()
    {
        parent::setDefaults();
        $this->set('created_at', new \DateTime());
        $this->set('modified_at', new \DateTime());
    }

    protected function store($tableName)
    {
        $this->productsRepository->updateSorting($this->get('sorting'));

        // use Repository::insert() instead Builder::store() to enable all features (auditlog and assertSlugs)
        $product = $this->productsRepository->insert($this->getData());
        if (!$product) {
            return false;
        }

        $this->productBundlesRepository->setBundleItems($product, $this->bundleItems);
        $this->productPropertiesRepository->setProductProperties($product, $this->productProperties);
        $this->productTagsRepository->setProductTags($product, $this->productTags);

        return $product;
    }
}
