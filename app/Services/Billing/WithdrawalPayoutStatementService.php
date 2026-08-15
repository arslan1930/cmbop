<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues a non-tax payout statement PDF when a withdrawal is marked paid.
 *
 * Wallet funds were already debited on request; this document confirms the
 * external transfer. It uses the PAY- series so it never consumes INV numbers.
 */
class WithdrawalPayoutStatementService
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private InvoicePdfGenerator $pdfs,
        private BillingEventLogger $events,
    ) {}

    public function issue(Withdrawal $withdrawal): ?Invoice
    {
        if ($withdrawal->status !== 'completed') {
            return null;
        }

        $withdrawalId = (int) $withdrawal->id;
        if ($withdrawalId <= 0) {
            return null;
        }

        try {
            return DB::transaction(function () use ($withdrawalId) {
                $locked = Withdrawal::query()
                    ->with('user')
                    ->whereKey($withdrawalId)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->status !== 'completed') {
                    return null;
                }

                if ($existing = $this->find($locked)) {
                    $existing = $this->normalizeLegacyFeeLineItems($existing);

                    if (! $existing->hasPdf() || ! $existing->pdfExists()) {
                        try {
                            $this->pdfs->generateAndStore($existing);
                        } catch (\Throwable $e) {
                            Log::warning('Failed to regenerate missing payout statement PDF', [
                                'withdrawal_id' => $locked->id,
                                'invoice_id' => $existing->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return $existing->fresh();
                }

                if (! $locked->user) {
                    return null;
                }

                $statement = Invoice::create($this->payload($locked));
                try {
                    $this->pdfs->generateAndStore($statement);
                } catch (\Throwable $pdfError) {
                    // Keep the statement row so the publisher can see it in Payout docs;
                    // download/view regenerate the PDF on demand.
                    Log::error('Payout statement created but PDF generation failed', [
                        'withdrawal_id' => $locked->id,
                        'invoice_id' => $statement->id,
                        'error' => $pdfError->getMessage(),
                    ]);
                    $this->events->log('withdrawal_payout_statement_pdf_failed', $statement, null, $locked->user_id, [
                        'withdrawal_id' => $locked->id,
                        'error' => $pdfError->getMessage(),
                    ]);

                    return $statement->fresh();
                }

                $this->events->log('withdrawal_payout_statement_generated', $statement, null, $locked->user_id, [
                    'withdrawal_id' => $locked->id,
                ]);

                return $statement->fresh();
            });
        } catch (\Throwable $e) {
            Log::error('Failed to generate withdrawal payout statement', [
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
            ]);
            $this->events->log('withdrawal_payout_statement_failed', null, null, $withdrawal->user_id, [
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * One PAY document per WD-{id}. Do not require invoices.user_id to match:
     * a publisher merge / corrected user_id would otherwise hide the statement
     * and issue() would create a second PAY row.
     */
    public function find(Withdrawal $withdrawal): ?Invoice
    {
        $reference = 'WD-'.$withdrawal->id;

        $statement = Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where('reference_code', $reference)
            ->orderByDesc('id')
            ->first();

        return $statement ? $this->reconcileOwner($statement, $withdrawal) : null;
    }

    /**
     * WD-{id} is the owner. A stale invoices.user_id would hide the PAY doc
     * from the publisher and leave it on the wrong billing list.
     */
    public function reconcileInvoice(Invoice $invoice): Invoice
    {
        if (! $invoice->isWithdrawalPayout()) {
            return $invoice;
        }

        $withdrawalId = $invoice->withdrawalId();
        if ($withdrawalId <= 0) {
            return $invoice;
        }

        $withdrawal = Withdrawal::query()->with('user')->find($withdrawalId);

        return $withdrawal ? $this->reconcileOwner($invoice, $withdrawal) : $invoice;
    }

    public function reconcileOwner(Invoice $statement, Withdrawal $withdrawal): Invoice
    {
        $ownerId = (int) $withdrawal->user_id;
        if ($ownerId <= 0) {
            return $statement;
        }

        $withdrawal->loadMissing('user');
        $user = $withdrawal->user;
        $details = Withdrawal::detailsArray($withdrawal->payment_details);
        $accountHolder = Withdrawal::detailText($details, 'account_holder');
        $payeeName = $user
            ? ($this->scalarText($user->payout_business_name)
                ?: $accountHolder
                ?: $this->scalarText($user->billing_name)
                ?: $this->scalarText($user->name))
            : $accountHolder;
        $email = $user ? $this->scalarText($user->email) : '';

        $ownerMismatch = (int) $statement->user_id !== $ownerId;
        $emailMismatch = $email !== ''
            && strcasecmp(trim((string) $statement->customer_email), $email) !== 0;
        $nameMismatch = $payeeName !== ''
            && trim((string) $statement->customer_name) !== $payeeName;
        $metaWithdrawalId = (int) data_get($statement->meta, 'withdrawal_id');
        $metaMismatch = $metaWithdrawalId !== (int) $withdrawal->id;

        // user_id may already have been corrected (earlier find()) while the
        // payee line and stored PDF still name the other publisher.
        if (! $ownerMismatch && ! $emailMismatch && ! $nameMismatch) {
            if ($metaMismatch) {
                $this->rewriteWithdrawalMeta($statement, $withdrawal);
            }

            return $statement;
        }

        // Owner change must never keep the previous publisher's name/email,
        // even when the new owner has a blank profile (Wise/PayPal, no holder).
        $resolvedName = $payeeName !== '' ? $payeeName : ($ownerMismatch ? 'Publisher #'.$ownerId : '');

        $snapshot = is_array($statement->billing_snapshot) ? $statement->billing_snapshot : [];
        if ($resolvedName !== '') {
            $snapshot['name'] = $resolvedName;
        }
        if ($ownerMismatch || $email !== '') {
            $snapshot['email'] = $email !== '' ? $email : null;
        }
        $snapshot['payment_details'] = $details;
        if ($user) {
            $snapshot['company'] = $this->scalarText($user->payout_business_name) ?: $this->scalarText($user->company_name) ?: null;
            $snapshot['address'] = $this->scalarText($user->address) ?: null;
            $snapshot['city'] = $this->scalarText($user->city) ?: null;
            $snapshot['state'] = $this->scalarText($user->state) ?: null;
            $snapshot['postal_code'] = $this->scalarText($user->postal_code) ?: null;
            $snapshot['country'] = $this->scalarText($user->country) ?: null;
        }

        $fromUserId = (int) $statement->user_id;

        try {
            $statement->user_id = $ownerId;
            if ($resolvedName !== '') {
                $statement->customer_name = $resolvedName;
            }
            if ($ownerMismatch || $email !== '') {
                $statement->customer_email = $email;
            }
            $statement->billing_snapshot = $snapshot;
            $meta = is_array($statement->meta) ? $statement->meta : [];
            $meta['withdrawal_id'] = (int) $withdrawal->id;
            $statement->meta = $meta;
            // Drop the stored PDF so download/view cannot keep serving the
            // other publisher's name and address.
            $statement->pdf_path = null;
            $statement->save();
            $statement->unsetRelation('user');
        } catch (\Throwable $e) {
            Log::warning('Failed to reassign payout statement to withdrawal owner', [
                'invoice_id' => $statement->id,
                'withdrawal_id' => $withdrawal->id,
                'from_user_id' => $fromUserId,
                'to_user_id' => $ownerId,
                'error' => $e->getMessage(),
            ]);
            $statement->refresh();
        }

        return $statement;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Withdrawal $withdrawal): array
    {
        $user = $withdrawal->user;
        $gross = round((float) $withdrawal->amount, 2);
        $fee = round((float) ($withdrawal->fee ?? 0), 2);
        $net = round((float) ($withdrawal->net_amount ?? ($gross - $fee)), 2);
        $paidAt = $withdrawal->processed_at ?: now();

        $lineItems = [
            [
                'description' => 'Publisher withdrawal payout',
                'reference' => 'WD-'.$withdrawal->id,
                'quantity' => 1,
                'unit_price' => $gross,
                'line_total' => $gross,
            ],
        ];

        // Fee is shown once in totals as "Withdrawal fee" (discount_amount),
        // not also as a negative line item.

        $details = Withdrawal::detailsArray($withdrawal->payment_details);
        $accountHolder = Withdrawal::detailText($details, 'account_holder');
        $payeeName = $this->scalarText($user->payout_business_name)
            ?: $accountHolder
            ?: $this->scalarText($user->billing_name)
            ?: $this->scalarText($user->name);

        return [
            'invoice_number' => $this->numbers->nextPayoutStatement(),
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $withdrawal->user_id,
            'order_id' => null,
            'reference_code' => 'WD-'.$withdrawal->id,
            'currency' => config('billing.currency', 'EUR'),
            'subtotal' => $gross,
            'tax_amount' => 0,
            'discount_amount' => $fee,
            'total_amount' => $net,
            'tax_rate' => 0,
            'tax_label' => null,
            'payment_method' => $withdrawal->payment_method,
            'payment_status' => 'paid',
            'transaction_id' => 'WD-'.$withdrawal->id,
            'invoice_date' => $paidAt,
            'paid_at' => $paidAt,
            'customer_name' => $payeeName,
            'customer_email' => $this->scalarText($user->email) ?: null,
            'billing_snapshot' => [
                'name' => $payeeName,
                'email' => $this->scalarText($user->email) ?: null,
                'company' => $this->scalarText($user->payout_business_name) ?: $this->scalarText($user->company_name) ?: null,
                'address' => $this->scalarText($user->address) ?: null,
                'city' => $this->scalarText($user->city) ?: null,
                'state' => $this->scalarText($user->state) ?: null,
                'postal_code' => $this->scalarText($user->postal_code) ?: null,
                'country' => $this->scalarText($user->country) ?: null,
                'payment_details' => $details,
            ],
            'line_items' => $lineItems,
            'pdf_disk' => config('billing.storage.disk', 'local'),
            'notes' => (string) config('billing.withdrawal_payout_note'),
            'meta' => [
                'withdrawal_id' => $withdrawal->id,
                'document' => 'withdrawal_payout',
                'gross_amount' => $gross,
                'fee' => $fee,
                'net_amount' => $net,
            ],
        ];
    }

    /**
     * Completed withdrawals that do not yet have a non-cancelled PAY statement.
     *
     * Portable (no MySQL CONCAT): resolve existing WD-{id} refs in PHP, then exclude.
     */
    public function missingCompletedWithdrawalsQuery()
    {
        $existingIds = $this->existingPayoutWithdrawalIds();

        $query = Withdrawal::query()
            ->where('status', 'completed')
            ->orderBy('id');

        if ($existingIds !== []) {
            $query->whereNotIn('id', $existingIds);
        }

        return $query;
    }

    /**
     * Ops: create missing PAY statements for completed withdrawals.
     *
     * @return array{created: int, skipped: int, failed: int, invoice_ids: list<int>}
     */
    public function backfillMissing(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $ids = [];

        $withdrawals = $this->missingCompletedWithdrawalsQuery()
            ->with('user')
            ->limit($limit)
            ->get();

        foreach ($withdrawals as $withdrawal) {
            if (! $withdrawal->user) {
                $skipped++;

                continue;
            }

            $statement = $this->issue($withdrawal);
            if ($statement) {
                $created++;
                $ids[] = (int) $statement->id;
            } else {
                $failed++;
            }
        }

        return compact('created', 'skipped', 'failed') + ['invoice_ids' => $ids];
    }

    /**
     * Ops: rewrite stored PDFs for existing payout statements (template updates).
     *
     * @return array{regenerated: int, failed: int}
     */
    public function regenerateExistingPdfs(int $limit = 50): array
    {
        $docs = Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get();

        $regenerated = 0;
        $failed = 0;

        foreach ($docs as $doc) {
            try {
                $doc = $this->reconcileInvoice($doc);
                $doc = $this->normalizeLegacyFeeLineItems($doc);
                $this->pdfs->generateAndStore($doc);
                $regenerated++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Failed to regenerate payout statement PDF', [
                    'invoice_id' => $doc->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('regenerated', 'failed');
    }

    /**
     * Early payloads stored the fee both as a negative line item and as discount_amount.
     * Strip those legacy fee lines so PDFs / regenerations show the fee once in totals.
     */
    public function normalizeLegacyFeeLineItems(Invoice $statement): Invoice
    {
        if ($statement->type !== Invoice::TYPE_WITHDRAWAL_PAYOUT) {
            return $statement;
        }

        $items = is_array($statement->line_items) ? $statement->line_items : [];
        $cleaned = [];

        foreach ($items as $line) {
            if (! is_array($line)) {
                continue;
            }

            $total = (float) ($line['line_total'] ?? $line['unit_price'] ?? 0);
            $desc = strtolower((string) ($line['description'] ?? ''));

            if ($total < 0) {
                continue;
            }

            if (str_contains($desc, 'withdrawal fee') || str_contains($desc, 'platform fee')) {
                continue;
            }

            $cleaned[] = $line;
        }

        $cleaned = array_values($cleaned);
        if ($cleaned === array_values($items)) {
            return $statement;
        }

        // Drop the stored PDF path so download/view cannot keep serving a stale
        // file that still shows the fee as both a line item and a total.
        $statement->update([
            'line_items' => $cleaned,
            'pdf_path' => null,
        ]);

        return $statement->fresh() ?? $statement;
    }

    private function rewriteWithdrawalMeta(Invoice $statement, Withdrawal $withdrawal): void
    {
        $fromUserId = (int) $statement->user_id;
        $meta = is_array($statement->meta) ? $statement->meta : [];
        $meta['withdrawal_id'] = (int) $withdrawal->id;

        try {
            $statement->meta = $meta;
            $statement->save();
        } catch (\Throwable $e) {
            Log::warning('Failed to rewrite payout statement withdrawal meta', [
                'invoice_id' => $statement->id,
                'withdrawal_id' => $withdrawal->id,
                'from_user_id' => $fromUserId,
                'error' => $e->getMessage(),
            ]);
            $statement->refresh();
        }
    }

    private function scalarText(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @return list<int>
     */
    private function existingPayoutWithdrawalIds(): array
    {
        return Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where('reference_code', 'like', 'WD-%')
            ->pluck('reference_code')
            ->map(function ($ref) {
                return preg_match('/^WD-(\d+)$/', (string) $ref, $m) ? (int) $m[1] : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
