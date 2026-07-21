<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdfGenerator;

class PaymentFailedMail extends PlatformMailable
{
    public function __construct(public Invoice $document)
    {
        parent::__construct();
        $this->notificationType = 'payment_failed';
        $this->recipientUser = $document->user;
        $this->dedupeKey = 'payment_failed:'.$document->id;
    }

    public function build()
    {
        $order = $this->document->order;
        $reason = data_get($this->document->meta, 'failure_reason')
            ?: $this->document->notes
            ?: 'Payment verification failed.';
        $symbol = config('billing.currency_symbol', '€');

        $retryUrl = route('advertiser.orders', ['payment_status' => 'failed']);
        if ($order && $order->payment_method === 'card'
            && $order->payment_status === 'failed'
            && $order->status === 'pending') {
            $retryUrl = route('advertiser.orders', [
                'payment_status' => 'failed',
                'focus' => 'order',
                'order' => $order->id,
            ]);
        }

        $mail = $this->subject('Payment Failed')
            ->markdown('emails.billing.payment-failed', [
                'document' => $this->document,
                'order' => $order,
                'user' => $this->document->user,
                'reason' => $reason,
                'symbol' => $symbol,
                'retryUrl' => $retryUrl,
            ]);

        $path = app(InvoicePdfGenerator::class)->absolutePath($this->document);
        if ($path && is_readable($path)) {
            $mail->attach($path, [
                'as' => $this->document->invoice_number.'-payment-failed.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
