<?php

namespace App\Services;

use App\Mail\SiteDiscountEnded;
use App\Models\Site;
use App\Models\SiteFeaturePurchase;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SitePromotionService
{
    public function featurePrice(): float
    {
        return round((float) config('site_promotions.feature.price', 10), 2);
    }

    public function featureDays(): int
    {
        return max(1, (int) config('site_promotions.feature.days', 7));
    }

    /**
     * Purchase featured placement using publisher wallet balance.
     *
     * @return array{success:bool, message:string, site?:Site, needs_top_up?:bool, balance?:float, price?:float}
     */
    public function featureWithWallet(Site $site, User $publisher): array
    {
        $price = $this->featurePrice();
        $days = $this->featureDays();
        $roleId = Wallet::publisherRoleId();

        if (! $roleId) {
            return ['success' => false, 'message' => 'Publisher wallet is not available.'];
        }

        try {
            return DB::transaction(function () use ($site, $publisher, $price, $days, $roleId) {
                $wallet = Wallet::lockOrCreateForRole($publisher->id, $roleId);

                if (round((float) $wallet->balance, 2) < $price) {
                    return [
                        'success' => false,
                        'needs_top_up' => true,
                        'balance' => (float) $wallet->balance,
                        'price' => $price,
                        'message' => 'Insufficient publisher balance. Top up €'
                            .number_format($price - (float) $wallet->balance, 2)
                            .' or more, then try again.',
                    ];
                }

                $wallet->debit($price);

                $site = $this->applyFeaturePeriod($site, $publisher, $price, $days, 'wallet');

                return [
                    'success' => true,
                    'message' => 'Site featured for '.$days.' days (€'.number_format($price, 2).').',
                    'site' => $site,
                ];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Apply featured placement after a successful Stripe card payment (no wallet debit).
     */
    public function featureFromStripePayment(Site $site, User $publisher, ?string $stripeSessionId = null): array
    {
        $price = $this->featurePrice();
        $days = $this->featureDays();

        try {
            return DB::transaction(function () use ($site, $publisher, $price, $days, $stripeSessionId) {
                if ($stripeSessionId) {
                    $already = SiteFeaturePurchase::query()
                        ->where('payment_method', 'stripe')
                        ->where('stripe_session_id', $stripeSessionId)
                        ->exists();
                    if ($already) {
                        return [
                            'success' => true,
                            'message' => 'Feature already applied for this payment.',
                            'site' => $site->fresh(),
                        ];
                    }
                }

                $locked = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                $featured = $this->applyFeaturePeriod($locked, $publisher, $price, $days, 'stripe', $stripeSessionId);

                return [
                    'success' => true,
                    'message' => 'Site featured for '.$days.' days (€'.number_format($price, 2).') via card.',
                    'site' => $featured,
                ];
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function applyFeaturePeriod(
        Site $site,
        User $publisher,
        float $price,
        int $days,
        string $paymentMethod,
        ?string $stripeSessionId = null
    ): Site {
        $starts = now();
        $base = $site->featured_until && $site->featured_until->isFuture()
            ? $site->featured_until->copy()
            : $starts->copy();
        $ends = $base->copy()->addDays($days);

        $site->forceFill([
            'featured_until' => $ends,
            'featured_purchased_at' => $starts,
        ])->save();

        SiteFeaturePurchase::create([
            'site_id' => $site->id,
            'user_id' => $publisher->id,
            'amount' => $price,
            'days' => $days,
            'payment_method' => $paymentMethod,
            'stripe_session_id' => $stripeSessionId,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ]);

        return $site->fresh();
    }

    public function joinBulkDiscount(Site $site, float $percent): Site
    {
        $min = (float) config('site_promotions.bulk.min_percent', 10);
        $max = (float) config('site_promotions.bulk.max_percent', 15);
        $percent = max($min, min($max, round($percent, 2)));

        $site->forceFill([
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => $percent,
        ])->save();

        return $site->fresh();
    }

    public function leaveBulkDiscount(Site $site): Site
    {
        $site->forceFill([
            'bulk_discount_enabled' => false,
            'bulk_discount_percent' => null,
        ])->save();

        return $site->fresh();
    }

    public function setCustomDiscount(Site $site, float $percent, int $days): Site
    {
        $min = (float) config('site_promotions.custom_discount.min_percent', 1);
        $max = (float) config('site_promotions.custom_discount.max_percent', 70);
        $maxDays = (int) config('site_promotions.custom_discount.max_days', 90);
        $percent = max($min, min($max, round($percent, 2)));
        $days = max(1, min($maxDays, $days));

        $starts = now();
        $site->forceFill([
            'custom_discount_percent' => $percent,
            'custom_discount_starts_at' => $starts,
            'custom_discount_ends_at' => $starts->copy()->addDays($days),
            'custom_discount_notified_at' => null,
        ])->save();

        return $site->fresh();
    }

    public function clearCustomDiscount(Site $site): Site
    {
        $site->forceFill([
            'custom_discount_percent' => null,
            'custom_discount_starts_at' => null,
            'custom_discount_ends_at' => null,
            'custom_discount_notified_at' => null,
        ])->save();

        return $site->fresh();
    }

    /**
     * Notify publishers whose custom discounts just ended.
     */
    public function notifyExpiredCustomDiscounts(int $limit = 100): int
    {
        $sites = Site::query()
            ->with('publisher')
            ->whereNotNull('custom_discount_ends_at')
            ->where('custom_discount_ends_at', '<=', now())
            ->whereNull('custom_discount_notified_at')
            ->whereNotNull('custom_discount_percent')
            ->limit($limit)
            ->get();

        $sent = 0;
        foreach ($sites as $site) {
            $publisher = $site->publisher;
            $percent = (float) $site->custom_discount_percent;
            $endedAt = $site->custom_discount_ends_at;

            if ($publisher?->email) {
                try {
                    Mail::to($publisher->email)->send(new SiteDiscountEnded($site, $publisher, $percent, $endedAt));
                    $sent++;
                } catch (\Throwable) {
                    // still mark notified to avoid retry storms
                }
            }

            $site->forceFill([
                'custom_discount_notified_at' => now(),
                'custom_discount_percent' => null,
                'custom_discount_starts_at' => null,
            ])->save();
        }

        return $sent;
    }
}
