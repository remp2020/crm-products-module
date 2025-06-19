<?php

namespace Crm\ProductsModule\Tests;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\ProductsModule\Models\PostalFeeCondition\ProductSumCondition;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Nette\Database\Table\ActiveRow;

class ProductSumConditionTest extends DatabaseTestCase
{
    /** @var ProductsRepository */
    private $productsRepository;

    protected function requiredRepositories(): array
    {
        return [
            ProductsRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->productsRepository = $this->getRepository(ProductsRepository::class);
    }

    public function testPositiveResult(): void
    {
        $productRow1 = $this->insertProduct(13.29);
        $productRow2 = $this->insertProduct(27);

        $productSumCondition = new ProductSumCondition(
            $this->inject(Translator::class),
            $this->productsRepository,
        );

        $cartProducts = [
            $productRow1->id => 2,
            $productRow2->id => 1,
        ];

        $this->assertFalse($productSumCondition->isReached($cartProducts, 100, null));
        $this->assertTrue($productSumCondition->isReached($cartProducts, 20, null));
    }

    private function insertProduct(float $price): ActiveRow
    {
        return $this->productsRepository->insert([
            'name' => 'test1',
            'code' => 'test1_code' . random_int(0, 9999),
            'price' => 10.0,
            'vat' => 19,
            'user_label' => 'user_label',
            'bundle' => 0,
            'shop' => 1,
            'visible' => 1,
            'stored' => 1,
            'stock' => 10,
            'created_at' => new \DateTime(),
            'modified_at' => new \DateTime(),
        ]);
    }
}
