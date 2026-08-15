<?php

namespace App\Services;

use App\Mail\SiteOwnerOrderNotification;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\Orders\OrderRefundService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class OrderPaymentService
{
    /**
     * Mark pending card orders paid from a verified Stripe checkout session.
     * Idempotent: already-paid orders are left unchanged.
     *
     * @return Collection<int, Order> Orders that transitioned to paid in this call
     */
    public function markOrdersPaidFromStripeSession(string $referenceCode, object $session): Collection
    {
        $this->assertStripeObjectIsOrderPayment($session);
        $sessionMeta = $this->sessionMetadataArray($session);
        $newlyPaid = DB::transaction(function () use ($referenceCode, $session) {
            $orders = $this->lockCardOrdersForPayer(
                $referenceCode,
                $this->sessionMetadataArray($session)
            );

            if ($orders->isEmpty()) {
                Log::warning('No card orders found for Stripe payment', [
                    'reference_code' => $referenceCode,
                    'session_id' => $session->id ?? null,
                ]);

                return collect();
            }

            $meta = $this->sessionMetadataArray($session);
            if (! $this->existingCardOrdersMatchStripeCharge($orders, $session, $meta)) {
                if ($this->pendingPackageBelongsToPayer($referenceCode, $meta)) {
                    Log::warning('Stripe charge does not match existing card order totals; leaving rows for package finalize', [
                        'reference_code' => $referenceCode,
                        'session_id' => $session->id ?? null,
                    ]);

                    return collect();
                }

                $this->assertStripeAmountMatchesExpected(
                    $session,
                    $this->markableCardOrdersDue($orders, $meta),
                    $referenceCode
                );
            }

            $this->assertStripeAmountMatchesExpected(
                $session,
                $this->expectedStripeEurosForOrders($orders, $meta),
                $referenceCode
            );

            $newlyPaid = collect();

            foreach ($orders as $order) {
                if (! $this->canMarkCardOrderPaid($order)) {
                    continue;
                }

                // Keep publisher-visible pending status (scheduled date is in publication_mode).
                $order->update([
                    'stripe_session_id' => $session->id ?? $order->stripe_session_id,
                    'stripe_payment_intent_id' => $session->payment_intent ?? $order->stripe_payment_intent_id,
                    'stripe_response' => method_exists($session, 'toArray')
                        ? json_encode($session->toArray())
                        : json_encode($session),
                    'paid_at' => now(),
                    'payment_status' => 'paid',
                    'status' => 'pending',
                ]);

                $newlyPaid->push($order->fresh('items'));
            }

            // Keep leftover checkout bonus reserved until approve/reject.
            // Consuming here made card+bonus rejects credit the promo slice as cash.

            return $newlyPaid;
        });

        $this->recordAdvertiserPurchaseForPaidCheckout(
            $referenceCode,
            $newlyPaid,
            (float) ($sessionMeta['bonus_applied'] ?? 0),
            (float) ($sessionMeta['order_total'] ?? 0)
        );
        $this->evaluateSpendBudgetAfterPaidOrders($newlyPaid);
        $this->creditHiddenCardOrdersAfterMarkPaid($referenceCode, $sessionMeta, $session);
        $this->persistPaidCheckoutBonus(
            (int) ($sessionMeta['user_id'] ?? ($newlyPaid->first()->user_id ?? 0)),
            $referenceCode,
            (float) ($sessionMeta['bonus_applied'] ?? 0)
        );

        return $newlyPaid;
    }

    /**
     * Mark pending/failed card orders paid from a confirmed PaymentIntent (saved card).
     *
     * @return Collection<int, Order>
     */
    public function markOrdersPaidFromPaymentIntent(string $referenceCode, object $intent): Collection
    {
        $this->assertStripeObjectIsOrderPayment($intent);
        $meta = [];
        if (isset($intent->metadata)) {
            $meta = is_array($intent->metadata)
                ? $intent->metadata
                : (method_exists($intent->metadata, 'toArray') ? $intent->metadata->toArray() : (array) $intent->metadata);
        }

        $newlyPaid = DB::transaction(function () use ($referenceCode, $intent, $meta) {
            $orders = $this->lockCardOrdersForPayer($referenceCode, $meta);

            if ($orders->isEmpty()) {
                return collect();
            }

            if (! $this->existingCardOrdersMatchStripeCharge($orders, $intent, $meta)) {
                if ($this->pendingPackageBelongsToPayer($referenceCode, $meta)) {
                    Log::warning('PaymentIntent charge does not match existing card order totals; leaving rows for package finalize', [
                        'reference_code' => $referenceCode,
                        'payment_intent' => $intent->id ?? null,
                    ]);

                    return collect();
                }

                $this->assertStripeAmountMatchesExpected(
                    $intent,
                    $this->markableCardOrdersDue($orders, $meta),
                    $referenceCode
                );
            }

            $this->assertStripeAmountMatchesExpected(
                $intent,
                $this->expectedStripeEurosForOrders($orders, $meta),
                $referenceCode
            );

            $newlyPaid = collect();
            foreach ($orders as $order) {
                if (! $this->canMarkCardOrderPaid($order)) {
                    continue;
                }

                $order->update([
                    'stripe_payment_intent_id' => $intent->id ?? $order->stripe_payment_intent_id,
                    'stripe_response' => method_exists($intent, 'toArray')
                        ? json_encode($intent->toArray())
                        : json_encode($intent),
                    'paid_at' => now(),
                    'payment_status' => 'paid',
                    'status' => 'pending',
                ]);
                $newlyPaid->push($order->fresh('items'));
            }

            // Keep leftover checkout bonus reserved until approve/reject.

            return $newlyPaid;
        });

        $this->recordAdvertiserPurchaseForPaidCheckout(
            $referenceCode,
            $newlyPaid,
            (float) ($meta['bonus_applied'] ?? 0),
            (float) ($meta['order_total'] ?? 0)
        );
        $this->evaluateSpendBudgetAfterPaidOrders($newlyPaid);
        $this->creditHiddenCardOrdersAfterMarkPaid($referenceCode, $meta, $intent);
        $this->persistPaidCheckoutBonus(
            (int) ($meta['user_id'] ?? ($newlyPaid->first()->user_id ?? 0)),
            $referenceCode,
            (float) ($meta['bonus_applied'] ?? 0)
        );

        return $newlyPaid;
    }

    /**
     * Card / bonus-only checkouts reserved promo without a purchase ledger row.
     * Clawback then treated the refund as cash. Write the same purchase hint
     * wallet checkout already writes, once per reference.
     *
     * @param  Collection<int, Order>  $orders
     */
    protected function recordAdvertiserPurchaseForPaidCheckout(
        string $referenceCode,
        Collection $orders,
        float $bonusApplied,
        float $orderTotal = 0.0
    ): void {
        $bonusApplied = round($bonusApplied, 2);
        if ($bonusApplied <= 0 || $orders->isEmpty()) {
            return;
        }

        $userId = (int) ($orders->first()->user_id ?? 0);
        $advertiserRoleId = Wallet::advertiserRoleId();
        if ($userId <= 0 || ! $advertiserRoleId) {
            return;
        }

        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('role_id', $advertiserRoleId)
            ->first();
        if (! $wallet) {
            return;
        }

        $total = round($orderTotal, 2);
        if ($total <= 0) {
            $total = round((float) $orders->sum(fn (Order $order) => (float) $order->total_amount), 2);
        }
        if ($total <= 0) {
            $total = $bonusApplied;
        }

        app(WalletLedgerService::class)->recordPurchaseOnce(
            $wallet,
            $total,
            $bonusApplied,
            $orders->first(),
            $referenceCode
        );
    }

    /**
     * Soft spend-budget alerts after card checkout (idempotent per period).
     *
     * @param  Collection<int, Order>  $orders
     */
    protected function evaluateSpendBudgetAfterPaidOrders(Collection $orders): void
    {
        if ($orders->isEmpty()) {
            return;
        }

        $userId = (int) ($orders->first()->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        try {
            app(SpendBudgetService::class)->evaluate($user);
        } catch (\Throwable $e) {
            Log::warning('Spend budget evaluate after card payment failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark pending card orders as payment_status=failed (session expired / declined).
     * Refunds any reserved checkout bonus for the reference. Leaves order rows intact for Pay again.
     *
     * @return Collection<int, Order>
     */
    public function markOrdersFailedFromReference(string $referenceCode, ?string $reason = null, ?int $userId = null, ?float $bonusFallback = null, ?string $expiredSessionId = null): Collection
    {
        $failed = DB::transaction(function () use ($referenceCode, $reason, $userId, $bonusFallback, $expiredSessionId) {
            $package = $this->getPendingCheckout($referenceCode);
            $liveSessionId = trim((string) ($package['stripe_session_id'] ?? ''));
            $expiredSessionId = trim((string) ($expiredSessionId ?? ''));
            if (is_array($package) && $expiredSessionId !== '' && $liveSessionId !== '' && $expiredSessionId !== $liveSessionId) {
                Log::info('Ignoring stale checkout.session.expired; live package belongs to another session', [
                    'reference_code' => $referenceCode,
                    'expired_session_id' => $expiredSessionId,
                    'live_session_id' => $liveSessionId,
                ]);

                return collect();
            }

            $resolvedUserId = $userId && $userId > 0
                ? (int) $userId
                : (int) ($package['user_id'] ?? 0);

            $orders = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'pending')
                ->when($resolvedUserId > 0, fn ($query) => $query->where('user_id', $resolvedUserId))
                ->when($expiredSessionId !== '', function ($query) use ($expiredSessionId) {
                    $query->where(function ($session) use ($expiredSessionId) {
                        $session->where('stripe_session_id', $expiredSessionId)
                            ->orWhereNull('stripe_session_id')
                            ->orWhere('stripe_session_id', '');
                    });
                })
                ->lockForUpdate()
                ->get();

            if ($resolvedUserId <= 0 && $orders->pluck('user_id')->unique()->count() > 1) {
                throw new \RuntimeException('Order payment reference is ambiguous without user_id');
            }

            $marked = collect();
            foreach ($orders as $order) {
                $order->update([
                    'payment_status' => 'failed',
                ]);
                $marked->push($order->fresh());
            }

            $resolvedUserId = (int) ($marked->first()?->user_id
                ?? ($package['user_id'] ?? 0)
                ?: ($userId ?? 0));

            if ($resolvedUserId > 0) {
                $fallback = $bonusFallback;
                if (($fallback ?? 0) <= 0) {
                    $fallback = round((float) ($package['bonus_applied'] ?? 0), 2);
                }
                $this->refundBonusReservedForReference($resolvedUserId, $referenceCode, $fallback, $marked);
            }
            $packageUserId = (int) ($package['user_id'] ?? 0);
            if ($packageUserId <= 0 || $resolvedUserId <= 0 || $packageUserId === $resolvedUserId) {
                $this->forgetPendingCheckout($referenceCode);
            }

            if ($marked->isNotEmpty()) {
                Log::info('Marked card orders payment_status=failed', [
                    'reference_code' => $referenceCode,
                    'order_count' => $marked->count(),
                    'reason' => $reason,
                ]);
            } else {
                Log::info('Expired Stripe-first checkout with no order rows; released reserved bonus', [
                    'reference_code' => $referenceCode,
                    'user_id' => $resolvedUserId,
                    'reason' => $reason,
                ]);
            }

            return $marked;
        });

        if ($failed->isNotEmpty()) {
            try {
                app(InAppNotificationService::class)->notifyPaymentFailed($failed, $reason);
            } catch (\Throwable $e) {
                Log::warning('notifyPaymentFailed failed after card payment failure', [
                    'reference_code' => $referenceCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $failed;
    }

    /**
     * Refund promotional credit reserved for a card checkout reference.
     */
    /**
     * @param  Collection<int, Order>|null  $failedOrders
     */
    public function refundBonusReservedForReference(
        int $userId,
        string $referenceCode,
        ?float $fallbackBonus = null,
        ?Collection $failedOrders = null
    ): void {
        app(OrderRefundService::class)->releaseReservedCheckoutBonusForReference(
            $userId,
            $referenceCode,
            $failedOrders ?? collect(),
            $fallbackBonus
        );
    }

    /**
     * Return promo reserved for an abandoned Stripe-first attempt on this REF.
     * Only this reference's recorded bonus is released so a later full-card
     * charge on the same REF cannot be approved by burning leftover promo.
     * Paid siblings still waiting on approve keep their hold — releasing it
     * would return promo to spendable, then a later reject credits cash.
     */
    public function releaseRecordedCheckoutBonus(int $userId, string $referenceCode): float
    {
        if ($userId <= 0 || $referenceCode === '') {
            return 0.0;
        }

        if ($this->hasOpenPaidCheckoutSiblings($userId, $referenceCode)) {
            return 0.0;
        }

        $intents = app(CheckoutIntentService::class);
        $recorded = $intents->recordedBonus($userId, $referenceCode);
        if ($recorded <= 0) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return 0.0;
        }

        return (float) DB::transaction(function () use ($userId, $roleId, $recorded, $referenceCode, $intents) {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->lockForUpdate()
                ->first();
            if (! $wallet) {
                return 0.0;
            }

            $share = min($recorded, max(0, round((float) $wallet->bonus_reserved, 2)));
            if ($share <= 0) {
                $intents->takeBonus($userId, $referenceCode);

                return 0.0;
            }

            $wallet->refundReserved($share, $share);
            if ($share + 0.009 >= $recorded) {
                $intents->takeBonus($userId, $referenceCode);
            } else {
                $intents->decrementBonus($userId, $referenceCode, $share);
            }

            return $share;
        });
    }

    /**
     * Paid rows that still need this REF's promo hold (pending publisher
     * approve). Pending/failed unpaid card rows do not count — Pay again
     * must still be able to return an abandoned Use-bonus reserve.
     */
    private function hasOpenPaidCheckoutSiblings(int $userId, string $referenceCode): bool
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('reference_code', $referenceCode)
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->exists();
    }

    public function persistPaidCheckoutBonus(int $userId, string $referenceCode, float $bonus): void
    {
        $bonus = round($bonus, 2);
        if ($userId <= 0 || $referenceCode === '' || $bonus <= 0) {
            return;
        }

        app(CheckoutIntentService::class)->rememberBonus($userId, $referenceCode, $bonus);
    }

    /**
     * Reserve leftover welcome bonus for a card / manual checkout.
     * Must lock the wallet row so two tabs cannot both apply the same €20.
     */
    public function reserveCheckoutBonus(int $userId, float $orderTotal): float
    {
        $orderTotal = round(max(0, $orderTotal), 2);
        if ($userId <= 0 || $orderTotal <= 0) {
            return 0.0;
        }

        return (float) DB::transaction(function () use ($userId, $orderTotal) {
            $roleId = Wallet::advertiserRoleId();
            if (! $roleId) {
                return 0.0;
            }

            $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
            $wallet->repairOrphanedWelcomeBonus();
            $wallet->reconcileInflatedBonusBalance();
            $wallet->refresh();

            return $wallet->reserveBonusOnly(min($wallet->lockedBonusBalance(), $orderTotal));
        });
    }

    /**
     * Hosted card checkout (or leftover pending card rows) still open on this REF.
     * Wallet must not forget that package — a later Stripe capture would credit
     * cash while wallet orders also stand, so the advertiser pays twice.
     */
    public function hasInFlightCardCheckout(int $userId, string $referenceCode): bool
    {
        if ($userId <= 0 || $referenceCode === '') {
            return false;
        }

        $package = $this->getPendingCheckout($referenceCode);
        if (is_array($package) && (int) ($package['user_id'] ?? 0) === $userId) {
            return true;
        }

        return Order::query()
            ->where('user_id', $userId)
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->where('payment_status', 'pending')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->exists();
    }

    public static function unfulfilledCardCreditReference(string $referenceCode, string $chargeToken = ''): string
    {
        $base = 'UNFULFILLED-CARD-'.$referenceCode;
        if ($chargeToken === '') {
            return $base;
        }

        return $base.'-'.substr($chargeToken, 0, 40);
    }

    /**
     * Credit captured card cash when Stripe-first lines cannot be fulfilled.
     * Idempotent per checkout reference, or per Stripe charge when $chargeToken is set.
     */
    public function creditUnfulfilledCardCapture(int $userId, string $referenceCode, float $amount, string $chargeToken = ''): float
    {
        $amount = round($amount, 2);
        if ($userId <= 0 || $amount <= 0) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return 0.0;
        }

        $reference = self::unfulfilledCardCreditReference($referenceCode, $chargeToken);

        return (float) DB::transaction(function () use ($userId, $roleId, $amount, $reference, $referenceCode, $chargeToken) {
            $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
            if (Schema::hasTable((new WalletTransaction)->getTable())
                && WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('reference', $reference)
                    ->exists()) {
                return 0.0;
            }

            $wallet->credit($amount);
            app(WalletLedgerService::class)->recordAdjustment(
                $wallet,
                $amount,
                'credit',
                null,
                $reference,
                'Card payment credited because listing(s) could not be fulfilled',
                array_filter([
                    'reference_code' => $referenceCode,
                    'charge_token' => $chargeToken !== '' ? $chargeToken : null,
                ])
            );

            Log::info('Credited unfulfilled Stripe-first card capture to advertiser wallet', [
                'user_id' => $userId,
                'reference_code' => $referenceCode,
                'amount' => $amount,
            ]);

            return $amount;
        });
    }

    /**
     * After a paid Stripe session, credit card cash for pending rows whose
     * listings left the catalog and cancel those rows so webhooks settle.
     *
     * @param  array<string, mixed>  $meta
     */
    private function creditHiddenCardOrdersAfterMarkPaid(string $referenceCode, array $meta, ?object $session = null): void
    {
        $orders = Order::with('items.site')
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->when(
                isset($meta['user_id']) && (int) $meta['user_id'] > 0,
                fn ($query) => $query->where('user_id', (int) $meta['user_id'])
            )
            ->get();

        $hiddenPending = $orders->filter(function (Order $order) {
            if (! in_array($order->payment_status, ['pending', 'failed'], true)) {
                return false;
            }
            if (in_array((string) $order->status, ['cancelled', 'completed'], true)) {
                return false;
            }

            return ! $order->hasCatalogVisibleFulfillment();
        });

        if ($hiddenPending->isEmpty()) {
            return;
        }

        $userId = (int) ($hiddenPending->first()->user_id ?? 0);
        $paidTotal = round((float) $orders
            ->filter(fn (Order $order) => $order->payment_status === 'paid')
            ->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $expected = $this->expectedStripeEurosForOrders($orders, $meta);
        $unfulfilled = round(max(0, $expected - $paidTotal), 2);
        if ($userId > 0 && $unfulfilled > 0.009) {
            $this->creditUnfulfilledCardCapture(
                $userId,
                $referenceCode,
                $unfulfilled,
                $session ? $this->stripeChargeToken($session) : ''
            );
        }

        foreach ($hiddenPending as $order) {
            $order->update(['status' => 'cancelled']);
        }

        if ($userId > 0) {
            $this->refundBonusReservedForReference(
                $userId,
                $referenceCode,
                isset($meta['bonus_applied']) ? round((float) $meta['bonus_applied'], 2) : null,
                $hiddenPending
            );
        }
    }

    public function unfulfilledCardCreditAmount(string $referenceCode, ?int $userId = null): float
    {
        if (! Schema::hasTable((new WalletTransaction)->getTable())) {
            return 0.0;
        }

        $base = self::unfulfilledCardCreditReference($referenceCode);
        $query = WalletTransaction::query()
            ->where('direction', 'credit')
            ->where(function ($credit) use ($base) {
                $credit->where('reference', $base)
                    ->orWhere('reference', 'like', $base.'-%');
            });

        if ($userId && $userId > 0) {
            $roleId = Wallet::advertiserRoleId();
            $walletId = $roleId
                ? Wallet::query()->where('user_id', $userId)->where('role_id', $roleId)->value('id')
                : null;
            if (! $walletId) {
                return 0.0;
            }
            $query->where('wallet_id', $walletId);
        }

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * Credit this Stripe capture when it cannot create or mark orders.
     * Idempotent per session / PaymentIntent so a second charge on the same REF is not lost.
     */
    public function creditCapturedCardWhenUnfulfillable(int $userId, string $referenceCode, object $session): float
    {
        $amount = $this->stripeEurosFromObject($session);
        $alreadySettled = round(
            (float) $this->paidCardOrdersForStripeCharge($referenceCode, $session, $userId)
                ->sum(fn (Order $order) => (float) $order->total_amount)
            + $this->refundedCardOrderAmountForStripeCharge($referenceCode, $session, $userId),
            2
        );
        $amount = round(max(0, $amount - $alreadySettled), 2);

        return $this->creditUnfulfilledCardCapture(
            $userId,
            $referenceCode,
            $amount,
            $this->stripeChargeToken($session)
        );
    }

    /**
     * Paid card orders created or marked paid by this Stripe session / PaymentIntent.
     *
     * @return Collection<int, Order>
     */
    public function paidCardOrdersForStripeCharge(string $referenceCode, object $session, int $payerId = 0): Collection
    {
        [$sessionId, $paymentIntentId] = $this->stripeChargeIds($session);
        if ($sessionId === '' && $paymentIntentId === '') {
            return collect();
        }

        return Order::query()
            ->with('items')
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->where('payment_status', 'paid')
            ->when($payerId > 0, fn ($query) => $query->where('user_id', $payerId))
            ->where(function ($query) use ($sessionId, $paymentIntentId) {
                if ($sessionId !== '') {
                    $query->orWhere('stripe_session_id', $sessionId);
                }
                if ($paymentIntentId !== '') {
                    $query->orWhere('stripe_payment_intent_id', $paymentIntentId);
                }
            })
            ->get();
    }

    /**
     * Card cash already returned via cancelAndRefund (e.g. a taken Content Library line).
     */
    public function refundedCardOrderAmount(string $referenceCode, ?int $userId = null): float
    {
        return round((float) Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->where('payment_status', 'refunded')
            ->when($userId && $userId > 0, fn ($query) => $query->where('user_id', $userId))
            ->sum('total_amount'), 2);
    }

    public function refundedCardOrderAmountForStripeCharge(string $referenceCode, object $session, int $payerId = 0): float
    {
        [$sessionId, $paymentIntentId] = $this->stripeChargeIds($session);
        if ($sessionId === '' && $paymentIntentId === '') {
            return 0.0;
        }

        return round((float) Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->where('payment_status', 'refunded')
            ->when($payerId > 0, fn ($query) => $query->where('user_id', $payerId))
            ->where(function ($query) use ($sessionId, $paymentIntentId) {
                if ($sessionId !== '') {
                    $query->orWhere('stripe_session_id', $sessionId);
                }
                if ($paymentIntentId !== '') {
                    $query->orWhere('stripe_payment_intent_id', $paymentIntentId);
                }
            })
            ->sum('total_amount'), 2);
    }

    /**
     * Wallet cash already given back when a paid card checkout could not be fulfilled.
     * Unfulfilled-card credits and cancelAndRefund rows do not overlap.
     */
    public function walletCreditForUnfulfillableCardCheckout(string $referenceCode, ?int $userId = null): float
    {
        return round(
            $this->unfulfilledCardCreditAmount($referenceCode, $userId)
            + $this->refundedCardOrderAmount($referenceCode, $userId),
            2
        );
    }

    /**
     * Settlement ack for THIS Stripe capture only. A prior refund or catalog-left
     * credit on the same 6-digit REF must not make a later charge look settled.
     */
    public function walletCreditForThisCardCharge(string $referenceCode, object $session, ?int $userId = null): float
    {
        $payerId = $userId && $userId > 0 ? (int) $userId : 0;

        return round(
            $this->unfulfilledCardCreditAmountForCharge($referenceCode, $session, $userId)
            + $this->refundedCardOrderAmountForStripeCharge($referenceCode, $session, $payerId),
            2
        );
    }

    public function unfulfilledCardCreditAmountForCharge(string $referenceCode, object $session, ?int $userId = null): float
    {
        if (! Schema::hasTable((new WalletTransaction)->getTable())) {
            return 0.0;
        }

        $token = $this->stripeChargeToken($session);
        if ($token === '') {
            return 0.0;
        }

        $reference = self::unfulfilledCardCreditReference($referenceCode, $token);
        $query = WalletTransaction::query()
            ->where('direction', 'credit')
            ->where('reference', $reference);

        if ($userId && $userId > 0) {
            $roleId = Wallet::advertiserRoleId();
            $walletId = $roleId
                ? Wallet::query()->where('user_id', $userId)->where('role_id', $roleId)->value('id')
                : null;
            if (! $walletId) {
                return 0.0;
            }
            $query->where('wallet_id', $walletId);
        }

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * Idempotency is per Stripe charge, not per 6-digit REF. A later checkout
     * that reused the session REF must not look "already paid".
     */
    public function paidCardOrderCountForStripeCharge(string $referenceCode, object $session, int $payerId = 0): int
    {
        return $this->paidCardOrdersForStripeCharge($referenceCode, $session, $payerId)->count();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function stripeChargeIds(object $session): array
    {
        $id = (string) ($session->id ?? '');
        $object = (string) ($session->object ?? '');
        if ($object === 'payment_intent' || str_starts_with($id, 'pi_')) {
            return ['', $id];
        }

        $paymentIntent = $session->payment_intent ?? '';
        if (is_object($paymentIntent)) {
            $paymentIntent = $paymentIntent->id ?? '';
        }

        return [$id, (string) $paymentIntent];
    }

    private function stripeChargeToken(object $session): string
    {
        [$sessionId, $paymentIntentId] = $this->stripeChargeIds($session);

        return $sessionId !== '' ? $sessionId : $paymentIntentId;
    }

    /**
     * Cache key for Stripe-first card checkout packages (Add Funds style).
     */
    public static function pendingCheckoutCacheKey(string $referenceCode): string
    {
        return CheckoutIntentService::pendingCheckoutCacheKey($referenceCode);
    }

    /**
     * Store a serializable checkout package until Stripe payment succeeds.
     *
     * @param  array<string, mixed>  $package
     */
    public function storePendingCheckout(string $referenceCode, array $package): void
    {
        $userId = (int) ($package['user_id'] ?? 0);
        if ($userId > 0) {
            $this->cancelUnpaidCardOrdersForNewCheckout($referenceCode, $userId);
        }

        app(CheckoutIntentService::class)->storePackage($referenceCode, $package);
    }

    /**
     * Bind the live hosted Checkout Session to this REF's package so a
     * later expiry of a superseded session cannot delete it.
     */
    public function attachCheckoutSessionId(string $referenceCode, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        $package = $this->getPendingCheckout($referenceCode);
        if ($sessionId === '' || ! is_array($package)) {
            return;
        }

        $package['stripe_session_id'] = $sessionId;
        app(CheckoutIntentService::class)->storePackage($referenceCode, $package);
    }

    public function forgetPendingCheckout(string $referenceCode, ?int $userId = null): void
    {
        app(CheckoutIntentService::class)->forget($referenceCode, $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPendingCheckout(string $referenceCode): ?array
    {
        return app(CheckoutIntentService::class)->getPackage($referenceCode);
    }

    /**
     * Create paid card orders from a Stripe-first pending package (like Add Funds deposit create-after-pay).
     * If orders already exist for the reference, mark them paid instead.
     *
     * @return Collection<int, Order>
     */
    public function finalizeStripeFirstCheckout(string $referenceCode, object $session): Collection
    {
        try {
            return Cache::lock('stripe_finalize:'.$referenceCode, 20)
                ->block(15, fn () => $this->finalizeStripeFirstCheckoutLocked($referenceCode, $session));
        } catch (\BadMethodCallException) {
            return $this->finalizeStripeFirstCheckoutLocked($referenceCode, $session);
        }
    }

    /**
     * @return Collection<int, Order>
     */
    private function finalizeStripeFirstCheckoutLocked(string $referenceCode, object $session): Collection
    {
        $this->assertStripeObjectIsOrderPayment($session);

        $meta = $this->sessionMetadataArray($session);
        $payerId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;
        $existing = Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->when($payerId > 0, fn ($query) => $query->where('user_id', $payerId))
            ->get();

        if ($existing->contains(fn (Order $order) => $this->canMarkCardOrderPaid($order))) {
            $marked = $this->markOrdersPaidFromStripeSession($referenceCode, $session);
            if ($marked->isNotEmpty()) {
                return $marked;
            }
        }

        if ($this->paidCardOrderCountForStripeCharge($referenceCode, $session, $payerId) > 0) {
            return $existing->filter(fn (Order $order) => $order->payment_status === 'paid')->values();
        }

        $package = $this->getPendingCheckout($referenceCode);
        if ($package === null) {
            Log::warning('No pending card checkout package to materialize', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
            ]);

            if ($payerId > 0) {
                $this->creditCapturedCardWhenUnfulfillable($payerId, $referenceCode, $session);
            }

            return collect();
        }

        $packageUserId = (int) ($package['user_id'] ?? 0);
        $metaUserId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;
        if ($packageUserId > 0 && $metaUserId > 0 && $packageUserId !== $metaUserId) {
            Log::error('Stripe checkout package user_id mismatch', [
                'reference_code' => $referenceCode,
                'package_user_id' => $packageUserId,
                'metadata_user_id' => $metaUserId,
            ]);

            $this->creditCapturedCardWhenUnfulfillable($metaUserId, $referenceCode, $session);

            return collect();
        }

        $expected = round((float) ($package['amount_due'] ?? $package['order_total'] ?? 0), 2);
        $charged = $this->stripeEurosFromObject($session);
        if ($expected > 0 && $charged > 0 && abs($charged - $expected) > 0.009) {
            Log::warning('Stripe card charge does not match the current checkout package; crediting this capture and leaving the package', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
                'charged' => $charged,
                'package_amount' => $expected,
            ]);

            $creditUserId = $metaUserId > 0 ? $metaUserId : $packageUserId;
            if ($creditUserId > 0) {
                $this->creditCapturedCardWhenUnfulfillable($creditUserId, $referenceCode, $session);
            }

            return collect();
        }

        $this->assertStripeAmountMatchesExpected($session, $expected, $referenceCode);

        $schema = app(CheckoutSchemaService::class);
        $schema->ensureCheckoutTables();

        $refundedInFinalize = 0.0;
        $created = DB::transaction(function () use ($package, $referenceCode, $session, $schema, &$refundedInFinalize) {
            $already = $this->lockCardOrdersForPayer(
                $referenceCode,
                $this->sessionMetadataArray($session)
            );

            if ($already->contains(fn (Order $order) => $this->canMarkCardOrderPaid($order))) {
                return $this->markOrdersPaidFromStripeSession($referenceCode, $session);
            }

            if ($this->paidCardOrderCountForStripeCharge(
                $referenceCode,
                $session,
                (int) ($package['user_id'] ?? 0)
            ) > 0) {
                return $already->filter(fn (Order $order) => $order->payment_status === 'paid')->values();
            }

            $userId = (int) ($package['user_id'] ?? 0);
            $buyer = $userId > 0 ? User::query()->find($userId) : null;
            $schedule = is_array($package['schedule'] ?? null) ? $package['schedule'] : ['mode' => 'immediate', 'timezone' => 'UTC'];
            $lines = is_array($package['lines'] ?? null) ? $package['lines'] : [];
            $orders = collect();

            $sessionId = (string) ($session->id ?? '');
            $isPaymentIntent = ($session->object ?? null) === 'payment_intent'
                || str_starts_with($sessionId, 'pi_');
            $stripeSessionId = $isPaymentIntent ? null : ($session->id ?? null);
            $stripePaymentIntentId = $isPaymentIntent
                ? ($session->id ?? null)
                : ($session->payment_intent ?? null);

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $siteId = isset($line['site_id']) ? (int) $line['site_id'] : 0;
                if ($siteId > 0 && isset($takenSiteIds[$siteId])) {
                    continue;
                }

                $site = $siteId > 0 ? Site::query()->find($siteId) : null;
                if ($siteId > 0 && (! $site || ! $site->isCatalogVisible() || $site->isOwnedBy($buyer))) {
                    Log::warning('Skipping Stripe-first line; listing left the catalog', [
                        'reference_code' => $referenceCode,
                        'site_id' => $siteId,
                        'session_id' => $session->id ?? null,
                    ]);

                    continue;
                }

                $lineKey = $this->checkoutLineKey(
                    $referenceCode,
                    $siteId,
                    (int) $index,
                    $userId,
                    (string) ($stripeSessionId ?: $stripePaymentIntentId ?: '')
                );

                $submissionId = (int) ($line['content_submission_id'] ?? 0);
                $submission = $submissionId > 0
                    ? ContentSubmission::query()->whereKey($submissionId)->lockForUpdate()->first()
                    : null;
                $articleTaken = $submission && $submission->isClaimedByAnotherOrder();
                $attachSubmission = $submission && ! $articleTaken;

                $order = $this->createPaidCardOrderRow($schema, [
                    'user_id' => $userId,
                    'reference_code' => $referenceCode,
                    'checkout_line_key' => $lineKey,
                    'subtotal' => $line['price'] ?? 0,
                    'tax' => 0,
                    'total_amount' => $line['price'] ?? 0,
                    'payment_method' => 'card',
                    'payment_status' => 'paid',
                    'status' => 'pending',
                    'sensitive_type' => $line['sensitive_type'] ?? null,
                    'additional_price' => $line['additional_price'] ?? 0,
                    'publication_mode' => $schedule['mode'] ?? 'immediate',
                    'scheduled_publish_at' => $schedule['at'] ?? null,
                    'schedule_timezone' => $schedule['timezone'] ?? 'UTC',
                    'stripe_session_id' => $stripeSessionId,
                    'stripe_payment_intent_id' => $stripePaymentIntentId,
                    'stripe_response' => method_exists($session, 'toArray')
                        ? json_encode($session->toArray())
                        : json_encode($session),
                    'paid_at' => now(),
                ]);

                if ($order === null) {
                    continue;
                }

                $itemPayload = [
                    'order_id' => $order->id,
                    'site_id' => $siteId ?: null,
                    'site_name' => $line['site_name'] ?? $site?->site_name,
                    'site_url' => $line['site_url'] ?? $site?->site_url,
                    'price' => $line['price'] ?? 0,
                    'content_link' => $line['content_link'] ?? null,
                    'content_submission_id' => $attachSubmission ? $submission->id : null,
                    'content_disk' => $attachSubmission ? $submission->disk : ($line['content_disk'] ?? null),
                    'content_path' => $attachSubmission ? $submission->path : ($line['content_path'] ?? null),
                    'content_original_name' => $attachSubmission ? $submission->original_filename : ($line['content_original_name'] ?? null),
                    'content_mime' => $attachSubmission ? $submission->mime : ($line['content_mime'] ?? null),
                    'anchor_text' => $attachSubmission ? $submission->anchor_text : ($line['anchor_text'] ?? null),
                    'target_url' => $attachSubmission ? $submission->target_url : ($line['target_url'] ?? null),
                    'feature_image_url' => $attachSubmission ? $submission->feature_image_url : ($line['feature_image_url'] ?? null),
                    'moderation_status' => $attachSubmission ? $submission->moderation_status : ($line['moderation_status'] ?? null),
                    'sensitive_type' => $line['sensitive_type'] ?? null,
                    'additional_price' => $line['additional_price'] ?? 0,
                    'homepage_days' => $line['homepage_days'] ?? null,
                    'homepage_price' => $line['homepage_price'] ?? 0,
                    'social_channels' => $line['social_channels']
                        ?? ($site?->enabledSocialChannels() ?: []),
                    'publisher_price' => $line['publisher_price'] ?? null,
                    'platform_fee_percent' => $line['platform_fee_percent'] ?? null,
                    'platform_fee_amount' => $line['platform_fee_amount'] ?? null,
                ];

                $item = OrderItem::create($schema->filterExistingColumns('order_items', $itemPayload));

                if ($articleTaken) {
                    app(OrderRefundService::class)->cancelAndRefund(
                        $order,
                        'Content Library article was already purchased on another checkout'
                    );
                    $refundedInFinalize = round(
                        $refundedInFinalize + (float) $order->total_amount,
                        2
                    );
                    Log::warning('Refunded duplicate Content Library Stripe checkout', [
                        'reference_code' => $referenceCode,
                        'order_id' => $order->id,
                        'content_submission_id' => $submission?->id,
                    ]);

                    continue;
                }

                if ($submission) {
                    $subPayload = [
                        'publication_mode' => $order->publication_mode,
                        'scheduled_publish_at' => $order->scheduled_publish_at,
                        'timezone' => $order->schedule_timezone ?: $submission->timezone,
                    ];
                    if (! $submission->order_id) {
                        $subPayload['order_id'] = $order->id;
                        $subPayload['order_item_id'] = $item->id;
                    }
                    $filteredSub = $schema->filterExistingColumns($submission->getTable(), $subPayload);
                    if ($filteredSub !== []) {
                        $submission->update($filteredSub);
                    }
                }

                $orders->push($order->fresh('items'));
            }

            return $orders;
        });

        if ($created->isEmpty()) {
            $existing = $this->restrictCardOrdersToPayer(
                Order::query()
                    ->where('reference_code', $referenceCode)
                    ->where('payment_method', 'card')
                    ->where('status', '!=', 'cancelled')
                    ->get(),
                $this->sessionMetadataArray($session)
            );
            if ($existing->isNotEmpty()) {
                return $this->markOrdersPaidFromStripeSession($referenceCode, $session);
            }

            $userId = (int) ($package['user_id'] ?? 0);
            if ($userId > 0) {
                $this->refundBonusReservedForReference(
                    $userId,
                    $referenceCode,
                    round((float) ($package['bonus_applied'] ?? 0), 2)
                );
                $unfulfilled = round(max(0, $expected - $refundedInFinalize), 2);
                $this->creditUnfulfilledCardCapture(
                    $userId,
                    $referenceCode,
                    $unfulfilled,
                    $this->stripeChargeToken($session)
                );
            }
            $this->forgetPendingCheckout($referenceCode, $userId > 0 ? $userId : null);
            Log::warning('Stripe-first checkout paid but no catalog-visible lines to materialize', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
                'user_id' => $userId,
            ]);

            return collect();
        }

        $userId = (int) ($package['user_id'] ?? 0);
        $fulfilled = round((float) $created->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $unfulfilled = round(max(0, $expected - $fulfilled - $refundedInFinalize), 2);
        if ($userId > 0 && $unfulfilled > 0.009) {
            $this->creditUnfulfilledCardCapture(
                $userId,
                $referenceCode,
                $unfulfilled,
                $this->stripeChargeToken($session)
            );
        }

        $this->forgetPendingCheckout($referenceCode, $userId > 0 ? $userId : null);
        $this->persistPaidCheckoutBonus(
            $userId,
            $referenceCode,
            (float) ($package['bonus_applied'] ?? $meta['bonus_applied'] ?? 0)
        );

        Log::info('Materialized Stripe-first card orders after payment', [
            'reference_code' => $referenceCode,
            'order_count' => $created->count(),
            'session_id' => $session->id ?? null,
        ]);

        $this->recordAdvertiserPurchaseForPaidCheckout(
            $referenceCode,
            $created,
            (float) ($package['bonus_applied'] ?? $meta['bonus_applied'] ?? 0),
            (float) ($package['order_total'] ?? $meta['order_total'] ?? 0)
        );
        $this->evaluateSpendBudgetAfterPaidOrders($created);

        return $created;
    }

    /**
     * Pending/failed card rows can be marked paid. Cancelled or completed
     * orders must not be resurrected by a late Stripe webhook or success URL.
     */
    private function canMarkCardOrderPaid(Order $order): bool
    {
        if ($order->payment_status === 'paid') {
            return false;
        }

        if (! in_array($order->payment_status, ['pending', 'failed'], true)) {
            Log::warning('Skipping order with unexpected payment status', [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
            ]);

            return false;
        }

        if (in_array((string) $order->status, ['cancelled', 'completed'], true)) {
            Log::warning('Skipping Stripe mark-paid for cancelled or completed order', [
                'order_id' => $order->id,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
            ]);

            return false;
        }

        if (! $order->hasCatalogVisibleFulfillment()) {
            Log::warning('Skipping Stripe mark-paid; listing left the catalog', [
                'order_id' => $order->id,
                'reference_code' => $order->reference_code,
                'payment_status' => $order->payment_status,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function expectedStripeEurosForOrders(Collection $orders, array $meta): float
    {
        if (isset($meta['expected_amount']) && $meta['expected_amount'] !== '') {
            return round((float) $meta['expected_amount'], 2);
        }

        return $this->markableCardOrdersDue($orders, $meta);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function markableCardOrdersDue(Collection $orders, array $meta): float
    {
        $markable = $orders->filter(fn (Order $order) => $this->canMarkCardOrderPaid($order));
        $total = round((float) $markable->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $bonus = round((float) ($meta['bonus_applied'] ?? 0), 2);

        return round(max(0, $total - $bonus), 2);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function existingCardOrdersMatchStripeCharge(Collection $orders, object $session, array $meta): bool
    {
        $markable = $orders->filter(fn (Order $order) => $this->canMarkCardOrderPaid($order));
        if ($markable->isEmpty()) {
            return true;
        }

        $orderDue = $this->markableCardOrdersDue($orders, $meta);
        $charged = $this->stripeEurosFromObject($session);
        if ($orderDue <= 0 || $charged <= 0) {
            return true;
        }

        return abs($charged - $orderDue) <= 0.009;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function pendingPackageBelongsToPayer(string $referenceCode, array $meta): bool
    {
        $package = $this->getPendingCheckout($referenceCode);
        if ($package === null) {
            return false;
        }

        $packageUserId = (int) ($package['user_id'] ?? 0);
        $payerId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;

        return $packageUserId <= 0 || $payerId <= 0 || $packageUserId === $payerId;
    }

    private function cancelUnpaidCardOrdersForNewCheckout(string $referenceCode, int $userId): void
    {
        $rows = Order::query()
            ->where('reference_code', $referenceCode)
            ->where('user_id', $userId)
            ->where('payment_method', 'card')
            ->whereIn('payment_status', ['pending', 'failed'])
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->get();

        foreach ($rows as $order) {
            ContentSubmission::releaseAllForOrder((int) $order->id);
            $order->update([
                'status' => 'cancelled',
                'payment_status' => $order->payment_status === 'paid' ? $order->payment_status : 'failed',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return Collection<int, Order>
     */
    private function lockCardOrdersForPayer(string $referenceCode, array $meta): Collection
    {
        $orders = Order::with('items')
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->lockForUpdate()
            ->get();

        return $this->restrictCardOrdersToPayer($orders, $meta);
    }

    /**
     * 6-digit client REFs can collide. Never settle another advertiser's
     * card orders from this Stripe charge.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     * @return Collection<int, Order>
     */
    private function restrictCardOrdersToPayer(Collection $orders, array $meta): Collection
    {
        $payerId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;
        if ($payerId > 0) {
            return $orders->where('user_id', $payerId)->values();
        }

        $userIds = $orders->pluck('user_id')
            ->unique()
            ->filter(fn ($id) => (int) $id > 0)
            ->values();
        if ($userIds->count() > 1) {
            throw new \RuntimeException('Order payment reference is ambiguous without user_id');
        }

        return $orders->values();
    }

    private function stripeEurosFromObject(object $session): float
    {
        $stripeCents = null;
        if (isset($session->amount_total)) {
            $stripeCents = (int) $session->amount_total;
        } elseif (isset($session->amount_received) || isset($session->amount)) {
            $stripeCents = (int) ($session->amount_received ?: $session->amount);
        }

        return $stripeCents !== null ? StripePaymentService::fromCents($stripeCents) : 0.0;
    }

    /**
     * Insert a paid card order, retrying order_number collisions.
     * Returns null only when this checkout line was already inserted (line-key race).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function createPaidCardOrderRow(CheckoutSchemaService $schema, array $attrs): ?Order
    {
        $lineKey = (string) ($attrs['checkout_line_key'] ?? '');
        $userId = (int) ($attrs['user_id'] ?? 0);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $attrs['order_number'] = $this->freshOrderNumber();

            try {
                return Order::create($schema->filterExistingColumns('orders', $attrs));
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }

                if ($lineKey === '') {
                    continue;
                }

                $existing = Order::query()->where('checkout_line_key', $lineKey)->first();
                if (! $existing) {
                    continue;
                }

                if ((int) $existing->user_id === $userId) {
                    $chargeToken = (string) ($attrs['stripe_session_id'] ?? $attrs['stripe_payment_intent_id'] ?? '');
                    if ($existing->payment_status === 'paid' && $chargeToken !== '' && ! str_contains($lineKey, $chargeToken)) {
                        $lineKey = $this->payerScopedKeyFromLegacy($lineKey, $userId).':'.substr($chargeToken, 0, 40);
                        $attrs['checkout_line_key'] = $lineKey;

                        continue;
                    }

                    return null;
                }

                if ($userId <= 0 || str_contains($lineKey, ':user:')) {
                    return null;
                }

                $lineKey = $this->payerScopedKeyFromLegacy($lineKey, $userId);
                $attrs['checkout_line_key'] = $lineKey;
            }
        }

        throw new \RuntimeException(
            'Unable to allocate a unique order number for checkout line '.$lineKey
        );
    }

    protected function freshOrderNumber(): string
    {
        return str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function checkoutLineKey(string $referenceCode, int $siteId, int $index, int $userId = 0, string $chargeToken = ''): string
    {
        // Qty>1 on one site is a real cart (two articles, two placements).
        // Deduping by site_id dropped the extra copies after Stripe charged them.
        $legacy = $siteId > 0
            ? $referenceCode.':site:'.$siteId.':line:'.$index
            : $referenceCode.':line:'.$index;

        if ($userId <= 0) {
            return $legacy;
        }

        $existing = Order::query()
            ->where('checkout_line_key', $legacy)
            ->first();

        if ($existing === null) {
            return $legacy;
        }

        if ((int) $existing->user_id !== $userId) {
            return $this->payerScopedCheckoutLineKey($referenceCode, $siteId, $index, $userId);
        }

        if ($existing->payment_status === 'paid' && $chargeToken !== '') {
            $token = substr($chargeToken, 0, 40);

            return $this->payerScopedCheckoutLineKey($referenceCode, $siteId, $index, $userId).':'.$token;
        }

        return $legacy;
    }

    private function payerScopedCheckoutLineKey(string $referenceCode, int $siteId, int $index, int $userId): string
    {
        return $siteId > 0
            ? $referenceCode.':user:'.$userId.':site:'.$siteId.':line:'.$index
            : $referenceCode.':user:'.$userId.':line:'.$index;
    }

    private function payerScopedKeyFromLegacy(string $legacyKey, int $userId): string
    {
        $parts = explode(':', $legacyKey, 2);

        return $parts[0].':user:'.$userId.(isset($parts[1]) ? ':'.$parts[1] : '');
    }

    private function isUniqueConstraintFailure(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = strtolower($e->getMessage());

        return $sqlState === '23000'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionMetadataArray(object $session): array
    {
        $meta = $session->metadata ?? null;
        if ($meta === null) {
            return [];
        }
        if (is_array($meta)) {
            return $meta;
        }

        return (array) json_decode(json_encode($meta), true);
    }

    /**
     * Wallet deposits and site-feature sessions must not settle catalog orders,
     * even when the client reuses the same reference_code and amount.
     */
    public function assertStripeObjectIsOrderPayment(object $session): void
    {
        $meta = $this->sessionMetadataArray($session);
        $type = isset($meta['type']) ? (string) $meta['type'] : '';
        if ($type === '' || in_array($type, ['order_payment', 'order'], true)) {
            return;
        }

        throw new \RuntimeException(
            'Stripe settlement is not an order payment (type '.$type.').'
        );
    }

    /**
     * Refuse to finalize if Stripe charged amount does not match expected euros (within 1 cent).
     */
    public function assertStripeAmountMatchesExpected(object $session, float $expectedEuros, string $referenceCode): void
    {
        $stripeCents = null;
        if (isset($session->amount_total)) {
            $stripeCents = (int) $session->amount_total;
        } elseif (isset($session->amount_received) || isset($session->amount)) {
            $stripeCents = (int) ($session->amount_received ?: $session->amount);
        }

        if ($stripeCents === null) {
            Log::error('Stripe session missing amount fields; refusing to finalize', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
            ]);

            throw new \RuntimeException(
                'Stripe charged amount is missing for ref '.$referenceCode
            );
        }

        $stripeEuros = StripePaymentService::fromCents($stripeCents);
        if (abs($stripeEuros - $expectedEuros) > 0.01) {
            Log::error('Stripe amount mismatch for order payment', [
                'reference_code' => $referenceCode,
                'expected_euros' => $expectedEuros,
                'stripe_euros' => $stripeEuros,
                'session_id' => $session->id ?? null,
            ]);

            throw new \RuntimeException(
                'Stripe charged amount does not match order total for ref '.$referenceCode
            );
        }
    }

    /**
     * Notify publishers only after payment is confirmed.
     *
     * @param  iterable<Order>  $orders
     */
    public function notifyPublishersOfPaidOrders(iterable $orders): void
    {
        try {
            $orders = collect($orders)->filter();
            if ($orders->isEmpty()) {
                return;
            }

            $freshOrders = collect();
            foreach ($orders as $order) {
                try {
                    $fresh = $order instanceof Order ? $order->fresh(['items']) : $order;
                    $freshOrders->push($fresh);
                    app(InAppNotificationService::class)->notifyOrderCreated($fresh);
                } catch (\Throwable $e) {
                    Log::warning('notifyOrderCreated failed after card payment', [
                        'order_id' => $order->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                app(InAppNotificationService::class)->notifyAdvertiserOrdersPaid($freshOrders);
            } catch (\Throwable $e) {
                Log::warning('notifyAdvertiserOrdersPaid failed after payment', [
                    'error' => $e->getMessage(),
                ]);
            }

            $siteOrders = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $siteId = $item->site_id;
                    if (! isset($siteOrders[$siteId])) {
                        $site = Site::find($siteId);
                        if (! $site) {
                            continue;
                        }
                        $siteOrders[$siteId] = [
                            'site' => $site,
                            'orders' => [],
                        ];
                    }
                    $siteOrders[$siteId]['orders'][] = $order;
                }
            }

            foreach ($siteOrders as $siteData) {
                $site = $siteData['site'];
                $publisher = $site->publisher_id ? User::find($site->publisher_id) : null;

                if (! $publisher || ! $publisher->email) {
                    Log::warning('Cannot notify publisher for paid order', [
                        'site_id' => $site->id,
                        'publisher_id' => $site->publisher_id,
                    ]);

                    continue;
                }

                try {
                    Mail::to($publisher->email)->send(
                        new SiteOwnerOrderNotification($site, $siteData['orders'])
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to send paid-order email to publisher', [
                        'email' => $publisher->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('notifyPublishersOfPaidOrders failed: '.$e->getMessage());
        }
    }
}
