<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ChangeProductsCodeColumnToUnique extends AbstractMigration
{
    public function up(): void
    {
        $q = <<<SQL
            SELECT `code`, GROUP_CONCAT(DISTINCT `id` ORDER BY `id` ASC) AS `ids`
            FROM `products`
            GROUP BY `code` HAVING COUNT(*) > 1;
        SQL;

        $duplicates = $this->fetchAll($q);
        if (count($duplicates) > 0) {
            $duplicatesForException = [];
            foreach ($duplicates as $duplicate) {
                $duplicatesForException[$duplicate['code']] = $duplicate['ids'];
            }
            throw new Exception(
                "Unable to add unique index to 'products' column 'code' because there are duplicate values."
                . "Fix products with same code (IDs included): " . PHP_EOL
                . print_r($duplicatesForException, true) . PHP_EOL
            );
        }

        $this->table('products')
            ->addIndex('code', ['unique' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('products')
            ->removeIndex('code')
            ->update();
    }
}
