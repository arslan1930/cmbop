<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdfGenerator;

class RefundReceiptMail extends PlatformMailable
{
    public function __construct(public Invoice $refund)
    {
        parent::__construct();
        $this->notificationType = 'refund_receipt';
        $this->recipientUser = $refund->user;
        $this->dedupeKey = 'refund_receipt:'.$refund->id;
    }

    public function build()
    {
        $symbol = config('billing.currency_symbol', '€');
        $reason = data_get($this->refund->meta, 'refund_reason')
            ?: $this->refund->notes
            ?: 'Refund processed.';

        $mail = $this->subject('Refund Receipt – '.$this->refund->invoice_number)
            ->markdown('emails.billing.refund-receipt', [
                'refund' => $this->refund,
                'order' => $this->refund->order,
                'user' => $this->refund->user,
                'originalInvoice' => $this->refund->parentInvoice,
                'reason' => $reason,
                'symbol' => $symbol,
                'downloadUrl' => route('advertiser.billing.download', $this->refund),
                'ordersUrl' => route('advertiser.orders'),
            ]);

        $path = app(InvoicePdfGenerator::class)->absolutePath($this->refund);
        if ($path && is_readable($path)) {
            $mail->attach($path, [
                'as' => $this->refund->invoice_number.'.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
