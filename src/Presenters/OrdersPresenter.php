<?php

namespace Crm\ProductsModule\Presenters;

use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\Ebook\EbookProvider;
use Crm\ProductsModule\PaymentItem\PaymentItemHelper;
use Crm\ProductsModule\Repository\OrdersRepository;

class OrdersPresenter extends FrontendPresenter
{
    private $ordersRepository;
    private $paymentsRepository;
    private $paymentItemHelper;
    private $ebookProvider;

    public function __construct(
        OrdersRepository $ordersRepository,
        PaymentsRepository $paymentsRepository,
        PaymentItemHelper $paymentItemHelper,
        EbookProvider $ebookProvider
    ) {
        parent::__construct();

        $this->ordersRepository = $ordersRepository;
        $this->paymentsRepository = $paymentsRepository;
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
            if ($order->payment->status != PaymentsRepository::STATUS_PAID) {
                continue;
            }

            $address = $order->ref('addresses', 'shipping_address_id');
            if (!$address) {
                $address = $order->ref('addresses', 'licence_address_id');
            }
            if (!$address) {
                $address = $order->ref('addresses', 'billing_address_id');
            }

            if ($address) {
                $this->paymentItemHelper->unBundleProducts($order->payment, function ($product) use ($address, &$ebooks) {
                    if (!isset($ebooks[$product->id])) {
                        $user = $this->usersRepository->find($this->user->getIdentity()->getId());
                        $links = $this->ebookProvider->getDownloadLinks($product, $user, $address);
                        if (!empty($links)) {
                            $ebooks[$product->id] = [
                                'product' => $product,
                                'links' => $links,
                            ];
                        }
                    }
                });
            }
        }

        $fileFormatMap = $this->ebookProvider->getFileTypes();

        $this->template->fileFormatMap = $fileFormatMap;
        $this->template->ebooks = $ebooks;
        $this->template->shopHost = $this->applicationConfig->get('shop_host');
    }
}
