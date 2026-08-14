<?php

namespace App\Services\Reminders;

use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\UserBlacklist;
use App\Models\UserFavorite;
use App\Services\CartPricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Picks the sites worth showing one advertiser.
 *
 * Ranking, in the order the product asked for: discounted and new to this
 * person first, then discounted, then recent listings by quality. Sites they
 * have blacklisted, already bought from, or already saved are excluded — the
 * first would be insulting, the other two are not news.
 */
class NewSitesSelector
{
    /**
     * @return Collection<int, Site>
     */
    public function forUser(User $user): Collection
    {
        $max = max(1, (int) config('reminders.new_sites_digest.max_sites', 6));
        $newWithin = now()->subDays(max(1, (int) config('reminders.new_sites_digest.new_within_days', 45)));

        $seen = $this->sitesTheyKnow($user);

        $candidates = Site::query()
            ->catalogVisible()
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->when($seen->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $seen->all()))
            ->where(function ($q) use ($newWithin) {
                // New organic picks must clear the quality bar; a live discount
                // is still news even when metrics are below the gate.
                $q->where(function ($inner) use ($newWithin) {
                    $inner->where('created_at', '>=', $newWithin)
                        ->withGoodMetrics();
                })->orWhere(fn ($inner) => $inner->onDiscount());
            })
            // Pull a wider set than needed so the ranking below has room.
            ->orderByDesc('created_at')
            ->limit($max * 6)
            ->get();

        return $candidates
            ->sortByDesc(fn (Site $site) => $this->score($site, $newWithin))
            ->take($max)
            ->values();
    }

    public function minimumMet(Collection $sites): bool
    {
        return $sites->count() >= max(1, (int) config('reminders.new_sites_digest.min_sites', 3));
    }

    /**
     * Sites this advertiser has already met: bought from, saved, or hidden.
     *
     * @return Collection<int, int>
     */
    private function sitesTheyKnow(User $user): Collection
    {
        $ordered = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->pluck('site_id');

        $favourited = UserFavorite::where('user_id', $user->id)->pluck('site_id');
        $blacklisted = UserBlacklist::where('user_id', $user->id)->pluck('site_id');

        return $ordered->merge($favourited)->merge($blacklisted)->filter()->unique()->values();
    }

    /**
     * Deterministic so the digest is testable and two runs agree.
     *
     * Discount weight uses the advertiser-facing effective % (after fee floor),
     * never the configured nominal — a 70% that floors to ~11% must not outrank
     * a real ~14% cut.
     */
    private function score(Site $site, Carbon $newWithin): float
    {
        $score = 0.0;
        $pricing = app(CartPricingService::class);

        $unit = $pricing->priceForAdvertiser($site);
        $effective = (float) ($unit['discount_percent'] ?? 0);
        if ($effective > 0) {
            $score += 1000 + min(50, $effective) * 4;
        } elseif ($site->joinsBulkDiscount()) {
            $packQty = (int) config('site_promotions.bulk.min_qty', 3);
            $packEffective = (float) ($pricing->priceForAdvertiser($site, null, $packQty)['discount_percent'] ?? 0);
            $score += 400 + min(50, $packEffective) * 2;
        }

        if ($site->created_at && $site->created_at->greaterThanOrEqualTo($newWithin)) {
            $score += 300;
            // Newer inside the window still beats older.
            $ageDays = max(0, (int) $site->created_at->diffInDays(now()));
            $score += max(0, 45 - $ageDays);
        }

        // Quality, kept deliberately small so it breaks ties rather than
        // dominating: the point of the email is what is new, not what is best.
        $score += min(100, (int) ($site->dr ?? 0)) * 0.8;
        $score += min(100, (int) ($site->da ?? 0)) * 0.4;
        $score += min(20, (int) (($site->traffic ?? 0) / 5000));

        if ((int) ($site->completed_orders_count ?? 0) > 0) {
            $score += 10;
        }

        return $score;
    }
}
