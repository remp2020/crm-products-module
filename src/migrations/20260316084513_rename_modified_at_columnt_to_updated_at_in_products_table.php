<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RenameModifiedAtColumntToUpdatedAtInProductsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('products')
            ->renameColumn('modified_at', 'updated_at')
            ->update();
    }
}
