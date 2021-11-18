<?php

namespace Crm\PaymentsModule\DataProvider;

use Crm\ApplicationModule\DataProvider\DataProviderInterface;
use Nette\Application\UI\Form;

interface CheckoutFormDataProviderInterface extends DataProviderInterface
{
    public function provide(array $params): Form;

    public function formSucceeded($form, $values, array $params);

    /**
     * addAdditionalColumns serves for storing extra columns in the `orders` table. In case your dataprovider needs
     * to store extra data in the orders table, alter $additionalColumns variable and add your value as you'd set
     * it in the Repository::update() method.
     * 
     *      $additionalColumns['foo'] = 'bar' // attempts to store 'bar' to the orders.foo column
     */
    public function addAdditionalColumns($form, $values, &$additionalColumns);
}
