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
use Illuminate\Support\Carbon;
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
     */
    public function assertStripeChargeMatchesFeaturePrice(object $session): void
    {
        $expected = $this->featurePrice();
        $stripeCents = null;
        if (isset($session->amount_total)) {
            $stripeCents = (int) $session->amount_total;
        } elseif (isset($session->amount_received) || isset($session->amount)) {
            $stripeCents = (int) ($session->amount_received ?: $session->amount);
        }

        if ($stripeCents === null) {
            throw new \RuntimeException('Stripe site-feature session is missing the charged amount.');
        }

        $charged = StripePaymentService::fromCents($stripeCents);
        if (abs($charged - $expected) > 0.01) {
            throw new \RuntimeException(
                'Stripe charged €'.number_format($charged, 2)
                .' but featured placement costs €'.number_format($expected, 2).'.'
            );
        }
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
                // Lock the listing before debiting. Returning false from this
                // closure commits the transaction — a post-debit visibility
                // check used to keep the charge and skip the feature.
                $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                if ($lockedSite->isArchived()) {
                    return [
                        'success' => false,
                        'message' => 'Archived sites cannot be promoted. Restore the site first.',
                    ];
                }
                if ($lockedSite->isFromCancelledBulk()) {
                    return [
                        'success' => false,
                        'message' => 'This listing is not in the catalog and cannot be promoted.',
                    ];
                }
                if (! $lockedSite->active && ! $lockedSite->verified) {
                    return [
                        'success' => false,
                        'message' => 'Only verified or active sites can use promotions.',
                    ];
                }

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
        ?string $reason = null
    ): array {
        $price = $this->featurePrice();
        $roleId = Wallet::publisherRoleId();
        if (! $roleId) {
            return ['success' => false, 'message' => 'Publisher wallet is not available.'];
        }
        if ($stripeSessionId === '') {
            return ['success' => false, 'message' => 'Missing Stripe session.'];
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

                $this->logStripeFeatureCredited(
                    $site,
                    $payer,
                    $price,
                    $stripeSessionId,
                    $reason ?: 'the website changed owner'
                );

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
                if ($locked->isFromCancelledBulk()) {
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
                        return $this->alreadyAppliedStripeFeature($locked, $already);
                    }
                }

                $featured = $this->applyFeaturePeriod($locked, $publisher, $price, $days, 'stripe', $stripeSessionId);
                $this->logStripeFeatureApplied($featured, $publisher, $price, $days, $stripeSessionId);

                return [
                    'success' => true,
                    'already' => false,
                    'credited' => false,
                    'message' => 'Site featured for '.$days.' days (€'.number_format($price, 2).') via card.',
                    'site' => $featured,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyAppliedStripeFeature($site, $stripeSessionId);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return $this->alreadyAppliedStripeFeature($site, $stripeSessionId);
            }

            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success:bool, already:bool, credited:bool, message:string, site:Site}
     */
    private function alreadyAppliedStripeFeature(Site $site, SiteFeaturePurchase|string|null $existing = null): array
    {
        $purchase = $existing instanceof SiteFeaturePurchase
            ? $existing
            : (is_string($existing) && $existing !== ''
                ? SiteFeaturePurchase::query()->where('stripe_session_id', $existing)->first()
                : null);
        $credited = $purchase?->payment_method === 'stripe_credit';

        return [
            'success' => true,
            'already' => true,
            'credited' => $credited,
            'message' => $credited
                ? 'This payment was already credited to your wallet.'
                : 'Feature already applied for this payment.',
            'site' => $site->fresh(),
        ];
    }

    private function logStripeFeatureApplied(
        Site $site,
        User $publisher,
        float $price,
        int $days,
        ?string $stripeSessionId
    ): void {
        ActivityLogger::tryLog(
            'site.featured_stripe',
            ($publisher->name ?: $publisher->email).' featured "'.$site->site_name.'" via Stripe',
            $site,
            [
                'session_id' => $stripeSessionId,
                'days' => $days,
                'amount' => $price,
                'payment_method' => 'stripe',
            ],
            $site->site_name,
            $publisher
        );
    }

    private function logStripeFeatureCredited(
        Site $site,
        User $payer,
        float $price,
        string $stripeSessionId,
        string $reason
    ): void {
        ActivityLogger::tryLog(
            'site.feature_stripe_credited',
            ($payer->name ?: $payer->email).' was credited €'.number_format($price, 2)
                .' for a Stripe feature payment that could not be applied',
            $site,
            [
                'session_id' => $stripeSessionId,
                'amount' => $price,
                'reason' => $reason,
                'payer_id' => $payer->id,
                'payment_method' => 'stripe_credit',
            ],
            $site->site_name,
            $payer
        );
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
        $currentUntil = $site->safeFeaturedUntil();
        $base = $currentUntil && $currentUntil->isFuture()
            ? Carbon::instance($currentUntil)
            : $starts->copy();
        $ends = $base->copy()->addDays($days);

        // Query-builder write: Eloquent save() casts leftover featured_until
        // when diffing originals and 422s the wallet feature purchase.
        Site::query()->whereKey($site->id)->update([
            'featured_until' => $ends,
            'featured_purchased_at' => $starts,
        ]);

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

        Site::query()->whereKey($site->id)->update([
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => $percent,
        ]);

        return $site->fresh();
    }

    public function leaveBulkDiscount(Site $site): Site
    {
        Site::query()->whereKey($site->id)->update([
            'bulk_discount_enabled' => false,
            'bulk_discount_percent' => null,
        ]);

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
        Site::query()->whereKey($site->id)->update([
            'custom_discount_percent' => $percent,
            'custom_discount_starts_at' => $starts,
            'custom_discount_ends_at' => $starts->copy()->addDays($days),
            'custom_discount_notified_at' => null,
        ]);

        return $site->fresh();
    }

    public function clearCustomDiscount(Site $site): Site
    {
        Site::query()->whereKey($site->id)->update([
            'custom_discount_percent' => null,
            'custom_discount_starts_at' => null,
            'custom_discount_ends_at' => null,
            'custom_discount_notified_at' => null,
        ]);

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
            ->where('custom_discount_ends_at', '>=', Site::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('custom_discount_ends_at', '<=', Site::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->where(function ($query) {
                // Leftover Hostinger strings are not null, so a bare whereNull
                // would leave the expired sale percent on the listing forever.
                $query->whereNull('custom_discount_notified_at')
                    ->orWhere('custom_discount_notified_at', '>', Site::PLAUSIBLE_SQL_DATETIME_CEIL)
                    ->orWhere('custom_discount_notified_at', '<', Site::PLAUSIBLE_SQL_DATETIME_FLOOR);
            })
            ->whereNotNull('custom_discount_percent')
            ->limit($limit)
            ->get();

        $sent = 0;
        foreach ($sites as $site) {
            $publisher = $site->publisher;
            $percent = (float) $site->custom_discount_percent;
            $endedAt = $site->safeCustomDiscountEndsAt();
            $endedAt = $endedAt instanceof \DateTimeInterface
                ? Carbon::instance($endedAt)
                : null;

            if ($publisher?->email && $endedAt) {
                try {
                    Mail::to($publisher->email)->send(new SiteDiscountEnded($site, $publisher, $percent, $endedAt));
                    $sent++;
                } catch (\Throwable) {
                    // still mark notified to avoid retry storms
                }
            }

            Site::query()->whereKey($site->id)->update([
                'custom_discount_notified_at' => now(),
                'custom_discount_percent' => null,
                'custom_discount_starts_at' => null,
            ]);
        }

        return $sent;
    }
}
