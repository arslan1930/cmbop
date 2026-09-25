<?php

namespace App\Services\Billing;

use App\Models\DepositRequest;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * Issues PDF receipts for wallet top-ups.
 *
 * A deposit is money taken on account, not a supply of services, so these
 * documents carry no tax line and are numbered in their own RCT- series to keep
 * them out of the sales invoice sequence.
 */
class DepositReceiptService
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private InvoicePdfGenerator $pdfs,
        private BillingEventLogger $events,
    ) {}

    /**
     * The receipt for a settled deposit, creating it on first request.
     */
    public function issue(DepositRequest $deposit): ?Invoice
    {
        if (! $this->isSettled($deposit)) {
            return null;
        }

        $deposit->loadMissing('user');

        if (! $deposit->user) {
            return null;
        }

        if ($existing = $this->find($deposit)) {
            return $existing;
        }

        try {
            $receipt = Invoice::create($this->payload($deposit));
            $this->pdfs->generateAndStore($receipt);
            $this->events->log('deposit_receipt_generated', $receipt, null, $deposit->user_id, [
                'deposit_request_id' => $deposit->id,
                'reference_code' => $deposit->reference_code,
            ]);

            return $receipt->fresh();
        } catch (\Throwable $e) {
            Log::error('Failed to generate deposit receipt', [
                'deposit_request_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);
            $this->events->log('deposit_receipt_failed', null, null, $deposit->user_id, [
                'deposit_request_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * An already-issued receipt for this deposit, if there is one.
     */
    public function find(DepositRequest $deposit): ?Invoice
    {
        return Invoice::query()
            ->where('user_id', $deposit->user_id)
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where('reference_code', $deposit->reference_code)
            ->first();
    }

    /**
     * Only a deposit whose funds actually landed in the wallet earns a receipt.
     */
    public function isSettled(DepositRequest $deposit): bool
    {
        return in_array($deposit->status, ['approved', 'completed'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DepositRequest $deposit): array
    {
        $user = $deposit->user;
        $amount = round((float) $deposit->amount, 2);
        $paidAt = $deposit->paid_at ?: $deposit->approved_at ?: now();

        return [
            'invoice_number' => $this->numbers->nextReceipt(),
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $deposit->user_id,
            'order_id' => null,
            'reference_code' => $deposit->reference_code,
            'currency' => config('billing.currency', 'EUR'),
            'subtotal' => $amount,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $amount,
            'tax_rate' => 0,
            'tax_label' => null,
            'payment_method' => $deposit->payment_method,
            'payment_status' => 'paid',
            'transaction_id' => $deposit->stripe_payment_intent_id
                ?: $deposit->stripe_session_id
                ?: $deposit->reference_code,
            'invoice_date' => $paidAt,
            'paid_at' => $paidAt,
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
            'line_items' => [[
                'description' => 'Wallet top-up',
                'reference' => $deposit->reference_code,
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ]],
            'pdf_disk' => config('billing.storage.disk', 'local'),
            'notes' => config('billing.deposit_receipt_note'),
            'meta' => [
                'deposit_request_id' => $deposit->id,
                'document' => 'deposit_receipt',
                'charge_currency' => $deposit->charge_currency,
                'charge_amount' => $deposit->charge_amount,
                'wallet_euros' => $amount,
            ],
        ];
    }
}
