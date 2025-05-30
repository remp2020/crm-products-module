<?php

namespace Crm\ProductsModule\Forms;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Forms\Controls\CountriesSelectItemsBuilder;
use Crm\ApplicationModule\Forms\FormPatterns;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\UI\Form;
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
use Crm\ProductsModule\Repositories\DistributionCentersRepository;
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

    /* callback function */
    public $onSave;

    /* callback function */
    public $onLogin;

    /* callback function */
    public $onAuth;

    private array $gateways = [];

    private array $cartFree;

    public function __construct(
        private readonly ApplicationConfig $applicationConfig,
        private readonly PaymentsRepository $paymentsRepository,
        private readonly PaymentGatewaysRepository $paymentGatewaysRepository,
        private readonly ProductsRepository $productsRepository,
        private readonly User $user,
        private readonly UsersRepository $usersRepository,
        private readonly UserManager $userManager,
        private readonly AddressesRepository $addressesRepository,
        private readonly AddressChangeRequestsRepository $addressChangeRequestsRepository,
        private readonly CountriesRepository $countriesRepository,
        private readonly OrdersRepository $ordersRepository,
        private readonly PostalFeesRepository $postalFeesRepository,
        private readonly Request $request,
        private readonly Authorizator $authorizator,
        private readonly Translator $translator,
        private readonly PaymentItemHelper $paymentItemHelper,
        private readonly DataProviderManager $dataProviderManager,
        private readonly CountryPostalFeesRepository $countryPostalFeesRepository,
        private readonly PostalFeeService $postalFeeService,
        private readonly OneStopShop $oneStopShop,
        private readonly UserActionsLogRepository $userActionsLogRepository,
        private readonly CountriesSelectItemsBuilder $countriesSelectItemsBuilder,
        private readonly DistributionCentersRepository $distributionCentersRepository,
    ) {
    }

    public function create($cart, $cartFree = [], $payment = null): Form
    {
        $this->cartFree = $cartFree;
        $defaults = [];
        $selectedCountry = $this->countriesRepository->defaultCountry();

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
                $selectedCountry = $address->country;
            }

            $address = $this->addressesRepository->address($user, 'invoice');
            if ($address) {
                $defaults['billing_address'] = $address->toArray();
                $defaults['billing_address']['country'] = $address->country->iso_code;

                if (!isset($defaults['contact']['phone_number'])) {
                    $defaults['contact']['phone_number'] = $address->phone_number;
                }
            }
        }

        $postShippingCountryIsoCode = $this->request->getPost('shipping_country');
        if (!empty($postShippingCountryIsoCode)) {
            $selectedCountry = $this->countriesRepository->findByIsoCode($postShippingCountryIsoCode);
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
                    $paymentGatewayItems[$code] = $code;
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
            $email->setHtmlAttribute('placeholder', '@')
                ->setHtmlAttribute('autocomplete', 'email');
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
                ->addConditionOn($action, $form::Equal, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.pass_required');
            $email->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($emailUsable, 'products.frontend.shop.checkout.fields.account_exists', $password);

            $user->addSubmit('login_submit', 'products.frontend.shop.checkout.login')
                ->setValidationScope([$form['user']]);
        }

        if ($hasDelivery) {
            $contact = $form->addContainer('contact');
            $contact->addText('phone_number', 'products.frontend.shop.checkout.fields.phone_number')
                ->setHtmlAttribute('placeholder', 'products.frontend.shop.checkout.fields.phone_number_placeholder')
                ->setHtmlAttribute('autocomplete', 'tel')
                ->setHtmlAttribute('inputmode', 'tel')
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.phone_number_required')
                ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.phone_number_wrong_format', FormPatterns::PHONE_NUMBER_INTERNATIONAL);
        }

        $invoice = $form->addContainer('invoice');
        $addInvoice = $invoice->addCheckbox('add_invoice', 'products.frontend.shop.checkout.fields.want_invoice');
        $addInvoice->getLabelPrototype()->addAttributes(['class' => 'checkbox-inline']);

        $sameShipping = $invoice->addCheckbox('same_shipping', 'products.frontend.shop.checkout.fields.same_shipping')
            ->setDefaultValue(true);
        $sameShipping->getLabelPrototype()->addAttributes(['class' => 'checkbox-inline', 'id' => 'same-address']);
        $addInvoice->addCondition($form::Equal, true)
            ->toggle('same-address');

        if ($hasDelivery) {
            $form->removeComponent($postalFee);

            $availableCountryPairs = $this->countryPostalFeesRepository->findAllAvailableCountryIsoPairs();
            $country = $form->addSelect('shipping_country', 'products.frontend.shop.checkout.fields.country', $availableCountryPairs)
                ->setHtmlAttribute('autocomplete', 'country')
                ->setDefaultValue($selectedCountry->iso_code);

            $options = $this->postalFeeService->getAvailablePostalFeesOptions($selectedCountry->id, $cart, $this->user->getId());

            $form->addRadioList('postal_fee', null, $options)
                ->setRequired('products.frontend.shop.checkout.fields.choose_shipping_method')
                ->setDefaultValue($this->postalFeeService->getDefaultPostalFee($selectedCountry->id, $options));

            $shippingAddress = $form->addContainer('shipping_address');
            $shippingAddress->addText('first_name', 'products.frontend.shop.checkout.fields.first_name')
                ->setHtmlAttribute('autocomplete', 'given-name')
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.first_name_required');

            $shippingAddress->addText('last_name', 'products.frontend.shop.checkout.fields.last_name')
                ->setHtmlAttribute('autocomplete', 'family-name')
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.last_name_required');

            $shippingAddress->addText('street', 'products.frontend.shop.checkout.fields.street')
                ->setHtmlAttribute('pattern', FormPatterns::STREET_NAME)
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.street_required')
                ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.street_wrong_format', FormPatterns::STREET_NAME)
                ->addRule($form::MinLength, 'products.frontend.shop.checkout.fields.street_min_length', 3);

            $shippingAddress->addText('number', 'products.frontend.shop.checkout.fields.number')
                ->setHtmlAttribute('pattern', FormPatterns::STREET_NUMBER)
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.number_required')
                ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.number_wrong_format', FormPatterns::STREET_NUMBER);

            $shippingAddress->addText('city', 'products.frontend.shop.checkout.fields.city')
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.city_required');

            $zip = $shippingAddress->addText('zip', 'products.frontend.shop.checkout.fields.zip_code')
                ->setHtmlAttribute('autocomplete', 'postal-code')
                ->setHtmlAttribute('pattern', FormPatterns::ZIP_CODE)
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.zip_code_required');

            $zip->addConditionOn($country, $form::IsIn, ['CZ', 'SK'])
                ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.zip_code_invalid', FormPatterns::ZIP_CODE);
        } elseif ($hasLicence) {
            $licenceAddress = $form->addContainer('licence_address');
            $licenceAddress->addText('first_name', 'products.frontend.shop.checkout.fields.first_name')
                ->setHtmlAttribute('autocomplete', 'given-name')
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.first_name_required');

            $licenceAddress->addText('last_name', 'products.frontend.shop.checkout.fields.last_name')
                ->setHtmlAttribute('autocomplete', 'family-name')
                ->addConditionOn($action, $form::NotEqual, 'login')
                ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.first_name_required');
        }

        if ($hasDelivery) {
            $addInvoice->addCondition($form::Equal, true)
                ->addConditionOn($sameShipping, $form::Equal, false)
                ->toggle('billing-address');
        } else {
            $sameShipping->setDisabled(true)->setDefaultValue(false);
            $addInvoice->addCondition($form::Equal, true)
                ->addConditionOn($sameShipping, $form::Equal, false)
                ->toggle('billing-address');
        }

        $billingAddress = $form->addContainer('billing_address');
        $billingAddress->addTextArea('company_name', 'products.frontend.shop.checkout.fields.company_name', null, 1)
            ->setHtmlAttribute('autocomplete', 'organization')
            ->setMaxLength(150)
            ->addConditionOn($addInvoice, $form::Equal, true)
            ->addConditionOn($sameShipping, $form::Equal, false)
            ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.company_name_required');

        $billingAddress->addText('street', 'products.frontend.shop.checkout.fields.street')
            ->setHtmlAttribute('pattern', FormPatterns::STREET_NAME)
            ->addConditionOn($addInvoice, $form::Equal, true)
            ->addConditionOn($sameShipping, $form::Equal, false)
            ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.street_wrong_format', FormPatterns::STREET_NAME)
            ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.street_required');

        $billingAddress->addText('number', 'products.frontend.shop.checkout.fields.number')
            ->setHtmlAttribute('pattern', FormPatterns::STREET_NUMBER)
            ->addConditionOn($addInvoice, $form::Equal, true)
            ->addConditionOn($sameShipping, $form::Equal, false)
            ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.number_wrong_format', FormPatterns::STREET_NUMBER)
            ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.number_required');

        $billingAddress->addText('city', 'products.frontend.shop.checkout.fields.city')
            ->addConditionOn($addInvoice, $form::Equal, true)
            ->addConditionOn($sameShipping, $form::Equal, false)
            ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.city_required');

        $zip = $billingAddress->addText('zip', 'products.frontend.shop.checkout.fields.zip_code')
            ->setHtmlAttribute('autocomplete', 'postal-code')
            ->setHtmlAttribute('pattern', FormPatterns::ZIP_CODE)
            ->addConditionOn($addInvoice, $form::Equal, true)
            ->addConditionOn($sameShipping, $form::Equal, false)
            ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.zip_code_invalid', FormPatterns::ZIP_CODE)
            ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.zip_code_required');

        $country = $billingAddress->addSelect(
            'country',
            'products.frontend.shop.checkout.fields.country',
            $this->countriesSelectItemsBuilder->getAllIsoPairs(),
        )
            ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.country_required')
            ->setHtmlAttribute('autocomplete', 'country');

        $zip->addConditionOn($country, $form::IsIn, ['CZ', 'SK'])
            ->addRule($form::Pattern, 'products.frontend.shop.checkout.fields.zip_code_invalid', '\d{3} ?\d{2}');

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
                    ['link' => $termsURL],
                )));
                $toc->addConditionOn($action, $form::NotEqual, 'login')
                    ->addRule($form::Filled, 'products.frontend.shop.checkout.fields.toc_required');
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

    public function addPaymentGateway($code, $label): void
    {
        $this->gateways[$code] = $label;
    }

    public function gatewayLabel($code): string
    {
        if (!isset($this->gateways[$code])) {
            throw new \Exception('request for label of gateway not registered in checkout form: ' . $code);
        }
        return $this->gateways[$code];
    }

    public function formSucceeded($form, $values): void
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

            $this->user->login(['user' => $user, 'autoLogin' => true]);

            if (!$user) {
                $form['user']['email']->addError('products.frontend.shop.checkout.warnings.unable_to_create_user');
                return;
            }
            $this->onAuth->__invoke($user->id);
        } else {
            $user = $this->userManager->loadUser($this->user);
        }

        if (!$values['invoice']['add_invoice']) {
            unset($values['billing_address']);
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

        $paymentGateway = $this->paymentGatewaysRepository->findByCode($values['payment_gateway']);

        // add products
        $cart = Json::decode($values['cart'], Json::FORCE_ARRAY);
        $products = $this->productsRepository->findByIds(array_keys($cart));

        // add postal fee
        $postalFee = $this->handlePostalFee($values);
        $postalFeeVat = null;

        // populate payment item container
        $paymentItemsContainer = new PaymentItemContainer();
        foreach ($products as $product) {
            $paymentItemsContainer->addItem(
                new ProductPaymentItem(
                    $product,
                    $cart[$product->id],
                ),
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
                        ->forcePrice(0),
                );
            }
        }
        if ($postalFee) {
            if ($postalFeeVat === null) {
                throw new \Exception("attempt to use uninitialized postal fee VAT (should have been calculated based on sold items)");
            }
            $postalFeeItem = new PostalFeePaymentItem(
                $postalFee,
                $postalFeeVat,
            );
            $postalFeeItem->forceName(
                sprintf(
                    "%s - %s",
                    $this->translator->translate('products.frontend.orders.postal_fee'),
                    $postalFeeItem->name(),
                ),
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
            subscriptionType: null,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemsContainer,
            referer: $this->request->getUrl()->getBaseUrl(),
            paymentCountry: $countryResolution?->country,
            paymentCountryResolutionReason: $countryResolution?->getReasonValue(),
        );
        $additionalColumns = [];

        /** @var CheckoutFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('products.dataprovider.checkout_form', CheckoutFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            [$form, $values] = $provider->formSucceeded($form, $values, [
                'payment' => $payment,
            ]);
            $provider->addAdditionalColumns($form, $values, $additionalColumns);
        }

        $shippingAddressId = $this->handleShippingAddress($user, $values);
        $licenceAddressId = $this->handleLicenceAddress($user, $values);
        $billingAddressId = $this->handleBillingAddress($user, $values);

        $this->ordersRepository->add($payment->id, $shippingAddressId, $licenceAddressId, $billingAddressId, $postalFee, $values['note'], $additionalColumns);

        $this->onSave->__invoke($payment);
    }

    public function formAdminSucceeded($form, $values): void
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

    private function handleShippingAddress($user, $values): ?int
    {
        $shippingAddressId = null;
        if (isset($values['shipping_address'])) {
            $values['shipping_address']['phone_number'] = $values['contact']['phone_number'] ?? null;
            $shippingAddress = $this->addressesRepository->findByAddress(
                $values['shipping_address'],
                AddressTypesSeeder::PRODUCTS_SHOP_ADDRESS_TYPE,
                $user->id,
            );
            if (!$shippingAddress) {
                $country = $this->countriesRepository->findByIsoCode($values['shipping_country']);

                $shippingAddress = $this->addressesRepository->add(
                    $user,
                    AddressTypesSeeder::PRODUCTS_SHOP_ADDRESS_TYPE,
                    $values['shipping_address']['first_name'],
                    $values['shipping_address']['last_name'],
                    $values['shipping_address']['street'],
                    $values['shipping_address']['number'],
                    $values['shipping_address']['city'],
                    $values['shipping_address']['zip'],
                    $country->id,
                    $values['shipping_address']['phone_number'],
                );
            }
            $this->addressesRepository->update($shippingAddress, []);
            $shippingAddressId = $shippingAddress->id;
        }
        return $shippingAddressId;
    }

    private function handleLicenceAddress($user, $values): ?int
    {
        $licenceAddressId = null;
        if (isset($values['licence_address'])) {
            $values['licence_address']['phone_number'] = $values['contact']['phone_number'] ?? null;
            $licenceAddress = $this->addressesRepository->findByAddress(
                $values['licence_address'],
                AddressTypesSeeder::PRODUCTS_LICENCE_ADDRESS_TYPE,
                $user->id,
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
                    $values['licence_address']['phone_number'],
                );
            }
            $this->addressesRepository->update($licenceAddress, []);
            $licenceAddressId = $licenceAddress->id;
        }
        return $licenceAddressId;
    }

    private function handleBillingAddress($user, $values): ?int
    {
        $billingAddressId = null;
        if ($values['invoice']['add_invoice']) {
            $changeRequest = null;
            $billingAddress = $this->addressesRepository->address($user, 'invoice');

            if (isset($values['shipping_address']) && isset($values['invoice']['same_shipping']) && $values['invoice']['same_shipping']) {
                $shippingCountry = $this->countriesRepository->findByIsoCode($values['shipping_country']);

                $changeRequest = $this->addressChangeRequestsRepository->add(
                    $user,
                    $billingAddress,
                    $values['shipping_address']['first_name'],
                    $values['shipping_address']['last_name'],
                    $values['shipping_address']['first_name'] . ' ' . $values['shipping_address']['last_name'],
                    $values['shipping_address']['street'],
                    $values['shipping_address']['number'],
                    $values['shipping_address']['city'],
                    $values['shipping_address']['zip'],
                    $shippingCountry->id,
                    null,
                    null,
                    null,
                    $values['shipping_address']['phone_number'],
                    'invoice',
                );
                if ($changeRequest) {
                    $billingAddress = $this->addressChangeRequestsRepository->acceptRequest($changeRequest);
                }
            } else {
                $billingCountry = $this->countriesRepository->findByIsoCode($values['billing_address']['country']);

                $values['billing_address']['phone_number'] = $values['contact']['phone_number'] ?? null;
                $changeRequest = $this->addressChangeRequestsRepository->add(
                    $user,
                    $billingAddress,
                    null,
                    null,
                    $values['billing_address']['company_name'],
                    $values['billing_address']['street'],
                    $values['billing_address']['number'],
                    $values['billing_address']['city'],
                    $values['billing_address']['zip'],
                    $billingCountry->id,
                    $values['billing_address']['company_id'],
                    $values['billing_address']['company_tax_id'],
                    $values['billing_address']['company_vat_id'],
                    $values['billing_address']['phone_number'],
                    'invoice',
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

    private function handlePostalFee($values): ?\Crm\ApplicationModule\Models\Database\ActiveRow
    {
        $postalFee = null;
        if (!empty($values['postal_fee'])) {
            $postalFee = $this->postalFeesRepository->find($values['postal_fee']);
        }
        return $postalFee;
    }
}
