# CRM Products Module

[![Translation status @ Weblate](https://hosted.weblate.org/widgets/remp-crm/-/products-module/svg-badge.svg)](https://hosted.weblate.org/projects/remp-crm/products-module/)

## Configuration

### Payment gateways

To configure which gateways are available on the checkout page, you need to register the gateway and add it's label in your `config.neon` file.

Please add following snippet with the (available) gateways you want to use:

```neon
services:
	# ...
	checkoutFormFactory:
		setup:
			- addPaymentGateway(paypal, "Paypal")
			- addPaymentGateway(cardpay, "Credit card")
```

### Terms and Conditions URL

If you want the customer to agree to the terms and conditions, you need to configure URL in settings _(category **Shop**)_.

![Terms and conditions URL - settings](docs/terms-and-conditions_-_configuration.png "Terms and conditions URL - settings")

Checkbox with the link to the terms and conditions will be displayed _(and required)_ at the checkout before the customer confirms the order.

![Terms and conditions URL - displayed at checkout](docs/terms-and-conditions_-_checkout.png "Terms and conditions URL - displayed at checkout")

### E-books

Product module can handle orders of ebooks and provides download links in the user's section.

To display the download links of e-books you have to register your own implementation of `EbookProviderInterface` in configuration:

```
ebookProvider:
    setup:
        - register(Crm\DibukModule\Models\DibukEbookProvider())
```

And register link on library into user's section menu:

```
public function registerFrontendMenuItems(MenuContainerInterface $menuContainer)
{
    ...
    
    $menuItem = new MenuItem($this->translator->translate('products.menu.books'), ':Products:Orders:Library', '', 155);
    $menuContainer->attachMenuItem($menuItem);
}
```

![Books library link](docs/books-library-link.png "Books library link")

## Change of VAT rates

If the VAT rate changes in your country, there is a command that helps you update the system. Schedule it to be executed on the date of VAT change.

- `products:change_vat --original-vat=X --target-vat=Y`
  - Command changes all existing products and each affected (`type=product`) payment item. You can test the command before the VAT change date by using `--dry-run` and `--verbose` options.

## Components

**DonatedSubscriptionListingWidget**

Simple badge showing subscription donor e-mail.

![alt text](docs/donated_by.png "DonatedSubscriptionListingWidget")

[Source code](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/components/DonatedSubscriptionListingWidget/DonatedSubscriptionListingWidget.php#L1)

[How to use](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/ProductsModule.php#L234)

**GiftCoupons**

Dropdown listing of gift subscriptions with modal detail.

![alt text](docs/gift_subscriptions_1.png "GiftCoupons")

![alt text](docs/gift_subscriptions_2.png "GiftCoupons")

[Source code](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/components/GiftCoupons/GiftCoupons.php#L1)

[How to use](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/ProductsModule.php#L229)

**ProductItemsListWidget**

Payments listing single payment item widget.

![alt text](docs/payment_items.png "ProductItemsListWidget")

[Source code](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/components/PaymentItemsListWidget/ProductItemsListWidget.php#L1)

[How to use](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/ProductsModule.php#L225)

**ProductStats**

Products stats listing component.

![alt text](docs/product_stats_table.png "ProductStats")

[Source code](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/components/ProductsStats/ProductStats.php#L1)

[How to use](https://github.com/remp2020/crm-products-module/blob/c8d80062b7f3cb355489327e17b0b40a69d89562/src/presenters/DashboardPresenter.php#L69)
