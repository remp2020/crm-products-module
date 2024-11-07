<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CountryPostalFeeConditionsNullableValue extends AbstractMigration
{
    public function up(): void
    {
        $this->table('country_postal_fee_conditions')
            ->changeColumn('value', 'string', ['null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('country_postal_fee_conditions')
            ->changeColumn('value', 'string', ['null' => false])
            ->update();
    }
}
