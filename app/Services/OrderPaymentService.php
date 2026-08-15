<?php

namespace App\Services;

use App\Mail\SiteOwnerOrderNotification;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\Orders\OrderRefundService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
            $orders = Order::with('items')
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return collect();
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
                $this->refundBonusReservedForReference($resolvedUserId, $referenceCode, $fallback);
            }
            $this->forgetPendingCheckout($referenceCode);

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
    public function refundBonusReservedForReference(int $userId, string $referenceCode, ?float $fallbackBonus = null): void
    {
        $bonus = app(CheckoutIntentService::class)->takeBonus($userId, $referenceCode, $fallbackBonus);
        if ($bonus <= 0) {
            return;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return;
        }

        $wallet = Wallet::where('user_id', $userId)->where('role_id', $roleId)->lockForUpdate()->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->refundReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
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

        if ($existingCount > 0) {
            return $this->markOrdersPaidFromStripeSession($referenceCode, $session);
        }

        $package = $this->getPendingCheckout($referenceCode);
        if ($package === null) {
            Log::warning('No pending card checkout package to materialize', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
            ]);

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
        if (isset($meta['expected_amount'])) {
            $expected = round((float) $meta['expected_amount'], 2);
        }
        $this->assertStripeAmountMatchesExpected($session, $expected, $referenceCode);

        $schema = app(CheckoutSchemaService::class);
        $schema->ensureCheckoutTables();

        $created = DB::transaction(function () use ($package, $referenceCode, $session, $schema) {
            $already = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->lockForUpdate()
                ->get();

            if ($already->isNotEmpty()) {
                return $this->markOrdersPaidFromStripeSession($referenceCode, $session);
            }

            $userId = (int) ($package['user_id'] ?? 0);
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
                $lineKey = $this->checkoutLineKey($referenceCode, $siteId, (int) $index);

                $submissionId = (int) ($line['content_submission_id'] ?? 0);
                $submission = $submissionId > 0
                    ? ContentSubmission::query()->whereKey($submissionId)->lockForUpdate()->first()
                    : null;
                $articleTaken = $submission && $submission->isClaimedByAnotherOrder();

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

                $siteId = isset($line['site_id']) ? (int) $line['site_id'] : 0;
                $site = $siteId > 0 ? Site::query()->find($siteId) : null;
                $attachSubmission = $submission && ! $articleTaken;

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
            $existing = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('status', '!=', 'cancelled')
                ->get();
            if ($existing->isNotEmpty()) {
                return $this->markOrdersPaidFromStripeSession($referenceCode, $session);
            }
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

        $total = round((float) $orders->sum(fn (Order $order) => (float) $order->total_amount), 2);
        $bonus = round((float) ($meta['bonus_applied'] ?? 0), 2);

        return round(max(0, $total - $bonus), 2);
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
