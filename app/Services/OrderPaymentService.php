<?php

namespace App\Services;

use App\Mail\SiteOwnerOrderNotification;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
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
        return DB::transaction(function () use ($referenceCode, $session) {
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
            if (isset($meta['expected_amount'])) {
                $this->assertStripeAmountMatchesExpected(
                    $session,
                    round((float) $meta['expected_amount'], 2),
                    $referenceCode
                );
            } elseif (! isset($meta['bonus_applied']) || (float) ($meta['bonus_applied'] ?? 0) <= 0) {
                $expected = round((float) $orders->sum(fn (Order $o) => (float) $o->total_amount), 2);
                $this->assertStripeAmountMatchesExpected($session, $expected, $referenceCode);
            }

            $newlyPaid = collect();

            foreach ($orders as $order) {
                if ($order->payment_status === 'paid') {
                    continue;
                }

                // Allow pending (first attempt) and failed (Pay again / recovered session).
                if (! in_array($order->payment_status, ['pending', 'failed'], true)) {
                    Log::warning('Skipping order with unexpected payment status', [
                        'order_id' => $order->id,
                        'payment_status' => $order->payment_status,
                    ]);

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

            if ($newlyPaid->isNotEmpty()) {
                $this->consumeBonusAppliedFromStripeSession($newlyPaid->first(), $session);
            }

            return $newlyPaid;
        });
    }

    /**
     * Mark pending/failed card orders paid from a confirmed PaymentIntent (saved card).
     *
     * @return Collection<int, Order>
     */
    public function markOrdersPaidFromPaymentIntent(string $referenceCode, object $intent): Collection
    {
        return DB::transaction(function () use ($referenceCode, $intent) {
            $orders = Order::with('items')
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return collect();
            }

            $newlyPaid = collect();
            foreach ($orders as $order) {
                if ($order->payment_status === 'paid') {
                    continue;
                }
                if (! in_array($order->payment_status, ['pending', 'failed'], true)) {
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

            if ($newlyPaid->isNotEmpty()) {
                $meta = [];
                if (isset($intent->metadata)) {
                    $meta = is_array($intent->metadata)
                        ? $intent->metadata
                        : (method_exists($intent->metadata, 'toArray') ? $intent->metadata->toArray() : (array) $intent->metadata);
                }
                $bonus = round((float) ($meta['bonus_applied'] ?? 0), 2);
                if ($bonus <= 0) {
                    $bonus = round((float) Cache::get('checkout_bonus:'.$newlyPaid->first()->user_id.':'.$referenceCode, 0), 2);
                }
                if ($bonus > 0) {
                    $this->consumeBonusAmount($newlyPaid->first(), $bonus);
                }
            }

            return $newlyPaid;
        });
    }

    protected function consumeBonusAmount(Order $order, float $bonus): void
    {
        $cacheKey = 'checkout_bonus:'.$order->user_id.':'.$order->reference_code;
        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            Cache::forget($cacheKey);

            return;
        }

        $wallet = Wallet::where('user_id', $order->user_id)->where('role_id', $roleId)->lockForUpdate()->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->consumeReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
        Cache::forget($cacheKey);
    }

    /**
     * When a card checkout applied promotional credit, permanently consume the reserved bonus.
     */
    protected function consumeBonusAppliedFromStripeSession(Order $order, object $session): void
    {
        $meta = [];
        if (isset($session->metadata)) {
            $meta = is_array($session->metadata)
                ? $session->metadata
                : (method_exists($session->metadata, 'toArray') ? $session->metadata->toArray() : (array) $session->metadata);
        }

        $bonus = round((float) ($meta['bonus_applied'] ?? 0), 2);
        $cacheKey = 'checkout_bonus:'.$order->user_id.':'.$order->reference_code;
        if ($bonus <= 0) {
            $bonus = round((float) Cache::get($cacheKey, 0), 2);
        }
        if ($bonus <= 0) {
            return;
        }

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            return;
        }

        $wallet = Wallet::where('user_id', $order->user_id)->where('role_id', $roleId)->lockForUpdate()->first();
        if ($wallet && (float) $wallet->bonus_reserved > 0) {
            $wallet->consumeReserved(min($bonus, (float) $wallet->bonus_reserved));
        }
        Cache::forget($cacheKey);
    }

    /**
     * Mark pending card orders as payment_status=failed (session expired / declined).
     * Refunds any reserved checkout bonus for the reference. Leaves order rows intact for Pay again.
     *
     * @return Collection<int, Order>
     */
    public function markOrdersFailedFromReference(string $referenceCode, ?string $reason = null): Collection
    {
        $failed = DB::transaction(function () use ($referenceCode, $reason) {
            $orders = Order::query()
                ->where('reference_code', $referenceCode)
                ->where('payment_method', 'card')
                ->where('payment_status', 'pending')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return collect();
            }

            $marked = collect();
            foreach ($orders as $order) {
                $order->update([
                    'payment_status' => 'failed',
                ]);
                $marked->push($order->fresh());
            }

            $userId = (int) $marked->first()->user_id;
            $this->refundBonusReservedForReference($userId, $referenceCode);

            Log::info('Marked card orders payment_status=failed', [
                'reference_code' => $referenceCode,
                'order_count' => $marked->count(),
                'reason' => $reason,
            ]);

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
    public function refundBonusReservedForReference(int $userId, string $referenceCode): void
    {
        $cacheKey = 'checkout_bonus:'.$userId.':'.$referenceCode;
        $bonus = round((float) Cache::pull($cacheKey, 0), 2);
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
        return 'pending_card_checkout:'.$referenceCode;
    }

    /**
     * Store a serializable checkout package until Stripe payment succeeds.
     *
     * @param  array<string, mixed>  $package
     */
    public function storePendingCheckout(string $referenceCode, array $package): void
    {
        Cache::put(self::pendingCheckoutCacheKey($referenceCode), $package, now()->addHours(6));
    }

    public function forgetPendingCheckout(string $referenceCode): void
    {
        Cache::forget(self::pendingCheckoutCacheKey($referenceCode));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPendingCheckout(string $referenceCode): ?array
    {
        $package = Cache::get(self::pendingCheckoutCacheKey($referenceCode));

        return is_array($package) ? $package : null;
    }

    /**
     * Create paid card orders from a Stripe-first pending package (like Add Funds deposit create-after-pay).
     * If orders already exist for the reference, mark them paid instead.
     *
     * @return Collection<int, Order>
     */
    public function finalizeStripeFirstCheckout(string $referenceCode, object $session): Collection
    {
        $existing = Order::query()
            ->where('reference_code', $referenceCode)
            ->where('payment_method', 'card')
            ->count();

        if ($existing > 0) {
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

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $orderNumber = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                $order = Order::create($schema->filterExistingColumns('orders', [
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
                    'reference_code' => $referenceCode,
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
                ]));

                $submissionId = (int) ($line['content_submission_id'] ?? 0);
                $submission = $submissionId > 0 ? ContentSubmission::query()->find($submissionId) : null;

                $siteId = isset($line['site_id']) ? (int) $line['site_id'] : 0;
                $site = $siteId > 0 ? Site::query()->find($siteId) : null;

                $itemPayload = [
                    'order_id' => $order->id,
                    'site_id' => $siteId ?: null,
                    'site_name' => $line['site_name'] ?? $site?->site_name,
                    'site_url' => $line['site_url'] ?? $site?->site_url,
                    'price' => $line['price'] ?? 0,
                    'content_link' => $line['content_link'] ?? null,
                    'content_submission_id' => $submission?->id,
                    'content_disk' => $submission?->disk ?? ($line['content_disk'] ?? null),
                    'content_path' => $submission?->path ?? ($line['content_path'] ?? null),
                    'content_original_name' => $submission?->original_filename ?? ($line['content_original_name'] ?? null),
                    'content_mime' => $submission?->mime ?? ($line['content_mime'] ?? null),
                    'anchor_text' => $submission?->anchor_text ?? ($line['anchor_text'] ?? null),
                    'target_url' => $submission?->target_url ?? ($line['target_url'] ?? null),
                    'feature_image_url' => $submission?->feature_image_url ?? ($line['feature_image_url'] ?? null),
                    'moderation_status' => $submission?->moderation_status ?? ($line['moderation_status'] ?? null),
                    'sensitive_type' => $line['sensitive_type'] ?? null,
                    'additional_price' => $line['additional_price'] ?? 0,
                    'publisher_price' => $line['publisher_price'] ?? null,
                    'platform_fee_percent' => $line['platform_fee_percent'] ?? null,
                    'platform_fee_amount' => $line['platform_fee_amount'] ?? null,
                ];

                $item = OrderItem::create($schema->filterExistingColumns('order_items', $itemPayload));

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

            $bonus = round((float) ($package['bonus_applied'] ?? 0), 2);
            if ($bonus > 0 && $orders->isNotEmpty()) {
                $this->consumeBonusAmount($orders->first(), $bonus);
            }

            return $orders;
        });

        $this->forgetPendingCheckout($referenceCode);

        Log::info('Materialized Stripe-first card orders after payment', [
            'reference_code' => $referenceCode,
            'order_count' => $created->count(),
            'session_id' => $session->id ?? null,
        ]);

        return $created;
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
            Log::warning('Stripe session missing amount fields; skipping amount check', [
                'reference_code' => $referenceCode,
                'session_id' => $session->id ?? null,
            ]);

            return;
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
