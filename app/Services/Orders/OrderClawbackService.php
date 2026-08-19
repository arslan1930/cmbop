<?php

namespace App\Services\Orders;

use App\Mail\DisputeClawbackPublisher;
use App\Mail\DisputeRefundAdvertiser;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\UserFacingError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderClawbackService
{
    public function __construct(
        private WalletLedgerService $ledger,
        private InAppNotificationService $notifications,
    ) {}

    public function canOpenDispute(Order $order, ?OrderItem $item = null, bool $asAdmin = false): bool
    {
        if (! OrderItemDispute::tableAvailable()) {
            return false;
        }

        try {
            $this->assertCanOpen($order, $item ?? $order->items->first(), $asAdmin);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function openDispute(OrderItem $item, User $opener, string $reason, bool $asAdmin = false): OrderItemDispute
    {
        $reason = trim($reason);
        if (strlen($reason) < 10 || strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Please provide a reason between 10 and 1000 characters.',
            ]);
        }

        return DB::transaction(function () use ($item, $opener, $reason, $asAdmin) {
            $item = OrderItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $order = Order::where('id', $item->order_id)->lockForUpdate()->firstOrFail();

            if (! $asAdmin && (int) $order->user_id !== (int) $opener->id) {
                throw ValidationException::withMessages([
                    'order' => 'Unauthorized: this order does not belong to you.',
                ]);
            }

            $this->assertCanOpen($order, $item, $asAdmin);

            $dispute = OrderItemDispute::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'opened_by' => $opener->id,
                'status' => OrderItemDispute::STATUS_OPEN,
                'reason' => $reason,
            ]);

            $fresh = $dispute->fresh(['order', 'orderItem.site']);
            $this->notifications->notifyDisputeOpened($fresh);
            $this->logDisputeActivity(
                'dispute.opened',
                ($opener->name ?: $opener->email).' opened a dispute on order #'
                    .($order->order_number ?? $order->id),
                $order,
                $opener,
                [
                    'dispute_id' => $fresh->id,
                    'order_item_id' => $item->id,
                    'reason' => $reason,
                    'as_admin' => $asAdmin,
                ]
            );

            return $fresh;
        });
    }

    public function dismiss(OrderItemDispute $dispute, User $admin, string $notes): OrderItemDispute
    {
        $notes = trim($notes);
        if (strlen($notes) < 10 || strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'admin_notes' => 'Please provide resolution notes between 10 and 1000 characters.',
            ]);
        }

        return DB::transaction(function () use ($dispute, $admin, $notes) {
            $dispute = OrderItemDispute::where('id', $dispute->id)->lockForUpdate()->firstOrFail();
            if (! $dispute->isOpen()) {
                throw ValidationException::withMessages([
                    'dispute' => 'Only open disputes can be dismissed.',
                ]);
            }

            $dispute->update([
                'status' => OrderItemDispute::STATUS_DISMISSED,
                'admin_notes' => $notes,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            $fresh = $dispute->fresh(['order', 'orderItem.site']);
            $this->notifications->notifyDisputeDismissed($fresh);
            $order = $fresh->order ?? Order::query()->find($fresh->order_id);
            $this->logDisputeActivity(
                'dispute.dismissed',
                ($admin->name ?: $admin->email).' dismissed a dispute on order #'
                    .($order?->order_number ?? $fresh->order_id),
                $order,
                $admin,
                [
                    'dispute_id' => $fresh->id,
                    'order_item_id' => $fresh->order_item_id,
                    'reason' => $notes,
                ]
            );

            return $fresh;
        });
    }

    public function uphold(OrderItemDispute $dispute, User $admin, string $notes): OrderItemDispute
    {
        $notes = trim($notes);
        if (strlen($notes) < 10 || strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'admin_notes' => 'Please provide resolution notes between 10 and 1000 characters.',
            ]);
        }

        $preview = OrderItemDispute::query()->findOrFail($dispute->id);
        $previewItem = OrderItem::query()->findOrFail($preview->order_item_id);
        $previewOrder = Order::query()->findOrFail($preview->order_id);
        $this->assertUpholdPreconditions($preview, $previewOrder, $previewItem);
        $advertiserCredit = round((float) $previewItem->price, 2);
        $preparedPaypal = $this->refundPaypalCashBeforeUphold($preview, $previewOrder, $advertiserCredit);

        return DB::transaction(function () use ($dispute, $admin, $notes, $advertiserCredit, $preparedPaypal) {
            $dispute = OrderItemDispute::where('id', $dispute->id)->lockForUpdate()->firstOrFail();
            $item = OrderItem::where('id', $dispute->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::where('id', $dispute->order_id)->lockForUpdate()->firstOrFail();
            $this->assertUpholdPreconditions($dispute, $order, $item);

            $targetPayout = round((float) $item->publisherPayoutAmount(), 2);
            $advertiserCredit = round((float) $item->price, 2);
            if (abs($advertiserCredit - $preparedPaypal['expected_credit']) > 0.009) {
                throw ValidationException::withMessages([
                    'dispute' => 'The disputed line amount changed. Refresh and try again.',
                ]);
            }

            $site = Site::find($item->site_id);
            $publisherId = $site?->publisher_id;
            $publisherRoleId = Wallet::publisherRoleId();
            $advertiserRoleId = Wallet::advertiserRoleId();

            $debited = 0.0;
            $debtCreated = 0.0;
            $publisherWallet = null;

            if ($publisherId && $publisherRoleId && $targetPayout > 0) {
                $publisherWallet = Wallet::lockOrCreateForRole((int) $publisherId, (int) $publisherRoleId);
                $available = $publisherWallet->withdrawableBalance();
                $debited = round(min($available, $targetPayout), 2);
                $debtCreated = round(max(0, $targetPayout - $debited), 2);

                if ($debited > 0) {
                    $publisherWallet->deductWithdrawable($debited);
                    $this->ledger->recordTransferOut(
                        $publisherWallet,
                        $debited,
                        $item,
                        'CLAWBACK-ITEM-'.$item->id,
                        'Clawback for order #'.($order->order_number ?? $order->id),
                        [
                            'order_id' => $order->id,
                            'dispute_id' => $dispute->id,
                            'target_payout' => $targetPayout,
                            'debt_created' => $debtCreated,
                            'advertiser_credited' => $advertiserCredit,
                        ]
                    );
                }

                if ($debtCreated > 0) {
                    $publisherWallet->increaseDebt($debtCreated);
                }
            }

            if ($advertiserRoleId && $advertiserCredit > 0) {
                $advertiserWallet = Wallet::lockOrCreateForRole((int) $order->user_id, (int) $advertiserRoleId);
                $bonusShare = $this->bonusShareFromPurchaseLedger($advertiserWallet, $order, $advertiserCredit);
                $cashShare = round($advertiserCredit - $bonusShare, 2);
                $skipCash = $preparedPaypal['refund'] !== null;
                if ($bonusShare > 0) {
                    $advertiserWallet->creditBonus($bonusShare);
                }
                if ($cashShare > 0 && ! $skipCash) {
                    $advertiserWallet->credit($cashShare);
                }
                if (! $skipCash || $bonusShare > 0) {
                    $this->ledger->recordRefund(
                        $advertiserWallet,
                        $skipCash ? $bonusShare : $advertiserCredit,
                        $bonusShare,
                        $order,
                        $order->reference_code ?: 'CLAWBACK-REFUND-'.$item->id
                    );
                }
            }

            $dispute->update([
                'status' => OrderItemDispute::STATUS_UPHELD,
                'admin_notes' => $notes,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
                'publisher_debited' => $debited,
                'advertiser_credited' => $advertiserCredit,
                'debt_created' => $debtCreated,
            ]);

            if ($this->everyItemHasBeenClawedBack($order)) {
                $order->update([
                    'payment_status' => 'refunded',
                ]);
            }

            if ($site) {
                Site::refreshCompletedOrdersCount((int) $site->id);
            }

            $fresh = $dispute->fresh(['order', 'orderItem.site', 'order.user']);
            $this->notifications->notifyDisputeUpheld($fresh);

            $publisher = $publisherId ? User::find($publisherId) : null;
            $advertiser = $order->user ?? User::find($order->user_id);

            if ($publisher?->email) {
                try {
                    Mail::to($publisher->email)->send(new DisputeClawbackPublisher($fresh, $publisher, $debited, $debtCreated));
                } catch (\Throwable $e) {
                    Log::warning('Dispute clawback publisher mail failed', [
                        'dispute_id' => $fresh->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($advertiser?->email) {
                try {
                    Mail::to($advertiser->email)->send(new DisputeRefundAdvertiser(
                        $fresh,
                        $advertiser,
                        $advertiserCredit,
                        $preparedPaypal['refund'] !== null
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Dispute refund advertiser mail failed', [
                        'dispute_id' => $fresh->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                ContentSubmission::releaseAllForOrderItem((int) $item->id);
            } catch (\Throwable $e) {
                Log::warning('Dispute uphold could not release the content submission', [
                    'dispute_id' => $fresh->id,
                    'order_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->logDisputeActivity(
                'dispute.upheld',
                ($admin->name ?: $admin->email).' upheld a dispute on order #'
                    .($order->order_number ?? $order->id)
                    .' (€'.number_format($advertiserCredit, 2).' credited, €'
                    .number_format($debited, 2).' debited'
                    .($debtCreated > 0 ? ', €'.number_format($debtCreated, 2).' debt' : '')
                    .')',
                $order,
                $admin,
                [
                    'dispute_id' => $fresh->id,
                    'order_item_id' => $item->id,
                    'publisher_id' => $publisherId,
                    'publisher_debited' => $debited,
                    'advertiser_credited' => $advertiserCredit,
                    'debt_created' => $debtCreated,
                    'payment_status' => $order->fresh()?->payment_status,
                    'reason' => $notes,
                ]
            );

            Log::info('Order item dispute upheld / clawback applied', [
                'dispute_id' => $fresh->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'publisher_debited' => $debited,
                'debt_created' => $debtCreated,
                'advertiser_credited' => $advertiserCredit,
            ]);

            return $fresh;
        });
    }

    /**
     * PayPal already returned cash to the advertiser. Claw back publisher
     * earnings (debit withdrawable, else debt) without crediting the
     * advertiser wallet. Idempotent per order item.
     */
    public function clawbackAfterExternalPaypalRefund(Order $order): void
    {
        $run = function () use ($order): void {
            $order->loadMissing('items');
            foreach ($order->items as $item) {
                if ($this->alreadyClawedPaypalItem($item)) {
                    continue;
                }

                $targetPayout = round((float) $item->publisherPayoutAmount(), 2);
                $site = Site::find($item->site_id);
                $publisherId = $site?->publisher_id;
                $publisherRoleId = Wallet::publisherRoleId();
                if (! $publisherId || ! $publisherRoleId || $targetPayout < 0.01) {
                    continue;
                }

                $wallet = Wallet::lockOrCreateForRole((int) $publisherId, (int) $publisherRoleId);
                $debited = round(min($wallet->withdrawableBalance(), $targetPayout), 2);
                $debt = round(max(0, $targetPayout - $debited), 2);
                $reference = 'CLAWBACK-PAYPAL-ITEM-'.$item->id;
                $description = 'PayPal refund clawback for order #'.($order->order_number ?? $order->id);
                $meta = [
                    'order_id' => $order->id,
                    'target_payout' => $targetPayout,
                    'debt_created' => $debt,
                    'advertiser_credited' => 0,
                ];

                if ($debited > 0) {
                    $wallet->deductWithdrawable($debited);
                    $this->ledger->recordTransferOut(
                        $wallet,
                        $debited,
                        $item,
                        $reference,
                        $description,
                        $meta
                    );
                } elseif ($debt > 0) {
                    $this->ledger->recordAdjustment(
                        $wallet,
                        0,
                        'debit',
                        $item,
                        $reference,
                        $description,
                        $meta
                    );
                }

                if ($debt > 0) {
                    $wallet->increaseDebt($debt);
                }

                if ($site) {
                    Site::refreshCompletedOrdersCount((int) $site->id);
                }
            }
        };

        DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    public function clearWalletDebt(Wallet $wallet, User $admin, string $reason): float
    {
        $reason = trim($reason);
        if (strlen($reason) < 5 || strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Please provide a reason between 5 and 1000 characters.',
            ]);
        }

        if (! Wallet::tableAvailable() || ! Wallet::hasTableColumn('debt_balance')) {
            throw ValidationException::withMessages([
                'debt' => 'Wallet debt cannot be updated on this database.',
            ]);
        }

        $ledgerReady = false;
        try {
            $ledgerReady = Schema::hasTable('wallet_transactions');
        } catch (\Throwable) {
            $ledgerReady = false;
        }
        if (! $ledgerReady) {
            throw ValidationException::withMessages([
                'debt' => 'Debt cannot be cleared on this database because the wallet ledger is missing.',
            ]);
        }

        return DB::transaction(function () use ($wallet, $admin, $reason) {
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            $cleared = $wallet->clearDebt();
            if ($cleared <= 0) {
                throw ValidationException::withMessages([
                    'debt' => 'This wallet has no outstanding debt.',
                ]);
            }

            $this->ledger->recordAdjustment(
                $wallet,
                $cleared,
                'credit',
                null,
                'DEBT-CLEAR-'.$wallet->id,
                'Admin cleared publisher debt',
                [
                    'cleared_by' => $admin->id,
                    'reason' => $reason,
                    'previous_debt' => $cleared,
                ]
            );

            return $cleared;
        });
    }

    /**
     * Fail closed before any PayPal HTTP, and again inside the locked TX.
     */
    private function assertUpholdPreconditions(OrderItemDispute $dispute, Order $order, OrderItem $item): void
    {
        if (! $dispute->isOpen()) {
            throw ValidationException::withMessages([
                'dispute' => 'Only open disputes can be upheld.',
            ]);
        }

        $existingUpheld = OrderItemDispute::where('order_item_id', $item->id)
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->where('id', '!=', $dispute->id)
            ->exists();
        if ($existingUpheld || $order->payment_status === 'refunded') {
            throw ValidationException::withMessages([
                'dispute' => 'This order item has already been clawed back or refunded.',
            ]);
        }

        if ($order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'dispute' => 'Only paid orders can be clawed back.',
            ]);
        }

        $targetPayout = round((float) $item->publisherPayoutAmount(), 2);
        $advertiserCredit = round((float) $item->price, 2);
        $site = Site::find($item->site_id);
        $publisherId = $site?->publisher_id;

        if ($targetPayout > 0 && ! $publisherId) {
            throw ValidationException::withMessages([
                'dispute' => 'Cannot uphold this dispute: the listing (and publisher) is missing, so the publisher clawback cannot be applied. Restore the site first.',
            ]);
        }

        if ($advertiserCredit > 0 && ! Wallet::advertiserRoleId()) {
            throw ValidationException::withMessages([
                'dispute' => 'Cannot uphold this dispute: the advertiser role is missing, so the refund cannot be applied. Seed roles first.',
            ]);
        }

        if ($targetPayout > 0 && ! Wallet::publisherRoleId()) {
            throw ValidationException::withMessages([
                'dispute' => 'Cannot uphold this dispute: the publisher role is missing, so the publisher clawback cannot be applied. Seed roles first.',
            ]);
        }
    }

    /**
     * PayPal HTTP stays outside the uphold transaction. Cash returns on PayPal;
     * leftover checkout bonus is still restored on-wallet inside the TX.
     *
     * @return array{refund: array{id: string, amount: float, status: string}|null, expected_credit: float}
     */
    private function refundPaypalCashBeforeUphold(OrderItemDispute $dispute, Order $order, float $advertiserCredit): array
    {
        $result = [
            'refund' => null,
            'expected_credit' => $advertiserCredit,
        ];
        if (($order->payment_method ?? '') !== 'paypal' || $advertiserCredit < 0.01) {
            return $result;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        $bonusShare = 0.0;
        if ($advertiserRoleId) {
            $peekWallet = Wallet::query()
                ->where('user_id', $order->user_id)
                ->where('role_id', $advertiserRoleId)
                ->first();
            if ($peekWallet) {
                $bonusShare = $this->bonusShareFromPurchaseLedger($peekWallet, $order, $advertiserCredit);
            }
        }
        $cashShare = round($advertiserCredit - $bonusShare, 2);
        if ($cashShare < 0.01) {
            return $result;
        }

        try {
            $result['refund'] = app(OrderRefundService::class)->refundPaypalCaptureIfPossible(
                $order,
                $cashShare,
                true,
                'dispute-'.$dispute->id
            );
        } catch (\Throwable $e) {
            Log::error('Dispute PayPal refund API failed', [
                'dispute_id' => $dispute->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'dispute' => UserFacingError::message($e, 'PayPal refund failed. The wallet was not credited.'),
            ]);
        }

        return $result;
    }

    /**
     * Completed orders already consumed reserved bonus. Restore that slice as
     * spend-only so a clawback cannot turn welcome credit into withdrawable cash.
     */
    private function bonusShareFromPurchaseLedger(Wallet $wallet, Order $order, float $amount): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return 0.0;
        }

        $reference = (string) ($order->reference_code ?: $order->order_number);
        $purchasedBonus = (float) WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where(function ($query) use ($order, $reference) {
                $query->where(function ($related) use ($order) {
                    $related->where('related_type', $order->getMorphClass())
                        ->where('related_id', $order->id);
                });
                if ($reference !== '') {
                    $query->orWhere('reference', $reference);
                }
            })
            ->sum('bonus_amount');

        $alreadyRestored = (float) WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_REFUND)
            ->where(function ($query) use ($order, $reference) {
                $query->where(function ($related) use ($order) {
                    $related->where('related_type', $order->getMorphClass())
                        ->where('related_id', $order->id);
                });
                if ($reference !== '') {
                    $query->orWhere('reference', $reference);
                }
            })
            ->sum('bonus_amount');

        $remaining = max(0, round($purchasedBonus - $alreadyRestored, 2));
        if ($remaining > 0.009) {
            return min($amount, $remaining);
        }

        // Admin mark-paid used to skip the purchase row. Fall back to this
        // leftover's live hold / package snapshot — never another checkout.
        $userId = (int) $order->user_id;
        $cap = app(OrderRefundService::class)->cardLeftoverBonusCap($userId, $reference);
        if ($cap !== null) {
            return min($amount, $cap);
        }

        $package = app(OrderPaymentService::class)->getPendingCheckout($reference);
        $snapshot = is_array($package)
            ? round((float) ($package['bonus_applied'] ?? 0), 2)
            : 0.0;

        return min($amount, max(0, $snapshot));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logDisputeActivity(
        string $action,
        string $description,
        ?Order $order,
        User $actor,
        array $properties
    ): void {
        ActivityLogger::tryLog(
            $action,
            $description,
            $order,
            $properties,
            $order ? 'Order #'.($order->order_number ?? $order->id) : null,
            $actor
        );
    }

    /**
     * @throws ValidationException
     */
    protected function assertCanOpen(Order $order, ?OrderItem $item, bool $asAdmin = false): void
    {
        if (! OrderItemDispute::tableAvailable()) {
            throw ValidationException::withMessages([
                'order' => 'Link removal reports are temporarily unavailable. Please contact support.',
            ]);
        }

        if (! $item) {
            throw ValidationException::withMessages([
                'order' => 'Order has no items to dispute.',
            ]);
        }

        if ($order->status !== 'completed') {
            throw ValidationException::withMessages([
                'order' => 'Only completed orders can be disputed.',
            ]);
        }

        if ($order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'order' => $order->payment_status === 'refunded'
                    ? 'This order has already been refunded.'
                    : 'Only paid completed orders can be disputed.',
            ]);
        }

        if (! $asAdmin) {
            $completedAt = $order->completed_at ?? $order->updated_at;
            if (! $completedAt || $completedAt->lt(now()->subDays(OrderItemDispute::REPORT_WINDOW_DAYS))) {
                throw ValidationException::withMessages([
                    'order' => 'The '.OrderItemDispute::REPORT_WINDOW_DAYS.'-day report window has expired.',
                ]);
            }
        }

        $blocking = OrderItemDispute::where('order_item_id', $item->id)
            ->whereIn('status', [OrderItemDispute::STATUS_OPEN, OrderItemDispute::STATUS_UPHELD])
            ->exists();

        if ($blocking) {
            throw ValidationException::withMessages([
                'order' => 'A dispute is already open or was already upheld for this placement.',
            ]);
        }
    }

    private function everyItemHasBeenClawedBack(Order $order): bool
    {
        $itemIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            return false;
        }

        $upheldItemIds = OrderItemDispute::query()
            ->where('order_id', $order->id)
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->pluck('order_item_id')
            ->unique();

        return $itemIds->every(fn ($id) => $upheldItemIds->contains($id));
    }

    private function alreadyClawedPaypalItem(OrderItem $item): bool
    {
        if (OrderItemDispute::tableAvailable()) {
            $upheld = OrderItemDispute::query()
                ->where('order_item_id', $item->id)
                ->where('status', OrderItemDispute::STATUS_UPHELD)
                ->exists();
            if ($upheld) {
                return true;
            }
        }

        if (! Schema::hasTable((new WalletTransaction)->getTable())) {
            return false;
        }

        return WalletTransaction::query()
            ->whereIn('type', [
                WalletTransaction::TYPE_TRANSFER_OUT,
                WalletTransaction::TYPE_ADJUSTMENT,
            ])
            ->where(function ($query) use ($item) {
                $query->where('reference', 'CLAWBACK-PAYPAL-ITEM-'.$item->id)
                    ->orWhere('reference', 'CLAWBACK-ITEM-'.$item->id);
            })
            ->exists();
    }
}
