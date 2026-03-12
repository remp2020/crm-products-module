<?php

namespace Crm\ProductsModule\Models\Distribution;

use Nette\Database\Explorer;

class ProductShopCountsDistribution implements DistributionInterface
{
    private $database;

    public function __construct(Explorer $database)
    {
        $this->database = $database;
    }

    public function distribution(int $productId, array $levels): array
    {
        $levelCount = count($levels);
        $result = array_fill(0, $levelCount, 0);

        $levelSelect = '';
        foreach ($levels as $i => $level) {
            if ($i+1 === count($levels)) {
                $levelSelect .= "SUM(CASE WHEN count >= {$level} THEN 1 ELSE 0 END) level{$i}\n";
                break;
            }
            $levelSelect .= "SUM(CASE WHEN count >= {$level} AND count < {$levels[$i+1]} THEN 1 ELSE 0 END) level{$i},\n";
        }

        $sql = <<<SQL
SELECT $levelSelect FROM (
    SELECT first_product_payment.user_id, COUNT(DISTINCT shop_payment_items.payment_id) AS count 
    FROM (
        SELECT payments.user_id, MIN(payments.paid_at) as paid_at
        FROM payments
        INNER JOIN payment_items 
          ON payment_items.payment_id = payments.id
          AND payment_items.product_id = {$productId}
        WHERE payments.status = 'paid'
        GROUP BY payments.user_id
    ) first_product_payment
    LEFT JOIN payments shop_payments ON shop_payments.status = 'paid' AND shop_payments.paid_at < first_product_payment.paid_at AND shop_payments.user_id = first_product_payment.user_id
    LEFT JOIN payment_items shop_payment_items ON shop_payment_items.payment_id = shop_payments.id
    GROUP BY first_product_payment.user_id
) levels
SQL;

        $res = $this->database->query($sql)->fetch();
        foreach ($levels as $i => $level) {
            $result[$i] = (int)$res['level'.$i];
        }

        return $result;
    }

    public function distributionList(int $productId, float $fromLevel, ?float $toLevel = null): array
    {
        if ($toLevel === 0.0) {
            $having = 'COUNT(DISTINCT shop_payment_items.payment_id) = 0';
        } else {
            $having = 'COUNT(DISTINCT shop_payment_items.payment_id) >= ' . $fromLevel;
            if ($toLevel !== null) {
                $having .= ' AND COUNT(DISTINCT shop_payment_items.payment_id) < ' . $toLevel;
            }
        }

        $res = $this->database->query("SELECT users.* FROM (
  SELECT DISTINCT user_id FROM (
    SELECT first_product_payment.user_id
        FROM (
        SELECT payments.user_id, MIN(payments.paid_at) as paid_at
        FROM payments
        INNER JOIN payment_items 
          ON payment_items.payment_id = payments.id
          AND payment_items.product_id = {$productId}
        WHERE payments.status = 'paid'
        GROUP BY payments.user_id
    ) first_product_payment
    LEFT JOIN payments shop_payments ON shop_payments.status = 'paid' AND shop_payments.paid_at < first_product_payment.paid_at AND shop_payments.user_id = first_product_payment.user_id
    LEFT JOIN payment_items shop_payment_items ON shop_payment_items.payment_id = shop_payments.id
    GROUP BY first_product_payment.user_id
    HAVING {$having}
  ) AS calc
  GROUP BY user_id
) sub
LEFT JOIN users ON users.id = sub.user_id")->fetchAll();

        return $res;
    }
}
