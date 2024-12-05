<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DistributionCenterLicensing extends AbstractMigration
{
    public function up(): void
    {
        $this->table('distribution_centers')
            ->addColumn('require_licence', 'boolean', ['default' => false, 'null' => true])
            ->update();

        $this->table('distribution_centers')
            ->changeColumn('require_licence', 'boolean', ['null' => false])
            ->update();
    }

    public function down(): void
    {
        $this->table('distribution_centers')
            ->removeColumn('require_licence')
            ->update();
    }
}
