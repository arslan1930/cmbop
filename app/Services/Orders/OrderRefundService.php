<?php

namespace App\Services\Orders;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Returns advertiser funds when an order is cancelled or rejected.
 *
 * Wallet checkouts hold money in reserved_balance, so they are released back to
 * the spendable balance (restoring any promotional portion). Every other payment
 * method was already captured, so the amount is credited to the wallet instead.
 */
class OrderRefundService
{
    public function __construct(private WalletLedgerService $ledger) {}

    /**
     * Cancel an order and return the advertiser's money in one locked transaction.
     *
     * @return bool True when a refund was applied, false when the order was already
     *              cancelled/refunded or nothing had been charged yet.
     */
    public function cancelAndRefund(Order $order, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status === 'cancelled' || $locked->payment_status === 'refunded') {
                return false;
            }

            $amount = round((float) $locked->total_amount, 2);
            $refundable = $locked->payment_status === 'paid' && $amount > 0;

            $locked->update(array_filter([
                'status' => 'cancelled',
                'payment_status' => $refundable ? 'refunded' : null,
            ], fn ($value) => $value !== null));

            ContentSubmission::releaseAllForOrder((int) $locked->id);

            if (! $refundable) {
                return false;
            }

            $this->refundToAdvertiser($locked, $amount, $reason);

            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Resolve the refund amount when an order is cancelled entirely.
     * Prefer the authoritative order total; fall back to the sum of line prices.
     */
    public function resolveOrderCancelRefundAmount(Order $order): float
    {
        $orderTotal = round((float) $order->total_amount, 2);
        if ($orderTotal > 0) {
            return $orderTotal;
        }

        $order->loadMissing('items');

        return round(abs((float) $order->items->sum('price')), 2);
    }

    /**
     * Resolve the refund amount for a rejected line without over-crediting.
     * Single-item orders use the authoritative order total; multi-item orders
     * refund only the rejected line, capped at the order total.
     *
     * Prefer resolveOrderCancelRefundAmount() when the whole order is cancelled.
     */
    public function resolveLineRefundAmount(Order $order, float $lineAmount): float
    {
        $order->loadMissing('items');
        $orderTotal = round((float) $order->total_amount, 2);
        $lineAmount = round(abs($lineAmount), 2);

        if ($order->items->count() <= 1) {
            return $orderTotal > 0 ? $orderTotal : $lineAmount;
        }

        if ($lineAmount <= 0) {
            return 0.0;
        }

        return min($lineAmount, max(0.0, $orderTotal));
    }

    /**
     * Move funds back to the advertiser wallet. Must run inside a transaction with
     * the order already locked; throws so the caller's transaction rolls back.
     */
    public function refundToAdvertiser(Order $order, float $amount, ?string $reason = null): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return false;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);

        $bonusRestored = 0.0;
        if ($order->payment_method === 'wallet') {
            $bonusShare = $this->checkoutBonusShare($wallet, $order, $amount);
            $bonusReservedBefore = (float) $wallet->bonus_reserved;
            $wallet->refundReserved($amount, $bonusShare);
            $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));
        } else {
            // Card / Wise / bank / crypto may still hold leftover checkout bonus
            // in reserved. Restore only this line's share so a sibling reject
            // cannot unlock the whole checkout promo while other paid rows remain.
            $bonusShare = $this->checkoutBonusShare($wallet, $order, $amount);
            $cashShare = round($amount - $bonusShare, 2);
            if ($bonusShare > 0) {
                $bonusReservedBefore = (float) $wallet->bonus_reserved;
                $wallet->refundReserved($bonusShare);
                $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));
            }
            if ($cashShare > 0) {
                $wallet->credit($cashShare);
            }
        }

        $this->ledger->recordRefund(
            $wallet,
            $amount,
            $bonusRestored,
            $order,
            $order->reference_code ?? $order->order_number
        );

        Log::info('Order refunded to advertiser wallet', [
            'order_id' => $order->id,
            'payment_method' => $order->payment_method,
            'amount' => $amount,
            'bonus_restored' => $bonusRestored,
            'new_balance' => $wallet->balance,
            'new_reserved_balance' => $wallet->reserved_balance,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Drop reserved funds when an order is completed.
     * Wallet checkouts consume the full line. Card / manual checkouts only
     * consume leftover promotional reserve so it cannot be refunded as cash later.
     * Shared checkout bonus is pro-rated across still-paid siblings so the
     * first approve cannot burn promo that a later reject would mint as cash.
     */
    public function consumeReservedForSettledOrder(Order $order, Wallet $wallet): void
    {
        $total = round((float) $order->total_amount, 2);
        if ($total <= 0) {
            return;
        }

        $bonusShare = $this->checkoutBonusShare($wallet, $order, $total);

        if ($order->payment_method === 'wallet') {
            $wallet->consumeReserved($total, $bonusShare);
            $this->decrementRecordedCheckoutBonus($order, $bonusShare);

            return;
        }

        if ($bonusShare > 0) {
            $wallet->consumeReserved($bonusShare, $bonusShare);
            $this->decrementRecordedCheckoutBonus($order, $bonusShare);
        }
    }

    /**
     * Split leftover checkout bonus across still-paid siblings that share
     * the same reference. Using the whole reserved bucket on the first
     * reject or approve unlocked promo that a later sibling refund would
     * mint as withdrawable cash.
     */
    private function checkoutBonusShare(Wallet $wallet, Order $order, float $amount): float
    {
        $reserved = max(0, round((float) $wallet->bonus_reserved, 2));
        if ($reserved <= 0 || $amount <= 0) {
            return 0.0;
        }

        $reference = (string) ($order->reference_code ?? '');
        $reserved = $this->promoReserveForReference(
            $wallet,
            (int) ($order->user_id ?? 0),
            $reference
        );
        if ($reserved <= 0) {
            return 0.0;
        }
        $siblingTotal = 0.0;
        if ($reference !== '') {
            // Completed siblings already spent their share. Counting them
            // again would leave leftover promo reserved after the last
            // open line is approved or rejected.
            $siblingTotal = $this->openCheckoutSiblingTotal(
                (int) $order->user_id,
                $reference,
                [(int) $order->id]
            );
        }

        if ($siblingTotal <= 0) {
            return min($amount, $reserved);
        }

        $pool = round($amount + $siblingTotal, 2);
        if ($pool <= 0) {
            return min($amount, $reserved);
        }

        return min($amount, max(0, round($reserved * ($amount / $pool), 2)));
    }

    /**
     * Restore this line's share of leftover checkout bonus (unpaid fail / cancel).
     * Paid siblings keep their share reserved.
     */
    public function releaseReservedCheckoutBonus(Order $order): float
    {
        return $this->releaseReservedCheckoutBonusForReference(
            (int) $order->user_id,
            (string) ($order->reference_code ?? ''),
            collect([$order])
        );
    }

    /**
     * Restore leftover checkout bonus for a failed/cancelled reference.
     * Stripe-first (no rows) or no remaining open siblings releases the rest.
     *
     * @param  Collection<int, Order>  $failedOrders
     */
    public function releaseReservedCheckoutBonusForReference(
        int $userId,
        string $referenceCode,
        $failedOrders,
        ?float $fallbackBonus = null
    ): float {
        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId || $userId <= 0) {
            return 0.0;
        }

        $wallet = Wallet::where('user_id', $userId)->where('role_id', $advertiserRoleId)->lockForUpdate()->first();
        if (! $wallet) {
            return 0.0;
        }

        $reserved = max(0, round((float) $wallet->bonus_reserved, 2));
        $failed = collect($failedOrders);
        $failedIds = $failed->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $failedTotal = round((float) $failed->sum(fn ($order) => (float) ($order->total_amount ?? 0)), 2);
        $openTotal = $referenceCode !== ''
            ? $this->openCheckoutSiblingTotal($userId, $referenceCode, $failedIds)
            : 0.0;

        if ($reserved <= 0) {
            if ($openTotal <= 0) {
                app(CheckoutIntentService::class)->takeBonus($userId, $referenceCode, $fallbackBonus);
            }

            return 0.0;
        }

        if ($openTotal > 0 && ($failed->isEmpty() || $failedTotal <= 0)) {
            return 0.0;
        }

        $share = $reserved;
        if ($openTotal > 0 && $failedTotal > 0) {
            $pool = round($failedTotal + $openTotal, 2);
            $share = min($reserved, max(0, round($reserved * ($failedTotal / $pool), 2)));
        }

        $share = min($share, $this->promoReserveForReference($wallet, $userId, $referenceCode, $fallbackBonus));
        if ($share <= 0) {
            return 0.0;
        }

        $wallet->refundReserved($share, $share);

        $intents = app(CheckoutIntentService::class);
        if ($openTotal <= 0) {
            $intents->takeBonus($userId, $referenceCode, $fallbackBonus);
        } else {
            $intents->decrementBonus($userId, $referenceCode, $share);
        }

        return $share;
    }

    /**
     * Promo this reference may spend or refund. Recorded holds win; otherwise
     * leftover wallet promo minus other REFs' recorded holds (legacy rows).
     */
    private function promoReserveForReference(
        Wallet $wallet,
        int $userId,
        string $referenceCode,
        ?float $fallbackBonus = null
    ): float {
        $reserved = max(0, round((float) $wallet->bonus_reserved, 2));
        if ($reserved <= 0) {
            return 0.0;
        }

        $intents = app(CheckoutIntentService::class);
        $recorded = $referenceCode !== ''
            ? $intents->recordedBonus($userId, $referenceCode, $fallbackBonus)
            : 0.0;
        if ($recorded > 0) {
            return min($reserved, $recorded);
        }

        $other = $referenceCode !== ''
            ? $intents->otherRecordedBonus($userId, $referenceCode)
            : 0.0;

        return max(0, round($reserved - $other, 2));
    }

    private function decrementRecordedCheckoutBonus(Order $order, float $amount): void
    {
        $amount = round($amount, 2);
        $reference = (string) ($order->reference_code ?? '');
        $userId = (int) ($order->user_id ?? 0);
        if ($amount <= 0 || $userId <= 0 || $reference === '') {
            return;
        }

        app(CheckoutIntentService::class)->decrementBonus($userId, $reference, $amount);
    }

    /**
     * Still-open siblings that share this checkout's reserved promo.
     * Pending rows still hold a claim; completed/cancelled already settled.
     *
     * @param  list<int>  $excludeIds
     */
    private function openCheckoutSiblingTotal(int $userId, string $reference, array $excludeIds = []): float
    {
        if ($reference === '' || $userId <= 0) {
            return 0.0;
        }

        return round((float) Order::query()
            ->where('reference_code', $reference)
            ->where('user_id', $userId)
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->whereIn('payment_status', ['paid', 'pending'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->sum('total_amount'), 2);
    }
}
