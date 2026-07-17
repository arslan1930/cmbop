<?php

namespace App\Services\Billing;

use App\Mail\PaymentFailedMail;
use App\Mail\PaymentPendingMail;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\RefundReceiptMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Orchestrates invoice/receipt generation and billing emails.
 * Invoked from Order model events — does not touch payment gateway code.
 */
class BillingDocumentService
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private InvoicePdfGenerator $pdfs,
        private BillingEventLogger $events,
    ) {
    }

    /**
     * Successful payment → tax invoice + payment receipt + email with PDF.
     * Idempotent: one tax invoice per order.
     */
    public function handlePaymentPaid(Order $order): ?Invoice
    {
        $order->loadMissing(['user', 'items']);

        if (!$order->user) {
            return null;
        }

        $existing = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            $invoice = $this->createDocument($order, Invoice::TYPE_TAX_INVOICE, Invoice::STATUS_PAID, [
                'paid_at' => $order->paid_at ?: now(),
            ]);
            $this->pdfs->generateAndStore($invoice);
            $this->events->log('invoice_generated', $invoice, $order);

            $receipt = $this->createDocument($order, Invoice::TYPE_PAYMENT_RECEIPT, Invoice::STATUS_PAID, [
                'paid_at' => $order->paid_at ?: now(),
                'parent_invoice_id' => $invoice->id,
            ]);
            $this->pdfs->generateAndStore($receipt);
            $this->events->log('payment_receipt_generated', $receipt, $order);

            $this->emailPaymentSuccess($invoice->fresh(['user', 'order.items']), $receipt->fresh());

            return $invoice->fresh();
        } catch (\Throwable $e) {
            Log::error('Failed to generate paid invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            $this->events->log('invoice_generation_failed', null, $order, $order->user_id, [
                'error' => $e->getMessage(),
                'type' => Invoice::TYPE_TAX_INVOICE,
            ]);

            return null;
        }
    }

    /**
     * Failed payment → failure report (no tax invoice) + email.
     */
    public function handlePaymentFailed(Order $order, ?string $reason = null): ?Invoice
    {
        $order->loadMissing(['user', 'items']);

        if (!$order->user) {
            return null;
        }

        $existing = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_PAYMENT_FAILURE)
            ->latest('id')
            ->first();

        // Allow multiple failure attempts; skip only if created in last 2 minutes (dedupe).
        if ($existing && $existing->created_at?->gt(now()->subMinutes(2))) {
            return $existing;
        }

        try {
            $doc = $this->createDocument($order, Invoice::TYPE_PAYMENT_FAILURE, Invoice::STATUS_FAILED, [
                'notes' => $reason ?: 'Payment verification failed.',
                'meta' => [
                    'failure_reason' => $reason ?: 'Payment verification failed.',
                    'attempted_at' => now()->toIso8601String(),
                ],
            ]);
            $this->pdfs->generateAndStore($doc);
            $this->events->log('payment_failure_recorded', $doc, $order, null, [
                'reason' => $reason,
            ]);

            $this->emailPaymentFailed($doc->fresh(['user', 'order']));

            return $doc->fresh();
        } catch (\Throwable $e) {
            Log::error('Failed to generate payment failure document', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            $this->events->log('invoice_generation_failed', null, $order, $order->user_id, [
                'error' => $e->getMessage(),
                'type' => Invoice::TYPE_PAYMENT_FAILURE,
            ]);

            return null;
        }
    }

    /**
     * Pending payment → status email only (no tax invoice / no PDF).
     */
    public function handlePaymentPending(Order $order): void
    {
        $order->loadMissing(['user', 'items']);

        if (!$order->user) {
            return;
        }

        // Dedupe via billing events (no invoice row for pending).
        $recent = \App\Models\BillingEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'payment_pending_emailed')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();

        if ($recent) {
            return;
        }

        try {
            $this->emailPaymentPending($order);
            $this->events->log('payment_pending_notified', null, $order);
        } catch (\Throwable $e) {
            Log::error('Failed to send pending payment notice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Refunded payment → refund receipt PDF + email.
     */
    public function handlePaymentRefunded(Order $order, ?string $reason = null): ?Invoice
    {
        $order->loadMissing(['user', 'items']);

        if (!$order->user) {
            return null;
        }

        $existing = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_REFUND_RECEIPT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            $original = Invoice::query()
                ->where('order_id', $order->id)
                ->where('type', Invoice::TYPE_TAX_INVOICE)
                ->latest('id')
                ->first();

            $refund = $this->createDocument($order, Invoice::TYPE_REFUND_RECEIPT, Invoice::STATUS_REFUNDED, [
                'parent_invoice_id' => $original?->id,
                'notes' => $reason ?: 'Payment refunded.',
                'meta' => [
                    'refund_reason' => $reason ?: 'Payment refunded.',
                    'original_invoice' => $original?->invoice_number,
                    'refunded_at' => now()->toIso8601String(),
                ],
            ]);
            $this->pdfs->generateAndStore($refund);
            $this->events->log('refund_receipt_generated', $refund, $order);

            if ($original && $original->status !== Invoice::STATUS_CANCELLED) {
                $original->update(['status' => Invoice::STATUS_REFUNDED]);
            }

            $this->emailRefund($refund->fresh(['user', 'order', 'parentInvoice']));

            return $refund->fresh();
        } catch (\Throwable $e) {
            Log::error('Failed to generate refund receipt', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            $this->events->log('invoice_generation_failed', null, $order, $order->user_id, [
                'error' => $e->getMessage(),
                'type' => Invoice::TYPE_REFUND_RECEIPT,
            ]);

            return null;
        }
    }

    /**
     * Admin / manual generation of a tax invoice for an order.
     */
    public function generateManually(Order $order, ?User $actor = null): Invoice
    {
        $order->loadMissing(['user', 'items']);

        $invoice = $this->createDocument(
            $order,
            Invoice::TYPE_TAX_INVOICE,
            $order->payment_status === 'paid' ? Invoice::STATUS_PAID : Invoice::STATUS_ISSUED,
            [
                'paid_at' => $order->paid_at,
                'meta' => [
                    'generated_manually' => true,
                    'generated_by' => $actor?->id,
                ],
            ]
        );
        $this->pdfs->generateAndStore($invoice);
        $this->events->log('invoice_generated_manually', $invoice, $order, $actor?->id);

        return $invoice->fresh();
    }

    public function resendInvoiceEmail(Invoice $invoice): void
    {
        $invoice->loadMissing(['user', 'order.items']);

        if ($invoice->type === Invoice::TYPE_TAX_INVOICE) {
            $receipt = Invoice::query()
                ->where('parent_invoice_id', $invoice->id)
                ->where('type', Invoice::TYPE_PAYMENT_RECEIPT)
                ->latest('id')
                ->first();
            $this->emailPaymentSuccess($invoice, $receipt);
        } elseif ($invoice->type === Invoice::TYPE_REFUND_RECEIPT) {
            $this->emailRefund($invoice);
        } elseif ($invoice->type === Invoice::TYPE_PAYMENT_FAILURE) {
            $this->emailPaymentFailed($invoice);
        }

        $this->events->log('invoice_resent', $invoice);
    }

    public function cancelInvoice(Invoice $invoice, User $admin, ?string $reason = null): Invoice
    {
        if ($invoice->isCancelled()) {
            return $invoice;
        }

        $invoice->update([
            'status' => Invoice::STATUS_CANCELLED,
            'cancelled_by' => $admin->id,
            'cancelled_at' => now(),
            'cancel_reason' => $reason ?: 'Cancelled by administrator.',
        ]);

        // PDFs are retained permanently; only status changes.
        $this->events->log('invoice_cancelled', $invoice, $invoice->order, $admin->id, [
            'reason' => $reason,
        ]);

        return $invoice->fresh();
    }

    public function recordDownload(Invoice $invoice): void
    {
        $invoice->increment('download_count');
        $this->events->log('invoice_downloaded', $invoice);
    }

    protected function createDocument(Order $order, string $type, string $status, array $extra = []): Invoice
    {
        $user = $order->user;
        $taxEnabled = (bool) config('billing.tax.enabled', false);
        $taxRate = $taxEnabled ? (float) config('billing.tax.rate', 0) : 0;
        $taxLabel = config('billing.tax.label', 'VAT');

        $subtotal = (float) ($order->subtotal ?? $order->total_amount ?? 0);
        $taxAmount = $taxEnabled
            ? round($subtotal * ($taxRate / 100), 2)
            : (float) ($order->tax ?? 0);
        $discount = (float) data_get($extra, 'discount_amount', 0);
        $total = (float) ($order->total_amount ?? ($subtotal + $taxAmount - $discount));

        $lineItems = $order->items->map(function ($item) {
            $qty = 1;
            $unit = (float) $item->price;
            $service = 'Guest post / sponsored placement';
            if (!empty($item->sensitive_type)) {
                $service .= ' (+ '.$item->sensitive_type.')';
            }

            return [
                'description' => $service,
                'publisher_website' => $item->site_name ?: $item->site_url,
                'site_url' => $item->site_url,
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => $unit * $qty,
            ];
        })->values()->all();

        $transactionId = $order->stripe_payment_intent_id
            ?: $order->stripe_session_id
            ?: $order->reference_code
            ?: $order->order_number;

        $payload = array_merge([
            'invoice_number' => $this->numbers->next(),
            'type' => $type,
            'status' => $status,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'reference_code' => $order->reference_code,
            'order_number' => $order->order_number,
            'currency' => config('billing.currency', 'EUR'),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discount,
            'total_amount' => $total,
            'tax_rate' => $taxRate,
            'tax_label' => $taxEnabled ? $taxLabel : null,
            'coupon_code' => data_get($extra, 'coupon_code'),
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'transaction_id' => $transactionId,
            'invoice_date' => now(),
            'due_date' => $status === Invoice::STATUS_PENDING ? now()->addDays(7) : null,
            'paid_at' => data_get($extra, 'paid_at'),
            'customer_name' => $user->billing_name ?? $user->name,
            'customer_email' => $user->email,
            'billing_snapshot' => [
                'name' => $user->billing_name ?? $user->name,
                'email' => $user->email,
                'company' => $user->company_name ?? null,
                'address' => $user->address ?? null,
                'city' => $user->city ?? null,
                'state' => $user->state ?? null,
                'postal_code' => $user->postal_code ?? null,
                'country' => $user->country ?? null,
                'vat_number' => $user->vat_number ?? null,
            ],
            'line_items' => $lineItems,
            'pdf_disk' => config('billing.storage.disk', 'local'),
            'parent_invoice_id' => data_get($extra, 'parent_invoice_id'),
            'notes' => data_get($extra, 'notes'),
            'meta' => data_get($extra, 'meta'),
        ], collect($extra)->only([
            'parent_invoice_id', 'notes', 'meta', 'paid_at', 'coupon_code', 'discount_amount',
        ])->all());

        return Invoice::create($payload);
    }

    protected function emailPaymentSuccess(Invoice $invoice, ?Invoice $receipt = null): void
    {
        if (!$invoice->user?->email) {
            return;
        }

        Mail::to($invoice->user->email)->send(
            new PaymentSuccessfulInvoiceMail($invoice, $receipt)
        );

        $invoice->update([
            'emailed_at' => now(),
            'email_count' => ((int) $invoice->email_count) + 1,
        ]);
        $this->events->log('invoice_emailed', $invoice);
    }

    protected function emailPaymentFailed(Invoice $doc): void
    {
        if (!$doc->user?->email) {
            return;
        }

        Mail::to($doc->user->email)->send(new PaymentFailedMail($doc));
        $doc->update([
            'emailed_at' => now(),
            'email_count' => ((int) $doc->email_count) + 1,
        ]);
        $this->events->log('payment_failure_emailed', $doc);
    }

    protected function emailPaymentPending(Order $order): void
    {
        if (!$order->user?->email) {
            return;
        }

        Mail::to($order->user->email)->send(new PaymentPendingMail($order));
        $this->events->log('payment_pending_emailed', null, $order);
    }

    protected function emailRefund(Invoice $refund): void
    {
        if (!$refund->user?->email) {
            return;
        }

        Mail::to($refund->user->email)->send(new RefundReceiptMail($refund));
        $refund->update([
            'emailed_at' => now(),
            'email_count' => ((int) $refund->email_count) + 1,
        ]);
        $this->events->log('refund_receipt_emailed', $refund);
    }
}
