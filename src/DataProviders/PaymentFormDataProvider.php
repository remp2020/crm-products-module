<?php

namespace Crm\ProductsModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderException;
use Crm\PaymentsModule\DataProviders\PaymentFormDataProviderInterface;
use Crm\ProductsModule\Events\CartItemAddedEvent;
use Crm\ProductsModule\Models\PaymentItem\PostalFeePaymentItem;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Repositories\PostalFeesRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use League\Event\Emitter;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;
use Nette\Localization\Translator;
use Nette\Utils\Json;

class PaymentFormDataProvider implements PaymentFormDataProviderInterface
{
    private $productsRepository;

    private $postalFeesRepository;

    private $emitter;

    private $translator;

    public function __construct(
        ProductsRepository $productsRepository,
        PostalFeesRepository $postalFeesRepository,
        Emitter $emitter,
        Translator $translator
    ) {
        $this->productsRepository = $productsRepository;
        $this->postalFeesRepository = $postalFeesRepository;
        $this->emitter = $emitter;
        $this->translator = $translator;
    }

    public function provide(array $params): Form
    {
        if (!isset($params['form'])) {
            throw new DataProviderException('missing [form] within data provider params');
        }
        if (!($params['form'] instanceof Form)) {
            throw new DataProviderException('invalid type of provided form: ' . get_class($params['form']));
        }

        $form = $params['form'];
        $payment = $params['payment'] ?? null;

        $container = $form->addContainer('products');

        $products = [];
        $productPairs = [];
        /** @var ActiveRow $p */
        foreach ($this->productsRepository->all() as $p) {
            $products[$p->id] = [
                'price' => $p->price,
            ];
            $productPairs[$p->id] = $p->name;
        }

        $container->addHidden('products_json', Json::encode($products));

        $productIdsMultiselect = $container->addMultiSelect(
            'product_ids',
            $this->translator->translate('products.data_provider.payment_form_data.products_from_eshop'),
            $productPairs
        )->setOption(
            'description',
            $this->translator->translate('products.data_provider.payment_form_data.products_from_eshop_desc')
        );
        $productIdsMultiselect->getControlPrototype()->addAttributes(['class' => 'select2']);

        $container->addHidden("product_counts");

        if ($payment) {
            $productIdsMultiselect->setHtmlAttribute('readonly', 'readonly')
                ->setOption(
                    'description',
                    $this->translator->translate('products.data_provider.payment_form_data.products_from_eshop_readonly')
                );
        } else {
            // TODO: revive possibility to create order directly from payment form
//            $displayOrder = $container->addCheckbox('display_order', 'Chcem k platbe aj objednávku')->setOption('id', 'displayOrder');
//            $productIdsMultiselect
//                ->addCondition($form::FILLED)
//                ->toggle($displayOrder->getOption('id'));
        }

        if ($payment) {
            $productIds = $payment->related('payment_items')->where('type = ?', ProductPaymentItem::TYPE)->fetchPairs(null, 'product_id');
            $container->setDefaults([
                'product_ids' => $productIds,
            ]);
            $productCounts = $payment->related('payment_items')->where('type = ?', ProductPaymentItem::TYPE)->fetchPairs('product_id', 'count');
            if (empty($productCounts)) {
                $productCounts = new \stdClass();
            }
            $container->setDefaults([
                'product_counts' => Json::encode($productCounts),
            ]);
        } else {
            $container->setDefaults([
                'product_counts' => '{}',
            ]);
        }

        return $form;
    }

    public function paymentItems(array $params): array
    {
        if (!isset($params['values'])) {
            throw new DataProviderException('missing [values] within data provider params');
        }
        $values = $params['values'];

        $paymentItems = Json::decode($values['payment_items']);

        $items = [];
        if (isset($values['products']['product_ids']) && isset($values['products']['product_counts'])) {
            $productIds = [];
            $productCounts = Json::decode($values['products']['product_counts'], Json::FORCE_ARRAY);

            foreach ($paymentItems as $paymentItem) {
                if ($paymentItem->type === ProductPaymentItem::TYPE) {
                    $productCounts[$paymentItem->product_id] = $paymentItem->count;
                }
            }

            foreach ($values['products']['product_ids'] as $productId) {
                $productIds[$productId] = $productCounts[$productId];
                $product = $this->productsRepository->find($productId);
                $items[] = new ProductPaymentItem($product, $productCounts[$productId]);

                $this->emitter->emit(new CartItemAddedEvent($product));
            }
        }

        foreach ($paymentItems as $paymentItem) {
            if ($paymentItem->type === PostalFeePaymentItem::TYPE) {
                $postalFee = $this->postalFeesRepository->find($paymentItem->postal_fee_id);
                $postalFeeItem = new PostalFeePaymentItem($postalFee, $paymentItem->vat, $paymentItem->count);
                $postalFeeItem->forceName(
                    sprintf(
                        "%s - %s",
                        $this->translator->translate('products.frontend.orders.postal_fee'),
                        $postalFeeItem->name()
                    )
                );
                $items[] = $postalFeeItem;
            }
        }

        unset($params['values']['products']);
        return $items;
    }
}
