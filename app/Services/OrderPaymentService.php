<?php

namespace App\Services;

use App\Mail\SiteOwnerOrderNotification;
use App\Models\CheckoutIntent;
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
        $amountMismatch = false;
        $newlyPaid = DB::transaction(function () use ($referenceCode, $session, &$amountMismatch) {
            $orders = Order::with('items')
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                Log::warning('No card orders found for Stripe payment', [
                    'reference_code' => $referenceCode,
                    'session_id' => $session->id ?? null,
                ]);

                return collect();
            }

            $meta = $this->sessionMetadataArray($session);
            $hasMarkable = $orders->contains(fn (Order $order) => $this->canMarkCardOrderPaid($order));
            if ($hasMarkable && ! $this->allowStripeCaptureForOrders($session, $orders, $meta, $referenceCode)) {
                $amountMismatch = true;

                return collect();
            }

            $newlyPaid = collect();

            foreach ($orders as $order) {
                $settled = $this->settleExistingCardOrder($order, [
                    'stripe_session_id' => $session->id ?? $order->stripe_session_id,
                    'stripe_payment_intent_id' => $session->payment_intent ?? $order->stripe_payment_intent_id,
                    'stripe_response' => method_exists($session, 'toArray')
                        ? json_encode($session->toArray())
                        : json_encode($session),
                    'paid_at' => now(),
                    'payment_status' => 'paid',
                    'status' => 'pending',
                ]);
                if ($settled) {
                    $newlyPaid->push($settled);
                }
            }

            if ($newlyPaid->isNotEmpty()) {
                $this->rereserveReleasedCheckoutBonus(
                    (int) $newlyPaid->first()->user_id,
                    $referenceCode,
                    (float) ($meta['bonus_applied'] ?? 0)
                );
            }

            // Keep leftover checkout bonus reserved until approve/reject.
            // Consuming here made card+bonus rejects credit the promo slice as cash.

            return $newlyPaid;
        });

        if ($amountMismatch) {
            return collect();
        }

        $this->recordAdvertiserPurchaseForPaidCheckout(
            $referenceCode,
            $newlyPaid,
            (float) ($sessionMeta['bonus_applied'] ?? 0),
            (float) ($sessionMeta['order_total'] ?? 0)
        );
        $this->evaluateSpendBudgetAfterPaidOrders($newlyPaid);
        $this->creditHiddenCardOrdersAfterMarkPaid($referenceCode, $sessionMeta);

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

        $amountMismatch = false;
        $newlyPaid = DB::transaction(function () use ($referenceCode, $intent, $meta, &$amountMismatch) {
            $orders = Order::with('items')
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return collect();
            }

            $hasMarkable = $orders->contains(fn (Order $order) => $this->canMarkCardOrderPaid($order));
            if ($hasMarkable && ! $this->allowStripeCaptureForOrders($intent, $orders, $meta, $referenceCode)) {
                $amountMismatch = true;

                return collect();
            }

            $newlyPaid = collect();
            foreach ($orders as $order) {
                $settled = $this->settleExistingCardOrder($order, [
                    'stripe_payment_intent_id' => $intent->id ?? $order->stripe_payment_intent_id,
                    'stripe_response' => method_exists($intent, 'toArray')
                        ? json_encode($intent->toArray())
                        : json_encode($intent),
                    'paid_at' => now(),
                    'payment_status' => 'paid',
                    'status' => 'pending',
                ]);
                if ($settled) {
                    $newlyPaid->push($settled);
                }
            }

            if ($newlyPaid->isNotEmpty()) {
                $this->rereserveReleasedCheckoutBonus(
                    (int) $newlyPaid->first()->user_id,
                    $referenceCode,
                    (float) ($meta['bonus_applied'] ?? 0)
                );
            }

            // Keep leftover checkout bonus reserved until approve/reject.

            return $newlyPaid;
        });

        if ($amountMismatch) {
            return collect();
        }

        $this->recordAdvertiserPurchaseForPaidCheckout(
            $referenceCode,
            $newlyPaid,
            (float) ($meta['bonus_applied'] ?? 0),
            (float) ($meta['order_total'] ?? 0)
        );
        $this->evaluateSpendBudgetAfterPaidOrders($newlyPaid);
        $this->creditHiddenCardOrdersAfterMarkPaid($referenceCode, $meta);

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
    public function markOrdersFailedFromReference(string $referenceCode, ?string $reason = null, ?int $userId = null, ?float $bonusFallback = null): Collection
    {
        $failed = DB::transaction(function () use ($referenceCode, $reason, $userId, $bonusFallback) {
            $orders = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'pending')
                ->lockForUpdate()
                ->get();

            $marked = collect();
            foreach ($orders as $order) {
                $order->update([
                    'payment_status' => 'failed',
                ]);
                $marked->push($order->fresh());
            }

            $package = $this->getPendingCheckout($referenceCode);
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
            // Legacy rows can be marked paid later. Stripe-first has no rows yet —
            // keep the package so a late paid webhook can still settle.
            if ($marked->isNotEmpty()) {
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
     * Keep only the fulfilled share of checkout promo. Leftover/hidden lines
     * must not leave their bonus slice reserved forever.
     */
    public function keepCheckoutBonusForFulfilled(
        int $userId,
        string $referenceCode,
        float $heldBonus,
        float $orderTotal,
        float $fulfilled
    ): float {
        $heldBonus = round($heldBonus, 2);
        $orderTotal = round($orderTotal, 2);
        $fulfilled = round($fulfilled, 2);
        if ($userId <= 0 || $heldBonus <= 0.009) {
            return 0.0;
        }

        $keep = $orderTotal > 0.009
            ? round(min($heldBonus, $heldBonus * ($fulfilled / $orderTotal)), 2)
            : 0.0;
        $release = round(max(0, $heldBonus - $keep), 2);
        if ($release <= 0.009) {
            return $heldBonus;
        }

        $roleId = Wallet::advertiserRoleId();
        if ($roleId) {
            $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
            $wallet->refundReserved($release, $release);
        }
        app(CheckoutIntentService::class)->rememberBonus($userId, $referenceCode, $keep);

        return $keep;
    }

    /**
     * If fail/cancel already returned this checkout's promo to bonus_balance,
     * hold it again when the same card payment later marks the order paid.
     */
    public function rereserveReleasedCheckoutBonus(int $userId, string $referenceCode, float $bonusApplied): float
    {
        $bonusApplied = round($bonusApplied, 2);
        if ($userId <= 0 || $bonusApplied <= 0.009) {
            return 0.0;
        }

        $intents = app(CheckoutIntentService::class);
        $held = $intents->heldBonus($userId, $referenceCode);
        $need = round(max(0, $bonusApplied - $held), 2);
        if ($need <= 0) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return 0.0;
        }

        $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
        $moved = $wallet->reserveBonusOnly($need);
        if ($moved <= 0) {
            return 0.0;
        }

        $intents->rememberBonus($userId, $referenceCode, round($held + $moved, 2));

        Log::info('Re-reserved checkout bonus after late card mark-paid', [
            'user_id' => $userId,
            'reference_code' => $referenceCode,
            'amount' => $moved,
        ]);

        return $moved;
    }

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

    public static function unfulfilledCardCreditReference(string $referenceCode, ?string $settlementKey = null): string
    {
        $base = 'UNFULFILLED-CARD-'.$referenceCode;
        if (is_string($settlementKey) && $settlementKey !== '') {
            return $base.'-'.$settlementKey;
        }

        return $base;
    }

    /**
     * Release promo held for abandoned Stripe-first packages so a retry can
     * reserve bonus again. Leaves bonus untouched when this reference already
     * has open paid/pending orders (approve/reject still owns that hold), or
     * when another checkout still has an open Stripe session (that tab can
     * still settle and must keep its promo).
     */
    public function releaseAbandonedStripeFirstBonus(int $userId, ?string $keepReference = null): void
    {
        if ($userId <= 0) {
            return;
        }

        $refs = collect();
        if (Schema::hasTable((new CheckoutIntent)->getTable())) {
            $refs = CheckoutIntent::query()
                ->where('user_id', $userId)
                ->pluck('reference_code');
        }
        if (is_string($keepReference) && $keepReference !== '') {
            $refs->push($keepReference);
        }

        foreach ($refs->filter()->map(fn ($code) => (string) $code)->unique() as $ref) {
            $hasOpenOrders = Order::query()
                ->where('reference_code', $ref)
                ->where('user_id', $userId)
                ->whereIn('payment_status', ['paid', 'pending'])
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->exists();
            if ($hasOpenOrders) {
                continue;
            }

            $package = $this->getPendingCheckout($ref);
            $openStripeSession = is_array($package)
                && search_text($package['stripe_session_id'] ?? '') !== '';
            if ($openStripeSession && $ref !== $keepReference) {
                continue;
            }

            $held = max(
                app(CheckoutIntentService::class)->heldBonus($userId, $ref),
                round((float) ($package['bonus_applied'] ?? 0), 2)
            );
            if ($held > 0.009) {
                $roleId = Wallet::advertiserRoleId();
                if ($roleId) {
                    $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
                    $wallet->refundReserved($held, $held);
                }
                app(CheckoutIntentService::class)->takeBonus($userId, $ref, $held);
            }

            if ($keepReference !== null && $ref !== $keepReference) {
                $this->forgetPendingCheckout($ref);
            }
        }
    }

    /**
     * Credit captured card cash when Stripe-first lines left the catalog.
     * Idempotent per checkout reference (and optional settlement/session key).
     */
    public function creditUnfulfilledCardCapture(int $userId, string $referenceCode, float $amount, ?string $settlementKey = null): float
    {
        $amount = round($amount, 2);
        if ($userId <= 0 || $amount <= 0) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return 0.0;
        }

        $reference = self::unfulfilledCardCreditReference($referenceCode, $settlementKey);
        $aliases = [$reference];
        if (is_string($settlementKey) && $settlementKey !== '') {
            $aliases[] = self::unfulfilledCardCreditReference($referenceCode);
        }

        return (float) DB::transaction(function () use ($userId, $roleId, $amount, $reference, $referenceCode, $aliases) {
            if (! User::query()->whereKey($userId)->exists()) {
                Log::warning('Cannot credit unfulfilled card capture; user missing', [
                    'user_id' => $userId,
                    'reference_code' => $referenceCode,
                    'amount' => $amount,
                ]);

                return 0.0;
            }

            $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
            if (Schema::hasTable((new WalletTransaction)->getTable())
                && WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->whereIn('reference', $aliases)
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
                'Card payment credited because listing(s) left the catalog',
                ['reference_code' => $referenceCode]
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
    private function creditHiddenCardOrdersAfterMarkPaid(string $referenceCode, array $meta): void
    {
        $orders = Order::with('items.site')
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
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
        $expected = $this->capturedStripeEurosForCredit($orders, $meta);
        $unfulfilled = round(max(0, $expected - $paidTotal), 2);
        if ($userId > 0 && $unfulfilled > 0.009) {
            $this->creditUnfulfilledCardCapture($userId, $referenceCode, $unfulfilled);
        }

        foreach ($hiddenPending as $order) {
            $order->update(['status' => 'cancelled']);
            ContentSubmission::releaseAllForOrder((int) $order->id);
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

    public function unfulfilledCardCreditAmount(string $referenceCode): float
    {
        if (! Schema::hasTable((new WalletTransaction)->getTable())) {
            return 0.0;
        }

        $prefix = self::unfulfilledCardCreditReference($referenceCode);

        return round((float) WalletTransaction::query()
            ->where('direction', 'credit')
            ->where(function ($query) use ($prefix) {
                $query->where('reference', $prefix)
                    ->orWhere('reference', 'like', $prefix.'-%');
            })
            ->sum('amount'), 2);
    }

    /**
     * Card cash already returned via cancelAndRefund (e.g. a taken Content Library line).
     */
    public function refundedCardOrderAmount(string $referenceCode): float
    {
        return round((float) Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->where('payment_status', 'refunded')
            ->sum('total_amount'), 2);
    }

    /**
     * Wallet cash already given back when a paid card checkout could not be fulfilled.
     * Unfulfilled-card credits and cancelAndRefund rows do not overlap.
     */
    public function walletCreditForUnfulfillableCardCheckout(string $referenceCode): float
    {
        return round(
            $this->unfulfilledCardCreditAmount($referenceCode)
            + $this->refundedCardOrderAmount($referenceCode),
            2
        );
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
        app(CheckoutIntentService::class)->storePackage($referenceCode, $package);
    }

    public function forgetPendingCheckout(string $referenceCode): void
    {
        app(CheckoutIntentService::class)->forget($referenceCode);
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

        $existingCount = Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->count();

        $package = $this->getPendingCheckout($referenceCode);
        if ($package === null) {
            if ($existingCount > 0) {
                $newlyPaid = $this->markOrdersPaidFromStripeSession($referenceCode, $session);
                if ($newlyPaid->isEmpty()) {
                    $this->creditCapturedCardWhenAlreadySettled($referenceCode, $session);
                }

                return $newlyPaid;
            }

            Log::warning('No pending card checkout package to materialize', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
            ]);
            $this->creditCapturedCardWhenPackageMissing($referenceCode, $session);

            return collect();
        }

        $packageSessionId = search_text($package['stripe_session_id'] ?? '');
        $incomingId = (string) ($session->id ?? '');
        $isPaymentIntent = ($session->object ?? null) === 'payment_intent'
            || str_starts_with($incomingId, 'pi_');
        if ($packageSessionId !== '' && $incomingId !== '' && ! $isPaymentIntent
            && $packageSessionId !== $incomingId) {
            Log::warning('Stripe session does not match current checkout package', [
                'reference_code' => $referenceCode,
                'package_session_id' => $packageSessionId,
                'session_id' => $incomingId,
            ]);
            $this->creditCapturedCardWhenPackageMissing($referenceCode, $session);

            return collect();
        }

        $meta = $this->sessionMetadataArray($session);
        $packageUserId = (int) ($package['user_id'] ?? 0);
        $metaUserId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;
        if ($packageUserId > 0 && $metaUserId > 0 && $packageUserId !== $metaUserId) {
            Log::error('Stripe checkout package user_id mismatch', [
                'reference_code' => $referenceCode,
                'package_user_id' => $packageUserId,
                'metadata_user_id' => $metaUserId,
            ]);

            throw new \RuntimeException('Checkout package does not belong to the paying user for ref '.$referenceCode);
        }

        $expected = round((float) ($package['amount_due'] ?? $package['order_total'] ?? 0), 2);
        $stripeEuros = $this->stripeEurosFromSession($session);
        if ($stripeEuros === null) {
            $this->assertStripeAmountMatchesExpected($session, $expected, $referenceCode);
        } elseif (abs($stripeEuros - $expected) > 0.01) {
            $userId = (int) ($package['user_id'] ?? $metaUserId);
            $sessionId = (string) ($session->id ?? '');
            if ($userId > 0) {
                $this->creditUnfulfilledCardCapture(
                    $userId,
                    $referenceCode,
                    $stripeEuros,
                    $sessionId !== '' ? $sessionId : null
                );
            }
            Log::warning('Stripe session amount does not match current checkout package', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
                'package_amount_due' => $expected,
                'stripe_euros' => $stripeEuros,
                'user_id' => $userId,
            ]);

            return collect();
        }

        $userId = (int) ($package['user_id'] ?? $metaUserId);
        $bonusNeeded = round((float) ($package['bonus_applied'] ?? $meta['bonus_applied'] ?? 0), 2);
        if ($userId > 0 && $bonusNeeded > 0.009) {
            $held = $this->ensureCheckoutBonusReserved($userId, $referenceCode, $bonusNeeded);
            if ($held + 0.009 < $bonusNeeded) {
                $sessionId = (string) ($session->id ?? '');
                $this->creditUnfulfilledCardCapture(
                    $userId,
                    $referenceCode,
                    $expected,
                    $sessionId !== '' ? $sessionId : null
                );
                $this->forgetPendingCheckout($referenceCode);
                Log::warning('Stripe-first paid after bonus was released and could not be re-reserved', [
                    'reference_code' => $referenceCode,
                    'session_id' => $session->id ?? null,
                    'user_id' => $userId,
                    'bonus_needed' => $bonusNeeded,
                    'bonus_held' => $held,
                    'wallet_credit' => $expected,
                ]);

                return collect();
            }
        }

        $schema = app(CheckoutSchemaService::class);
        $schema->ensureCheckoutTables();

        $refundedInFinalize = 0.0;
        $created = DB::transaction(function () use ($package, $referenceCode, $session, $schema, &$refundedInFinalize) {
            $already = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->lockForUpdate()
                ->get();

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

                $site = $siteId > 0
                    ? Site::query()->whereKey($siteId)->lockForUpdate()->first()
                    : null;
                if ($siteId > 0 && (! $site || ! $site->isCatalogVisible() || $site->isOwnedBy($buyer))) {
                    Log::warning('Skipping Stripe-first line; listing left the catalog', [
                        'reference_code' => $referenceCode,
                        'site_id' => $siteId,
                        'session_id' => $session->id ?? null,
                    ]);

                    continue;
                }

                $lineKey = $this->checkoutLineKey($referenceCode, $siteId, (int) $index);

                $submissionId = (int) ($line['content_submission_id'] ?? 0);
                $expectLibrary = $submissionId > 0;
                $submission = $expectLibrary
                    ? ContentSubmission::query()->whereKey($submissionId)->lockForUpdate()->first()
                    : null;
                $articleMissing = $expectLibrary && ! $submission;
                $articleTaken = $submission && $submission->isClaimedByAnotherOrder();
                $articleUnready = $articleMissing
                    || ($submission && ! $articleTaken && ! $submission->isReadyForCheckout());
                $attachSubmission = $submission && ! $articleTaken && ! $articleUnready;

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
                    'content_disk' => $attachSubmission ? $submission->disk : ($expectLibrary ? null : ($line['content_disk'] ?? null)),
                    'content_path' => $attachSubmission ? $submission->path : ($expectLibrary ? null : ($line['content_path'] ?? null)),
                    'content_original_name' => $attachSubmission ? $submission->original_filename : ($expectLibrary ? null : ($line['content_original_name'] ?? null)),
                    'content_mime' => $attachSubmission ? $submission->mime : ($expectLibrary ? null : ($line['content_mime'] ?? null)),
                    'anchor_text' => $attachSubmission ? $submission->anchor_text : ($expectLibrary ? null : ($line['anchor_text'] ?? null)),
                    'target_url' => $attachSubmission ? $submission->target_url : ($expectLibrary ? null : ($line['target_url'] ?? null)),
                    'feature_image_url' => $attachSubmission ? $submission->feature_image_url : ($expectLibrary ? null : ($line['feature_image_url'] ?? null)),
                    'moderation_status' => $attachSubmission ? $submission->moderation_status : ($expectLibrary ? null : ($line['moderation_status'] ?? null)),
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

                if ($articleTaken || $articleUnready) {
                    $reason = $articleTaken
                        ? 'Content Library article was already purchased on another checkout'
                        : 'Content Library article is no longer available for checkout';
                    app(OrderRefundService::class)->cancelAndRefund($order, $reason);
                    $refundedInFinalize = round(
                        $refundedInFinalize + (float) $order->total_amount,
                        2
                    );
                    Log::warning($articleTaken
                        ? 'Refunded duplicate Content Library Stripe checkout'
                        : ($articleMissing
                            ? 'Refunded Stripe checkout for a missing Content Library article'
                            : 'Refunded Stripe checkout for an unready Content Library article'), [
                                'reference_code' => $referenceCode,
                                'order_id' => $order->id,
                                'content_submission_id' => $submission?->id ?? $submissionId,
                            ]);

                    continue;
                }

                if ($submission) {
                    $subPayload = [
                        'publication_mode' => $order->publication_mode,
                        'scheduled_publish_at' => $order->scheduled_publish_at,
                        'timezone' => $order->schedule_timezone ?: $submission->timezone,
                    ];
                    if ($submission->shouldAdoptOwnerOrder((int) $order->id)) {
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
            $existing = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('status', '!=', 'cancelled')
                ->get();
            if ($existing->isNotEmpty()) {
                $marked = $this->markOrdersPaidFromStripeSession($referenceCode, $session);
                if ($marked->isNotEmpty()) {
                    $this->forgetPendingCheckout($referenceCode);

                    return $marked;
                }
            }

            $userId = (int) ($package['user_id'] ?? 0);
            $unmaterialized = $this->packageHasUnmaterializedLines($package, $referenceCode);
            if ($userId > 0 && ($existing->isEmpty() || $unmaterialized)) {
                $this->refundBonusReservedForReference(
                    $userId,
                    $referenceCode,
                    round((float) ($package['bonus_applied'] ?? 0), 2)
                );
                $unfulfilled = round(max(0, $expected - $refundedInFinalize), 2);
                $sessionId = (string) ($session->id ?? '');
                $this->creditUnfulfilledCardCapture(
                    $userId,
                    $referenceCode,
                    $unfulfilled,
                    $sessionId !== '' ? $sessionId : null
                );
            }
            $this->forgetPendingCheckout($referenceCode);
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
            $sessionId = (string) ($session->id ?? '');
            $this->creditUnfulfilledCardCapture(
                $userId,
                $referenceCode,
                $unfulfilled,
                $sessionId !== '' ? $sessionId : null
            );
        }

        $packageBonus = round((float) ($package['bonus_applied'] ?? $meta['bonus_applied'] ?? 0), 2);
        $packageTotal = round((float) ($package['order_total'] ?? $meta['order_total'] ?? 0), 2);
        $bonusKeep = $userId > 0
            ? $this->keepCheckoutBonusForFulfilled(
                $userId,
                $referenceCode,
                $packageBonus,
                $packageTotal,
                $fulfilled
            )
            : 0.0;
        if ($userId > 0) {
            $this->rereserveReleasedCheckoutBonus($userId, $referenceCode, $bonusKeep);
        }

        $this->forgetPendingCheckout($referenceCode);

        Log::info('Materialized Stripe-first card orders after payment', [
            'reference_code' => $referenceCode,
            'order_count' => $created->count(),
            'session_id' => $session->id ?? null,
        ]);

        $this->recordAdvertiserPurchaseForPaidCheckout(
            $referenceCode,
            $created,
            $bonusKeep,
            $fulfilled
        );
        $this->evaluateSpendBudgetAfterPaidOrders($created);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $paidAttributes
     */
    private function settleExistingCardOrder(Order $order, array $paidAttributes): ?Order
    {
        if (! $this->canMarkCardOrderPaid($order)) {
            return null;
        }

        $libraryState = $this->libraryContentStateForSettlement($order);
        if ($libraryState !== 'ok') {
            $order->update($paidAttributes);
            $reason = $libraryState === 'taken'
                ? 'Content Library article was already purchased on another checkout'
                : 'Content Library article is no longer available for checkout';
            app(OrderRefundService::class)->cancelAndRefund($order, $reason);
            Log::warning('Refunded legacy card mark-paid for an unusable Content Library article', [
                'order_id' => $order->id,
                'reference_code' => $order->reference_code,
                'library_state' => $libraryState,
            ]);

            return null;
        }

        $this->refreshOrderItemLibraryFields($order);
        $order->update($paidAttributes);

        return $order->fresh('items');
    }

    /**
     * @return 'ok'|'missing'|'unready'|'taken'
     */
    public function libraryContentStateForSettlement(Order $order): string
    {
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            $id = (int) ($item->content_submission_id ?? 0);
            if ($id <= 0) {
                if ($item->looksLikeLibraryLine()) {
                    return 'missing';
                }

                continue;
            }

            $submission = ContentSubmission::query()->whereKey($id)->lockForUpdate()->first();
            if (! $submission) {
                return 'missing';
            }
            if ($submission->isClaimedByAnotherOrder((int) $order->id)) {
                return 'taken';
            }
            if (! $submission->isReadyToFulfill((int) $order->id)) {
                return 'unready';
            }
        }

        return 'ok';
    }

    public function refreshOrderItemLibraryFields(Order $order): void
    {
        $schema = app(CheckoutSchemaService::class);
        $order->loadMissing('items');
        foreach ($order->items as $item) {
            $id = (int) ($item->content_submission_id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $submission = ContentSubmission::query()->whereKey($id)->first();
            if (! $submission) {
                continue;
            }
            $fields = $schema->filterExistingColumns('order_items', [
                'anchor_text' => $submission->anchor_text,
                'target_url' => $submission->target_url,
                'feature_image_url' => $submission->feature_image_url,
                'content_disk' => $submission->disk,
                'content_path' => $submission->path,
                'content_original_name' => $submission->original_filename,
                'content_mime' => $submission->mime,
                'moderation_status' => $submission->moderation_status,
            ]);
            if ($fields !== []) {
                $item->update($fields);
            }

            // Pay again / admin mark-paid can settle a leftover whose order_id is
            // still null or pointed at a cancelled owner. Rewrite ownership so
            // the paid row shows in progress and stays locked.
            if ($submission->shouldAdoptOwnerOrder((int) $order->id)) {
                $ownerFields = $schema->filterExistingColumns($submission->getTable(), [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                ]);
                if ($ownerFields !== []) {
                    $submission->update($ownerFields);
                }
            }
        }
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
     * Card cash still owed for these order rows. Ignore session metadata
     * expected_amount — a stale cheaper Checkout session baked its own figure
     * and must not mark the current totals paid.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function expectedStripeEurosForOrders(Collection $orders, array $meta): float
    {
        $total = round((float) $orders->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $bonus = round((float) ($meta['bonus_applied'] ?? 0), 2);

        return round(max(0, $total - $bonus), 2);
    }

    /**
     * Card cash to return for hidden leftover rows. Prefer the session's
     * captured expected_amount so a full charge is not reduced by leftover
     * bonus metadata; fall back to current order totals minus bonus.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function capturedStripeEurosForCredit(Collection $orders, array $meta): float
    {
        if (isset($meta['expected_amount']) && $meta['expected_amount'] !== '') {
            return round((float) $meta['expected_amount'], 2);
        }

        return $this->expectedStripeEurosForOrders($orders, $meta);
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

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $attrs['order_number'] = $this->freshOrderNumber();

            try {
                return Order::create($schema->filterExistingColumns('orders', $attrs));
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }

                if ($lineKey !== '' && Order::query()->where('checkout_line_key', $lineKey)->exists()) {
                    return null;
                }
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

    /**
     * @param  array<string, mixed>  $package
     */
    private function packageHasUnmaterializedLines(array $package, string $referenceCode): bool
    {
        $lines = is_array($package['lines'] ?? null) ? $package['lines'] : [];
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $siteId = isset($line['site_id']) ? (int) $line['site_id'] : 0;
            $key = $this->checkoutLineKey($referenceCode, $siteId, (int) $index);
            if ($key === '') {
                continue;
            }
            if (! Order::query()->where('checkout_line_key', $key)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function checkoutLineKey(string $referenceCode, int $siteId, int $index): string
    {
        // Qty>1 on one site is a real cart (two articles, two placements).
        // Deduping by site_id dropped the extra copies after Stripe charged them.
        return $siteId > 0
            ? $referenceCode.':site:'.$siteId.':line:'.$index
            : $referenceCode.':line:'.$index;
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
     * Re-reserve checkout promo after cancel/expiry released it.
     * Returns $needed when the hold is in place, otherwise 0.
     */
    private function ensureCheckoutBonusReserved(int $userId, string $referenceCode, float $needed): float
    {
        $needed = round($needed, 2);
        if ($userId <= 0 || $needed <= 0) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return 0.0;
        }

        return (float) DB::transaction(function () use ($userId, $roleId, $referenceCode, $needed) {
            $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
            $held = app(CheckoutIntentService::class)->heldBonus($userId, $referenceCode);
            $reserved = round((float) $wallet->bonus_reserved, 2);
            if ($held + 0.009 >= $needed && $reserved + 0.009 >= $needed) {
                return $needed;
            }

            $got = $wallet->reserveBonusOnly($needed);
            if ($got + 0.009 >= $needed) {
                app(CheckoutIntentService::class)->rememberBonus($userId, $referenceCode, $needed);

                return $needed;
            }

            if ($got > 0.009) {
                $wallet->refundReserved($got, $got);
            }

            return 0.0;
        });
    }

    /**
     * Last-resort credit when Stripe captured a card checkout after the package was dropped.
     */
    private function creditCapturedCardWhenPackageMissing(string $referenceCode, object $session): float
    {
        $meta = $this->sessionMetadataArray($session);
        $userId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;
        $amount = isset($meta['expected_amount']) && $meta['expected_amount'] !== ''
            ? round((float) $meta['expected_amount'], 2)
            : (float) ($this->stripeEurosFromSession($session) ?? 0);

        if ($userId <= 0 || $amount <= 0.009) {
            return 0.0;
        }

        $sessionId = (string) ($session->id ?? '');

        return $this->creditUnfulfilledCardCapture(
            $userId,
            $referenceCode,
            $amount,
            $sessionId !== '' ? $sessionId : null
        );
    }

    /**
     * Credit a second Stripe capture after this reference already has paid orders.
     * Same-session webhook/success-URL replay is a no-op.
     */
    public function creditCapturedCardWhenAlreadySettled(string $referenceCode, object $session): float
    {
        $paidOrders = Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->where('payment_status', 'paid')
            ->get();
        if ($paidOrders->isEmpty()) {
            return 0.0;
        }

        $sessionId = (string) ($session->id ?? '');
        $isPaymentIntent = ($session->object ?? null) === 'payment_intent'
            || str_starts_with($sessionId, 'pi_');
        $alreadySettledThisCapture = $paidOrders->contains(function (Order $order) use ($session, $sessionId, $isPaymentIntent) {
            if ($sessionId === '') {
                return false;
            }
            if ($isPaymentIntent) {
                return (string) $order->stripe_payment_intent_id === $sessionId;
            }
            if ((string) $order->stripe_session_id === $sessionId) {
                return true;
            }
            $paymentIntentId = (string) ($session->payment_intent ?? '');

            return $paymentIntentId !== ''
                && (string) $order->stripe_payment_intent_id === $paymentIntentId;
        });
        if ($alreadySettledThisCapture) {
            return 0.0;
        }

        $userId = (int) ($paidOrders->first()->user_id ?? 0);
        $amount = (float) ($this->stripeEurosFromSession($session) ?? 0);
        if ($userId <= 0 || $amount <= 0.009) {
            return 0.0;
        }

        return $this->creditUnfulfilledCardCapture(
            $userId,
            $referenceCode,
            $amount,
            $sessionId !== '' ? $sessionId : null
        );
    }

    private function stripeEurosFromSession(object $session): ?float
    {
        $stripeCents = null;
        if (isset($session->amount_total)) {
            $stripeCents = (int) $session->amount_total;
        } elseif (isset($session->amount_received) || isset($session->amount)) {
            $stripeCents = (int) ($session->amount_received ?: $session->amount);
        }

        return $stripeCents === null ? null : StripePaymentService::fromCents($stripeCents);
    }

    /**
     * Credit a captured Stripe amount that does not match pending/failed card
     * orders. Returns true when that capture may mark those orders paid.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function allowStripeCaptureForOrders(
        object $session,
        Collection $orders,
        array $meta,
        string $referenceCode
    ): bool {
        $expected = $this->expectedStripeEurosForOrders($orders, $meta);
        $stripeEuros = $this->stripeEurosFromSession($session);
        if ($stripeEuros === null) {
            $this->assertStripeAmountMatchesExpected($session, $expected, $referenceCode);

            return true;
        }
        if (abs($stripeEuros - $expected) <= 0.01) {
            return true;
        }

        $userId = (int) ($orders->first()?->user_id ?? 0);
        $sessionId = (string) ($session->id ?? '');
        if ($userId > 0 && $stripeEuros > 0.009) {
            $this->creditUnfulfilledCardCapture(
                $userId,
                $referenceCode,
                $stripeEuros,
                $sessionId !== '' ? $sessionId : null
            );
        }
        Log::warning('Stripe session amount does not match pending card orders', [
            'reference_code' => $referenceCode,
            'expected_euros' => $expected,
            'stripe_euros' => $stripeEuros,
            'session_id' => $session->id ?? null,
            'user_id' => $userId,
        ]);

        return false;
    }

    /**
     * Refuse to finalize if Stripe charged amount does not match expected euros (within 1 cent).
     */
    public function assertStripeAmountMatchesExpected(object $session, float $expectedEuros, string $referenceCode): void
    {
        $stripeEuros = $this->stripeEurosFromSession($session);
        if ($stripeEuros === null) {
            Log::error('Stripe session missing amount fields; refusing to finalize', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
            ]);

            throw new \RuntimeException(
                'Stripe charged amount is missing for ref '.$referenceCode
            );
        }

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
