<?php

namespace App\Services\Billing;

use App\Mail\DepositApproved;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentPendingMail;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\RefundReceiptMail;
use App\Mail\WithdrawalStatusUpdated;
use App\Models\BillingEvent;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\InAppNotificationService;
use App\Support\BillingCustomerMailSuppressor;
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
    ) {}

    /**
     * Successful payment → tax invoice + payment receipt + email with PDF.
     * Idempotent: one tax invoice per order.
     */
    public function handlePaymentPaid(Order $order): ?Invoice
    {
        $order->loadMissing(['user', 'items']);

        if (! $order->user) {
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

        if (! $order->user) {
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

        if (! $order->user) {
            return;
        }

        // Dedupe via billing events (no invoice row for pending).
        $recent = BillingEvent::query()
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

        if (! $order->user) {
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
     * Idempotent: returns the existing non-cancelled tax invoice when present.
     */
    public function generateManually(Order $order, ?User $actor = null): Invoice
    {
        $order->loadMissing(['user', 'items']);

        if (! $order->user) {
            throw new \RuntimeException('Cannot generate an invoice: the order has no customer account.');
        }

        $existing = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->latest('id')
            ->first();

        if ($existing) {
            if (! $existing->hasPdf() || ! $existing->pdfExists()) {
                $this->pdfs->generateAndStore($existing);
                $existing->refresh();
            }

            $this->events->log('invoice_generate_manual_reuse', $existing, $order, $actor?->id);

            return $existing;
        }

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

    /**
     * @return array{ok: bool, message: string}
     */
    public function resendInvoiceEmail(Invoice $invoice): array
    {
        if ($invoice->isWithdrawalPayout()) {
            $invoice = app(WithdrawalPayoutStatementService::class)->reconcileInvoice($invoice);
            $invoice->unsetRelation('user');
        }

        $invoice->loadMissing(['user', 'order.items', 'parentInvoice']);

        if ($invoice->isCancelled()) {
            return [
                'ok' => false,
                'message' => 'Cancelled documents cannot be resent.',
            ];
        }

        $email = $invoice->user?->email ?: $invoice->customer_email;
        if (! filled($email)) {
            return [
                'ok' => false,
                'message' => 'This document has no customer email.',
            ];
        }

        $result = match ($invoice->type) {
            Invoice::TYPE_TAX_INVOICE => $this->resendTaxInvoiceEmail($invoice),
            Invoice::TYPE_PAYMENT_RECEIPT => $this->resendPaymentReceiptEmail($invoice),
            Invoice::TYPE_REFUND_RECEIPT => $this->emailRefund($invoice),
            Invoice::TYPE_PAYMENT_FAILURE => $this->emailPaymentFailed($invoice),
            Invoice::TYPE_DEPOSIT_RECEIPT => $this->emailDepositReceipt($invoice),
            Invoice::TYPE_WITHDRAWAL_PAYOUT => $this->emailPayoutStatement($invoice),
            default => 'This document type cannot be emailed.',
        };

        if ($result === true) {
            $this->events->log('invoice_resent', $invoice);

            return [
                'ok' => true,
                'message' => 'Invoice email resent to '.$email,
            ];
        }

        return [
            'ok' => false,
            'message' => is_string($result)
                ? $result
                : 'Could not send the email to '.$email.'.',
        ];
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

    /**
     * Staff audit download — do not increment the customer download counter.
     */
    public function recordAdminDownload(Invoice $invoice, ?User $actor = null): void
    {
        $this->events->log('invoice_downloaded_by_admin', $invoice, $invoice->order, $actor?->id);
    }

    /**
     * Ops: create tax invoices for paid orders that are missing one.
     *
     * @return array{created: int, skipped: int, failed: int, invoice_ids: list<int>}
     */
    public function backfillMissingTaxInvoices(int $limit = 50): array
    {
        $orders = Order::query()
            ->with(['user', 'items'])
            ->where('payment_status', 'paid')
            ->whereDoesntHave('invoices', function ($q) {
                $q->where('type', Invoice::TYPE_TAX_INVOICE)
                    ->where('status', '!=', Invoice::STATUS_CANCELLED);
            })
            ->orderBy('id')
            ->limit(max(1, min(200, $limit)))
            ->get();

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $ids = [];

        foreach ($orders as $order) {
            if (! $order->user) {
                $skipped++;

                continue;
            }

            try {
                $invoice = $this->generateManually($order);
                $created++;
                $ids[] = (int) $invoice->id;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Backfill tax invoice failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('created', 'skipped', 'failed') + ['invoice_ids' => $ids];
    }

    /**
     * Ops: regenerate a missing or corrupt PDF for an existing document.
     */
    public function regeneratePdf(Invoice $invoice): Invoice
    {
        if ($invoice->isWithdrawalPayout()) {
            $statements = app(WithdrawalPayoutStatementService::class);
            $invoice = $statements->reconcileInvoice($invoice);
            $invoice = $statements->normalizeLegacyFeeLineItems($invoice);
        }

        $this->pdfs->generateAndStore($invoice);
        $this->events->log('invoice_pdf_regenerated', $invoice->fresh());

        return $invoice->fresh();
    }

    /**
     * Ops: regenerate PDFs that are missing on disk.
     *
     * @return array{regenerated: int, failed: int}
     */
    public function regenerateMissingPdfs(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $scan = max(400, $limit * 8);

        $docs = Invoice::query()
            ->orderByDesc('id')
            ->limit($scan)
            ->get()
            ->filter(fn (Invoice $inv) => ! $inv->pdfExists())
            ->take($limit)
            ->values();

        $regenerated = 0;
        $failed = 0;

        foreach ($docs as $doc) {
            try {
                $this->regeneratePdf($doc);
                $regenerated++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Regenerate invoice PDF failed', [
                    'invoice_id' => $doc->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('regenerated', 'failed');
    }

    protected function createDocument(Order $order, string $type, string $status, array $extra = []): Invoice
    {
        $user = $order->user;
        $taxEnabled = (bool) config('billing.tax.enabled', false);
        $taxRate = $taxEnabled ? (float) config('billing.tax.rate', 0) : 0;
        $taxLabel = config('billing.tax.label', 'VAT');

        $subtotal = round((float) ($order->subtotal ?? $order->total_amount ?? 0), 2);
        $discount = round((float) data_get($extra, 'discount_amount', 0), 2);

        // Defensive tax math: when VAT is enabled, total is derived from
        // subtotal + tax − discount. While VAT stays off, prefer the stored
        // order total so wallet/checkout amounts remain authoritative.
        if ($taxEnabled && $taxRate > 0) {
            $taxAmount = round($subtotal * ($taxRate / 100), 2);
            $total = round($subtotal + $taxAmount - $discount, 2);
        } else {
            $taxAmount = round((float) ($order->tax ?? 0), 2);
            $total = round((float) ($order->total_amount ?? ($subtotal + $taxAmount - $discount)), 2);
        }

        $lineItems = $order->items->map(function ($item) {
            $qty = 1;
            $unit = (float) $item->price;
            $service = 'Guest post / sponsored placement';
            if (! empty($item->sensitive_type)) {
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
            'invoice_number' => $this->numbers->nextForType($type),
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
            'tax_label' => ($taxEnabled && $taxRate > 0) ? $taxLabel : null,
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

    protected function customerEmailSuppressed(): bool
    {
        return app(BillingCustomerMailSuppressor::class)->suppressed();
    }

    protected function resendTaxInvoiceEmail(Invoice $invoice): bool
    {
        $receipt = Invoice::query()
            ->where('parent_invoice_id', $invoice->id)
            ->where('type', Invoice::TYPE_PAYMENT_RECEIPT)
            ->latest('id')
            ->first();

        return $this->emailPaymentSuccess($invoice, $receipt);
    }

    protected function resendPaymentReceiptEmail(Invoice $invoice): bool
    {
        $parent = $invoice->parentInvoice
            ?: Invoice::query()
                ->where('order_id', $invoice->order_id)
                ->where('type', Invoice::TYPE_TAX_INVOICE)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->latest('id')
                ->first();

        if ($parent) {
            return $this->emailPaymentSuccess($parent->loadMissing(['user', 'order.items']), $invoice);
        }

        return $this->emailPaymentSuccess($invoice, null);
    }

    protected function emailPaymentSuccess(Invoice $invoice, ?Invoice $receipt = null): bool
    {
        if ($this->customerEmailSuppressed() || ! $invoice->user?->email) {
            return false;
        }

        Mail::to($invoice->user->email)->send(
            new PaymentSuccessfulInvoiceMail($invoice, $receipt)
        );

        $invoice->update([
            'emailed_at' => now(),
            'email_count' => ((int) $invoice->email_count) + 1,
        ]);
        $this->events->log('invoice_emailed', $invoice);

        return true;
    }

    protected function emailPaymentFailed(Invoice $doc): bool
    {
        if ($this->customerEmailSuppressed() || ! $doc->user?->email) {
            return false;
        }

        Mail::to($doc->user->email)->send(new PaymentFailedMail($doc));
        $doc->update([
            'emailed_at' => now(),
            'email_count' => ((int) $doc->email_count) + 1,
        ]);
        $this->events->log('payment_failure_emailed', $doc);

        return true;
    }

    protected function emailPaymentPending(Order $order): void
    {
        if ($this->customerEmailSuppressed() || ! $order->user?->email) {
            return;
        }

        Mail::to($order->user->email)->send(new PaymentPendingMail($order));
        $this->events->log('payment_pending_emailed', null, $order);

        try {
            app(InAppNotificationService::class)->notifyPaymentPending($order);
        } catch (\Throwable $e) {
            Log::warning('Payment pending bell failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function emailRefund(Invoice $refund): bool
    {
        if ($this->customerEmailSuppressed() || ! $refund->user?->email) {
            return false;
        }

        Mail::to($refund->user->email)->send(new RefundReceiptMail($refund));
        $refund->update([
            'emailed_at' => now(),
            'email_count' => ((int) $refund->email_count) + 1,
        ]);
        $this->events->log('refund_receipt_emailed', $refund);

        return true;
    }

    /**
     * Resend deposit receipt email (admin panel).
     */
    protected function emailDepositReceipt(Invoice $receipt): bool|string
    {
        if (! $receipt->user?->email) {
            return 'This document has no customer email.';
        }

        $depositId = data_get($receipt->meta, 'deposit_request_id');
        $deposit = $depositId
            ? DepositRequest::query()->with('user')->find($depositId)
            : null;

        if (! $deposit && $receipt->reference_code) {
            $deposit = DepositRequest::query()
                ->with('user')
                ->where('user_id', $receipt->user_id)
                ->where('reference_code', $receipt->reference_code)
                ->first();
        }

        if (! $deposit) {
            Log::warning('Cannot resend deposit receipt — deposit request not found', [
                'invoice_id' => $receipt->id,
            ]);

            return 'Cannot resend this deposit receipt — the deposit request was not found.';
        }

        $mail = new DepositApproved($deposit);
        $mail->dedupeKey = 'deposit_receipt_resent:'.$receipt->id.':'.now()->timestamp;
        Mail::to($receipt->user->email)->send($mail);
        $receipt->update([
            'emailed_at' => now(),
            'email_count' => ((int) $receipt->email_count) + 1,
        ]);
        $this->events->log('deposit_receipt_emailed', $receipt);

        return true;
    }

    protected function emailPayoutStatement(Invoice $statement): bool|string
    {
        $withdrawal = $this->findWithdrawalForStatement($statement);
        if (! $withdrawal) {
            Log::warning('Cannot resend payout statement — withdrawal not found', [
                'invoice_id' => $statement->id,
            ]);

            return 'Cannot resend this payout statement — the withdrawal was not found.';
        }

        $statement = app(WithdrawalPayoutStatementService::class)->reconcileOwner($statement, $withdrawal);
        $statement->unsetRelation('user');

        $recipient = $withdrawal->user;
        $email = $recipient?->email ?: $statement->customer_email;
        if (! filled($email)) {
            return 'This document has no customer email.';
        }

        $mail = new WithdrawalStatusUpdated(
            $withdrawal,
            (string) $withdrawal->status,
            (string) $withdrawal->status,
            'Payout statement resent.'
        );
        $mail->dedupeKey = 'withdrawal_payout_resent:'.$statement->id.':'.now()->timestamp;
        $mail->recipientUser = $recipient;

        Mail::to($email)->send($mail);
        $statement->update([
            'emailed_at' => now(),
            'email_count' => ((int) $statement->email_count) + 1,
        ]);
        $this->events->log('payout_statement_emailed', $statement);

        return true;
    }

    protected function findWithdrawalForStatement(Invoice $statement): ?Withdrawal
    {
        $id = (int) $statement->withdrawalId();
        if ($id <= 0) {
            return null;
        }

        return Withdrawal::query()->with('user')->find($id);
    }
}
