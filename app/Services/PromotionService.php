<?php

namespace App\Services;

use App\Models\AdBanner;
use App\Models\SiteAnnouncement;
use App\Models\User;
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
            return SiteAnnouncement::query()
                ->active()
                ->forAudience($audience)
                ->orderBy('priority')
                ->orderByDesc('id')
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

            return $query->get();
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
            'banners_live' => 0,
            'banners_total' => 0,
            'banner_impressions' => 0,
            'banner_clicks' => 0,
            'upcoming_announcements' => 0,
        ];

        if (! Schema::hasTable('site_announcements') && ! Schema::hasTable('ad_banners')) {
            return $empty;
        }

        try {
            return [
                'announcements_live' => Schema::hasTable('site_announcements')
                    ? SiteAnnouncement::query()->active()->count()
                    : 0,
                'announcements_total' => Schema::hasTable('site_announcements')
                    ? SiteAnnouncement::query()->count()
                    : 0,
                'banners_live' => Schema::hasTable('ad_banners')
                    ? AdBanner::query()->active()->count()
                    : 0,
                'banners_total' => Schema::hasTable('ad_banners')
                    ? AdBanner::query()->count()
                    : 0,
                'banner_impressions' => Schema::hasTable('ad_banners')
                    ? (int) AdBanner::query()->sum('impressions')
                    : 0,
                'banner_clicks' => Schema::hasTable('ad_banners')
                    ? (int) AdBanner::query()->sum('clicks')
                    : 0,
                'upcoming_announcements' => Schema::hasTable('site_announcements')
                    ? SiteAnnouncement::query()
                        ->where('is_active', true)
                        ->whereNotNull('starts_at')
                        ->where('starts_at', '>', now())
                        ->count()
                    : 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to load promotion dashboard stats', ['error' => $e->getMessage()]);

            return $empty;
        }
    }
}
