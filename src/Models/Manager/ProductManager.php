<?php

namespace Crm\ProductsModule\Models\Manager;

use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ProductsModule\DataProviders\ProductManagerDataProviderInterface;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Nette\Database\Table\ActiveRow;

class ProductManager
{
    private $productsRepository;

    private $dataProviderManager;

    public function __construct(
        DataProviderManager $dataProviderManager,
        ProductsRepository $productsRepository,
    ) {
        $this->productsRepository = $productsRepository;
        $this->dataProviderManager = $dataProviderManager;
    }

    public function decreaseStock(ActiveRow $product, int $itemCount): void
    {
        $this->productsRepository->decreaseStock($product, $itemCount);

        foreach ($product->related('product_bundles', 'item_id') as $item) {
            $mainProduct = $item->bundle;
            if ($mainProduct->stock > $product->stock) {
                $this->productsRepository->update($mainProduct, ['stock' => $product->stock]);
            }
        }
    }

    public function syncProductWithDistributionCenter(ActiveRow $product): ActiveRow
    {
        /** @var ProductManagerDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('products.dataprovider.product_manager.sync_product', ProductManagerDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $product = $provider->provide(['product' => $product]);
        }

        return $product;
    }
}
