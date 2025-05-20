<?php

namespace Crm\ProductsModule\Components\RecommendedProductsWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\ProductsModule\Repositories\ProductsRepository;

class RecommendedProductsWidget extends BaseLazyWidget
{
    private $templateName = 'recommended_products_widget.latte';

    private $productsRepository;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        ProductsRepository $productsRepository,
    ) {
        parent::__construct($lazyWidgetManager);
        $this->productsRepository = $productsRepository;
    }

    public function identifier()
    {
        return 'recommendedproductswidget';
    }

    public function render($product)
    {
        $relatedProducts = $this->productsRepository->relatedProducts($product)->fetchAll();

        $this->template->relatedProducts = $relatedProducts;
        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
