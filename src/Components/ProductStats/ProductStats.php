<?php

namespace Crm\ProductsModule\Components\ProductStats;

use Nette\Application\UI\Control;

/**
 * Nette listing component using bootstrap table.
 * This component fetches product sales stats
 * and shows them in table for different time intervals.
 *
 * @package Crm\SubscriptionsModule\Components
 */
class ProductStats extends Control
{
    private $templateName = 'products_stats.latte';

    private $stats;

    public function __construct($stats)
    {
        $this->stats = $stats;
    }

    public function render()
    {
        $this->template->productStats = $this->stats['productStats'];
        $this->template->totalStats = $this->stats['totalStats'];
        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
