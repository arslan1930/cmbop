<?php

namespace App\Services\Orders;

use App\Models\CheckoutIntent;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItemDispute;
use App\Models\Wallet;
use App\Services\CheckoutIntentService;
use App\Services\PaypalCheckoutService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Returns advertiser funds when an order is cancelled or rejected.
 *
 * Wallet checkouts hold money in reserved_balance, so they are released back to
 * the spendable balance (restoring any promotional portion). Card / bank / Wise /
 * crypto were already captured, so the amount is credited to the wallet instead.
 * PayPal checkout refunds the capture to the buyer and skips that wallet credit.
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
        return $this->cancelAndRefundBreakdown($order, $reason)['applied'];
    }

    /**
     * Cancel and return funds. `cash` is the wallet credit (card) or reserved
     * release (wallet) — not the gross line total, which may include promo.
     *
     * @return array{applied: bool, cash: float, bonus: float}
     */
    public function cancelAndRefundBreakdown(Order $order, ?string $reason = null): array
    {
        $peekAmount = $this->resolveOrderCancelRefundAmount($order);
        $this->refundPaypalCaptureIfPossible($order, $peekAmount);

        return DB::transaction(function () use ($order, $reason) {
            $none = ['applied' => false, 'cash' => 0.0, 'bonus' => 0.0];
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status === 'cancelled' || $locked->payment_status === 'refunded') {
                return $none;
            }

            $amount = $this->resolveOrderCancelRefundAmount($locked);
            $refundable = $locked->payment_status === 'paid' && $amount > 0;

            $locked->update(array_filter([
                'status' => 'cancelled',
                'payment_status' => $refundable ? 'refunded' : null,
            ], fn ($value) => $value !== null));

            ContentSubmission::releaseAllForOrder((int) $locked->id);

            if (! $refundable) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return $none;
            }

            $moved = $this->applyAdvertiserRefund($locked, $amount, $reason);
            $order->setRawAttributes($locked->getAttributes(), true);

            return $moved;
        });
    }

    /**
     * Resolve the refund amount when an order is cancelled entirely.
     * Prefer the authoritative order total; fall back to the sum of line prices.
     */
    public function resolveOrderCancelRefundAmount(Order $order): float
    {
        $orderTotal = round((float) $order->total_amount, 2);
        $alreadyCredited = $this->priorAdvertiserCredits($order);
        if ($orderTotal > 0) {
            return max(0.0, round($orderTotal - $alreadyCredited, 2));
        }

        $order->loadMissing('items');

        return max(0.0, round(abs((float) $order->items->sum('price')) - $alreadyCredited, 2));
    }

    /**
     * Line clawbacks already returned this slice. A later full-order cancel
     * must not credit the advertiser a second time.
     */
    private function priorAdvertiserCredits(Order $order): float
    {
        if (! $order->id || ! OrderItemDispute::tableAvailable()) {
            return 0.0;
        }

        return round((float) OrderItemDispute::query()
            ->where('order_id', $order->id)
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->sum('advertiser_credited'), 2);
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
    public function refundToAdvertiser(Order $order, float $amount, ?string $reason = null, ?float $maxBonusShare = null): bool
    {
        $this->refundPaypalCaptureIfPossible($order, $amount);

        return $this->applyAdvertiserRefund($order, $amount, $reason, $maxBonusShare)['applied'];
    }

    /**
     * PayPal already returned cash. Restore leftover checkout bonus only —
     * never mint wallet credit for a capture that was refunded outside admin.
     */
    public function restoreCheckoutBonusAfterExternalPaypalRefund(Order $order): float
    {
        if (($order->payment_method ?? '') !== 'paypal') {
            return 0.0;
        }

        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();
            if (! $locked) {
                return 0.0;
            }

            $amount = $this->resolveOrderCancelRefundAmount($locked);
            if ($amount <= 0) {
                return 0.0;
            }

            $moved = $this->applyAdvertiserRefund($locked, $amount, 'PayPal capture refunded');
            $order->setRawAttributes($locked->getAttributes(), true);

            return $moved['bonus'];
        });
    }

    /**
     * Refund a PayPal capture when one exists. HTTP stays outside the caller's
     * DB transaction when this is invoked first. Returns null only when there
     * is no capture (wallet fallback). A capture with PayPal unconfigured
     * fails closed — do not mint wallet cash while the buyer can still be
     * refunded in the PayPal dashboard.
     *
     * @return array{id: string, amount: float, status: string}|null
     */
    public function refundPaypalCaptureIfPossible(
        Order $order,
        float $amount,
        bool $allowAdditionalPartial = false,
        ?string $idempotencySuffix = null
    ): ?array {
        $amount = round($amount, 2);
        if (($order->payment_method ?? '') !== 'paypal' || $amount < 0.01) {
            return null;
        }

        $existingId = $this->existingPaypalRefundId($order);
        if ($existingId !== '' && ! $allowAdditionalPartial) {
            return [
                'id' => $existingId,
                'amount' => $amount,
                'status' => 'COMPLETED',
            ];
        }

        $captureId = $this->resolvePaypalCaptureId($order);
        if ($captureId === '') {
            return null;
        }

        $paypal = app(PaypalCheckoutService::class);
        if (! $paypal->configured()) {
            throw new \RuntimeException(
                'PayPal is not configured. Refund this capture in the PayPal dashboard first.'
            );
        }

        if (DB::transactionLevel() > 0) {
            Log::warning('PayPal refund API called inside a DB transaction', [
                'order_id' => $order->id,
                'paypal_capture_id' => $captureId,
            ]);
        }

        $requestId = 'refund-'.$captureId.'-'.number_format($amount, 2, '.', '');
        $suffix = trim((string) $idempotencySuffix);
        if ($suffix !== '') {
            $requestId .= '-'.$suffix;
        }

        [$refundAmount, $refundCurrency] = $this->paypalRefundAmount($order, $amount);
        $refunded = $paypal->refundCapture($captureId, $refundAmount, $requestId, $refundCurrency);
        $prepared = [
            'id' => (string) ($refunded['id'] ?? ''),
            'amount' => (float) ($refunded['amount'] ?? $amount),
            'status' => (string) ($refunded['status'] ?? ''),
        ];
        $this->stampPaypalRefundId($order, $prepared['id']);

        Log::info('PayPal capture refunded', [
            'order_id' => $order->id,
            'paypal_capture_id' => $captureId,
            'paypal_refund_id' => $prepared['id'],
            'amount' => $prepared['amount'],
        ]);

        return $prepared;
    }

    /**
     * PayPal must be refunded in the capture currency. The order amount is euros.
     *
     * @return array{0: float, 1: string}
     */
    private function paypalRefundAmount(Order $order, float $euroAmount): array
    {
        $raw = is_array($order->paypal_response) ? $order->paypal_response : [];
        $unit = is_array($raw['purchase_units'][0] ?? null) ? $raw['purchase_units'][0] : [];
        $capture = is_array($unit['payments']['captures'][0]['amount'] ?? null)
            ? $unit['payments']['captures'][0]['amount']
            : (is_array($unit['amount'] ?? null) ? $unit['amount'] : []);
        $currency = strtoupper((string) ($capture['currency_code'] ?? 'EUR'));
        $charged = round((float) ($capture['value'] ?? 0), 2);
        if (! in_array($currency, ['USD', 'GBP'], true) || $charged < 0.01) {
            return [$euroAmount, 'EUR'];
        }

        $ledger = 0.0;
        if (preg_match('/ledger EUR ([0-9]+(?:\.[0-9]{1,2})?)/', (string) ($unit['description'] ?? ''), $match) === 1) {
            $ledger = round((float) $match[1], 2);
        }
        if ($ledger < 0.01) {
            throw new \RuntimeException('PayPal refund is missing the euro ledger amount.');
        }

        $share = min(1.0, $euroAmount / $ledger);
        $refund = round($charged * $share, 2);
        if ($refund > $charged) {
            $refund = $charged;
        }
        if ($refund < 0.01) {
            throw new \RuntimeException('PayPal refund amount is below the minimum.');
        }

        return [$refund, $currency];
    }

    /**
     * Capture id for this row, or the sibling that stored the unique capture.
     */
    public function resolvePaypalCaptureId(Order $order): string
    {
        $own = trim((string) ($order->paypal_capture_id ?? ''));
        if ($own !== '') {
            return $own;
        }

        $paypalOrderId = trim((string) ($order->paypal_order_id ?? ''));
        $reference = trim((string) ($order->reference_code ?? ''));
        $query = Order::query()
            ->where('payment_method', 'paypal')
            ->whereNotNull('paypal_capture_id')
            ->where('paypal_capture_id', '!=', '');

        if ($paypalOrderId !== '') {
            $found = (clone $query)->where('paypal_order_id', $paypalOrderId)->value('paypal_capture_id');
            if (filled($found)) {
                return trim((string) $found);
            }
        }

        if ($reference !== '') {
            $found = (clone $query)->where('reference_code', $reference)->value('paypal_capture_id');
            if (filled($found)) {
                return trim((string) $found);
            }
        }

        return '';
    }

    /**
     * @return array{applied: bool, cash: float, bonus: float}
     */
    private function applyAdvertiserRefund(Order $order, float $amount, ?string $reason = null, ?float $maxBonusShare = null): array
    {
        $none = ['applied' => false, 'cash' => 0.0, 'bonus' => 0.0];
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return $none;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $externalPaypal = $this->externalPaypalRefundFor($order);
        $skipCashCredit = $externalPaypal !== null;

        $wallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);

        $bonusRestored = 0.0;
        if ($order->payment_method === 'wallet') {
            // Pro-rate only the promo that fits inside this hold. Capping
            // after splitting the whole bonus_reserved bucket still handed
            // another checkout's leftover to the first sibling.
            $holdCap = $this->walletHoldBonusCap($wallet, $order, $amount);
            $bonusShare = $this->checkoutBonusShare($wallet, $order, $amount, $holdCap);
            if ($maxBonusShare !== null && $maxBonusShare > 0) {
                $bonusShare = min($bonusShare, round($maxBonusShare, 2));
            }
        } else {
            $poolCap = $maxBonusShare ?? $this->cardLeftoverBonusCap(
                (int) $order->user_id,
                (string) ($order->reference_code ?? '')
            );
            $bonusShare = $this->checkoutBonusShare($wallet, $order, $amount, $poolCap);
        }

        $ledgerAmount = $amount;

        $cashShare = 0.0;
        if ($order->payment_method === 'wallet') {
            $reservedBefore = round((float) $wallet->reserved_balance, 2);
            if ($reservedBefore <= 0) {
                return $none;
            }

            $bonusReservedBefore = (float) $wallet->bonus_reserved;
            $wallet->refundReserved($amount, $bonusShare);
            $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));
            $ledgerAmount = max(0, round($reservedBefore - (float) $wallet->reserved_balance, 2));
            if ($ledgerAmount <= 0) {
                return $none;
            }
            $cashShare = $ledgerAmount;
        } else {
            // Card / PayPal / Wise / bank / crypto may still hold leftover checkout bonus
            // in reserved. Restore only this line's share so a sibling reject
            // cannot unlock the whole checkout promo while other paid rows remain.
            // An explicit cap (this reference's leftover) also stops a second
            // in-flight checkout on the same wallet from being stolen.
            // PayPal Refunds API already returned cash to the buyer — do not
            // also mint wallet credit (that would be a double refund).
            $cashShare = $skipCashCredit ? 0.0 : round($amount - $bonusShare, 2);
            if ($bonusShare > 0) {
                $bonusReservedBefore = (float) $wallet->bonus_reserved;
                $wallet->refundReserved($bonusShare);
                $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));
            }
            if ($cashShare > 0) {
                $wallet->credit($cashShare);
            }
            $this->syncCheckoutBonusAfterLeftoverRestore($order, $bonusRestored);
            if ($skipCashCredit && $externalPaypal !== null) {
                $this->stampPaypalRefundId($order, (string) $externalPaypal['id']);
            }
        }

        if (! $skipCashCredit || $bonusRestored > 0) {
            $this->ledger->recordRefund(
                $wallet,
                $skipCashCredit ? 0.0 : $ledgerAmount,
                $bonusRestored,
                $order,
                $order->reference_code ?? $order->order_number
            );
        }

        Log::info($skipCashCredit
            ? 'Order refunded via PayPal (wallet cash skipped)'
            : 'Order refunded to advertiser wallet', [
                'order_id' => $order->id,
                'payment_method' => $order->payment_method,
                'amount' => $amount,
                'bonus_restored' => $bonusRestored,
                'paypal_refund_id' => $externalPaypal['id'] ?? null,
                'new_balance' => $wallet->balance,
                'new_reserved_balance' => $wallet->reserved_balance,
                'reason' => $reason,
            ]);

        return [
            'applied' => true,
            'cash' => round($cashShare, 2),
            'bonus' => round($bonusRestored, 2),
        ];
    }

    /**
     * @return array{id: string, amount: float, status: string}|null
     */
    private function externalPaypalRefundFor(Order $order): ?array
    {
        $refundId = $this->existingPaypalRefundId($order);
        if ($refundId === '') {
            return null;
        }

        return [
            'id' => $refundId,
            'amount' => 0.0,
            'status' => 'COMPLETED',
        ];
    }

    /**
     * Refund id on this row or the capture-holder sibling (multi-line carts).
     */
    public function existingPaypalRefundId(Order $order): string
    {
        $own = trim((string) ($order->paypal_refund_id ?? ''));
        if ($own !== '') {
            return $own;
        }

        $holder = $this->paypalRefundIdHolder($order);
        if ($holder && filled($holder->paypal_refund_id)) {
            return trim((string) $holder->paypal_refund_id);
        }

        return '';
    }

    private function stampPaypalRefundId(Order $order, string $refundId): void
    {
        $refundId = trim($refundId);
        if ($refundId === '') {
            return;
        }

        $holder = $this->paypalRefundIdHolder($order);
        if (! $holder || filled($holder->paypal_refund_id)) {
            return;
        }

        $holder->update(['paypal_refund_id' => $refundId]);
        if ((int) $holder->id === (int) $order->id) {
            $order->paypal_refund_id = $refundId;
        }
    }

    private function paypalRefundIdHolder(Order $order): ?Order
    {
        $captureId = $this->resolvePaypalCaptureId($order);
        if ($captureId !== '') {
            $withCapture = Order::query()
                ->where('paypal_capture_id', $captureId)
                ->first();
            if ($withCapture) {
                return $withCapture;
            }
        }

        return $order;
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

        if ($order->payment_method === 'wallet') {
            $holdCap = $this->walletHoldBonusCap($wallet, $order, $total);
            $bonusShare = $this->checkoutBonusShare($wallet, $order, $total, $holdCap);
            $wallet->consumeReserved($total, $bonusShare);
            if ($bonusShare > 0) {
                $this->syncCheckoutBonusAfterLeftoverRestore($order, $bonusShare);
            }

            return;
        }

        $bonusShare = $this->checkoutBonusShare(
            $wallet,
            $order,
            $total,
            $this->cardLeftoverBonusCap(
                (int) $order->user_id,
                (string) ($order->reference_code ?? '')
            )
        );

        if ($bonusShare > 0) {
            $wallet->consumeReserved($bonusShare, $bonusShare);
            // Pair leftover consume with the leftover hold. Leaving the peek
            // after approve made releaseAbandonedStripeFirstBonus refundReserved
            // another in-flight checkout's promo.
            $this->syncCheckoutBonusAfterLeftoverRestore($order, $bonusShare);
        }
    }

    /**
     * Promo that can belong to this wallet hold. Reserved above this line
     * (and its same-reference siblings) is another checkout's leftover.
     */
    private function walletHoldBonusCap(Wallet $wallet, Order $order, float $amount): float
    {
        $reserved = max(0, round((float) $wallet->reserved_balance, 2));
        $bonus = max(0, round((float) $wallet->bonus_reserved, 2));
        $siblingTotal = $this->openCheckoutSiblingTotal(
            (int) $order->user_id,
            (string) ($order->reference_code ?? ''),
            [(int) $order->id]
        );
        $otherReserved = max(0, round($reserved - $amount - $siblingTotal, 2));

        return max(0, round($bonus - $otherReserved, 2));
    }

    /**
     * Split leftover checkout bonus across still-paid siblings that share
     * the same reference. Using the whole reserved bucket on the first
     * reject or approve unlocked promo that a later sibling refund would
     * mint as withdrawable cash.
     */
    private function checkoutBonusShare(Wallet $wallet, Order $order, float $amount, ?float $poolCap = null): float
    {
        $reserved = max(0, round((float) $wallet->bonus_reserved, 2));
        if ($poolCap !== null) {
            $reserved = min($reserved, max(0, round($poolCap, 2)));
        }
        if ($reserved <= 0 || $amount <= 0) {
            return 0.0;
        }

        $reference = (string) ($order->reference_code ?? '');
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

        $peek = $this->liveCheckoutBonusCap($userId, $referenceCode, $fallbackBonus);

        if ($reserved <= 0) {
            if ($openTotal <= 0) {
                app(CheckoutIntentService::class)->takeBonus($userId, $referenceCode, $fallbackBonus);
            }

            return 0.0;
        }

        if ($openTotal > 0 && ($failed->isEmpty() || $failedTotal <= 0)) {
            return 0.0;
        }

        // Cap the pool first, then pro-rate. Capping after a split of the
        // whole bucket still gave the first sibling another checkout's promo.
        $share = $reserved;
        if ($peek > 0) {
            $share = min($share, $peek);
        } elseif ($failed->isEmpty() || $openTotal <= 0 || $this->otherOpenCheckoutExists($userId, $referenceCode)) {
            // Unknown bonus for this ref — do not unlock another checkout's reserve.
            $share = 0.0;
        }

        if ($openTotal > 0 && $failedTotal > 0 && $share > 0) {
            $pool = round($failedTotal + $openTotal, 2);
            $share = min($share, max(0, round($share * ($failedTotal / $pool), 2)));
        }

        if ($share <= 0) {
            if ($openTotal <= 0) {
                app(CheckoutIntentService::class)->takeBonus($userId, $referenceCode, $fallbackBonus);
            }

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
     * Keep this reference's leftover peek in sync after a card refund.
     * A stale full peek plus another checkout's reserved bonus let a later
     * unpaid fail dump the other checkout's promo.
     */
    private function syncCheckoutBonusAfterLeftoverRestore(Order $order, float $restored): void
    {
        $userId = (int) $order->user_id;
        $reference = (string) ($order->reference_code ?? '');
        if ($userId <= 0 || $reference === '') {
            return;
        }

        $intents = app(CheckoutIntentService::class);
        $openTotal = $this->openCheckoutSiblingTotal($userId, $reference, [(int) $order->id]);
        if ($openTotal <= 0) {
            $intents->takeBonus($userId, $reference);

            return;
        }

        $reduce = $restored;
        if ($reduce <= 0) {
            $peek = $intents->peekBonus($userId, $reference);
            $amount = round((float) $order->total_amount, 2);
            $pool = round($amount + $openTotal, 2);
            $reduce = ($peek > 0 && $pool > 0)
                ? min($peek, max(0, round($peek * ($amount / $pool), 2)))
                : 0.0;
        }

        if ($reduce > 0) {
            $intents->decrementBonus($userId, $reference, $reduce);
        }
    }

    /**
     * Card leftover share after finalize may have no intent row left.
     * Uncapped (null) is safe only when no other checkout holds promo.
     */
    public function cardLeftoverBonusCap(int $userId, string $referenceCode): ?float
    {
        $held = app(CheckoutIntentService::class)->heldBonus($userId, $referenceCode);
        if ($held > 0.009) {
            return $held;
        }

        return $this->otherLiveCheckoutBonusExists($userId, $referenceCode) ? 0.0 : null;
    }

    /**
     * Live hold for this reference. A stale package snapshot / fallback must
     * not cap a release when another checkout still holds promo.
     */
    private function liveCheckoutBonusCap(int $userId, string $referenceCode, ?float $fallbackBonus): float
    {
        $held = app(CheckoutIntentService::class)->heldBonus($userId, $referenceCode);
        if ($held > 0.009) {
            return $held;
        }

        $fallback = round((float) ($fallbackBonus ?? 0), 2);
        if ($fallback <= 0.009 || $this->otherLiveCheckoutBonusExists($userId, $referenceCode)) {
            return 0.0;
        }

        return $fallback;
    }

    private function otherLiveCheckoutBonusExists(int $userId, string $reference): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ($this->otherOpenCheckoutExists($userId, $reference)) {
            return true;
        }

        if (! Schema::hasTable((new CheckoutIntent)->getTable())) {
            return false;
        }

        return CheckoutIntent::query()
            ->where('user_id', $userId)
            ->when($reference !== '', fn ($q) => $q->where('reference_code', '!=', $reference))
            ->where('bonus_applied', '>', 0)
            ->exists();
    }

    /**
     * Another checkout still has paid/pending rows that may hold reserved promo.
     */
    private function otherOpenCheckoutExists(int $userId, string $reference): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return Order::query()
            ->where('user_id', $userId)
            ->when($reference !== '', fn ($q) => $q->where('reference_code', '!=', $reference))
            ->whereIn('payment_status', ['paid', 'pending'])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->exists();
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
