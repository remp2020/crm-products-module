<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ChangeProductsVatColumnTypeToDecimal extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
ALTER TABLE products MODIFY COLUMN vat DECIMAL(10,2), LOCK=SHARED;
        ");
    }

    public function down(): void
    {
        $this->execute("
ALTER TABLE products MODIFY vat INTEGER, LOCK=SHARED;
        ");
    }
}
