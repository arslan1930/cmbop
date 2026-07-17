<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdfGenerator;

class PaymentSuccessfulInvoiceMail extends PlatformMailable
{
    public function __construct(
        public Invoice $invoice,
        public ?Invoice $receipt = null,
    ) {
        parent::__construct();
        $this->notificationType = 'payment_successful_invoice';
        $this->recipientUser = $invoice->user;
        $this->dedupeKey = 'payment_successful_invoice:'.$invoice->id;
    }

    public function build()
    {
        $order = $this->invoice->order;
        $user = $this->invoice->user;
        $symbol = config('billing.currency_symbol', '€');

        $mail = $this->subject('Payment Successful – Invoice Attached')
            ->markdown('emails.billing.payment-successful', [
                'invoice' => $this->invoice,
                'receipt' => $this->receipt,
                'order' => $order,
                'user' => $user,
                'symbol' => $symbol,
                'viewOrderUrl' => route('advertiser.orders'),
                'downloadInvoiceUrl' => route('advertiser.billing.download', $this->invoice),
                'dashboardUrl' => route('advertiser.dashboard'),
            ]);

        $path = app(InvoicePdfGenerator::class)->absolutePath($this->invoice);
        if ($path && is_readable($path)) {
            $mail->attach($path, [
                'as' => $this->invoice->invoice_number.'.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        if ($this->receipt) {
            $receiptPath = app(InvoicePdfGenerator::class)->absolutePath($this->receipt);
            if ($receiptPath && is_readable($receiptPath)) {
                $mail->attach($receiptPath, [
                    'as' => $this->receipt->invoice_number.'-receipt.pdf',
                    'mime' => 'application/pdf',
                ]);
            }
        }

        return $mail;
    }
}
