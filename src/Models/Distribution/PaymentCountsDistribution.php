<?php

namespace Crm\ProductsModule\Models\Distribution;

use Nette\Database\Explorer;

class PaymentCountsDistribution implements DistributionInterface
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
                $levelSelect .= "\n  SUM(CASE WHEN count >= {$level} THEN 1 ELSE 0 END) level{$i}";
                break;
            }
            $levelSelect .= "\n  SUM(CASE WHEN count >= {$level} AND count < {$levels[$i+1]} THEN 1 ELSE 0 END) level{$i},";
        }

        $sql = <<<SQL
SELECT $levelSelect FROM (
    SELECT first_product_payment.user_id, COUNT(previous_payments.id) AS count FROM (
        SELECT payments.user_id, MIN(payments.paid_at) as paid_at
        FROM payments
        INNER JOIN payment_items 
          ON payment_items.payment_id = payments.id
          AND payment_items.product_id = {$productId}
        WHERE payments.status = 'paid'
        GROUP BY payments.user_id
    ) first_product_payment
    LEFT JOIN payments previous_payments 
      ON previous_payments.status = 'paid'
      AND previous_payments.paid_at < first_product_payment.paid_at
      AND previous_payments.user_id = first_product_payment.user_id
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
            $having = 'count = 0';
        } else {
            $having = 'count >= ' . $fromLevel;
            if ($toLevel !== null) {
                $having .=  ' AND count < ' . $toLevel;
            }
        }

        $sql = <<<SQL
SELECT users.* FROM (
    SELECT first_product_payment.user_id, COUNT(previous_payments.id) AS count FROM (
        SELECT payments.user_id, MIN(payments.paid_at) as paid_at
        FROM payments
        INNER JOIN payment_items 
          ON payment_items.payment_id = payments.id
          AND payment_items.product_id = {$productId}
        WHERE payments.status = 'paid'
        GROUP BY payments.user_id
    ) first_product_payment
    LEFT JOIN payments previous_payments 
      ON previous_payments.status = 'paid'
      AND previous_payments.paid_at < first_product_payment.paid_at
      AND previous_payments.user_id = first_product_payment.user_id
    GROUP BY first_product_payment.user_id
    HAVING $having
) levels
LEFT JOIN users ON users.id = levels.user_id
SQL;

        return $this->database->query($sql)->fetchAll();
    }
}
