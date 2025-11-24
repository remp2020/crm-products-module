<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ProductPaymentItemsIndex extends AbstractMigration
{
    public function up(): void
    {
        $this->table('payment_items')
            ->addIndex(['product_id', 'created_at'])
            ->update();
    }

    public function down(): void
    {
        $this->table('payment_items')
            ->removeIndex(['product_id', 'created_at'])
            ->update();
    }
}
