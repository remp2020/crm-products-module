<?php

namespace Crm\ProductsModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderException;
use Crm\ApplicationModule\UI\Form;
use Crm\PaymentsModule\DataProviders\AdminFilterFormDataProviderInterface;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Nette\Database\Table\Selection;

class PaymentsAdminFilterFormDataProvider implements AdminFilterFormDataProviderInterface
{
    private $productsRepository;

    public function __construct(ProductsRepository $productsRepository)
    {
        $this->productsRepository = $productsRepository;
    }

    /**
     * @param array $params
     * @throws DataProviderException
     */
    public function provide(array $params): Form
    {
        if (!isset($params['form'])) {
            throw new DataProviderException('missing [form] within data provider params');
        }
        if (!($params['form'] instanceof Form)) {
            throw new DataProviderException('invalid type of provided form: ' . get_class($params['form']));
        }

        if (!isset($params['formData'])) {
            throw new DataProviderException('missing [formData] within data provider params');
        }
        if (!is_array($params['formData'])) {
            throw new DataProviderException('invalid type of provided formData: ' . get_class($params['formData']));
        }

        $form = $params['form'];
        $formData = $params['formData'];

        $products = $this->productsRepository->getTable()->fetchPairs('id', 'name');
        $form->addMultiSelect('products', 'Produkty', $products)
            ->getControlPrototype()->addAttributes(['class' => 'select2']);

        $form->setDefaults([
            'products' => $this->getProducts($formData),
        ]);

        return $form;
    }

    public function filter(Selection $selection, array $formData): Selection
    {
        if ($this->getProducts($formData)) {
            $selection
                ->where(':payment_items.type', ProductPaymentItem::TYPE)
                ->where(':payment_items.product_id', $this->getProducts($formData));
        }
        return $selection;
    }

    private function getProducts($formData)
    {
        return $formData['products'] ?? null;
    }
}
