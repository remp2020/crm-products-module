<?php

namespace Crm\ProductsModule\Events;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\DataRow;
use Crm\PaymentsModule\DataProvider\PaymentInvoiceProviderManager;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\ProductsModule\PaymentItem\PaymentItemHelper;
use Crm\ProductsModule\Repository\ProductPropertiesRepository;
use Crm\UsersModule\Events\NotificationEvent;
use League\Event\AbstractListener;
use League\Event\Emitter;
use League\Event\EventInterface;
use Nette\Database\Table\ActiveRow;
use Tracy\Debugger;

class PaymentStatusChangeNotifyHandler extends AbstractListener
{
    private $paymentInvoiceProviderManager;

    private $productPropertiesRepository;

    private $paymentItemHelper;

    private $emitter;

    private $applicationConfig;

    public function __construct(
        PaymentInvoiceProviderManager $paymentInvoiceProviderManager,
        ProductPropertiesRepository $productPropertiesRepository,
        PaymentItemHelper $paymentItemHelper,
        Emitter $emitter,
        ApplicationConfig $applicationConfig
    ) {
        $this->paymentInvoiceProviderManager = $paymentInvoiceProviderManager;
        $this->productPropertiesRepository = $productPropertiesRepository;
        $this->paymentItemHelper = $paymentItemHelper;
        $this->emitter = $emitter;
        $this->applicationConfig = $applicationConfig;
    }

    public function handle(EventInterface $event)
    {
        if (!($event instanceof PaymentChangeStatusEvent)) {
            throw new \Exception('Invalid type of event received, PaymentChangeStatusEvent expected: ' . get_class($event));
        }

        /** @var ActiveRow $payment */
        $payment = $event->getPayment();
        $order = $payment->related('orders')->fetch();

        if (!$order) {
            // this is not order payment
            return;
        }

        $params = [
            'payment' => $payment->toArray(),
            'order' => $order->toArray(),
        ];
        $attachments = [];
        $templateCode = 'new_order';
        $sendHelpdeskEmail = false;

        switch ($payment->status) {
            case PaymentsRepository::STATUS_PAID:
                if ($order->billing_address_id !== null) {
                    $invoices = $this->paymentInvoiceProviderManager->getAttachments($payment);
                    if (!empty($invoices)) {
                        $attachments = array_merge($attachments, $invoices);
                    }
                }

                $this->attachAttachments($payment, $order, $templateCode, $sendHelpdeskEmail, $attachments);
                $this->sendNotifications($payment, $templateCode, $params, $sendHelpdeskEmail, $attachments);
                break;

            case PaymentsRepository::STATUS_PREPAID:
                $this->attachAttachments($payment, $order, $templateCode, $sendHelpdeskEmail, $attachments);
                $this->sendNotifications($payment, $templateCode, $params, $sendHelpdeskEmail, $attachments);
                break;
        }
    }

    /**
     * Checks products for coupon property and attaches coupon attachment to provided &$attachments array
     *
     * @param ActiveRow $payment
     * @param ActiveRow $order
     * @param string $templateCode Reference to templateCode; updated if coupon product found.
     * @param array $attachments Reference to array of attachments; updated with coupon if coupon product found.
     */
    private function attachAttachments(ActiveRow $payment, ActiveRow $order, &$templateCode, &$sendHelpdeskEmail, &$attachments)
    {
        $this->paymentItemHelper->unBundleProducts($payment, function ($product) use ($payment, $order, &$templateCode, &$sendHelpdeskEmail, &$attachments) {
            // TODO: following should be part of module responsible for product_template 'coupon'
            if ($product->product_template_id && $product->product_template->name == 'coupon') {
                $templateCode = 'new-order-coupon';

                if (!$this->productPropertiesRepository->getPropertyByCode($product, 'subscription_type_code')) {
                    $sendHelpdeskEmail = true;
                }

                $attachmentName = $this->productPropertiesRepository->getPropertyByCode($product, 'attachment');

                try {
                    $attachment = file_get_contents($attachmentName);
                    if ($attachment !== false) {
                        $attachments[] = [
                            'file' => 'coupon.pdf',
                            'content' => $attachment,
                            'mime_type' => 'application/pdf',
                        ];
                    } else {
                        Debugger::log("Attachment {$attachmentName} not loaded. Payment ID: {$payment->id} Order ID: {$order->id}");
                    }
                } catch (\Exception $e) {
                    Debugger::log("Attachment {$attachmentName} load failed. Payment ID: {$payment->id} Order ID: {$order->id}. Exception: {$e->getCode()} - {$e->getMessage()}");
                }
            }
        });
    }

    private function sendNotifications(ActiveRow $payment, string $templateCode, array $params, $sendHelpdeskEmail, array $attachments)
    {
        $this->emitter->emit(new NotificationEvent(
            $this->emitter,
            $payment->user,
            $templateCode,
            $params,
            null,
            $attachments
        ));

        if ($sendHelpdeskEmail) {
            $userRow = new DataRow([
                'email' => $this->applicationConfig->get('contact_email'),
            ]);
            $this->emitter->emit(new NotificationEvent(
                $this->emitter,
                $userRow,
                'notification-new-coupon',
                $params,
                null
            ));
        }
    }
}
