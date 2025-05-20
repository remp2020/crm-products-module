<?php

namespace Crm\ProductsModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\ProductsModule\DataProviders\EbookProvider;
use Crm\ProductsModule\Models\PaymentItem\PaymentItemHelper;
use Crm\ProductsModule\Repositories\OrdersRepository;

class OrdersPresenter extends FrontendPresenter
{
    private $ordersRepository;
    private $paymentItemHelper;
    private $ebookProvider;

    public function __construct(
        OrdersRepository $ordersRepository,
        PaymentItemHelper $paymentItemHelper,
        EbookProvider $ebookProvider,
    ) {
        parent::__construct();

        $this->ordersRepository = $ordersRepository;
        $this->paymentItemHelper = $paymentItemHelper;
        $this->ebookProvider = $ebookProvider;
    }

    public function renderMy()
    {
        $this->onlyLoggedIn();
    }

    public function renderLibrary()
    {
        $this->onlyLoggedIn();

        $ebooks = [];
        $orders = $this->ordersRepository->getByUser($this->getUser()->getId());
        foreach ($orders as $order) {
            if ($order->payment->status != PaymentStatusEnum::Paid->value) {
                continue;
            }

            $address = $order->ref('addresses', 'shipping_address_id');
            if (!$address) {
                $address = $order->ref('addresses', 'licence_address_id');
            }
            if (!$address) {
                $address = $order->ref('addresses', 'billing_address_id');
            }

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
        }

        $fileFormatMap = $this->ebookProvider->getFileTypes();

        $this->template->fileFormatMap = $fileFormatMap;
        $this->template->ebooks = $ebooks;
        $this->template->shopHost = $this->applicationConfig->get('shop_host');
    }
}
