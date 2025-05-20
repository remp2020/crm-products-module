<?php

namespace Crm\ProductsModule\Components\ProductStats;

use Crm\ProductsModule\Repositories\ProductsRepository;
use Nette\Localization\Translator;
use Nette\Utils\DateTime;

class ProductStatsFactory
{
    public const MODE_ALL = 'all';
    public const MODE_SOLD = 'sold';
    public const MODE_GIFTED = 'gifted';

    public const MODES = [self::MODE_ALL, self::MODE_SOLD, self::MODE_GIFTED];

    private $productsRepository;

    private $translator;

    public function __construct(
        Translator $translator,
        ProductsRepository $productsRepository,
    ) {
        $this->productsRepository = $productsRepository;
        $this->translator = $translator;
    }

    public function create($mode)
    {
        $stats = $this->getProductsStats($mode);
        return new ProductStats($stats);
    }

    public function getProductsStats($mode)
    {
        $now =  new DateTime();
        $periods = [
            'today' => [(new DateTime())->setTime(0, 0), $now],
            'yesterday' => [(new DateTime('yesterday'))->setTime(0, 0), (new DateTime('yesterday'))->setTime(23, 59, 59)],
            'last_7days' => [(new DateTime('-7 days'))->setTime(0, 0), $now],
            'current_month' => [(new DateTime('first day of this month'))->setTime(0, 0), $now],
            'last_month' => [(new DateTime('first day of previous month'))->setTime(0, 0), (new DateTime('last day of previous month'))->setTime(23, 59, 59)],
            'all' => [null, null],
        ];

        $totalStats = [];
        $productModeQuery = $this->getProductModeQuery($mode);

        $productStats = $this->productsRepository->getTable()->fetchAssoc('id');
        foreach ($periods as $periodName => $period) {
            $periodStats = $this->productsRepository->stats($period[0], $period[1])->where($productModeQuery)->fetchAll();
            $periodCount = 0;
            $periodAmount = 0;
            foreach ($periodStats as $productStat) {
                $productStats[$productStat->product_id]['count'][$periodName] = $productStat->product_count;
                $productStats[$productStat->product_id]['amount'][$periodName] = $productStat->product_amount;
                $periodCount += $productStat->product_count;
                $periodAmount += $productStat->product_amount;
            }
            $totalStats[$periodName]['count'] = $periodCount;
            $totalStats[$periodName]['amount'] = $periodAmount;
        }

        $productStats = array_filter($productStats, function ($item) {
            return array_key_exists('count', $item) || array_key_exists('amount', $item);
        });

        return ['productStats' => $productStats, 'totalStats' => $totalStats];
    }

    private function getProductModeQuery($mode): array
    {
        switch ($mode) {
            case self::MODE_ALL:
                return [];
            case self::MODE_GIFTED:
                return [':payment_items.amount' => 0];
            case self::MODE_SOLD:
                return [':payment_items.amount > ?' => 0];
            default:
                throw new \Exception("Unsupported query mode \"{$mode}\" provided");
        }
    }

    public function getProductModesPairs()
    {
        return array_combine(
            self::MODES,
            array_map(function ($mode) {
                return $this->translator->translate("products.admin.products.stats.modes.{$mode}");
            }, self::MODES),
        );
    }
}
