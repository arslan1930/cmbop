<?php

namespace App\Services;

use App\Mail\SiteOwnerOrderNotification;
use App\Mail\UnfulfilledCheckoutCredited;
use App\Models\CheckoutIntent;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\ContentModeration\ContentModerationService;
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
     * Occupies a Stripe-first checkout reference after the package is stored
     * and before Stripe returns a real cs_ id, so a second Pay cannot reuse
     * the ref or treat the in-progress hold as abandoned.
     */
    public const PENDING_STRIPE_SESSION_ID = 'pending';

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
            if ($hasMarkable && $this->sessionAlreadyCreditedAsUnfulfilled($referenceCode, $session)) {
                $amountMismatch = true;

                return collect();
            }
            if ($hasMarkable && ! $this->allowStripeCaptureForOrders($session, $orders, $meta, $referenceCode)) {
                $amountMismatch = true;

                return collect();
            }
            if ($hasMarkable && ! $this->ensureBonusForCardMarkPaid($session, $orders, $meta, $referenceCode)) {
                $amountMismatch = true;

                return collect();
            }
            if ($hasMarkable && ! $this->consumeAppliedLeftoverCreditForMarkPaid($orders, $meta, $referenceCode)) {
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

            if ($hasMarkable && $newlyPaid->isEmpty() && $this->appliedLeftoverCreditFromMeta($meta) > 0.009) {
                throw new \RuntimeException(
                    'Consumed leftover card credit but no leftover could be marked paid for ref '.$referenceCode
                );
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
        $this->creditHiddenCardOrdersAfterMarkPaid($referenceCode, $sessionMeta, $session);

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
            if ($hasMarkable && $this->sessionAlreadyCreditedAsUnfulfilled($referenceCode, $intent)) {
                $amountMismatch = true;

                return collect();
            }
            if ($hasMarkable && ! $this->allowStripeCaptureForOrders($intent, $orders, $meta, $referenceCode)) {
                $amountMismatch = true;

                return collect();
            }
            if ($hasMarkable && ! $this->ensureBonusForCardMarkPaid($intent, $orders, $meta, $referenceCode)) {
                $amountMismatch = true;

                return collect();
            }
            if ($hasMarkable && ! $this->consumeAppliedLeftoverCreditForMarkPaid($orders, $meta, $referenceCode)) {
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

            if ($hasMarkable && $newlyPaid->isEmpty() && $this->appliedLeftoverCreditFromMeta($meta) > 0.009) {
                throw new \RuntimeException(
                    'Consumed leftover card credit but no leftover could be marked paid for ref '.$referenceCode
                );
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
        $this->creditHiddenCardOrdersAfterMarkPaid($referenceCode, $meta, $intent);

        return $newlyPaid;
    }

    /**
     * Card / bonus-only checkouts reserved promo without a purchase ledger row.
     * Clawback then treated the refund as cash. Write the same purchase hint
     * wallet checkout already writes, once per reference.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function recordAdvertiserPurchaseForPaidCheckout(
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

        try {
            app(WalletLedgerService::class)->recordPurchaseOnce(
                $wallet,
                $total,
                $bonusApplied,
                $orders->first(),
                $referenceCode
            );
        } catch (\Throwable $e) {
            // Orders and leftover hold are already settled. A thrown ledger
            // write must not unwind the caller into refund/forget.
            Log::error('Advertiser purchase ledger write failed after paid checkout', [
                'reference_code' => $referenceCode,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Promo this settled leftover still owns. Used so admin mark-paid can
     * write the same purchase hint Stripe finalize writes — clawback otherwise
     * credits the full line as withdrawable cash.
     */
    public function leftoverBonusForPurchaseLedger(Order $order): float
    {
        $userId = (int) $order->user_id;
        $reference = (string) ($order->reference_code ?? '');
        $cap = app(OrderRefundService::class)->cardLeftoverBonusCap($userId, $reference);
        if ($cap !== null) {
            return round($cap, 2);
        }

        $snapshot = $this->leftoverPackageBonusSnapshot($order);
        if ($snapshot > 0.009) {
            return $snapshot;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId || $userId <= 0) {
            return 0.0;
        }

        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();

        return $wallet ? max(0, round((float) $wallet->bonus_reserved, 2)) : 0.0;
    }

    /**
     * Fail/cancel snapshot for THIS leftover. leftoverBonusForPurchaseLedger
     * returns 0 when another checkout exists so reject cannot steal that
     * hold — but admin mark-paid still has to try to re-reserve this
     * leftover's own promo from the snapshot.
     */
    public function leftoverBonusToRereserve(Order $order): float
    {
        return max(
            $this->leftoverBonusForPurchaseLedger($order),
            $this->leftoverPackageBonusSnapshot($order)
        );
    }

    public function leftoverPackageBonusSnapshot(Order $order): float
    {
        $package = $this->getPendingCheckout((string) ($order->reference_code ?? ''));

        return is_array($package)
            ? round((float) ($package['bonus_applied'] ?? 0), 2)
            : 0.0;
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
            // Paid siblings on this ref still need the leftover hold for
            // approve/reject — a full forget made cardLeftoverBonusCap return 0.
            if ($marked->isNotEmpty()) {
                $this->forgetPendingCheckoutKeepLeftoverHold($referenceCode, $resolvedUserId);
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
     * Drop this advertiser's unpaid or failed leftovers for these articles so a
     * new checkout can claim them. Pay again on the same leftover still works
     * until they start that checkout of a content-ready article. Unready rows
     * (broken links, missing rights, expired) are skipped so Pay again survives.
     * Already-failed card leftovers are cancelled here because pending-only
     * fail updates skip them. Sibling leftovers that share the Stripe
     * reference stay open for Pay again.
     *
     * Stripe-first cancel keeps a package with no order rows. A later checkout
     * of one of those articles must drop that package so a late webhook credits
     * the wallet instead of fulfilling the abandoned sibling line.
     *
     * Checkout must call this only at the payment commit point (Stripe session
     * persisted, saved-card charge started, or wallet attach about to write).
     * $keepReferenceCode leaves the in-flight Stripe-first package in place so
     * forget does not drop the checkout we just stored. $forgetPackages is
     * false when the caller is still inside a DB transaction and will forget
     * after commit — otherwise a rolled-back leftover would lose its package.
     *
     * @param  array<int, int|string>  $submissionIds
     */
    public function replaceUnpaidLeftoversForSubmissions(
        int $userId,
        array $submissionIds,
        ?string $keepReferenceCode = null,
        bool $forgetPackages = true,
        bool $replaceExpired = false
    ): void {
        $submissionIds = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
        if ($userId <= 0 || $submissionIds === []) {
            return;
        }

        // Order / assign / checkout used to cancel leftovers first, then
        // reject unready articles. That dropped Pay again on a leftover the
        // advertiser could still settle after fixing links or rights.
        // Checkout commit may also replace expired leftovers that cannot
        // Pay again — assign-time must not, or the cart prune drops them.
        $submissionIds = ContentSubmission::query()
            ->whereIn('id', $submissionIds)
            ->where('user_id', $userId)
            ->get()
            ->filter(function (ContentSubmission $submission) use ($replaceExpired) {
                if ($submission->isLockedByPaidOrder() || ! $submission->hasFulfillableContent()) {
                    return false;
                }

                if ($submission->isContentReadyForOrder()) {
                    return true;
                }

                return $replaceExpired && $submission->isExpired();
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        if ($submissionIds === []) {
            return;
        }

        if (! Schema::hasColumn('order_items', 'content_submission_id')) {
            Log::warning('Skipping unpaid leftover replace: order_items.content_submission_id missing');

            return;
        }

        $itemOrderIds = OrderItem::query()
            ->whereIn('content_submission_id', $submissionIds)
            ->whereHas('order', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->where('status', 'pending')
                    ->where(function ($payment) {
                        $payment->whereNull('payment_status')
                            ->orWhereNotIn('payment_status', ['paid', 'refunded']);
                    });
            })
            ->pluck('order_id');

        $ownedOrderIds = ContentSubmission::query()
            ->whereIn('id', $submissionIds)
            ->where('user_id', $userId)
            ->whereNotNull('order_id')
            ->pluck('order_id');

        $orderIds = $itemOrderIds->merge($ownedOrderIds)->unique()->filter()->map(fn ($id) => (int) $id)->all();
        $orders = $orderIds === []
            ? collect()
            : Order::query()
                ->whereIn('id', $orderIds)
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->where(function ($payment) {
                    $payment->whereNull('payment_status')
                        ->orWhereNotIn('payment_status', ['paid', 'refunded']);
                })
                ->get();

        if ($orders->isNotEmpty()) {
            $refunds = app(OrderRefundService::class);
            foreach ($orders as $order) {
                $refunds->releaseReservedCheckoutBonus($order);
                if ((string) $order->payment_method !== 'card') {
                    $order->update([
                        'payment_status' => 'failed',
                        'status' => 'cancelled',
                    ]);
                } elseif ((string) $order->payment_status === 'pending') {
                    $order->update([
                        'payment_status' => 'failed',
                    ]);
                }

                ContentSubmission::releaseAllForOrder((int) $order->id);
                $fresh = $order->fresh();
                if ($fresh && $fresh->status !== 'cancelled') {
                    $fresh->update(['status' => 'cancelled']);
                }
            }

            // Multi-site card checkouts share one reference_code. Fail only the
            // replaced rows. Sibling leftovers stay pending so Pay again still
            // works; a late webhook must not treat the cancelled line as still owed.
            $replacedIds = $orders->pluck('id')->all();
            $cardRefs = $orders->where('payment_method', 'card')
                ->pluck('reference_code')
                ->unique()
                ->filter();
            foreach ($cardRefs as $referenceCode) {
                Order::query()
                    ->where('reference_code', $referenceCode)
                    ->where('user_id', $userId)
                    ->where('payment_method', 'card')
                    ->where('payment_status', 'pending')
                    ->where('status', 'pending')
                    ->whereNotIn('id', $replacedIds)
                    ->update(['payment_status' => 'failed']);

                $this->refundBonusReservedForReference($userId, (string) $referenceCode);
            }
        }

        if ($forgetPackages) {
            $this->forgetPendingCheckoutsForSubmissions($userId, $submissionIds, $keepReferenceCode);
        }
    }

    /**
     * Cancel leftovers only when the articles are free for a new checkout
     * afterwards. A concurrent paid claim or leftover that is still attached
     * rolls the cancel back so Pay again stays. $keepReferenceCode leaves the
     * in-flight Stripe-first package in place when forgetting after commit.
     *
     * @param  array<int, int|string>  $submissionIds
     */
    public function replaceUnpaidLeftoversIfStillOrderable(
        int $userId,
        array $submissionIds,
        ?string $keepReferenceCode = null,
        bool $forgetPackages = true,
        bool $replaceExpired = false
    ): bool {
        $submissionIds = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
        if ($userId <= 0 || $submissionIds === []) {
            return true;
        }

        try {
            DB::transaction(function () use ($userId, $submissionIds, $replaceExpired) {
                $this->replaceUnpaidLeftoversForSubmissions($userId, $submissionIds, null, false, $replaceExpired);
                foreach ($submissionIds as $submissionId) {
                    $fresh = ContentSubmission::query()->whereKey($submissionId)->lockForUpdate()->first();
                    if (! $fresh || $fresh->isLockedByPaidOrder() || ! $fresh->hasFulfillableContent()) {
                        throw new \RuntimeException('leftover-replace-unready');
                    }

                    if ($fresh->isClaimedByAnotherOrder()) {
                        // Assign-time: keep an expired leftover until checkout
                        // can actually charge. Cancelling here makes the row
                        // unused-expired and the cart prune drops it.
                        if (! $replaceExpired
                            && $fresh->isExpired()
                            && $fresh->canReplaceUnpaidLeftover()) {
                            continue;
                        }

                        throw new \RuntimeException('leftover-replace-unready');
                    }

                    if (! $fresh->isExpired()
                        && (! $fresh->canBeOrdered() || ! $fresh->isReadyForCheckout())) {
                        throw new \RuntimeException('leftover-replace-unready');
                    }
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'leftover-replace-unready') {
                throw $e;
            }

            return false;
        }

        if ($forgetPackages) {
            $this->forgetPendingCheckoutsForSubmissions($userId, $submissionIds, $keepReferenceCode);
        }

        return true;
    }

    /**
     * Drop Stripe-first packages that still list these articles. Cancel URL
     * keeps the package when there are no leftover rows; a later checkout of
     * one line must not let a late webhook rematerialize the rest.
     *
     * @param  array<int, int>  $submissionIds
     */
    public function forgetPendingCheckoutsForSubmissions(
        int $userId,
        array $submissionIds,
        ?string $keepReferenceCode = null
    ): void {
        $submissionIds = array_values(array_unique(array_filter(array_map('intval', $submissionIds))));
        if ($userId <= 0 || $submissionIds === []) {
            return;
        }

        $wanted = array_fill_keys($submissionIds, true);
        $refs = [];

        if (Schema::hasTable((new CheckoutIntent)->getTable())) {
            $intents = CheckoutIntent::query()
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->orWhereNull('user_id');
                })
                ->whereNotNull('package')
                ->get(['reference_code', 'package', 'user_id']);
            foreach ($intents as $intent) {
                $package = is_array($intent->package) ? $intent->package : [];
                $packageUserId = (int) ($package['user_id'] ?? $intent->user_id ?? 0);
                if ($packageUserId > 0 && $packageUserId !== $userId) {
                    continue;
                }
                if ($this->pendingCheckoutPackageListsSubmission($package, $wanted)) {
                    $refs[] = (string) $intent->reference_code;
                }
            }
        }

        $sessionRef = search_text((string) session('pending_card_reference', ''));
        if ($sessionRef !== '') {
            $sessionPackage = $this->getPendingCheckout($sessionRef);
            if (is_array($sessionPackage)
                && (int) ($sessionPackage['user_id'] ?? 0) === $userId
                && $this->pendingCheckoutPackageListsSubmission($sessionPackage, $wanted)) {
                $refs[] = $sessionRef;
            }
        }

        $keepReferenceCode = search_text((string) $keepReferenceCode);
        foreach (array_unique(array_filter($refs)) as $referenceCode) {
            if ($keepReferenceCode !== '' && (string) $referenceCode === $keepReferenceCode) {
                continue;
            }
            $this->forgetPendingCheckoutKeepLeftoverHold($referenceCode, $userId);
        }
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<int, true>  $wanted
     */
    private function pendingCheckoutPackageListsSubmission(array $package, array $wanted): bool
    {
        $lines = is_array($package['lines'] ?? null) ? $package['lines'] : [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $submissionId = (int) ($line['content_submission_id'] ?? 0);
            if ($submissionId > 0 && isset($wanted[$submissionId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only the fulfilled share of checkout promo. Leftover/hidden lines
     * must not leave their bonus slice reserved forever.
     *
     * $packageBonus is the cart snapshot, not a live hold. After a taken
     * library line already restored promo, refundReserved() of that snapshot
     * would spend another checkout's reserve.
     */
    public function keepCheckoutBonusForFulfilled(
        int $userId,
        string $referenceCode,
        float $packageBonus,
        float $orderTotal,
        float $fulfilled
    ): float {
        $packageBonus = round($packageBonus, 2);
        $orderTotal = round($orderTotal, 2);
        $fulfilled = round($fulfilled, 2);
        if ($userId <= 0 || $packageBonus <= 0.009) {
            return 0.0;
        }

        $desiredKeep = $orderTotal > 0.009
            ? round(min($packageBonus, $packageBonus * ($fulfilled / $orderTotal)), 2)
            : 0.0;

        $intents = app(CheckoutIntentService::class);
        $liveHeld = $intents->heldBonus($userId, $referenceCode);
        $release = round(max(0, $liveHeld - $desiredKeep), 2);
        if ($release > 0.009) {
            $roleId = Wallet::advertiserRoleId();
            if ($roleId) {
                $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
                $wallet->refundReserved($release, $release);
            }
            $left = round(max(0, $liveHeld - $release), 2);
            if ($left > 0.009) {
                $intents->rememberBonus($userId, $referenceCode, $left);
            } else {
                $intents->forgetBonus($userId, $referenceCode);
            }
        }

        return $desiredKeep;
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
     * still settle and must keep its promo). Uses the live hold only —
     * leftover package JSON is a late-pay snapshot, not a second reserve.
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

            // Live hold only. package.bonus_applied is a late-pay snapshot —
            // after cancel, held is 0 but the JSON still lists the promo.
            $held = app(CheckoutIntentService::class)->heldBonus($userId, $ref);
            if ($held > 0.009) {
                $alreadySettled = Order::query()
                    ->where('reference_code', $ref)
                    ->where('user_id', $userId)
                    ->where(function ($query) {
                        $query->where('status', 'completed')
                            ->orWhere('payment_status', 'refunded');
                    })
                    ->exists();
                // Approve/reject already moved this leftover in the wallet.
                // refundReserved() here would drain another checkout's reserve.
                if (! $alreadySettled) {
                    $roleId = Wallet::advertiserRoleId();
                    if ($roleId) {
                        $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
                        $wallet->refundReserved($held, $held);
                    }
                }
                app(CheckoutIntentService::class)->takeBonus($userId, $ref, $held);
            }

            if ($keepReference !== null && $ref !== $keepReference) {
                $snapshotBonus = is_array($package)
                    ? round((float) ($package['bonus_applied'] ?? 0), 2)
                    : 0.0;
                // Cancelled leftovers (held=0, snapshot still lists promo) must
                // stay so a late paid webhook can still settle that cart.
                if ($held > 0.009 || $snapshotBonus <= 0.009) {
                    $this->forgetPendingCheckout($ref);
                }
            }
        }
    }

    /**
     * Credit captured card cash when Stripe-first lines left the catalog.
     * Idempotent per checkout reference (and optional settlement/session key).
     *
     * @param  list<string>  $captureIds  Checkout session and PaymentIntent ids for the same capture
     */
    public function creditUnfulfilledCardCapture(int $userId, string $referenceCode, float $amount, ?string $settlementKey = null, array $captureIds = [], string $paymentMethod = 'card'): float
    {
        $amount = round($amount, 2);
        if ($userId <= 0 || $amount <= 0) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return 0.0;
        }

        $captureIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => is_string($id) ? $id : '', array_merge(
                $captureIds,
                is_string($settlementKey) && $settlementKey !== '' ? [$settlementKey] : []
            )),
            static fn (string $id) => $id !== ''
        )));

        $reference = self::unfulfilledCardCreditReference($referenceCode, $settlementKey);
        $aliases = [$reference];
        $unkeyed = ! is_string($settlementKey) || $settlementKey === '';
        $prefix = self::unfulfilledCardCreditReference($referenceCode);
        if (! $unkeyed) {
            $aliases[] = $prefix;
        }
        foreach ($captureIds as $captureId) {
            $aliases[] = self::unfulfilledCardCreditReference($referenceCode, $captureId);
        }
        $aliases = array_values(array_unique($aliases));

        $credited = (float) DB::transaction(function () use ($userId, $roleId, $amount, $reference, $referenceCode, $aliases, $unkeyed, $prefix, $captureIds, $paymentMethod, $settlementKey) {
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
                    ->where(function ($query) use ($aliases, $unkeyed, $prefix) {
                        $query->whereIn('reference', $aliases);
                        // Hidden-line leftover credit is unkeyed. A prior
                        // session-keyed credit for this leftover is the same
                        // capture — stacking it minted the card cash twice.
                        if ($unkeyed) {
                            $query->orWhere('reference', 'like', $prefix.'-%');
                        }
                    })
                    ->exists()) {
                return 0.0;
            }

            $method = $this->leftoverPaymentMethod($paymentMethod, $captureIds, $settlementKey);
            $methodLabel = $method === 'paypal' ? 'PayPal' : 'Card';

            $wallet->credit($amount);
            app(WalletLedgerService::class)->recordAdjustment(
                $wallet,
                $amount,
                'credit',
                null,
                $reference,
                $methodLabel.' payment credited because the checkout could not create the order',
                [
                    'reference_code' => $referenceCode,
                    'capture_ids' => $captureIds,
                    'payment_method' => $method,
                ]
            );

            Log::info('Credited unfulfilled checkout capture to advertiser wallet', [
                'user_id' => $userId,
                'reference_code' => $referenceCode,
                'amount' => $amount,
                'payment_method' => $method,
            ]);

            return $amount;
        });

        if ($credited > 0.009) {
            $method = $this->leftoverPaymentMethod($paymentMethod, $captureIds, $settlementKey);
            $this->logLeftoverCardCredit($userId, $roleId, $referenceCode, $credited, $captureIds, $method);
            $this->notifyUnfulfilledCheckoutCredited($userId, $reference, $credited, $method);
        }

        return $credited;
    }

    /**
     * @param  list<string>  $captureIds
     */
    private function leftoverPaymentMethod(string $paymentMethod, array $captureIds, ?string $settlementKey): string
    {
        $ids = array_merge(
            $captureIds,
            is_string($settlementKey) && $settlementKey !== '' ? [$settlementKey] : []
        );
        foreach ($ids as $id) {
            $id = strtolower((string) $id);
            if (str_starts_with($id, 'cap-') || str_starts_with($id, 'po-')) {
                return 'paypal';
            }
            if (str_starts_with($id, 'cs_') || str_starts_with($id, 'pi_') || str_starts_with($id, 'ch_')) {
                return 'card';
            }
        }

        $explicit = strtolower(trim($paymentMethod));

        return in_array($explicit, ['paypal', 'card'], true) ? $explicit : 'card';
    }

    /**
     * @param  list<string>  $captureIds
     */
    private function logLeftoverCardCredit(int $userId, int $roleId, string $referenceCode, float $amount, array $captureIds, string $paymentMethod = 'card'): void
    {
        $user = User::query()->find($userId);
        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();

        $who = $user?->name ?: $user?->email ?: 'Advertiser';
        $methodLabel = $paymentMethod === 'paypal' ? 'PayPal' : 'card';
        ActivityLogger::tryLog(
            'wallet.leftover_card_credited',
            $who.' was credited €'.number_format($amount, 2).' from a '.$methodLabel.' checkout that could not create the order',
            $wallet,
            [
                'user_id' => $userId,
                'wallet_id' => $wallet?->id,
                'reference_code' => $referenceCode,
                'amount' => $amount,
                'capture_ids' => $captureIds,
                'payment_method' => $paymentMethod,
            ],
            $user?->email,
            $user
        );
    }

    private function notifyUnfulfilledCheckoutCredited(int $userId, string $walletReference, float $amount, string $paymentMethod): void
    {
        $user = User::query()->find($userId);
        if (! $user?->email) {
            Log::warning('Cannot email leftover checkout credit — user has no email', [
                'user_id' => $userId,
                'reference' => $walletReference,
            ]);

            return;
        }

        try {
            Mail::to($user->email)->send(new UnfulfilledCheckoutCredited(
                $user,
                $amount,
                $walletReference,
                $paymentMethod
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send leftover checkout credit email: '.$e->getMessage(), [
                'user_id' => $userId,
                'reference' => $walletReference,
            ]);
        }

        try {
            app(InAppNotificationService::class)->notifyUnfulfilledCheckoutCredited(
                $user,
                $amount,
                $walletReference,
                $paymentMethod
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send leftover checkout credit bell: '.$e->getMessage(), [
                'user_id' => $userId,
                'reference' => $walletReference,
            ]);
        }
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
        $expected = $this->capturedStripeEurosForCredit($orders, $meta);
        $paidThisCapture = $this->settledCardEurosForStripeCapture($orders, $session, 'paid');
        $refundedThisCapture = $this->settledCardEurosForStripeCapture($orders, $session, 'refunded');
        // expected_amount is THIS capture. Paid/refunded siblings from an
        // earlier session must not shrink (or zero) the hidden leftover's
        // credit, and same-capture refunds must not be credited again.
        $unfulfilled = round(max(0, $expected - $paidThisCapture - $refundedThisCapture), 2);
        // Same leftover may already have a session-keyed credit (#831 bonus
        // fail, amount mismatch). An unkeyed top-up here paid the capture
        // twice after the listing left the catalog and the webhook retried.
        // A prior capture on this reference must not skip THIS capture —
        // unkeyed credit also treats any UNFULFILLED-CARD-{ref}-* as a hit.
        $alreadyCredited = $session !== null
            ? $this->sessionAlreadyCreditedAsUnfulfilled($referenceCode, $session)
            : $this->unfulfilledCardCreditAmount($referenceCode) > 0.009;
        if ($userId > 0 && $unfulfilled > 0.009 && ! $alreadyCredited) {
            if ($session !== null) {
                $this->creditUnfulfilledFromStripeObject($userId, $referenceCode, $unfulfilled, $session);
            } else {
                $this->creditUnfulfilledCardCapture($userId, $referenceCode, $unfulfilled);
            }
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

    public static function unfulfilledCardCreditApplyReference(string $referenceCode): string
    {
        return 'UNFULFILLED-CARD-APPLY-'.$referenceCode;
    }

    /**
     * Cash from an earlier unfulfilled leftover capture that Pay again can
     * spend toward this package. Capped by what is still withdrawable.
     */
    public function unfulfilledCardCreditAppliedAmount(string $referenceCode): float
    {
        if (! Schema::hasTable((new WalletTransaction)->getTable())) {
            return 0.0;
        }

        return round((float) WalletTransaction::query()
            ->where('direction', 'debit')
            ->where('reference', self::unfulfilledCardCreditApplyReference($referenceCode))
            ->sum('amount'), 2);
    }

    public function unfulfilledCardCreditRemaining(string $referenceCode): float
    {
        return round(max(
            0,
            $this->unfulfilledCardCreditAmount($referenceCode)
            - $this->unfulfilledCardCreditAppliedAmount($referenceCode)
        ), 2);
    }

    public function unfulfilledCardCreditToApply(int $userId, string $referenceCode, float $packageTotal): float
    {
        $packageTotal = round($packageTotal, 2);
        $credited = $this->unfulfilledCardCreditRemaining($referenceCode);
        if ($userId <= 0 || $packageTotal <= 0.009 || $credited <= 0.009) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        $wallet = $roleId
            ? Wallet::query()->where('user_id', $userId)->where('role_id', $roleId)->first()
            : null;
        $withdrawable = $wallet ? $wallet->withdrawableBalance() : 0.0;

        return round(min($credited, $packageTotal, $withdrawable), 2);
    }

    /**
     * Spend leftover card credit so Pay again cannot keep the refund and
     * still receive the placement. Idempotent per reference.
     */
    public function consumeUnfulfilledCardCreditForLeftover(int $userId, string $referenceCode, float $amount): float
    {
        $amount = round($amount, 2);
        if ($userId <= 0 || $amount <= 0.009) {
            return 0.0;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return 0.0;
        }

        $reference = self::unfulfilledCardCreditApplyReference($referenceCode);

        return (float) DB::transaction(function () use ($userId, $roleId, $amount, $reference, $referenceCode) {
            $wallet = Wallet::lockOrCreateForRole($userId, $roleId);
            if (Schema::hasTable((new WalletTransaction)->getTable())) {
                $already = WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)
                    ->where('direction', 'debit')
                    ->where('reference', $reference)
                    ->first();
                if ($already) {
                    return round((float) $already->amount, 2);
                }
            }

            // All-or-nothing: a shortfall used to debit whatever was left,
            // then mark-paid returned false and committed the partial take.
            // Pay again had already charged the card shortfall, so the
            // leftover stayed failed and the advertiser lost wallet cash.
            $apply = round(min($amount, $wallet->withdrawableBalance()), 2);
            if ($apply + 0.009 < $amount) {
                return 0.0;
            }

            $wallet->debit($apply);
            app(WalletLedgerService::class)->recordAdjustment(
                $wallet,
                $apply,
                'debit',
                null,
                $reference,
                'Applied leftover card credit toward Pay again',
                ['reference_code' => $referenceCode]
            );

            return $apply;
        });
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function consumeAppliedLeftoverCreditForMarkPaid(
        Collection $orders,
        array $meta,
        string $referenceCode
    ): bool {
        $appliedCredit = $this->appliedLeftoverCreditFromMeta($meta);
        if ($appliedCredit <= 0.009) {
            return true;
        }

        $userId = (int) ($orders->first()?->user_id ?? ($meta['user_id'] ?? 0));
        if ($userId <= 0) {
            return false;
        }

        return $this->consumeUnfulfilledCardCreditForLeftover($userId, $referenceCode, $appliedCredit) + 0.009
            >= $appliedCredit;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function appliedLeftoverCreditFromMeta(array $meta): float
    {
        return round((float) ($meta['unfulfilled_credit_applied'] ?? 0), 2);
    }

    /**
     * Pay again when the leftover card credit already covers the package.
     *
     * @return Collection<int, Order>
     */
    public function settleFailedCardLeftoversFromAppliedCredit(
        string $referenceCode,
        int $userId,
        float $applied
    ): Collection {
        $applied = round($applied, 2);
        if ($userId <= 0 || $applied <= 0.009) {
            return collect();
        }

        $newlyPaid = DB::transaction(function () use ($referenceCode, $userId, $applied) {
            $consumed = $this->consumeUnfulfilledCardCreditForLeftover($userId, $referenceCode, $applied);
            if ($consumed + 0.009 < $applied) {
                return collect();
            }

            $orders = Order::with('items')
                ->where('reference_code', $referenceCode)
                ->where('user_id', $userId)
                ->where('payment_method', 'card')
                ->where('payment_status', 'failed')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            $newlyPaid = collect();
            foreach ($orders as $order) {
                $settled = $this->settleExistingCardOrder($order, [
                    'paid_at' => now(),
                    'payment_status' => 'paid',
                    'status' => 'pending',
                ]);
                if ($settled) {
                    $newlyPaid->push($settled);
                }
            }

            return $newlyPaid;
        });

        if ($newlyPaid->isNotEmpty()) {
            $this->recordAdvertiserPurchaseForPaidCheckout(
                $referenceCode,
                $newlyPaid,
                0.0,
                (float) $newlyPaid->sum(fn (Order $order) => (float) $order->total_amount)
            );
            $this->evaluateSpendBudgetAfterPaidOrders($newlyPaid);
            $this->notifyPublishersOfPaidOrders($newlyPaid);
        }

        return $newlyPaid;
    }

    /**
     * This Stripe capture was already returned as wallet cash (bonus gone,
     * listing gone, or amount mismatch). A later webhook/success URL must
     * not also mark the leftover paid once the promo is free again.
     *
     * Checkout session and PaymentIntent ids are the same capture. Matching
     * only the object in hand let payment_intent.succeeded settle after
     * checkout.session.completed had already credited the leftover.
     */
    private function sessionAlreadyCreditedAsUnfulfilled(string $referenceCode, object $session): bool
    {
        $ids = $this->stripeCaptureIds($session);
        if ($ids === [] || ! Schema::hasTable((new WalletTransaction)->getTable())) {
            return false;
        }

        $prefix = self::unfulfilledCardCreditReference($referenceCode);
        $keys = array_map(
            fn (string $id) => self::unfulfilledCardCreditReference($referenceCode, $id),
            $ids
        );

        if (WalletTransaction::query()
            ->where('direction', 'credit')
            ->whereIn('reference', $keys)
            ->exists()) {
            return true;
        }

        $credits = WalletTransaction::query()
            ->where('direction', 'credit')
            ->where(function ($query) use ($prefix) {
                $query->where('reference', $prefix)
                    ->orWhere('reference', 'like', $prefix.'-%');
            })
            ->get(['meta']);

        foreach ($credits as $credit) {
            $stored = $credit->meta['capture_ids'] ?? [];
            if (! is_array($stored)) {
                continue;
            }
            $stored = array_map(static fn ($id) => is_string($id) ? $id : '', $stored);
            if (array_intersect($ids, $stored) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function stripeCaptureIds(object $session): array
    {
        $ids = [];
        foreach ([(string) ($session->id ?? ''), (string) ($session->payment_intent ?? '')] as $id) {
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * cancelAndRefund already returned this capture after mark-paid found the
     * library article unusable. Webhook / finalize last-resort credits must
     * not stack a second wallet credit for the same Stripe object.
     */
    private function stripeCaptureAlreadyRefunded(string $referenceCode, object $session): bool
    {
        $ids = $this->stripeCaptureIds($session);
        if ($ids === [] || $referenceCode === '') {
            return false;
        }

        return Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->where('payment_status', 'refunded')
            ->where(function ($query) use ($ids) {
                $query->whereIn('stripe_session_id', $ids)
                    ->orWhereIn('stripe_payment_intent_id', $ids);
            })
            ->exists();
    }

    private function creditUnfulfilledFromStripeObject(
        int $userId,
        string $referenceCode,
        float $amount,
        object $session
    ): float {
        $ids = $this->stripeCaptureIds($session);

        return $this->creditUnfulfilledCardCapture(
            $userId,
            $referenceCode,
            $amount,
            $ids[0] ?? null,
            $ids
        );
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
            $this->unfulfilledCardCreditRemaining($referenceCode)
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
     * Drop the package but keep leftover promo so approve/reject can cap this
     * ref. Used after Stripe-first finalize and after fail/cancel when a paid
     * sibling on the same checkout still owns reserved bonus.
     */
    public function forgetPendingCheckoutKeepLeftoverHold(string $referenceCode, int $userId): void
    {
        $intents = app(CheckoutIntentService::class);
        $held = $userId > 0
            ? $intents->heldBonus($userId, $referenceCode)
            : 0.0;
        $package = $this->getPendingCheckout($referenceCode);
        $snapshotBonus = is_array($package)
            ? round((float) ($package['bonus_applied'] ?? 0), 2)
            : 0.0;

        $this->forgetPendingCheckout($referenceCode);
        if ($userId > 0 && $held > 0.009) {
            $intents->rememberBonus($userId, $referenceCode, $held);

            return;
        }

        // Fail/cancel already released the live hold. Keep a snapshot-only
        // package so admin mark-paid can re-reserve THIS leftover's promo
        // instead of minting it as cash on a later reject. Do not snapshot
        // after a paid leftover — Pay again / full-card settle must not
        // leave bonus_applied for clawback to treat as promo.
        $alreadyPaid = $userId > 0 && Order::query()
            ->where('reference_code', $referenceCode)
            ->where('user_id', $userId)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled')
            ->exists();
        if ($userId > 0 && $snapshotBonus > 0.009 && is_array($package) && ! $alreadyPaid) {
            $intents->storeLeftoverBonusSnapshot($referenceCode, $userId, $package, $snapshotBonus);
        }
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
        $packageLines = is_array($package['lines'] ?? null) ? $package['lines'] : [];
        $hasMaterializableLines = collect($packageLines)->contains(fn ($line) => is_array($line));
        if ($package !== null && ! $hasMaterializableLines && $existingCount > 0) {
            // Snapshot-only leftover after fail/cancel. A late Pay-again
            // session must mark those rows paid — comparing its amount to
            // the old amount_due would wallet-credit and leave them failed.
            return $this->markOrdersPaidFromStripeSession($referenceCode, $session);
        }
        if ($package === null) {
            if ($existingCount > 0) {
                $newlyPaid = $this->markOrdersPaidFromStripeSession($referenceCode, $session);
                if ($newlyPaid->isEmpty()) {
                    $hasOpenPaid = Order::query()
                        ->where('reference_code', $referenceCode)
                        ->where('payment_method', 'card')
                        ->where('payment_status', 'paid')
                        ->where('status', '!=', 'cancelled')
                        ->exists();
                    if ($hasOpenPaid) {
                        $this->creditCapturedCardWhenAlreadySettled($referenceCode, $session);
                    } else {
                        // Replaced leftover: cancelled rows remain, but the
                        // capture still has to land in the advertiser wallet.
                        $this->creditCapturedCardWhenPackageMissing($referenceCode, $session);
                    }
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

            // Pay again uses a new Checkout session and does not rewrite the
            // leftover package. Mark matching leftovers instead of only
            // crediting the capture and leaving the article claimed.
            $existingCardOrders = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->get();
            if ($existingCardOrders->contains(fn (Order $order) => $this->canMarkCardOrderPaid($order))) {
                $marked = $this->markOrdersPaidFromStripeSession($referenceCode, $session);
                if ($marked->isNotEmpty()) {
                    $this->forgetPendingCheckoutKeepLeftoverHold(
                        $referenceCode,
                        (int) ($marked->first()->user_id ?? $package['user_id'] ?? 0)
                    );

                    return $marked;
                }
            }

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
            if ($userId > 0) {
                $this->creditUnfulfilledFromStripeObject(
                    $userId,
                    $referenceCode,
                    $stripeEuros,
                    $session
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
        $existingCardOrders = Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->get();
        $hasMarkableLeftover = $existingCardOrders->contains(
            fn (Order $order) => $this->canMarkCardOrderPaid($order)
        );
        $hasOpenPaid = $existingCardOrders->contains(
            fn (Order $order) => $order->payment_status === 'paid' && $order->status !== 'cancelled'
        );
        if ($existingCardOrders->isNotEmpty() && ! $hasMarkableLeftover && ! $hasOpenPaid) {
            if ($userId > 0) {
                $this->creditUnfulfilledFromStripeObject(
                    $userId,
                    $referenceCode,
                    $expected,
                    $session
                );
            }
            $this->forgetPendingCheckout($referenceCode);
            Log::warning('Stripe-first checkout paid after leftover was replaced; credited wallet', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
                'user_id' => $userId,
                'wallet_credit' => $expected,
            ]);

            return collect();
        }

        // Leftover rows on this ref already own the placement. Rematerializing
        // the package first minted a second paid order (or refunded it as
        // "taken") and then marked the leftover paid — double settlement.
        if ($hasMarkableLeftover) {
            $marked = $this->markOrdersPaidFromStripeSession($referenceCode, $session);
            if ($marked->isNotEmpty()) {
                $this->forgetPendingCheckoutKeepLeftoverHold(
                    $referenceCode,
                    (int) ($marked->first()->user_id ?? $userId)
                );

                return $marked;
            }

            return collect();
        }

        $bonusNeeded = round((float) ($package['bonus_applied'] ?? $meta['bonus_applied'] ?? 0), 2);
        if ($userId > 0 && $bonusNeeded > 0.009) {
            $held = $this->ensureCheckoutBonusReserved($userId, $referenceCode, $bonusNeeded);
            if ($held + 0.009 < $bonusNeeded) {
                $this->creditUnfulfilledFromStripeObject(
                    $userId,
                    $referenceCode,
                    $expected,
                    $session
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
                if ($submission && ! $articleTaken && ! $articleUnready
                    && ! $this->submissionPassesLivePolicy($submission, $buyer)) {
                    $articleUnready = true;
                    $submission = $submission->fresh() ?? $submission;
                }
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
                    $refunded = app(OrderRefundService::class)->cancelAndRefundBreakdown($order, $reason);
                    // Subtract only the card cash actually credited. The gross
                    // line total still includes promo; using it here shorted
                    // leftover hidden lines by that bonus slice.
                    $refundedInFinalize = round(
                        $refundedInFinalize + (float) $refunded['cash'],
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
                    $this->forgetPendingCheckoutKeepLeftoverHold(
                        $referenceCode,
                        (int) ($marked->first()->user_id ?? $package['user_id'] ?? 0)
                    );

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
                $this->creditUnfulfilledFromStripeObject(
                    $userId,
                    $referenceCode,
                    $unfulfilled,
                    $session
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
            $this->creditUnfulfilledFromStripeObject(
                $userId,
                $referenceCode,
                $unfulfilled,
                $session
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

        $this->forgetPendingCheckoutKeepLeftoverHold($referenceCode, $userId);

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
     * Capture-then-fulfill for PayPal. Idempotent on paypal_capture_id /
     * paypal_order_id so return + webhook cannot mint a second checkout.
     *
     * @param  array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw?: array<string, mixed>}  $captured
     * @return Collection<int, Order>
     */
    public function finalizePaypalCheckout(string $referenceCode, array $captured): Collection
    {
        $captureId = trim((string) ($captured['capture_id'] ?? ''));
        if ($captureId === '') {
            throw new \RuntimeException('PayPal finalize is missing capture_id.');
        }

        try {
            return Cache::lock('paypal_finalize:'.$captureId, 20)
                ->block(15, fn () => $this->finalizePaypalCheckoutLocked($referenceCode, $captured));
        } catch (\BadMethodCallException) {
            return $this->finalizePaypalCheckoutLocked($referenceCode, $captured);
        }
    }

    /**
     * @param  array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw?: array<string, mixed>}  $captured
     * @return Collection<int, Order>
     */
    private function finalizePaypalCheckoutLocked(string $referenceCode, array $captured): Collection
    {
        $paypalOrderId = trim((string) ($captured['id'] ?? ''));
        $captureId = trim((string) ($captured['capture_id'] ?? ''));
        $already = $this->paidPaypalOrdersForCapture($captureId, $paypalOrderId, $referenceCode);
        if ($already->isNotEmpty()) {
            return collect();
        }

        $package = $this->getPendingCheckout($referenceCode);
        $custom = is_array($captured['custom'] ?? null) ? $captured['custom'] : [];
        $metaUserId = isset($custom['user_id']) ? (int) $custom['user_id'] : 0;
        $metaType = (string) ($custom['type'] ?? '');
        $capturedAmount = round((float) ($captured['amount'] ?? 0), 2);

        if ($metaType !== '' && $metaType !== PaypalCheckoutService::TYPE_ORDER_CHECKOUT) {
            throw new \RuntimeException('PayPal capture is not an order checkout.');
        }

        if ($package === null) {
            if ($already->isNotEmpty()) {
                return $already;
            }
            if ($metaUserId > 0 && $capturedAmount > 0.009) {
                $this->creditUnfulfilledCardCapture($metaUserId, $referenceCode, $capturedAmount, $captureId, [$captureId, $paypalOrderId], 'paypal');
            }
            Log::warning('No pending PayPal checkout package to materialize', [
                'reference_code' => $referenceCode,
                'paypal_order_id' => $paypalOrderId,
                'paypal_capture_id' => $captureId,
            ]);

            return collect();
        }

        $packageUserId = (int) ($package['user_id'] ?? 0);
        if ($packageUserId > 0 && $metaUserId > 0 && $packageUserId !== $metaUserId) {
            throw new \RuntimeException('PayPal checkout package does not belong to the paying user for ref '.$referenceCode);
        }

        $packagePaypalOrderId = search_text((string) ($package['paypal_order_id'] ?? ''));
        if ($packagePaypalOrderId !== '' && $paypalOrderId !== '' && $packagePaypalOrderId !== $paypalOrderId) {
            Log::warning('PayPal order id does not match current checkout package', [
                'reference_code' => $referenceCode,
                'package_paypal_order_id' => $packagePaypalOrderId,
                'paypal_order_id' => $paypalOrderId,
            ]);
            $this->creditUnfulfilledCardCapture(
                $packageUserId > 0 ? $packageUserId : $metaUserId,
                $referenceCode,
                $capturedAmount,
                $captureId,
                [$captureId, $paypalOrderId],
                'paypal'
            );

            return collect();
        }

        $expected = round((float) ($package['amount_due'] ?? $package['order_total'] ?? 0), 2);
        $userId = $packageUserId > 0 ? $packageUserId : $metaUserId;
        if ($capturedAmount > 0 && abs($capturedAmount - $expected) > 0.01) {
            if ($userId > 0) {
                $this->creditUnfulfilledCardCapture($userId, $referenceCode, $capturedAmount, $captureId, [$captureId, $paypalOrderId], 'paypal');
            }
            Log::warning('PayPal capture amount does not match current checkout package', [
                'reference_code' => $referenceCode,
                'package_amount_due' => $expected,
                'paypal_euros' => $capturedAmount,
                'user_id' => $userId,
            ]);

            return collect();
        }

        $schema = app(CheckoutSchemaService::class);
        $schema->ensureCheckoutTables();

        $created = DB::transaction(function () use ($package, $referenceCode, $captured, $schema, $paypalOrderId, $captureId, $userId) {
            $existingPaid = $this->paidPaypalOrdersForCapture($captureId, $paypalOrderId, $referenceCode);
            if ($existingPaid->isNotEmpty()) {
                return $existingPaid;
            }

            $buyer = $userId > 0 ? User::query()->find($userId) : null;
            $schedule = is_array($package['schedule'] ?? null) ? $package['schedule'] : ['mode' => 'immediate', 'timezone' => 'UTC'];
            $lines = is_array($package['lines'] ?? null) ? $package['lines'] : [];
            $orders = collect();
            $storedCaptureOnFirst = false;

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $siteId = isset($line['site_id']) ? (int) $line['site_id'] : 0;
                $site = $siteId > 0
                    ? Site::query()->whereKey($siteId)->lockForUpdate()->first()
                    : null;
                if ($siteId > 0 && (! $site || ! $site->isCatalogVisible() || $site->isOwnedBy($buyer))) {
                    Log::warning('Skipping PayPal line; listing left the catalog', [
                        'reference_code' => $referenceCode,
                        'site_id' => $siteId,
                        'paypal_capture_id' => $captureId,
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
                if ($submission && ! $articleTaken && ! $articleUnready
                    && ! $this->submissionPassesLivePolicy($submission, $buyer)) {
                    $articleUnready = true;
                }
                $attachSubmission = $submission && ! $articleTaken && ! $articleUnready;

                $order = $this->createPaidCardOrderRow($schema, [
                    'user_id' => $userId,
                    'reference_code' => $referenceCode,
                    'checkout_line_key' => $lineKey,
                    'subtotal' => $line['price'] ?? 0,
                    'tax' => 0,
                    'total_amount' => $line['price'] ?? 0,
                    'payment_method' => 'paypal',
                    'payment_status' => 'paid',
                    'status' => 'pending',
                    'sensitive_type' => $line['sensitive_type'] ?? null,
                    'additional_price' => $line['additional_price'] ?? 0,
                    'publication_mode' => $schedule['mode'] ?? 'immediate',
                    'scheduled_publish_at' => $schedule['at'] ?? null,
                    'schedule_timezone' => $schedule['timezone'] ?? 'UTC',
                    'paypal_order_id' => $paypalOrderId !== '' ? $paypalOrderId : null,
                    'paypal_capture_id' => $storedCaptureOnFirst ? null : $captureId,
                    'paypal_response' => $captured['raw'] ?? $captured,
                    'paid_at' => now(),
                ]);
                if ($order === null) {
                    continue;
                }
                if (! $storedCaptureOnFirst && filled($order->paypal_capture_id)) {
                    $storedCaptureOnFirst = true;
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
                    app(OrderRefundService::class)->cancelAndRefundBreakdown($order, $reason);

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
            $replay = $this->paidPaypalOrdersForCapture($captureId, $paypalOrderId, $referenceCode);
            if ($replay->isNotEmpty()) {
                return collect();
            }
            if ($userId > 0 && $capturedAmount > 0.009) {
                $this->creditUnfulfilledCardCapture($userId, $referenceCode, $capturedAmount, $captureId, [$captureId, $paypalOrderId], 'paypal');
            }
            $this->forgetPendingCheckout($referenceCode);
            Log::warning('PayPal checkout paid but no catalog-visible lines to materialize', [
                'reference_code' => $referenceCode,
                'paypal_capture_id' => $captureId,
                'user_id' => $userId,
                'line_count' => is_array($package['lines'] ?? null) ? count($package['lines']) : -1,
            ]);

            return collect();
        }

        $fulfilled = round((float) $created->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $unfulfilled = round(max(0, $expected - $fulfilled), 2);
        if ($userId > 0 && $unfulfilled > 0.009) {
            $this->creditUnfulfilledCardCapture($userId, $referenceCode, $unfulfilled, $captureId, [$captureId, $paypalOrderId], 'paypal');
        }

        $packageBonus = round((float) ($package['bonus_applied'] ?? 0), 2);
        $packageTotal = round((float) ($package['order_total'] ?? 0), 2);
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

        $this->forgetPendingCheckoutKeepLeftoverHold($referenceCode, $userId);
        $this->recordAdvertiserPurchaseForPaidCheckout($referenceCode, $created, $bonusKeep, $fulfilled);
        $this->evaluateSpendBudgetAfterPaidOrders($created);

        Log::info('Materialized PayPal checkout orders after capture', [
            'reference_code' => $referenceCode,
            'order_count' => $created->count(),
            'paypal_capture_id' => $captureId,
        ]);

        return $created;
    }

    /**
     * @return Collection<int, Order>
     */
    private function paidPaypalOrdersForCapture(string $captureId, string $paypalOrderId, string $referenceCode): Collection
    {
        $query = Order::query()
            ->with('items')
            ->where('payment_method', 'paypal')
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'cancelled');

        if ($captureId === '' && $paypalOrderId === '') {
            if ($referenceCode === '') {
                return collect();
            }

            return $query->where('reference_code', $referenceCode)->get();
        }

        return $query->where(function ($inner) use ($captureId, $paypalOrderId) {
            if ($captureId !== '') {
                $inner->orWhere('paypal_capture_id', $captureId);
            }
            if ($paypalOrderId !== '') {
                $inner->orWhere('paypal_order_id', $paypalOrderId);
            }
        })->get();
    }

    /**
     * PayPal already returned the money. Stamp refund id and cancel paid rows
     * without a second wallet credit.
     *
     * @return Collection<int, Order>
     */
    public function markPaypalCaptureRefunded(string $captureId, string $refundId, string $paypalOrderId = '', float $amount = 0.0): Collection
    {
        $captureId = trim($captureId);
        $refundId = trim($refundId);
        $paypalOrderId = trim($paypalOrderId);
        $amount = round($amount, 2);
        if ($captureId === '' && $paypalOrderId === '') {
            return collect();
        }

        return DB::transaction(function () use ($captureId, $refundId, $paypalOrderId, $amount) {
            $orders = Order::query()
                ->where('payment_method', 'paypal')
                ->where(function ($inner) use ($captureId, $paypalOrderId) {
                    if ($captureId !== '') {
                        $inner->orWhere('paypal_capture_id', $captureId);
                    }
                    if ($paypalOrderId !== '') {
                        $inner->orWhere('paypal_order_id', $paypalOrderId);
                    }
                })
                ->lockForUpdate()
                ->get();

            $paidTotal = round((float) $orders
                ->filter(fn (Order $order) => ($order->payment_status ?? '') === 'paid')
                ->sum(fn (Order $order) => (float) $order->total_amount), 2);
            $partial = $amount >= 0.01 && ($paidTotal - $amount) > 0.01;

            $storedRefund = $orders->contains(fn (Order $order) => filled($order->paypal_refund_id));
            foreach ($orders as $order) {
                $attrs = [];
                if ($refundId !== '' && ! $storedRefund && blank($order->paypal_refund_id)) {
                    $attrs['paypal_refund_id'] = $refundId;
                    $storedRefund = true;
                }
                if (! $partial
                    && $order->payment_status === 'paid'
                    && ! in_array((string) $order->status, ['cancelled', 'completed'], true)
                ) {
                    $attrs['payment_status'] = 'refunded';
                    $attrs['status'] = 'cancelled';
                }
                if ($attrs === []) {
                    continue;
                }
                $order->update($attrs);
                if (($attrs['status'] ?? null) === 'cancelled') {
                    ContentSubmission::releaseAllForOrder((int) $order->id);
                }
            }

            return $orders->map(fn (Order $order) => $order->fresh('items'))->filter();
        });
    }

    /**
     * @return Collection<int, Order>
     */
    public function findPaypalOrdersForCapture(string $captureId, string $paypalOrderId = ''): Collection
    {
        $captureId = trim($captureId);
        $paypalOrderId = trim($paypalOrderId);
        if ($captureId === '' && $paypalOrderId === '') {
            return collect();
        }

        return Order::query()
            ->with(['user', 'items'])
            ->where('payment_method', 'paypal')
            ->where(function ($inner) use ($captureId, $paypalOrderId) {
                if ($captureId !== '') {
                    $inner->orWhere('paypal_capture_id', $captureId);
                }
                if ($paypalOrderId !== '') {
                    $inner->orWhere('paypal_order_id', $paypalOrderId);
                }
            })
            ->get();
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

            try {
                $submission = ContentSubmission::query()->whereKey($id)->lockForUpdate()->first();
            } catch (\Throwable) {
                return 'missing';
            }
            if (! $submission) {
                return 'missing';
            }
            if ($submission->isClaimedByAnotherOrder((int) $order->id)) {
                return 'taken';
            }
            if (! $submission->isReadyToFulfill((int) $order->id)) {
                return 'unready';
            }
            $owner = $order->user;
            if (! $this->submissionPassesLivePolicy($submission, $owner instanceof User ? $owner : null)) {
                return 'unready';
            }
        }

        return 'ok';
    }

    protected function submissionPassesLivePolicy(ContentSubmission $submission, ?User $user): bool
    {
        return app(ContentModerationService::class)->submissionPassesLivePolicy($submission, $user);
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
     * Cancelled leftovers (replaced by a later checkout) are not owed. A
     * multi-site Stripe session that still totals the original package must
     * not match and mark the leftover sibling paid. Already-paid siblings
     * are not owed either — Pay again charges only the failed rows, and
     * counting the paid line made that capture look short and wallet-credit
     * instead of marking the leftover paid.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function expectedStripeEurosForOrders(Collection $orders, array $meta): float
    {
        $total = round((float) $orders
            ->filter(fn (Order $order) => ! in_array((string) $order->status, ['cancelled', 'completed'], true)
                && (string) $order->payment_status !== 'paid')
            ->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $bonus = round((float) ($meta['bonus_applied'] ?? 0), 2);
        $appliedCredit = round((float) ($meta['unfulfilled_credit_applied'] ?? 0), 2);

        return round(max(0, $total - $bonus - $appliedCredit), 2);
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
     * Card cash already settled on this Stripe object (paid or refunded).
     *
     * @param  Collection<int, Order>  $orders
     * @param  'paid'|'refunded'  $paymentStatus
     */
    private function settledCardEurosForStripeCapture(
        Collection $orders,
        ?object $session,
        string $paymentStatus
    ): float {
        if ($session === null) {
            return 0.0;
        }

        $ids = $this->stripeCaptureIds($session);
        if ($ids === []) {
            return 0.0;
        }

        return round((float) $orders
            ->filter(function (Order $order) use ($ids, $paymentStatus) {
                if ((string) $order->payment_status !== $paymentStatus) {
                    return false;
                }

                return in_array((string) $order->stripe_session_id, $ids, true)
                    || in_array((string) $order->stripe_payment_intent_id, $ids, true);
            })
            ->sum(fn (Order $order) => (float) $order->total_amount), 2);
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
        if ($this->stripeCaptureAlreadyRefunded($referenceCode, $session)) {
            return 0.0;
        }

        $meta = $this->sessionMetadataArray($session);
        $userId = isset($meta['user_id']) ? (int) $meta['user_id'] : 0;
        $amount = isset($meta['expected_amount']) && $meta['expected_amount'] !== ''
            ? round((float) $meta['expected_amount'], 2)
            : (float) ($this->stripeEurosFromSession($session) ?? 0);

        if ($userId <= 0 || $amount <= 0.009) {
            return 0.0;
        }

        return $this->creditUnfulfilledFromStripeObject(
            $userId,
            $referenceCode,
            $amount,
            $session
        );
    }

    /**
     * Credit a second Stripe capture after this reference already has paid orders.
     * Same-session webhook/success-URL replay is a no-op.
     */
    public function creditCapturedCardWhenAlreadySettled(string $referenceCode, object $session): float
    {
        if ($this->stripeCaptureAlreadyRefunded($referenceCode, $session)) {
            return 0.0;
        }

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

        return $this->creditUnfulfilledFromStripeObject(
            $userId,
            $referenceCode,
            $amount,
            $session
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
     * Rematerialize already refuses to create orders when the discounted
     * Stripe capture's promo cannot be held again. Leftover mark-paid used
     * to settle anyway, so the advertiser kept (or re-spent) the bonus and
     * still received the placement at the card-only price.
     *
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $meta
     */
    private function ensureBonusForCardMarkPaid(
        object $session,
        Collection $orders,
        array $meta,
        string $referenceCode
    ): bool {
        $bonusNeeded = round((float) ($meta['bonus_applied'] ?? 0), 2);
        if ($bonusNeeded <= 0.009) {
            return true;
        }

        $userId = (int) ($orders->first()?->user_id ?? ($meta['user_id'] ?? 0));
        if ($userId <= 0) {
            return false;
        }

        $intents = app(CheckoutIntentService::class);
        $held = $intents->heldBonus($userId, $referenceCode);
        if ($held + 0.009 < $bonusNeeded) {
            $this->rereserveReleasedCheckoutBonus($userId, $referenceCode, $bonusNeeded);
            $held = $intents->heldBonus($userId, $referenceCode);
        }

        $roleId = Wallet::advertiserRoleId();
        $wallet = $roleId
            ? Wallet::query()->where('user_id', $userId)->where('role_id', $roleId)->first()
            : null;
        $reserved = $wallet ? round((float) $wallet->bonus_reserved, 2) : 0.0;

        if ($held + 0.009 >= $bonusNeeded && $reserved + 0.009 >= $bonusNeeded) {
            return true;
        }

        $cap = app(OrderRefundService::class)->cardLeftoverBonusCap($userId, $referenceCode);
        if ($reserved + 0.009 >= $bonusNeeded && ($cap === null || $cap + 0.009 >= $bonusNeeded)) {
            $intents->rememberBonus($userId, $referenceCode, $bonusNeeded);

            return true;
        }

        $amount = $this->stripeEurosFromSession($session)
            ?? $this->expectedStripeEurosForOrders($orders, $meta);
        if ($amount > 0.009) {
            $this->creditUnfulfilledFromStripeObject(
                $userId,
                $referenceCode,
                $amount,
                $session
            );
        }
        Log::warning('Stripe leftover mark-paid skipped; checkout bonus could not be re-reserved', [
            'reference_code' => $referenceCode,
            'session_id' => $session->id ?? null,
            'user_id' => $userId,
            'bonus_needed' => $bonusNeeded,
            'bonus_held' => $held,
            'bonus_reserved' => $reserved,
            'wallet_credit' => $amount,
        ]);

        return false;
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
        if ($userId > 0 && $stripeEuros > 0.009) {
            $this->creditUnfulfilledFromStripeObject(
                $userId,
                $referenceCode,
                $stripeEuros,
                $session
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
