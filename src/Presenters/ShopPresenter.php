<?php

namespace Crm\ProductsModule\Presenters;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\PaymentsModule\Models\CannotProcessPayment;
use Crm\PaymentsModule\Models\PaymentProcessor;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\DataProviders\EbookProvider;
use Crm\ProductsModule\DataProviders\TrackerDataProviderInterface;
use Crm\ProductsModule\Forms\CheckoutFormFactory;
use Crm\ProductsModule\Models\CartTrait;
use Crm\ProductsModule\Models\PaymentItem\PaymentItemHelper;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Repositories\PostalFeesRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\ProductsModule\Repositories\TagsRepository;
use Crm\UsersModule\Repositories\CountriesRepository;
use Nette\Application\BadRequestException;
use Nette\Forms\Controls\RadioList;
use Nette\Utils\DateTime;
use Tomaj\Hermes\Emitter;

class ShopPresenter extends FrontendPresenter
{
    use CartTrait;

    private const SALES_FUNNEL_SHOP = 'shop';

    public function __construct(
        private readonly ProductsRepository $productsRepository,
        private readonly PostalFeesRepository $postalFeesRepository,
        private readonly CheckoutFormFactory $checkoutFormFactory,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly PaymentItemHelper $paymentItemHelper,
        private readonly TagsRepository $tagsRepository,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly EbookProvider $ebookProvider,
        private readonly Emitter $hermesEmitter,
        private readonly DataProviderManager $dataProviderManager,
        private readonly PostalFeeService $postalFeeService,
        private readonly CountriesRepository $countriesRepository,
    ) {
        parent::__construct();
    }

    public function startup()
    {
        parent::startup();
        $this->buildCartSession();

        if ($this->layoutManager->exists($this->getLayoutName() . '_shop')) {
            $this->setLayout($this->getLayoutName() . '_shop');
        } else {
            $this->setLayout('shop');
        }

        $this->template->layoutName = $this->layoutManager->getLayout($this->getLayout());
        $this->template->headerCode = $this->applicationConfig->get('shop_header_block');
        $this->template->ogImageUrl = $this->applicationConfig->get('shop_og_image_url');
        $this->template->shopTitle = $this->applicationConfig->get('shop_title');
    }

    protected function getLayoutName()
    {
        $layoutName = $this->applicationConfig->get('layout_name');
        if ($layoutName) {
            return $layoutName;
        }

        return 'shop';
    }

    protected function beforeRender()
    {
        parent::beforeRender();

        $this->template->cartProductSum = $this->cartProductSum;
        $this->template->currency = $this->applicationConfig->get('currency');
    }

    private function productListData()
    {
        $this->template->tags = $this->tagsRepository->all()->where(['visible' => true]);
        $counts = [];
        foreach ($this->tagsRepository->counts()->where(['shop' => true]) as $count) {
            $counts[$count->id] = $count->val;
        }
        $this->template->tagCounts = $counts;
        $this->template->productsCount = $this->productsRepository->getShopProducts(true, true)->count('*');
    }

    public function renderDefault()
    {
        // TODO: remove this fallback for deprecated routing by 2022
        if ($tags = $this->getParameter('tags')) {
            if (count($tags) === 1) {
                $tag = $this->tagsRepository->find(array_key_first($tags));
                if ($tag) {
                    $this->redirect('tag', ['tagCode' => $tag->code]);
                }
            }
            $this->redirect('this');
        }

        $this->setView('productList');
        $this->productListData();

        $this->template->title = $this->template->htmlHeading = $this->applicationConfig->get('shop_title');
        $this->template->products = $this->productsRepository->getShopProducts(true, true);
        $this->template->selectedTag = null;
        $this->template->cartProducts = $this->cartSession->products;
    }

    public function renderSearchResults($q)
    {
        if (empty($q)) {
            $this->redirect('default');
        }

        $products = $this->productsRepository->all($q)->where('shop')->where([
            'products.shop' => true,
        ]);

        if ($this->isAjax()) {
            $products->limit(10);

            $data = [];
            foreach ($products as $product) {
                $data[] = [
                    'value' => $product->id,
                    'label' => $product->name,
                ];
            }
            $this->sendJson($data);
        }

        $this->template->products = $products;
        $this->template->hideMenu = true;
        $this->template->title = $this->template->htmlHeading = $this->translator->translate('products.frontend.shop.search.results_title');
        $this->template->searchTerm = $q;
        $this->template->cartProducts = $this->cartSession->products;
        $this->template->selectedTag = null;
        $this->template->productsCount = $products->count('*');

        $this->setView('productList');
    }

    public function renderTag($tagCode)
    {
        $tag = $this->tagsRepository->findBy('code', $tagCode);
        if (!$tag) {
            throw new BadRequestException("Tag not found: " . $tagCode, 404);
        }

        $this->setView('productList');
        $this->productListData();

        $this->template->title = $tag->name;
        $this->template->htmlHeading = $tag->html_heading;
        $this->template->products = $this->productsRepository->getShopProducts(false, true, $tag);
        $this->template->selectedTag = $tag;
        $this->template->cartProducts = $this->cartSession->products;
    }

    public function renderShow($id, $code)
    {
        $product = $this->productsRepository->find($id);
        if (!$product && $code) {
            $product = $this->productsRepository->getByCode($code);
        }
        if (!$product || !$product->shop) {
            throw new BadRequestException('Product not found.', 404);
        }

        if (!$id || !$code) {
            $this->canonicalize('this', ['id' => $product->id, 'code' => $product->code]);
        }

        if ($code !== $product->code) {
            throw new BadRequestException("Product code does not match the product ID. Is your URL valid?", 404);
        }

        $this->template->title = $this->applicationConfig->get('shop_title');
        $this->template->now = new DateTime();
        $this->template->product = $product;
        $this->template->cartProducts = $this->cartSession->products;
    }

    public function actionAddToCart($id)
    {
        $this->handleAddCart($id);
    }

    public function renderCart()
    {
        $products = $this->productsRepository->findByIds(array_keys($this->cartSession->products));
        $removedProducts = [];

        foreach ($products as $productKey => $product) {
            if ($product->stock <= 0 || $product->stock < $this->cartSession->products[$product->id]) {
                unset($this->cartSession->products[$product->id]);
                unset($products[$productKey]);
                $this->cartProductSum = array_sum($this->cartSession->products);
                $removedProducts[] = $product->name;
            }
        }

        $freeProducts = [];
        if (is_array($this->cartSession->freeProducts) && count($this->cartSession->freeProducts)) {
            $freeProducts = $this->productsRepository->findByIds(array_keys($this->cartSession->freeProducts));
        }

        if (!empty($removedProducts)) {
            $this->flashMessage(implode(', ', $removedProducts), 'product-out-of-stock');
        }

        $this->template->cartProductSum = $this->cartProductSum;
        $this->template->cartProducts = $this->cartSession->products;
        $this->template->freeProducts = $freeProducts;
        $this->template->products = $products;
    }

    public function renderCheckout()
    {
        // without products there is nothing to checkout
        if (count($this->cartSession->products) === 0 && count($this->cartSession->freeProducts) === 0) {
            // redirect to cart which displays info about no products in cart
            $this->redirect('cart');
        }

        $products = $this->productsRepository->findByIds(array_keys($this->cartSession->products));
        $outOfStockProducts = [];
        $littleStockProducts = [];
        $amount = 0;

        foreach ($products as $product) {
            if ($product->stock <= 0) {
                unset($this->cartSession->products[$product->id]);
                $outOfStockProducts[] = $product->name;
            } elseif ($product->stock < $this->cartSession->products[$product->id]) {
                $this->cartSession->products[$product->id] = $product->stock;
                $littleStockProducts[] = $product->name;
            }
            $amount += $product->price * $this->cartProducts[$product->id];
        }

        if (!empty($outOfStockProducts) || !empty($littleStockProducts)) {
            if (!empty($outOfStockProducts)) {
                $this->flashMessage(implode(', ', $outOfStockProducts), 'product-out-of-stock');
            }
            if (!empty($littleStockProducts)) {
                $this->flashMessage(implode(', ', $littleStockProducts), 'product-little-in-stock');
            }

            $this->redirect('cart');
        }

        $freeProducts = [];
        if (count($this->cartSession->freeProducts)) {
            $freeProducts = $this->productsRepository->findByIds(array_keys($this->cartSession->freeProducts));
        }

        $this->template->cartProducts = $this->cartSession->products;
        $this->template->products = $products;
        $this->template->freeProducts = $freeProducts;
        $this->template->amount = $amount;
        $this->template->user = $this->getUser();
        $this->template->back = $this->storeRequest();
        $this->template->gatewayLabel = function ($gatewayCode) {
            return $this->checkoutFormFactory->gatewayLabel($gatewayCode);
        };
    }

    public function createComponentCheckoutForm()
    {
        $form = $this->checkoutFormFactory->create($this->cartProducts, $this->cartSession->freeProducts);

        $this->checkoutFormFactory->onLogin = function ($removeProducts) {
            if (empty($removeProducts)) {
                $this->redirect('checkout');
            } else {
                $products = [];
                foreach ($removeProducts as $product) {
                    unset($this->cartSession->products[$product->id]);
                    $products[] = $product->name;
                }
                $this->flashMessage(implode(', ', $products), 'product-removed');
                $this->redirect('cart');
            }
        };
        $this->checkoutFormFactory->onAuth = function ($userId) {
            $this->hermesEmitter->emit(
                new HermesMessage(
                    'sales-funnel',
                    array_merge($this->getTrackerParams(), [
                        'type' => 'checkout',
                        'user_id' => $userId,
                        'sales_funnel_id' => self::SALES_FUNNEL_SHOP,
                    ])
                ),
                HermesMessage::PRIORITY_DEFAULT
            );
        };
        $this->checkoutFormFactory->onSave = function ($payment) {
            $trackerParams = $this->getTrackerParams();

            $this->paymentsRepository->addMeta($payment, $trackerParams);

            $eventParams = [
                'type' => 'payment',
                'user_id' => $payment->user_id,
                'sales_funnel_id' => self::SALES_FUNNEL_SHOP,
                'payment_id' => $payment->id,
            ];
            $this->hermesEmitter->emit(
                new HermesMessage(
                    'sales-funnel',
                    array_merge($eventParams, $trackerParams)
                ),
                HermesMessage::PRIORITY_DEFAULT
            );

            try {
                $this->paymentProcessor->begin($payment);
            } catch (CannotProcessPayment $err) {
                $this->redirect('error');
            }
        };

        return $form;
    }

    public function handleCountryChange($value)
    {
        if (!$value) {
            return;
        }
        if ($this['checkoutForm']['postal_fee'] instanceof RadioList) {
            $country = $this->countriesRepository->findByIsoCode($value);

            $options = $this->postalFeeService->getAvailablePostalFeesOptions($country->id, $this->cartProducts, $this->user->getId());
            $this['checkoutForm']['postal_fee']
                ->setItems($options)
                ->setDefaultValue($this->postalFeeService->getDefaultPostalFee($country->id, $options));
            $this['checkoutForm']['shipping_country']->setValue($country->iso_code);
        }

        $this->template->getLatte()->addProvider('formsStack', [$this['checkoutForm']]);

        $this->redrawControl('postalFees');
        $this->redrawControl('deliveryContainer');
        $this->redrawControl('cart');
    }

    public function handlePostalFeeChange($value, $countryIsoCode)
    {
        if (!$value || !$countryIsoCode) {
            return;
        }

        $country = $this->countriesRepository->findByIsoCode($countryIsoCode);
        if ($this['checkoutForm']['postal_fee'] instanceof RadioList) {
            $options = $this->postalFeeService->getAvailablePostalFeesOptions($country->id, $this->cartProducts, $this->user->getId());
            $this['checkoutForm']['postal_fee']
                ->setItems($options)
                ->setDefaultValue($this->postalFeeService->getDefaultPostalFee($country->id, $options));
            $this['checkoutForm']['postal_fee']->setDefaultValue($value);
        }

        $this->template->getLatte()->addProvider('formsStack', [$this['checkoutForm']]);

        $this->redrawControl('cart');
    }

    public function renderSuccess($id)
    {
        $payment = $this->paymentsRepository->findByVs($id);
        if ($payment->user_id != $this->user->getId()) {
            $this->flashMessage('Odkaz nie je platný. Boli ste presmerovaný naspäť na obchod.', 'error');
            $this->redirect('default');
        }

        $order = $payment->related('orders')->fetch();
        $address = $order->ref('addresses', 'shipping_address_id');
        if (!$address) {
            $address = $order->ref('addresses', 'licence_address_id');
        }
        if (!$address) {
            $address = $order->ref('addresses', 'billing_address_id');
        }

        $ebooks = [];

        $this->paymentItemHelper->unBundleProducts($order->payment, function ($product) use ($address, &$ebooks, $order) {
            if (!isset($ebooks[$product->id])) {
                $user = $this->usersRepository->find($this->user->getIdentity()->getId());
                $links = $this->ebookProvider->getDownloadLinks($product, $user, $address, $order->payment);
                if (!empty($links)) {
                    $ebooks[$product->id] = [
                        'product' => $product,
                        'links' => $links,
                    ];
                }
            }
        });

        $fileFormatMap = $this->ebookProvider->getFileTypes();

        $this->template->payment = $payment;
        $this->template->order = $order;
        $this->template->fileFormatMap = $fileFormatMap;
        $this->template->ebooks = $ebooks;

        $this->cartSession->products = [];
    }

    public function renderError()
    {
        $this->template->contactEmail = $this->applicationConfig->get('contact_email');
    }

    protected function getTrackerParams()
    {
        $trackerParams = [];

        /** @var TrackerDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders(
            'products.dataprovider.tracker',
            TrackerDataProviderInterface::class
        );
        foreach ($providers as $sorting => $provider) {
            $trackerParams[] = $provider->provide();
        }
        return array_merge([], ...$trackerParams);
    }
}
