<?php

namespace Crm\ProductsModule\Forms;

use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\UI\Form;
use Crm\ProductsModule\DataProviders\SortShopProductsFormValidationDataProviderInterface;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Nette\Localization\Translator;

class SortShopProductsFormFactory
{
    public ProductsRepository $productsRepository;

    public DataProviderManager $dataProviderManager;

    public Translator $translator;

    public $onSave;

    public $onError;

    public function __construct(
        ProductsRepository $productsRepository,
        DataProviderManager $dataProviderManager,
        Translator $translator
    ) {
        $this->productsRepository = $productsRepository;
        $this->dataProviderManager = $dataProviderManager;
        $this->translator = $translator;
    }

    public function create()
    {
        $form = new Form;
        $form->addProtection();

        $form->addSubmit('submit')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('system.save'));

        $form->onSuccess[] = [$this, 'formSucceeded'];

        return $form;
    }

    public function formSucceeded($form)
    {
        /** @var SortShopProductsFormValidationDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders(
            'products.dataprovider.sort_shop_products.validation',
            SortShopProductsFormValidationDataProviderInterface::class
        );
        $errors = [];
        foreach ($providers as $provider) {
            $error = $provider->provide(['form' => $form]);
            if (!empty($error)) {
                $errors[] = $error;
            }
        }

        if (!empty($errors)) {
            $this->onError->__invoke($errors);
        }

        $productIds = $form->getHttpData($form::DATA_TEXT, 'products[]');

        $sorting = $this->productsRepository->getTable()->where('id', $productIds)->fetchPairs('id', 'sorting');
        $sortingValues = array_values($sorting);
        asort($sortingValues, SORT_NUMERIC);
        $sortingValues = array_values($sortingValues);

        $this->productsRepository->resortProducts(array_combine($productIds, $sortingValues));
        $this->onSave->__invoke();
    }
}
