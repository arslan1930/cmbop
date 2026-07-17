<?php

namespace App\Services;

use App\Models\AdBanner;
use App\Models\SiteAnnouncement;
use Illuminate\Support\Collection;

class PromotionService
{
    public function resolveAudience(?\App\Models\User $user = null): string
    {
        $user = $user ?: auth()->user();
        if (!$user) {
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
        $audience = $audience ?: $this->resolveAudience();

        return SiteAnnouncement::query()
            ->active()
            ->forAudience($audience)
            ->orderBy('priority')
            ->orderByDesc('id')
            ->get();
    }

    public function activeBanners(?string $placement = null, ?string $audience = null): Collection
    {
        $audience = $audience ?: $this->resolveAudience();

        $query = AdBanner::query()
            ->active()
            ->forAudience($audience)
            ->orderBy('priority')
            ->orderByDesc('id');

        if ($placement) {
            $query->forPlacement($placement);
        }

        return $query->get();
    }

    public function dashboardStats(): array
    {
        return [
            'announcements_live' => SiteAnnouncement::query()->active()->count(),
            'announcements_total' => SiteAnnouncement::query()->count(),
            'banners_live' => AdBanner::query()->active()->count(),
            'banners_total' => AdBanner::query()->count(),
            'banner_impressions' => (int) AdBanner::query()->sum('impressions'),
            'banner_clicks' => (int) AdBanner::query()->sum('clicks'),
            'upcoming_announcements' => SiteAnnouncement::query()
                ->where('is_active', true)
                ->whereNotNull('starts_at')
                ->where('starts_at', '>', now())
                ->count(),
        ];
    }
}
