<?php

namespace Crm\ProductsModule\Models\Distribution;

use Nette\Database\Explorer;

class ProductDaysFromLastOrderDistribution implements DistributionInterface
{
    private $database;

    public function __construct(Explorer $database)
    {
        $this->database = $database;
    }

    public function distribution(int $productId, array $levels): array
    {
        $result = [];
        $lastLevel = null;

        foreach ($levels as $i => $level) {
            if ($i === 0) {
                $lastLevel = $i;
                $result[0] = 0;
                continue;
            }
            $where = "shop_payments.paid_at BETWEEN first_product_payment.paid_at - INTERVAL {$levels[$i]} DAY AND first_product_payment.paid_at - INTERVAL {$levels[$lastLevel]} DAY";
            $skeleton = $this->getQuerySkeleton($where, $productId);

            $query = <<<SQL
SELECT COUNT(*) AS result FROM (
  SELECT DISTINCT first_product_payment.user_id FROM {$skeleton}
) AS sub
SQL;
            $res = $this->database->query($query)->fetch();
            $result[$level] = $res->result;
            $lastLevel = $i;
        }

        $skeleton = $this->getNegativeQuerySkeleton($productId);
        $query = <<<SQL
SELECT COUNT(*) AS result FROM (
  SELECT DISTINCT first_product_payment.user_id FROM {$skeleton}
) AS sub
SQL;
        $res = $this->database->query($query)->fetch();
        $result[-1] = $res->result;

        return $result;
    }

    public function distributionList(int $productId, float $fromLevel, ?float $toLevel = null): array
    {
        if ($toLevel === -1.0) {
            $skeleton = $this->getNegativeQuerySkeleton($productId);
        } else {
            $where = "shop_payments.paid_at BETWEEN first_product_payment.paid_at - INTERVAL {$toLevel} DAY AND first_product_payment.paid_at - INTERVAL {$fromLevel} DAY";
            $skeleton = $this->getQuerySkeleton($where, $productId);
        }

        $query = <<<SQL
SELECT users.* FROM (
  SELECT DISTINCT first_product_payment.user_id
  FROM {$skeleton}
) AS sub
LEFT JOIN users ON sub.user_id = users.id
SQL;
        return $this->database->query($query)->fetchAll();
    }

    private function getQuerySkeleton($whereCondition, $productId)
    {
        return <<<SQL
(
    SELECT payments.user_id, MIN(payments.paid_at) as paid_at
    FROM payments
    INNER JOIN payment_items 
      ON payment_items.payment_id = payments.id
      AND payment_items.product_id = {$productId}
    WHERE payments.status = 'paid'
    GROUP BY payments.user_id
) first_product_payment

-- join tables to get shop payments
LEFT JOIN payments shop_payments 
  ON shop_payments.status = 'paid' 
  AND shop_payments.user_id = first_product_payment.user_id
  AND shop_payments.paid_at < first_product_payment.paid_at
LEFT JOIN payment_items shop_payment_items
  ON shop_payments.id = shop_payment_items.payment_id

-- helper table to filter only last shop payments
LEFT JOIN payments next_shop_payments 
  ON next_shop_payments.status = 'paid' 
  AND next_shop_payments.user_id = shop_payments.user_id
  AND next_shop_payments.paid_at > shop_payments.paid_at
  AND next_shop_payments.paid_at < first_product_payment.paid_at
LEFT JOIN payment_items next_shop_payment_items
  ON next_shop_payments.id = next_shop_payment_items.payment_id
  
WHERE (next_shop_payments.id IS NULL OR next_shop_payment_items.id IS NULL)
  AND shop_payment_items.id IS NOT NULL
  AND {$whereCondition}
SQL;
    }

    private function getNegativeQuerySkeleton($productId)
    {
        return <<<SQL
(
    SELECT payments.user_id, MIN(payments.paid_at) as paid_at
    FROM payments
    INNER JOIN payment_items 
      ON payment_items.payment_id = payments.id
      AND payment_items.product_id = {$productId}
    WHERE payments.status = 'paid'
    GROUP BY payments.user_id
) first_product_payment
LEFT JOIN payments shop_payments 
  ON shop_payments.user_id = first_product_payment.user_id
  AND shop_payments.paid_at < first_product_payment.paid_at
LEFT JOIN payment_items shop_payment_items ON shop_payment_items.payment_id = shop_payments.id
GROUP BY first_product_payment.user_id
HAVING COUNT(shop_payment_items.id) = 0
SQL;
    }
}
