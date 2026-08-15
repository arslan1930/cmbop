<?php

namespace App\Services;

use App\Mail\SiteDiscountEnded;
use App\Models\Site;
use App\Models\SiteFeaturePurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Refuse to apply a featured placement when Stripe charged a different amount.
     * Prefer the price quoted on the Checkout Session so a later config change
     * cannot leave a paid session unfulfilled (webhook 500 until Stripe gives up).
     */
    public function assertStripeChargeMatchesFeaturePrice(object $session): void
    {
        $expected = $this->quotedFeaturePrice($session) ?? $this->featurePrice();
        $charged = $this->stripeChargedEuros($session);
        if ($charged <= 0) {
            throw new \RuntimeException('Stripe site-feature session is missing the charged amount.');
        }

        if (abs($charged - $expected) > 0.01) {
            throw new \RuntimeException(
                'Stripe charged €'.number_format($charged, 2)
                .' but featured placement costs €'.number_format($expected, 2).'.'
            );
        }
    }

    /**
     * Amount Stripe actually captured for this feature Checkout / PaymentIntent.
     */
    public function stripeChargedEuros(object $session): float
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
     * Price the publisher was quoted when the Checkout Session was created.
     */
    private function quotedFeaturePrice(object $session): ?float
    {
        $metadata = $session->metadata ?? null;
        if ($metadata === null) {
            return null;
        }

        $meta = is_array($metadata)
            ? $metadata
            : (array) json_decode(json_encode($metadata), true);
        if (! isset($meta['price']) || $meta['price'] === '' || $meta['price'] === null) {
            return null;
        }

        $quoted = round((float) $meta['price'], 2);

        return $quoted > 0 ? $quoted : null;
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
                $withdrawable = $wallet->withdrawableBalance();

                if ($withdrawable < $price) {
                    return [
                        'success' => false,
                        'needs_top_up' => true,
                        'balance' => $withdrawable,
                        'price' => $price,
                        'message' => 'Insufficient publisher balance. Top up €'
                            .number_format($price - $withdrawable, 2)
                            .' or more, then try again.',
                    ];
                }

                $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                if (! $lockedSite->isCatalogVisible()) {
                    return [
                        'success' => false,
                        'message' => 'This listing is not in the catalog and cannot be promoted.',
                    ];
                }

                $wallet->deductWithdrawable($price);
                $site = $this->applyFeaturePeriod($lockedSite, $publisher, $price, $days, 'wallet');

                // Promo feature spends are intentionally excluded from INV tax
                // invoicing (see config billing.promo_feature.issue_invoice).
                try {
                    app(WalletLedgerService::class)->recordPurchase(
                        $wallet,
                        $price,
                        0,
                        $site,
                        'PROMO-FEATURE-'.$site->id.'-'.now()->format('YmdHis')
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to record promo feature ledger debit', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }

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
     * When a paid feature session can no longer be applied (site changed owner),
     * credit the payer's publisher wallet once and record the session so retries
     * do not feature the new owner's listing or double-credit.
     *
     * @return array{success:bool, credited?:bool, already?:bool, message:string}
     */
    public function creditPayerWhenFeatureCannotApply(
        Site $site,
        User $payer,
        string $stripeSessionId,
        ?string $reason = null,
        ?float $amount = null
    ): array {
        $price = $amount !== null ? round($amount, 2) : $this->featurePrice();
        $roleId = Wallet::publisherRoleId();
        if (! $roleId) {
            return ['success' => false, 'message' => 'Publisher wallet is not available.'];
        }
        if ($stripeSessionId === '') {
            return ['success' => false, 'message' => 'Missing Stripe session.'];
        }
        if ($price <= 0) {
            return ['success' => false, 'message' => 'Invalid feature credit amount.'];
        }

        try {
            return DB::transaction(function () use ($site, $payer, $stripeSessionId, $price, $roleId, $reason) {
                $already = SiteFeaturePurchase::query()
                    ->where('payment_method', 'stripe')
                    ->where('stripe_session_id', $stripeSessionId)
                    ->lockForUpdate()
                    ->first();
                if ($already) {
                    return [
                        'success' => true,
                        'credited' => false,
                        'already' => true,
                        'message' => 'Feature already applied for this payment.',
                    ];
                }

                $credited = SiteFeaturePurchase::query()
                    ->where('payment_method', 'stripe_credit')
                    ->where('stripe_session_id', $stripeSessionId)
                    ->lockForUpdate()
                    ->first();
                if ($credited) {
                    return [
                        'success' => true,
                        'credited' => true,
                        'already' => true,
                        'message' => 'This payment was already credited to your wallet.',
                    ];
                }

                $wallet = Wallet::lockOrCreateForRole($payer->id, $roleId);
                $wallet->credit($price);
                app(WalletLedgerService::class)->recordRefund(
                    $wallet,
                    $price,
                    0,
                    $site,
                    'PROMO-FEATURE-CREDIT-'.$stripeSessionId
                );

                SiteFeaturePurchase::create([
                    'site_id' => $site->id,
                    'user_id' => $payer->id,
                    'amount' => $price,
                    'days' => 0,
                    'payment_method' => 'stripe_credit',
                    'stripe_session_id' => $stripeSessionId,
                    'starts_at' => now(),
                    'ends_at' => now(),
                ]);

                return [
                    'success' => true,
                    'credited' => true,
                    'already' => false,
                    'message' => 'Featured placement could not be applied because '
                        .($reason ?: 'the website changed owner')
                        .'. €'.number_format($price, 2)
                        .' was credited to your publisher wallet.',
                ];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyCreditedOrAppliedFeature($stripeSessionId);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->alreadyCreditedOrAppliedFeature($stripeSessionId);
            }

            return ['success' => false, 'message' => $e->getMessage()];
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
                // Lock the site first so webhook + success URL cannot both
                // pass an unlocked exists() check and stack two 7-day periods.
                $locked = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isCatalogVisible()) {
                    if (is_string($stripeSessionId) && $stripeSessionId !== '') {
                        return $this->creditPayerWhenFeatureCannotApply(
                            $locked,
                            $publisher,
                            $stripeSessionId,
                            'the listing is no longer in the catalog'
                        );
                    }

                    return [
                        'success' => false,
                        'message' => 'This listing is not in the catalog and cannot be promoted.',
                    ];
                }

                if ($stripeSessionId) {
                    $already = SiteFeaturePurchase::query()
                        ->where('stripe_session_id', $stripeSessionId)
                        ->first();
                    if ($already) {
                        return [
                            'success' => true,
                            'message' => $already->payment_method === 'stripe_credit'
                                ? 'This payment was already credited to your wallet.'
                                : 'Feature already applied for this payment.',
                            'site' => $locked->fresh(),
                        ];
                    }
                }

                $featured = $this->applyFeaturePeriod($locked, $publisher, $price, $days, 'stripe', $stripeSessionId);

                return [
                    'success' => true,
                    'message' => 'Site featured for '.$days.' days (€'.number_format($price, 2).') via card.',
                    'site' => $featured,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyAppliedStripeFeature($site);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->alreadyAppliedStripeFeature($site);
            }

            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success:bool, message:string, site:Site}
     */
    private function alreadyAppliedStripeFeature(Site $site): array
    {
        return [
            'success' => true,
            'message' => 'Feature already applied for this payment.',
            'site' => $site->fresh(),
        ];
    }

    /**
     * @return array{success:bool, credited:bool, already:bool, message:string}
     */
    private function alreadyCreditedOrAppliedFeature(string $stripeSessionId): array
    {
        $existing = SiteFeaturePurchase::query()
            ->where('stripe_session_id', $stripeSessionId)
            ->first();

        $credited = $existing?->payment_method === 'stripe_credit';

        return [
            'success' => true,
            'credited' => $credited,
            'already' => true,
            'message' => $credited
                ? 'This payment was already credited to your wallet.'
                : 'Feature already applied for this payment.',
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();

        return $sqlState === '23000'
            || str_contains($message, 'UNIQUE')
            || str_contains($message, 'unique');
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
        $max = (float) config('site_promotions.bulk.max_percent', 80);
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
