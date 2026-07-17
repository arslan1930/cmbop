<?php

namespace App\Services;

use App\Mail\SiteOwnerOrderNotification;
use App\Models\Order;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
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

            $newlyPaid = collect();

            foreach ($orders as $order) {
                if ($order->payment_status === 'paid') {
                    continue;
                }

                if ($order->payment_status !== 'pending') {
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
        $cacheKey = 'checkout_bonus:' . $order->user_id . ':' . $order->reference_code;
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

            foreach ($orders as $order) {
                try {
                    app(InAppNotificationService::class)->notifyOrderCreated(
                        $order instanceof Order ? $order->fresh(['items']) : $order
                    );
                } catch (\Throwable $e) {
                    Log::warning('notifyOrderCreated failed after card payment', [
                        'order_id' => $order->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $siteOrders = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $siteId = $item->site_id;
                    if (!isset($siteOrders[$siteId])) {
                        $site = Site::find($siteId);
                        if (!$site) {
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

                if (!$publisher || !$publisher->email) {
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
            Log::error('notifyPublishersOfPaidOrders failed: ' . $e->getMessage());
        }
    }
}
