<?php

namespace App\Services;

use App\Models\AdBanner;
use App\Models\Site;
use App\Models\SiteAnnouncement;
use App\Models\User;
use App\Models\WelcomeBonusClaim;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PromotionService
{
    public function resolveAudience(?User $user = null): string
    {
        $user = $user ?: auth()->user();
        if (! $user) {
            return 'public';
        }

        $active = method_exists($user, 'activeRole') ? $user->activeRole() : null;
        if (in_array($active, ['advertiser', 'publisher'], true)) {
            return $active;
        }

        if (method_exists($user, 'hasRole')) {
            if ($user->hasRole('advertiser')) {
                return 'advertiser';
            }
            if ($user->hasRole('publisher')) {
                return 'publisher';
            }
        }

        return 'public';
    }

    public function activeAnnouncements(?string $audience = null): Collection
    {
        if (! Schema::hasTable('site_announcements')) {
            return collect();
        }

        $audience = $audience ?: $this->resolveAudience();

        try {
            $limit = max(1, (int) config('promotions.max_live_announcements', 2));

            return SiteAnnouncement::query()
                ->active()
                ->forAudience($audience)
                ->orderBy('priority')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Failed to load site announcements', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    public function activeBanners(?string $placement = null, ?string $audience = null): Collection
    {
        if (! Schema::hasTable('ad_banners')) {
            return collect();
        }

        $audience = $audience ?: $this->resolveAudience();

        try {
            $query = AdBanner::query()
                ->active()
                ->forAudience($audience)
                ->orderBy('priority')
                ->orderByDesc('id');

            if ($placement) {
                $query->forPlacement($placement);
            }

            $all = $query->get();
            $limit = max(1, (int) config('promotions.banners_per_placement', 1));
            $seed = crc32(($placement ?? 'any').'|'.now()->toDateString());

            return $all->values()
                ->sortBy(fn (AdBanner $banner) => crc32($seed.'|'.$banner->id))
                ->take($limit)
                ->values();
        } catch (\Throwable $e) {
            Log::warning('Failed to load ad banners', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    public function dashboardStats(): array
    {
        $empty = [
            'announcements_live' => 0,
            'announcements_total' => 0,
            'announcements_expired' => 0,
            'banners_live' => 0,
            'banners_total' => 0,
            'banners_expired' => 0,
            'banner_impressions' => 0,
            'banner_clicks' => 0,
            'banner_impressions_7d' => 0,
            'banner_clicks_7d' => 0,
            'banner_ctr_7d' => 0.0,
            'announcement_clicks' => 0,
            'announcement_clicks_7d' => 0,
            'upcoming_announcements' => 0,
            'featured_live' => 0,
            'custom_discounts_live' => 0,
            'bulk_discounts_live' => 0,
        ];

        if (! Schema::hasTable('site_announcements') && ! Schema::hasTable('ad_banners')) {
            return $empty;
        }

        try {
            $since = now()->subDays(7)->startOfDay();
            $tracking = app(PromotionTrackingService::class);
            $impressions7 = $tracking->countSince(AdBanner::class, PromotionTrackingService::EVENT_IMPRESSION, $since);
            $clicks7 = $tracking->countSince(AdBanner::class, PromotionTrackingService::EVENT_CLICK, $since);
            $annClicks7 = $tracking->countSince(SiteAnnouncement::class, PromotionTrackingService::EVENT_CLICK, $since);

            return [
                'announcements_live' => Schema::hasTable('site_announcements')
                    ? SiteAnnouncement::query()->active()->count()
                    : 0,
                'announcements_total' => Schema::hasTable('site_announcements')
                    ? SiteAnnouncement::query()->count()
                    : 0,
                'announcements_expired' => Schema::hasTable('site_announcements')
                    ? SiteAnnouncement::query()->scheduleState('expired')->count()
                    : 0,
                'banners_live' => Schema::hasTable('ad_banners')
                    ? AdBanner::query()->active()->count()
                    : 0,
                'banners_total' => Schema::hasTable('ad_banners')
                    ? AdBanner::query()->count()
                    : 0,
                'banners_expired' => Schema::hasTable('ad_banners')
                    ? AdBanner::query()->scheduleState('expired')->count()
                    : 0,
                'banner_impressions' => Schema::hasTable('ad_banners')
                    ? (int) AdBanner::query()->sum('impressions')
                    : 0,
                'banner_clicks' => Schema::hasTable('ad_banners')
                    ? (int) AdBanner::query()->sum('clicks')
                    : 0,
                'banner_impressions_7d' => $impressions7,
                'banner_clicks_7d' => $clicks7,
                'banner_ctr_7d' => $impressions7 > 0 ? round(100 * $clicks7 / $impressions7, 2) : 0.0,
                'announcement_clicks' => Schema::hasTable('site_announcements') && Schema::hasColumn('site_announcements', 'clicks')
                    ? (int) SiteAnnouncement::query()->sum('clicks')
                    : 0,
                'announcement_clicks_7d' => $annClicks7,
                'upcoming_announcements' => Schema::hasTable('site_announcements')
                    ? SiteAnnouncement::query()->scheduleState('scheduled')->count()
                    : 0,
                'featured_live' => $this->featuredLiveCount(),
                'custom_discounts_live' => $this->customDiscountLiveCount(),
                'bulk_discounts_live' => $this->bulkDiscountLiveCount(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to load promotion dashboard stats', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * @return Collection<int, Site>
     */
    public function marketplaceFeatured(int $limit = 8): Collection
    {
        if (! $this->sitesColumnReady('featured_until')) {
            return collect();
        }

        try {
            return Site::query()
                ->whereNotNull('featured_until')
                ->where('featured_until', '>', now())
                ->orderByDesc('featured_until')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Failed to load featured marketplace sites', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, Site>
     */
    public function marketplaceCustomDiscounts(int $limit = 8): Collection
    {
        if (! $this->sitesColumnReady('custom_discount_percent')) {
            return collect();
        }

        try {
            return Site::query()->onDiscount()->orderByDesc('custom_discount_ends_at')->limit($limit)->get();
        } catch (\Throwable $e) {
            Log::warning('Failed to load custom-discount sites', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, Site>
     */
    public function marketplaceBulkDiscounts(int $limit = 8): Collection
    {
        if (! $this->sitesColumnReady('bulk_discount_enabled')) {
            return collect();
        }

        try {
            return Site::query()
                ->where('bulk_discount_enabled', true)
                ->whereNotNull('bulk_discount_percent')
                ->where('bulk_discount_percent', '>', 0)
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Failed to load bulk-discount sites', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return array{week:int, total:int, last:?WelcomeBonusClaim}
     */
    public function welcomeBonusClaimStats(): array
    {
        $empty = ['week' => 0, 'total' => 0, 'last' => null];
        if (! Schema::hasTable('welcome_bonus_claims')) {
            return $empty;
        }

        try {
            return [
                'week' => (int) WelcomeBonusClaim::query()->where('created_at', '>=', now()->startOfWeek())->count(),
                'total' => (int) WelcomeBonusClaim::query()->count(),
                'last' => WelcomeBonusClaim::query()->with('user')->latest('id')->first(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to load welcome bonus claim stats', ['error' => $e->getMessage()]);

            return $empty;
        }
    }

    public function placementIsWired(string $placement): bool
    {
        $wired = config('promotions.wired_placements.'.$placement, []);

        return is_array($wired) && $wired !== [];
    }

    private function featuredLiveCount(): int
    {
        if (! $this->sitesColumnReady('featured_until')) {
            return 0;
        }

        try {
            return (int) Site::query()
                ->whereNotNull('featured_until')
                ->where('featured_until', '>', now())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function customDiscountLiveCount(): int
    {
        if (! $this->sitesColumnReady('custom_discount_percent')) {
            return 0;
        }

        try {
            return (int) Site::query()->onDiscount()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function bulkDiscountLiveCount(): int
    {
        if (! $this->sitesColumnReady('bulk_discount_enabled')) {
            return 0;
        }

        try {
            return (int) Site::query()
                ->where('bulk_discount_enabled', true)
                ->whereNotNull('bulk_discount_percent')
                ->where('bulk_discount_percent', '>', 0)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function sitesColumnReady(string $column): bool
    {
        try {
            return Schema::hasTable('sites') && Schema::hasColumn('sites', $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
