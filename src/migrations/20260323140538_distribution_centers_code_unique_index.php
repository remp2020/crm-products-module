<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DistributionCentersCodeUniqueIndex extends AbstractMigration
{
    public function up(): void
    {
        $this->table('distribution_centers')
            ->removeIndex(['code'])
            ->addIndex('code', ['unique' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('distribution_centers')
            ->removeIndex(['code'])
            ->addIndex('code')
            ->update();
    }
}
