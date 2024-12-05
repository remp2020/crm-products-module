<?php

namespace Crm\ProductsModule\Models;

use Crm\ProductsModule\Repositories\DistributionCentersRepository;

/**
 * @property DistributionCentersRepository $distributionCentersRepository
 */
trait ProductsTrait
{
    public function hasDelivery(array $products): bool
    {
        foreach ($products as $product) {
            if ($product->bundle) {
                foreach ($product->related('product_bundles') as $productBundle) {
                    if ($productBundle->item->has_delivery) {
                        return true;
                    }
                }
            } elseif ($product->has_delivery) {
                return true;
            }
        }

        return false;
    }

    public function hasLicense(array $products): bool
    {
        foreach ($products as $product) {
            if ($product->bundle) {
                foreach ($product->related('product_bundles') as $productBundle) {
                    $distributionCenter = $this->distributionCentersRepository->findByCode($product->distribution_center);
                    if ($distributionCenter->require_licence) {
                        return true;
                    }
                }
            } else {
                $distributionCenter = $this->distributionCentersRepository->findByCode($product->distribution_center);
                if ($distributionCenter->require_licence) {
                    return true;
                }
            }
        }

        return false;
    }
}
