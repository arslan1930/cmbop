<?php

namespace App\Services\Partners;

use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerClawback;
use App\Models\PartnerLedgerAccount;
use App\Models\PartnerLedgerBalance;
use App\Models\PartnerLedgerEntry;
use App\Models\PartnerLedgerTransaction;
use App\Models\PartnerLinkIncident;
use App\Models\PartnerOrderCorrection;
use App\Models\PartnerStatement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PartnerLedgerService
{
    public function onSubmitted(OrderItem $item, Partner $partner, string $mode): void
    {
        if (PartnerSandbox::isSandboxItem($item) || ! PartnerChannel::tablesReady()) {
            return;
        }
        $amount = $this->payoutMinor($item);
        if ($amount <= 0) {
            return;
        }
        $this->ensureEscrow($item);
        if ($mode === PartnerChannel::MODE_INSTANT) {
            $this->post([
                'type' => 'earning_instant',
                'order_item_id' => $item->id,
                'idempotency_key' => 'submit-'.$item->id,
                'description' => 'Instant earning on submission',
                'lines' => [
                    ['code' => 'buyer_escrow:item_'.$item->id, 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'debit', 'amount' => $amount, 'allow_negative' => false],
                    ['code' => 'partner_available:'.$partner->id, 'owner_type' => 'partner', 'owner_id' => $partner->id, 'direction' => 'credit', 'amount' => $amount, 'allow_negative' => true],
                ],
            ]);
            $item->partner_earnings_status = 'available';
            $item->save();
            $this->applyOpenClawbacks($partner);

            return;
        }
        $item->partner_earnings_status = 'awaiting_approval';
        $item->save();
    }

    public function onApproved(OrderItem $item, Partner $partner): void
    {
        if (PartnerSandbox::isSandboxItem($item) || ! PartnerChannel::tablesReady()) {
            return;
        }
        $amount = $this->payoutMinor($item);
        $slb = max(0, $this->slbMinor($item) - $amount);
        $this->ensureEscrow($item);
        $mode = (string) ($item->payout_mode_at_submission ?: PartnerChannel::payoutMode($partner));
        if ($mode === PartnerChannel::MODE_INSTANT) {
            if ($slb > 0) {
                $this->post([
                    'type' => 'order_completed',
                    'order_item_id' => $item->id,
                    'idempotency_key' => 'approve-slb-'.$item->id,
                    'description' => 'Order completed',
                    'lines' => [
                        ['code' => 'buyer_escrow:item_'.$item->id, 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'debit', 'amount' => $slb, 'allow_negative' => false],
                        ['code' => 'slb_revenue', 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'credit', 'amount' => $slb, 'allow_negative' => false],
                    ],
                ]);
            }

            return;
        }
        $lines = [
            ['code' => 'buyer_escrow:item_'.$item->id, 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'debit', 'amount' => $amount + $slb, 'allow_negative' => false],
            ['code' => 'partner_pending:'.$partner->id, 'owner_type' => 'partner', 'owner_id' => $partner->id, 'direction' => 'credit', 'amount' => $amount, 'allow_negative' => false],
        ];
        if ($slb > 0) {
            $lines[] = ['code' => 'slb_revenue', 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'credit', 'amount' => $slb, 'allow_negative' => false];
        } elseif ($amount > 0) {
            // still post partner pending even if SLB remainder is 0
        }
        $this->post([
            'type' => 'order_approved',
            'order_item_id' => $item->id,
            'idempotency_key' => 'approve-'.$item->id,
            'description' => 'Order completed, earnings on hold',
            'lines' => $lines,
        ]);
        $item->partner_earnings_status = 'on_hold';
        if ($item->hold_until && $item->hold_until->lte(now())) {
            $item->hold_until = now()->addHours(24);
        }
        $item->save();
    }

    public function releaseHold(OrderItem $item, Partner $partner): bool
    {
        if (! PartnerChannel::tablesReady()) {
            return false;
        }
        if (! $this->holdIsReleasable($item)) {
            return false;
        }
        $amount = $this->payoutMinor($item);
        $this->post([
            'type' => 'hold_released',
            'order_item_id' => $item->id,
            'idempotency_key' => 'release-'.$item->id,
            'description' => 'Hold released',
            'lines' => [
                ['code' => 'partner_pending:'.$partner->id, 'owner_type' => 'partner', 'owner_id' => $partner->id, 'direction' => 'debit', 'amount' => $amount, 'allow_negative' => false],
                ['code' => 'partner_available:'.$partner->id, 'owner_type' => 'partner', 'owner_id' => $partner->id, 'direction' => 'credit', 'amount' => $amount, 'allow_negative' => true],
            ],
        ]);
        $item->partner_earnings_status = 'available';
        $item->hold_released_at = now();
        $item->save();
        $this->applyOpenClawbacks($partner);

        return true;
    }

    public function clawback(OrderItem $item, Partner $partner, string $reason, ?int $incidentId = null, ?int $disputeId = null): PartnerClawback
    {
        $amount = $this->payoutMinor($item);
        $tx = $this->post([
            'type' => 'clawback',
            'order_item_id' => $item->id,
            'idempotency_key' => 'clawback-'.$item->id.'-'.$reason,
            'description' => 'Clawback '.$reason,
            'lines' => [
                ['code' => 'partner_available:'.$partner->id, 'owner_type' => 'partner', 'owner_id' => $partner->id, 'direction' => 'debit', 'amount' => $amount, 'allow_negative' => true],
                ['code' => 'refunds_payable', 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'credit', 'amount' => $amount, 'allow_negative' => false],
            ],
        ]);
        $clawback = PartnerClawback::query()->create([
            'order_item_id' => $item->id,
            'partner_id' => $partner->id,
            'amount_minor' => $amount,
            'reason' => $reason,
            'incident_id' => $incidentId,
            'dispute_id' => $disputeId,
            'ledger_transaction_id' => $tx?->id,
            'status' => PartnerClawback::OPEN,
            'opened_at' => now(),
        ]);
        $item->partner_earnings_status = 'deducted';
        $item->save();
        $this->applyOpenClawbacks($partner);

        return $clawback;
    }

    public function debitAvailableForPayout(Partner $partner, int $amountMinor, int $withdrawalId): PartnerLedgerTransaction
    {
        if ($amountMinor <= 0) {
            throw new RuntimeException('Payout amount must be positive.');
        }
        $available = $this->availableMinor($partner);
        if ($available < $amountMinor) {
            throw new RuntimeException('insufficient_available');
        }

        return $this->post([
            'type' => 'payout',
            'payout_id' => $withdrawalId,
            'idempotency_key' => 'payout-'.$withdrawalId,
            'description' => 'Payout request',
            'lines' => [
                ['code' => 'partner_available:'.$partner->id, 'owner_type' => 'partner', 'owner_id' => $partner->id, 'direction' => 'debit', 'amount' => $amountMinor, 'allow_negative' => true],
                ['code' => 'payout_clearing', 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'credit', 'amount' => $amountMinor, 'allow_negative' => false],
            ],
        ]);
    }

    public function creditAvailable(Partner $partner, int $amountMinor, string $key, string $description): void
    {
        $this->post([
            'type' => 'adjustment',
            'idempotency_key' => $key,
            'description' => $description,
            'lines' => [
                ['code' => 'payout_clearing', 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'debit', 'amount' => $amountMinor, 'allow_negative' => false],
                ['code' => 'partner_available:'.$partner->id, 'owner_type' => 'partner', 'owner_id' => $partner->id, 'direction' => 'credit', 'amount' => $amountMinor, 'allow_negative' => true],
            ],
        ]);
    }

    public function holdIsReleasable(OrderItem $item): bool
    {
        if ((string) $item->partner_earnings_status !== 'on_hold') {
            return false;
        }
        if ($item->hold_until && $item->hold_until->isFuture()) {
            return false;
        }
        $orderStatus = (string) ($item->order?->status ?? '');
        if ($orderStatus !== '' && $orderStatus !== 'completed') {
            return false;
        }
        if ($item->isModificationRequested()) {
            return false;
        }
        if ((string) ($item->partner_dispute_status ?? '') === 'open') {
            return false;
        }
        if ((bool) $item->link_alert) {
            return false;
        }
        if (PartnerOrderCorrection::query()
            ->where('order_item_id', $item->id)
            ->whereIn('status', [PartnerOrderCorrection::OPEN, PartnerOrderCorrection::PENDING_RELAY])
            ->exists()) {
            return false;
        }
        if (PartnerLinkIncident::query()
            ->where('order_item_id', $item->id)
            ->whereNull('resolved')
            ->exists()) {
            return false;
        }

        return true;
    }

    public function pendingMinor(Partner $partner): int
    {
        return $this->balance('partner_pending:'.$partner->id);
    }

    public function availableMinor(Partner $partner): int
    {
        return $this->balance('partner_available:'.$partner->id);
    }

    public function instantExposureMinor(Partner $partner): int
    {
        if (! Schema::hasColumn('order_items', 'payout_mode_at_submission')) {
            return 0;
        }

        return (int) OrderItem::query()
            ->whereHas('site', fn ($q) => $q->where('publisher_id', $partner->user_id))
            ->where('payout_mode_at_submission', PartnerChannel::MODE_INSTANT)
            ->where(function ($q) {
                $q->where('partner_earnings_status', 'available')
                    ->orWhereNull('completed_at');
            })
            ->where(function ($q) {
                $q->whereNull('first_submitted_at')
                    ->orWhere('first_submitted_at', '>=', now()->subDays(30));
            })
            ->get()
            ->sum(fn (OrderItem $item) => $this->payoutMinor($item));
    }

    /**
     * Partner-visible ledger lines only.
     *
     * @return list<array{id: int, type: string, amount: int, currency: string, order_id: ?int, payout_id: ?int, created_at: ?string}>
     */
    public function partnerLines(Partner $partner, int $limit = 50): array
    {
        $accountIds = PartnerLedgerAccount::query()
            ->where('owner_type', 'partner')
            ->where('owner_id', $partner->id)
            ->pluck('id');
        if ($accountIds->isEmpty()) {
            return [];
        }
        $entries = PartnerLedgerEntry::query()
            ->with('transaction')
            ->whereIn('account_id', $accountIds)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
        $out = [];
        foreach ($entries as $entry) {
            $tx = $entry->transaction;
            $signed = $entry->direction === 'credit' ? (int) $entry->amount_minor : -1 * (int) $entry->amount_minor;
            $out[] = [
                'id' => (int) $entry->id,
                'type' => (string) ($tx?->type ?? 'entry'),
                'amount' => $signed,
                'currency' => 'EUR',
                'order_id' => $tx?->order_item_id,
                'payout_id' => $tx?->payout_id,
                'created_at' => optional($entry->created_at)?->toIso8601String(),
            ];
        }

        return $out;
    }

    public function statement(Partner $partner, string $period): PartnerStatement
    {
        $existing = PartnerStatement::query()->where('partner_id', $partner->id)->where('period', $period)->first();
        if ($existing) {
            return $existing;
        }
        $lines = $this->partnerLines($partner, 500);
        $earned = 0;
        $released = 0;
        $paid = 0;
        $reversed = 0;
        foreach ($lines as $line) {
            if (in_array($line['type'], ['earning_held', 'earning_instant', 'order_approved'], true) && $line['amount'] > 0) {
                $earned += $line['amount'];
            }
            if ($line['type'] === 'hold_released') {
                $released += abs($line['amount']);
            }
            if ($line['type'] === 'payout') {
                $paid += abs($line['amount']);
            }
            if (in_array($line['type'], ['clawback', 'reversal', 'deduction'], true)) {
                $reversed += abs($line['amount']);
            }
        }

        return PartnerStatement::query()->create([
            'partner_id' => $partner->id,
            'period' => $period,
            'status' => 'final',
            'issued_at' => now(),
            'lines' => $lines,
            'totals' => [
                'earned' => $earned,
                'released' => $released,
                'reversed' => $reversed,
                'paid_out' => $paid,
                'pending' => $this->pendingMinor($partner),
                'available' => $this->availableMinor($partner),
                'issuer' => 'Teqno LTD (17392540)',
            ],
        ]);
    }

    public function transactionIsBalanced(PartnerLedgerTransaction $tx): bool
    {
        $debit = 0;
        $credit = 0;
        foreach ($tx->entries as $entry) {
            if ($entry->direction === 'debit') {
                $debit += (int) $entry->amount_minor;
            } else {
                $credit += (int) $entry->amount_minor;
            }
        }

        return $debit === $credit;
    }

    /**
     * @param  array{type: string, order_item_id?: ?int, payout_id?: ?int, idempotency_key?: ?string, description?: ?string, lines: list<array{code: string, owner_type: string, owner_id: int, direction: string, amount: int, allow_negative: bool}>}  $payload
     */
    public function post(array $payload): PartnerLedgerTransaction
    {
        $key = $payload['idempotency_key'] ?? null;
        if ($key) {
            $existing = PartnerLedgerTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($payload, $key) {
            $lines = $payload['lines'] ?? [];
            $debit = 0;
            $credit = 0;
            foreach ($lines as $line) {
                if ($line['direction'] === 'debit') {
                    $debit += (int) $line['amount'];
                } else {
                    $credit += (int) $line['amount'];
                }
            }
            if ($debit !== $credit) {
                throw new RuntimeException('Unbalanced ledger transaction.');
            }
            $tx = PartnerLedgerTransaction::query()->create([
                'type' => $payload['type'],
                'order_item_id' => $payload['order_item_id'] ?? null,
                'payout_id' => $payload['payout_id'] ?? null,
                'idempotency_key' => $key,
                'description' => $payload['description'] ?? null,
            ]);
            foreach ($lines as $line) {
                $account = $this->account($line['code'], $line['owner_type'], (int) $line['owner_id'], (bool) $line['allow_negative']);
                PartnerLedgerEntry::query()->create([
                    'transaction_id' => $tx->id,
                    'account_id' => $account->id,
                    'direction' => $line['direction'],
                    'amount_minor' => (int) $line['amount'],
                    'currency' => 'EUR',
                ]);
                $signed = $line['direction'] === 'credit' ? (int) $line['amount'] : -1 * (int) $line['amount'];
                $this->adjustBalance($account, $signed);
            }

            return $tx->fresh(['entries']) ?? $tx;
        });
    }

    private function applyOpenClawbacks(Partner $partner): void
    {
        $available = $this->availableMinor($partner);
        if ($available >= 0) {
            PartnerClawback::query()
                ->where('partner_id', $partner->id)
                ->where('status', PartnerClawback::OPEN)
                ->update(['status' => PartnerClawback::OFFSET, 'recovered_at' => now()]);
        }
    }

    private function ensureEscrow(OrderItem $item): void
    {
        $amount = max($this->slbMinor($item), $this->payoutMinor($item));
        if ($amount <= 0) {
            return;
        }
        $this->post([
            'type' => 'escrow_in',
            'order_item_id' => $item->id,
            'idempotency_key' => 'escrow-'.$item->id,
            'description' => 'Buyer funds received',
            'lines' => [
                ['code' => 'platform_clearing', 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'debit', 'amount' => $amount, 'allow_negative' => true],
                ['code' => 'buyer_escrow:item_'.$item->id, 'owner_type' => 'platform', 'owner_id' => 0, 'direction' => 'credit', 'amount' => $amount, 'allow_negative' => false],
            ],
        ]);
    }

    private function payoutMinor(OrderItem $item): int
    {
        $euros = $item->partner_payout_eur !== null && $item->partner_payout_eur !== ''
            ? (float) $item->partner_payout_eur
            : $item->publisherPayoutAmount();

        return PartnerChannel::eurosToMinor($euros);
    }

    private function slbMinor(OrderItem $item): int
    {
        if ($item->slb_price_eur_internal !== null && $item->slb_price_eur_internal !== '') {
            return PartnerChannel::eurosToMinor((float) $item->slb_price_eur_internal);
        }

        return PartnerChannel::eurosToMinor($item->advertiserEurAmount());
    }

    private function account(string $code, string $ownerType, int $ownerId, bool $allowNegative): PartnerLedgerAccount
    {
        $account = PartnerLedgerAccount::query()->firstOrCreate(
            ['code' => $code],
            ['owner_type' => $ownerType, 'owner_id' => $ownerId ?: null, 'currency' => 'EUR', 'allow_negative' => $allowNegative]
        );
        PartnerLedgerBalance::query()->firstOrCreate(
            ['account_id' => $account->id],
            ['balance_minor' => 0, 'version' => 0]
        );

        return $account;
    }

    private function adjustBalance(PartnerLedgerAccount $account, int $delta): void
    {
        $row = PartnerLedgerBalance::query()->where('account_id', $account->id)->lockForUpdate()->first();
        if (! $row) {
            $row = PartnerLedgerBalance::query()->create([
                'account_id' => $account->id,
                'balance_minor' => 0,
                'version' => 0,
            ]);
        }
        $next = (int) $row->balance_minor + $delta;
        if ($next < 0 && ! $account->allow_negative) {
            throw new RuntimeException('insufficient_available');
        }
        $row->balance_minor = $next;
        $row->version = (int) $row->version + 1;
        $row->save();
    }

    private function balance(string $code): int
    {
        $account = PartnerLedgerAccount::query()->where('code', $code)->first();
        if (! $account) {
            return 0;
        }

        return (int) (PartnerLedgerBalance::query()->where('account_id', $account->id)->value('balance_minor') ?? 0);
    }
}
