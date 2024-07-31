<?php

namespace Crm\ProductsModule\Forms;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\PaymentsModule\Models\OneStopShop\OneStopShop;
use Crm\PaymentsModule\Models\OneStopShop\OneStopShopCountryConflictException;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\ProductsModule\DataProviders\CheckoutFormDataProviderInterface;
use Crm\ProductsModule\Models\PaymentItem\PaymentItemHelper;
use Crm\ProductsModule\Models\PaymentItem\PostalFeePaymentItem;
use Crm\ProductsModule\Models\PaymentItem\ProductPaymentItem;
use Crm\ProductsModule\Models\PostalFeeCondition\PostalFeeService;
use Crm\ProductsModule\Models\ProductsTrait;
use Crm\ProductsModule\Repositories\CountryPostalFeesRepository;
use Crm\ProductsModule\Repositories\OrdersRepository;
use Crm\ProductsModule\Repositories\PostalFeesRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\ProductsModule\Seeders\AddressTypesSeeder;
use Crm\UsersModule\DataProviders\AddressFormDataProviderInterface;
use Crm\UsersModule\Models\Auth\Authorizator;
use Crm\UsersModule\Models\Auth\InvalidEmailException;
use Crm\UsersModule\Models\Auth\UserManager;
use Crm\UsersModule\Repositories\AddressChangeRequestsRepository;
use Crm\UsersModule\Repositories\AddressesRepository;
use Crm\UsersModule\Repositories\CountriesRepository;
use Crm\UsersModule\Repositories\UserActionsLogRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;
use Nette\Forms\Controls\TextInput;
use Nette\Http\Request;
use Nette\Security\AuthenticationException;
use Nette\Security\User;
use Nette\Utils\Html;
use Nette\Utils\Json;
use Tracy\Debugger;
use Tracy\ILogger;

class CheckoutFormFactory
{
    use ProductsTrait;

    public $onSave;
    public $onLogin;
    public $onAuth;

    private $gateways = [];

    private $cartFree;

    public function __construct(
        private ApplicationConfig $applicationConfig,
        private PaymentsRepository $paymentsRepository,
        private PaymentGatewaysRepository $paymentGatewaysRepository,
        private ProductsRepository $productsRepository,
        private User $user,
        private UsersRepository $usersRepository,
        private UserManager $userManager,
        private AddressesRepository $addressesRepository,
        private AddressChangeRequestsRepository $addressChangeRequestsRepository,
        private CountriesRepository $countriesRepository,
        private OrdersRepository $ordersRepository,
        private PostalFeesRepository $postalFeesRepository,
        private Request $request,
        private Authorizator $authorizator,
        private Translator $translator,
        private PaymentItemHelper $paymentItemHelper,
        private DataProviderManager $dataProviderManager,
        private CountryPostalFeesRepository $countryPostalFeesRepository,
        private PostalFeeService $postalFeeService,
        private OneStopShop $oneStopShop,
        private UserActionsLogRepository $userActionsLogRepository,
    ) {
    }

    public function create($cart, $cartFree = [], $payment = null)
    {
        $this->cartFree = $cartFree;
        $defaults = [];
        $countryId = $this->countriesRepository->defaultCountry()->id;

        if ($this->user->isLoggedIn()) {
            if ($payment) {
                $user = $payment->user;
            } else {
                $user = $this->userManager->loadUser($this->user);
            }

            $address = $this->addressesRepository->address($user, AddressTypesSeeder::PRODUCTS_SHOP_ADDRESS_TYPE);
            if ($address) {
                $defaults['shipping_address'] = $address->toArray();
                $defaults['contact']['phone_number'] = $address->phone_number;
                $countryId = $address->country_id;
            }

            $address = $this->addressesRepository->address($user, 'invoice');
            if ($address) {
                $defaults['billing_address'] = $address->toArray();

                if (!isset($defaults['contact']['phone_number'])) {
                    $defaults['contact']['phone_number'] = $address->phone_number;
                }
            }
        }

        $postShippingCountryId = $this->request->getPost('shipping_country_id');
        if (!empty($postShippingCountryId)) {
            $countryId = $postShippingCountryId;
        }

        $products = $this->productsRepository->findByIds(array_keys($cart));

        $hasDelivery = $this->hasDelivery($products);
        $hasLicence = $this->hasLicense($products);

        $form = new Form;
        $form->setTranslator($this->translator);

        $form->addHidden('cart', Json::encode($cart));
        $form->addHidden('cart_free', Json::encode($cartFree));
        $postalFee = $form->addHidden('postal_fee', false);
        $action = $form->addHidden('action', 'checkout');

        if (!$payment) {
            $paymentGateways = $this->paymentGatewaysRepository->getAllVisible()
                ->where(['code' => array_keys($this->gateways)])
                ->fetchPairs('code', 'id');

            $paymentGatewayItems = [];
            foreach ($this->gateways as $code => $name) {
                if (isset($paymentGateways[$code])) {
                    $paymentGatewayItems[$paymentGateways[$code]] = $code;
                }
            }

            $form->addRadioList('payment_gateway', null, $paymentGatewayItems)
                ->setRequired('products.frontend.shop.checkout.choose_payment_method');
        }

        $user = $form->addContainer('user');

        if ($this->user->isLoggedIn()) {
            $user->addHidden('email')
                ->setDefaultValue($this->user->getIdentity()->email);
        } else {
            $email = $user->addText('email', Html::el()->setHtml('Email<i id="preloader" class="fa fa-refresh fa-spin"></i>'));
            $email->setHtmlAttribute('placeholder', '@');
            $email->setRequired('products.frontend.shop.checkout.fields.email_required');

            $emailUsable = function (TextInput $emailField, ?string $password) {
                if (strlen($password)) {
                    // User is trying to log in, formSucceeded will validate the credentials.
                    return true;
                }
                $user = $this->usersRepository->findBy('email', $emailField->getValue());
                return !$user;
            };

            $password = $user->addPassword('password', 'products.frontend.shop.checkout.fields.password');
            $password
                ->addConditionOn($action, Form::EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.pass_required');
            $email->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule($emailUsable, 'products.frontend.shop.checkout.fields.account_exists', $password);

            $user->addSubmit('login_submit', 'products.frontend.shop.checkout.login')
                ->setValidationScope([$form['user']]);
        }

        if ($hasDelivery) {
            $contact = $form->addContainer('contact');
            $contact->addText('phone_number', 'products.frontend.shop.checkout.fields.phone_number')
                ->setHtmlAttribute('placeholder', 'products.frontend.shop.checkout.fields.phone_number_placeholder')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.phone_number_required')
                ->addRule(Form::PATTERN, 'products.frontend.shop.checkout.fields.phone_number_wrong_format', '^[+][-\s\.\/\d]{12,}$');
        }

        $invoice = $form->addContainer('invoice');
        $addInvoice = $invoice->addCheckbox('add_invoice', 'products.frontend.shop.checkout.fields.want_invoice');
        $addInvoice->getLabelPrototype()->addAttributes(['class' => 'checkbox-inline']);

        $sameShipping = $invoice->addCheckbox('same_shipping', 'products.frontend.shop.checkout.fields.same_shipping')
            ->setDefaultValue(true);
        $sameShipping->getLabelPrototype()->addAttributes(['class' => 'checkbox-inline', 'id' => 'same-address']);
        $addInvoice->addCondition(Form::EQUAL, true)
            ->toggle('same-address');

        if ($hasDelivery) {
            $form->removeComponent($postalFee);

            $availableCountryPairs = $this->countryPostalFeesRepository->findAllAvailableCountryPairs();
            $country = $form->addSelect('shipping_country_id', 'products.frontend.shop.checkout.fields.country', $availableCountryPairs)
                        ->setDefaultValue($countryId);

            $options = $this->postalFeeService->getAvailablePostalFeesOptions($countryId, $cart, $this->user->getId());

            $form->addRadioList('postal_fee', null, $options)
                ->setRequired('products.frontend.shop.checkout.fields.choose_shipping_method')
                ->setDefaultValue($this->postalFeeService->getDefaultPostalFee($countryId, $options));

            $shippingAddress = $form->addContainer('shipping_address');
            $shippingAddress->addText('first_name', 'products.frontend.shop.checkout.fields.first_name')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.first_name_required');

            $shippingAddress->addText('last_name', 'products.frontend.shop.checkout.fields.last_name')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.last_name_required');

            $shippingAddress->addText('address', 'products.frontend.shop.checkout.fields.street')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.street_required')
                ->addRule(Form::MIN_LENGTH, 'products.frontend.shop.checkout.fields.street_min_length', 3);

            $shippingAddress->addText('number', 'products.frontend.shop.checkout.fields.number')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.number_required');

            $shippingAddress->addText('city', 'products.frontend.shop.checkout.fields.city')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.city_required');

            $zip = $shippingAddress->addText('zip', 'products.frontend.shop.checkout.fields.zip_code')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.zip_code_required');

            $validateCountryIds = $this->getIdsOfCountriesByIsoCode(['CZ', 'SK']);
            $zip->addConditionOn($country, Form::IS_IN, $validateCountryIds)
                ->addRule(Form::PATTERN, 'products.frontend.shop.checkout.fields.zip_code_invalid', '\d{3} ?\d{2}');
        } elseif ($hasLicence) {
            $licenceAddress = $form->addContainer('licence_address');
            $licenceAddress->addText('first_name', 'products.frontend.shop.checkout.fields.first_name')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.first_name_required');

            $licenceAddress->addText('last_name', 'products.frontend.shop.checkout.fields.last_name')
                ->addConditionOn($action, Form::NOT_EQUAL, 'login')
                ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.first_name_required');
        }

        if ($hasDelivery) {
            $addInvoice->addCondition(Form::EQUAL, true)
                ->addConditionOn($sameShipping, Form::EQUAL, false)
                ->toggle('billing-address');
        } else {
            $sameShipping->setDisabled(true)->setDefaultValue(false);
            $addInvoice->addCondition(Form::EQUAL, true)
                ->addConditionOn($sameShipping, Form::EQUAL, false)
                ->toggle('billing-address');
        }

        $billingAddress = $form->addContainer('billing_address');
        $billingAddress->addTextArea('company_name', 'products.frontend.shop.checkout.fields.company_name', null, 1)
            ->setMaxLength(150)
            ->addConditionOn($addInvoice, Form::EQUAL, true)
            ->addConditionOn($sameShipping, Form::EQUAL, false)
            ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.company_name_required');

        $billingAddress->addText('address', 'products.frontend.shop.checkout.fields.street')
            ->addConditionOn($addInvoice, Form::EQUAL, true)
            ->addConditionOn($sameShipping, Form::EQUAL, false)
            ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.street_required');

        $billingAddress->addText('number', 'products.frontend.shop.checkout.fields.number')
            ->addConditionOn($addInvoice, Form::EQUAL, true)
            ->addConditionOn($sameShipping, Form::EQUAL, false)
            ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.number_required');

        $billingAddress->addText('city', 'products.frontend.shop.checkout.fields.city')
            ->addConditionOn($addInvoice, Form::EQUAL, true)
            ->addConditionOn($sameShipping, Form::EQUAL, false)
            ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.city_required');

        $zip = $billingAddress->addText('zip', 'products.frontend.shop.checkout.fields.zip_code')
            ->addConditionOn($addInvoice, Form::EQUAL, true)
            ->addConditionOn($sameShipping, Form::EQUAL, false)
            ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.zip_code_required');

        $country = $billingAddress->addSelect(
            'country_id',
            'products.frontend.shop.checkout.fields.country',
            $this->countriesRepository->getAllPairs()
        );

        $validateCountryIds = $this->getIdsOfCountriesByIsoCode(['CZ', 'SK']);
        $zip->addConditionOn($country, Form::IS_IN, $validateCountryIds)
            ->addRule(Form::PATTERN, 'products.frontend.shop.checkout.fields.zip_code_invalid', '\d{3} ?\d{2}');

        $billingAddress->addText('company_id', 'products.frontend.shop.checkout.fields.company_id')
            ->setHtmlAttribute('placeholder', 'products.frontend.shop.checkout.fields.company_id_placeholder')
            ->setNullable();

        $billingAddress->addText('company_tax_id', 'products.frontend.shop.checkout.fields.company_tax_id')
            ->setHtmlAttribute('placeholder', 'products.frontend.shop.checkout.fields.company_tax_id_placeholder')
            ->setNullable();

        $billingAddress->addText('company_vat_id', 'products.frontend.shop.checkout.fields.company_vat_id')
            ->setHtmlAttribute('placeholder', 'products.frontend.shop.checkout.fields.company_vat_id_placeholder')
            ->setNullable();

        /** @var CheckoutFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('products.dataprovider.checkout_form', CheckoutFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide([
                'form' => $form,
                'action' => $action,
                'cart' => $cart,
            ]);
        }

        /** @var AddressFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('products.dataprovider.checkout_form.billing_address', AddressFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form, 'addressType' => 'invoice', 'container' => 'billing_address']);
        }

        if (!$payment) {
            // display terms and conditions if URL is configured
            $termsURL = $this->applicationConfig->get('shop_terms_and_conditions_url');
            if ($termsURL !== null && !empty(trim($termsURL))) {
                $toc = $form->addCheckbox('toc1', Html::el()->setHtml($this->translator->translate(
                    'products.frontend.shop.checkout.fields.toc',
                    ['link' => $termsURL]
                )));
                $toc->addConditionOn($action, Form::NOT_EQUAL, 'login')
                    ->addRule(Form::FILLED, 'products.frontend.shop.checkout.fields.toc_required');
                $toc->getLabelPrototype()->addAttributes(['class' => 'checkbox-inline']);
            }
        }

        $finishSubmit = $form->addSubmit('finish', 'products.frontend.shop.cart.confirm_order');


        $form->onAnchor[]  = function () use ($form, $addInvoice, $sameShipping, $finishSubmit) {
            // if invoice is not required or checkbox to "use same invoice address as shipping address" is checked,
            // disable validation of billing address
            if (!$addInvoice->getValue() || $sameShipping->getValue()) {
                $finishSubmit->setValidationScope($this->validationScopeReverse($form, ['billing_address']));
            }
        };

        $form->addProtection();
        $form->setDefaults($defaults);

        if ($payment) {
            $form->addHidden('payment_id', $payment->id);
            $form->onSuccess[] = [$this, 'formAdminSucceeded'];
        } else {
            $form->onSuccess[] = [$this, 'formSucceeded'];
        }

        return $form;
    }

    // Inspired by https://forum.nette.org/cs/34868-setvalidationscope-validovat-vse-mimo-nekterych
    private function validationScopeReverse(Form $form, array $noValidate): array
    {
        $scope = [];
        foreach ($form->getComponents(false) as $control) {
            if (!in_array($control->getName(), $noValidate, true)) {
                $scope[] = $control;
            }
        }
        return $scope;
    }

    public function addPaymentGateway($code, $label)
    {
        $this->gateways[$code] = $label;
    }

    public function gatewayLabel($code)
    {
        if (!isset($this->gateways[$code])) {
            throw new \Exception('request for label of gateway not registered in checkout form: ' . $code);
        }
        return $this->gateways[$code];
    }

    public function formSucceeded($form, $values)
    {
        if (isset($form['user']['login_submit']) && $form['user']['login_submit']->isSubmittedBy()) {
            $this->user->setExpiration('14 days');
            try {
                $this->user->login(['username' => $values['user']['email'], 'password' => $values['user']['password']]);
                $this->user->setAuthorizator($this->authorizator);

                $cart = Json::decode($this->request->getPost('cart'), Json::FORCE_ARRAY);
                $products = $this->productsRepository->findByIds(array_keys($cart));
                $removeProducts = [];
                foreach ($products as $product) {
                    if ($product->unique_per_user && $this->paymentItemHelper->hasUniqueProduct($product, $this->user->getId())) {
                        $removeProducts[] = $product;
                    }
                }

                $this->onAuth->__invoke($this->user->getId());
                $this->onLogin->__invoke($removeProducts);
            } catch (AuthenticationException $e) {
                $form['user']['password']->addError('products.frontend.shop.checkout.warnings.unable_to_login_with_password');
                $form['user']['password']->getControlPrototype()->addClass('error');
                return;
            }
        }

        if (!$this->user->isLoggedIn()) {
            $email = filter_input(INPUT_POST, 'user', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
            try {
                $user = $this->userManager->addNewUser($email['email'], true, 'shop');
            } catch (InvalidEmailException $e) {
                $form['user']['email']->addError('products.frontend.shop.checkout.warnings.invalid_email');
                return;
            }

            if (!$user) {
                $form['user']['email']->addError('products.frontend.shop.checkout.warnings.unable_to_create_user');
                return;
            }
            $this->onAuth->__invoke($user->id);
        } else {
            $user = $this->userManager->loadUser($this->user);
        }

        try {
            $this->oneStopShop->resolveCountry(
                user: $user,
                formParams: (array) $values,
            );
        } catch (OneStopShopCountryConflictException $e) {
            Debugger::log("Shop checkout - OSS conflict: " . $e->getMessage(), ILogger::WARNING);
            $this->userActionsLogRepository->add($user->id, 'funnel.one_stop_shop.conflict', ['exception' => $e->getMessage()]);
            $form->addError('products.frontend.shop.checkout.warnings.unable_to_create_payment_one_stop_shop');
            return;
        }

        $paymentGateway = $this->paymentGatewaysRepository->find($values['payment_gateway']);

        $amount = 0;

        // add products
        $cart = Json::decode($values['cart'], Json::FORCE_ARRAY);
        $products = $this->productsRepository->findByIds(array_keys($cart));
        foreach ($products as $product) {
            $amount += $product->price * $cart[$product->id];
        }

        // add postal fee
        $postalFee = $this->handlePostalFee($values);
        $postalFeeVat = null;
        if (!is_null($postalFee)) {
            $amount += $postalFee->amount;
        }

        // populate payment item container
        $paymentItemsContainer = new PaymentItemContainer();
        foreach ($products as $product) {
            $paymentItemsContainer->addItem(
                new ProductPaymentItem(
                    $product,
                    $cart[$product->id]
                )
            );
            if ($postalFeeVat === null || $product->vat > $postalFeeVat) {
                $postalFeeVat = $product->vat;
            }
        }
        if (Json::encode($this->cartFree) == $values['cart_free']) {
            $freeCart = Json::decode($values['cart_free'], Json::FORCE_ARRAY);
            $freeProducts = $this->productsRepository->findByIds(array_keys($freeCart));
            foreach ($freeProducts as $product) {
                $paymentItemsContainer->addItem(
                    (new ProductPaymentItem($product, $freeCart[$product->id]))
                        ->forceVat(0)
                        ->forcePrice(0)
                );
            }
        }
        if ($postalFee) {
            if ($postalFeeVat === null) {
                throw new \Exception("attempt to use uninitialized postal fee VAT (should have been calculated based on sold items)");
            }
            $postalFeeItem = new PostalFeePaymentItem(
                $postalFee,
                $postalFeeVat
            );
            $postalFeeItem->forceName(
                sprintf(
                    "%s - %s",
                    $this->translator->translate('products.frontend.orders.postal_fee'),
                    $postalFeeItem->name()
                )
            );
            $paymentItemsContainer->addItem($postalFeeItem);
        }

        // Repeat check, now with paymentItemContainer
        $countryResolution = null;
        try {
            $countryResolution = $this->oneStopShop->resolveCountry(
                user: $user,
                paymentItemContainer: $paymentItemsContainer,
                formParams: (array) $values,
            );
        } catch (OneStopShopCountryConflictException $e) {
            Debugger::log("Shop checkout - OSS conflict: " . $e->getMessage(), ILogger::WARNING);
            $this->userActionsLogRepository->add($user->id, 'funnel.one_stop_shop.conflict', ['exception' => $e->getMessage()]);
            $form->addError('products.frontend.shop.checkout.warnings.unable_to_create_payment_one_stop_shop');
            return;
        }

        $payment = $this->paymentsRepository->add(
            null,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemsContainer,
            referer: $this->request->getUrl()->getBaseUrl(),
            amount: $amount,
            paymentCountry: $countryResolution?->country,
            paymentCountryResolutionReason: $countryResolution?->getReasonValue(),
        );
        $additionalColumns = [];

        /** @var CheckoutFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('products.dataprovider.checkout_form', CheckoutFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            [$form, $values] = $provider->formSucceeded($form, $values, [
                'payment' => $payment
            ]);
            $provider->addAdditionalColumns($form, $values, $additionalColumns);
        }

        $shippingAddressId = $this->handleShippingAddress($user, $values);
        $licenceAddressId = $this->handleLicenceAddress($user, $values);
        $billingAddressId = $this->handleBillingAddress($user, $values);

        $this->ordersRepository->add($payment->id, $shippingAddressId, $licenceAddressId, $billingAddressId, $postalFee, $values['note'], $additionalColumns);

        $this->onSave->__invoke($payment);
    }

    public function formAdminSucceeded($form, $values)
    {
        $payment = $this->paymentsRepository->find($values['payment_id']);
        $user = $payment->user;

        $shippingAddressId = $this->handleShippingAddress($user, $values);
        $licenceAddressId = $this->handleLicenceAddress($user, $values);
        $billingAddressId = $this->handleBillingAddress($user, $values);
        $postalFee = $this->handlePostalFee($values);

        /** @var CheckoutFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('products.dataprovider.checkout_form', CheckoutFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            [$form, $values] = $provider->formSucceeded($form, $values, [
                'payment' => $payment,
            ]);
        }

        $this->ordersRepository->add($payment->id, $shippingAddressId, $licenceAddressId, $billingAddressId, $postalFee, $values['note']);
        $amount = 0;
        /** @var ActiveRow $paymentItem */
        foreach ($payment->related('payment_items') as $paymentItem) {
            $amount += $paymentItem->amount * $paymentItem->count;
        }
        if ($postalFee) {
            $amount += $postalFee->amount;
        }
        $this->paymentsRepository->update($payment, [
            'amount' => $amount,
        ]);

        $this->onSave->__invoke($payment);
    }

    private function handleShippingAddress($user, $values)
    {
        $shippingAddressId = null;
        if (isset($values['shipping_address'])) {
            $values['shipping_address']['phone_number'] = $values['contact']['phone_number'] ?? null;
            $shippingAddress = $this->addressesRepository->findByAddress(
                $values['shipping_address'],
                AddressTypesSeeder::PRODUCTS_SHOP_ADDRESS_TYPE,
                $user->id
            );
            if (!$shippingAddress) {
                $shippingAddress = $this->addressesRepository->add(
                    $user,
                    AddressTypesSeeder::PRODUCTS_SHOP_ADDRESS_TYPE,
                    $values['shipping_address']['first_name'],
                    $values['shipping_address']['last_name'],
                    $values['shipping_address']['address'],
                    $values['shipping_address']['number'],
                    $values['shipping_address']['city'],
                    $values['shipping_address']['zip'],
                    $values['shipping_country_id'],
                    $values['shipping_address']['phone_number']
                );
            }
            $this->addressesRepository->update($shippingAddress, []);
            $shippingAddressId = $shippingAddress->id;
        }
        return $shippingAddressId;
    }

    private function handleLicenceAddress($user, $values)
    {
        $licenceAddressId = null;
        if (isset($values['licence_address'])) {
            $values['licence_address']['phone_number'] = $values['contact']['phone_number'] ?? null;
            $licenceAddress = $this->addressesRepository->findByAddress(
                $values['licence_address'],
                AddressTypesSeeder::PRODUCTS_LICENCE_ADDRESS_TYPE,
                $user->id
            );
            if (!$licenceAddress) {
                $licenceAddress = $this->addressesRepository->add(
                    $user,
                    AddressTypesSeeder::PRODUCTS_LICENCE_ADDRESS_TYPE,
                    $values['licence_address']['first_name'],
                    $values['licence_address']['last_name'],
                    null,
                    null,
                    null,
                    null,
                    null,
                    $values['licence_address']['phone_number']
                );
            }
            $this->addressesRepository->update($licenceAddress, []);
            $licenceAddressId = $licenceAddress->id;
        }
        return $licenceAddressId;
    }

    private function handleBillingAddress($user, $values)
    {
        $billingAddressId = null;
        if ($values['invoice']['add_invoice']) {
            $changeRequest = null;
            $billingAddress = $this->addressesRepository->address($user, 'invoice');

            if (isset($values['shipping_address']) && isset($values['invoice']['same_shipping']) && $values['invoice']['same_shipping']) {
                $changeRequest = $this->addressChangeRequestsRepository->add(
                    $user,
                    $billingAddress,
                    $values['shipping_address']['first_name'],
                    $values['shipping_address']['last_name'],
                    $values['shipping_address']['first_name'] . ' ' . $values['shipping_address']['last_name'],
                    $values['shipping_address']['address'],
                    $values['shipping_address']['number'],
                    $values['shipping_address']['city'],
                    $values['shipping_address']['zip'],
                    $values['shipping_country_id'],
                    null,
                    null,
                    null,
                    $values['shipping_address']['phone_number'],
                    'invoice'
                );
                if ($changeRequest) {
                    $billingAddress = $this->addressChangeRequestsRepository->acceptRequest($changeRequest);
                }
            } else {
                $values['billing_address']['phone_number'] = $values['contact']['phone_number'] ?? null;
                $changeRequest = $this->addressChangeRequestsRepository->add(
                    $user,
                    $billingAddress,
                    null,
                    null,
                    $values['billing_address']['company_name'],
                    $values['billing_address']['address'],
                    $values['billing_address']['number'],
                    $values['billing_address']['city'],
                    $values['billing_address']['zip'],
                    $values['billing_address']['country_id'],
                    $values['billing_address']['company_id'],
                    $values['billing_address']['company_tax_id'],
                    $values['billing_address']['company_vat_id'],
                    $values['billing_address']['phone_number'],
                    'invoice'
                );
                if ($changeRequest) {
                    $billingAddress = $this->addressChangeRequestsRepository->acceptRequest($changeRequest);
                }
            }

            $this->usersRepository->update($user, ['invoice' => true]);
            $this->addressesRepository->update($billingAddress, []);
            $billingAddressId = $billingAddress->id;
        }
        return $billingAddressId;
    }

    private function handlePostalFee($values)
    {
        $postalFee = null;
        if (!empty($values['postal_fee'])) {
            $postalFee = $this->postalFeesRepository->find($values['postal_fee']);
        }
        return $postalFee;
    }

    private function getIdsOfCountriesByIsoCode(array $codes)
    {
        $countries = $this->countriesRepository->all()->where(['iso_code' => $codes])->fetchAssoc('id');
        if (empty($countries)) {
            return [];
        }
        return array_keys($countries);
    }
}
