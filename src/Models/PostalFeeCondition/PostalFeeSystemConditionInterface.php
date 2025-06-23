<?php

namespace Crm\ProductsModule\Models\PostalFeeCondition;

use Nette\Database\Table\ActiveRow;

interface PostalFeeSystemConditionInterface
{
    /**
     * isAvailable returns flag whether the affected postal fee is available to be used in shop or not.
     */
    public function isAvailable(array $products, ActiveRow $postalFee, int $userId = null): bool;

    /**
     * getAffectedPostalFees returns list of postal fee codes that are affected by this condition if it's registered.
     */
    public function getAffectedPostalFees(): array;

    /**
     * getAdminMessage returns message displayed to admin user when editing country postal fee in admin.
     */
    public function getAdminMessage(): string;
}
