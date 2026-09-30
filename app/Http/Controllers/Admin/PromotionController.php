<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdBanner;
use App\Models\FeatureCredit;
use App\Models\FeatureOfferSetting;
use App\Models\Site;
use App\Models\SiteAnnouncement;
use App\Models\User;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use App\Services\PromotionService;
use App\Services\PromotionTrackingService;
use App\Services\Wallet\WelcomeBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PromotionController extends Controller
{
    public function index(PromotionService $promotions, WelcomeBonusService $welcomeBonus)
    {
        $stats = $promotions->dashboardStats();
        $nowShowing = $promotions->staffNowShowing();
        $showingNoticeIds = PromotionService::showingNoticeIds($nowShowing);
        $showingBannerIds = PromotionService::showingBannerIds($nowShowing);

        $announcements = collect();
        $banners = collect();
        $sizes = config('promotions.banner_sizes', []);
        $featuredNotices = config('promotions.featured_notices', []);
        $noticeCounts = [];

        foreach (array_keys($featuredNotices) as $type) {
            $noticeCounts[$type] = ['live' => 0, 'total' => 0];
        }

        $announcementsTableReady = false;
        $bannersTableReady = false;
        $welcomeBonusTableReady = false;

        try {
            $announcementsTableReady = Schema::hasTable('site_announcements');
            if ($announcementsTableReady) {
                $announcements = SiteAnnouncement::query()
                    ->latest('id')
                    ->limit(8)
                    ->get();

                foreach (array_keys($featuredNotices) as $type) {
                    $noticeCounts[$type] = [
                        'live' => SiteAnnouncement::query()->active()->where('type', $type)->get()->filter->isCurrentlyLive()->count(),
                        'total' => SiteAnnouncement::query()->where('type', $type)->count(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Admin promotions hub announcements failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $bannersTableReady = Schema::hasTable('ad_banners');
            if ($bannersTableReady) {
                $banners = AdBanner::query()
                    ->latest('id')
                    ->limit(8)
                    ->get();
            }
        } catch (\Throwable $e) {
            Log::warning('Admin promotions hub banners failed', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            WelcomeBonusSetting::ensureTable();
            WelcomeBonusClaim::ensureTable();
            $welcomeBonusTableReady = Schema::hasTable('welcome_bonus_settings')
                && Schema::hasTable('welcome_bonus_claims');
        } catch (\Throwable) {
            $welcomeBonusTableReady = false;
        }

        $welcomeBonusEnabled = false;
        $welcomeBonusAmount = 0.0;
        $welcomeBonusCanGrant = false;
        $welcomeBonusStatusUnknown = ! $welcomeBonusTableReady;
        try {
            $welcomeBonusEnabled = $welcomeBonus->isEnabled();
            $welcomeBonusAmount = $welcomeBonus->amount();
            $welcomeBonusCanGrant = $welcomeBonus->canGrant();
        } catch (\Throwable $e) {
            $welcomeBonusStatusUnknown = true;
            Log::warning('Admin promotions hub welcome bonus status failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $featureOffers = FeatureOfferSetting::offers();
        $featureCredits = collect();
        $featureCreditUsers = collect();
        $featureCreditSites = collect();
        $featureCreditsTableReady = false;
        if (auth()->user()?->isAdmin()) {
            $featureCreditUsers = $this->featureCreditPublishers();
            $selectedUserId = (int) old('user_id');
            if ($selectedUserId > 0 && $featureCreditUsers->contains('id', $selectedUserId)) {
                $featureCreditSites = $this->sitesForPublisher($selectedUserId);
            }
        }
        try {
            $featureCreditsTableReady = Schema::hasTable('feature_credits');
            if ($featureCreditsTableReady && auth()->user()?->isAdmin()) {
                $featureCredits = FeatureCredit::query()
                    ->with(['user:id,name,email', 'site:id,site_name,domain'])
                    ->latest('id')
                    ->limit(20)
                    ->get();
            }
        } catch (\Throwable $e) {
            $featureCreditsTableReady = false;
            Log::warning('Admin promotions hub feature credits failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $welcomeBonusClaims = $promotions->welcomeBonusClaimStats();
        $featuredSites = $promotions->marketplaceFeatured();
        $customDiscountSites = $promotions->marketplaceCustomDiscounts();
        $bulkDiscountSites = $promotions->marketplaceBulkDiscounts();

        $since = now()->subDays(7)->startOfDay();
        $tracker = app(PromotionTrackingService::class);
        $announcementClicks7ById = $tracker->countsForSubjectsSince(
            SiteAnnouncement::class,
            $announcements->pluck('id')->all(),
            PromotionTrackingService::EVENT_CLICK,
            $since
        );
        $bannerImps7ById = $tracker->countsForSubjectsSince(
            AdBanner::class,
            $banners->pluck('id')->all(),
            PromotionTrackingService::EVENT_IMPRESSION,
            $since
        );
        $bannerClicks7ById = $tracker->countsForSubjectsSince(
            AdBanner::class,
            $banners->pluck('id')->all(),
            PromotionTrackingService::EVENT_CLICK,
            $since
        );

        return view('admin.promotions.index', compact(
            'stats',
            'announcements',
            'banners',
            'sizes',
            'featuredNotices',
            'noticeCounts',
            'welcomeBonusEnabled',
            'welcomeBonusAmount',
            'welcomeBonusCanGrant',
            'welcomeBonusTableReady',
            'welcomeBonusStatusUnknown',
            'announcementsTableReady',
            'bannersTableReady',
            'welcomeBonusClaims',
            'featuredSites',
            'customDiscountSites',
            'bulkDiscountSites',
            'featureOffers',
            'featureCredits',
            'featureCreditUsers',
            'featureCreditSites',
            'featureCreditsTableReady',
            'nowShowing',
            'showingNoticeIds',
            'showingBannerIds',
            'announcementClicks7ById',
            'bannerImps7ById',
            'bannerClicks7ById'
        ));
    }

    public function featureCreditSites(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $userId = (int) $request->query('user_id');
        abort_unless($userId > 0, 404);

        $user = User::query()->findOrFail($userId);
        abort_unless($user->hasRole('publisher'), 404);

        return response()->json([
            'sites' => $this->sitesForPublisher($userId)->map(fn (Site $site) => [
                'id' => (int) $site->id,
                'site_name' => scalar_text($site->site_name),
                'domain' => scalar_text($site->domain),
            ])->values(),
        ]);
    }

    public function grantFeatureCredit(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if (! Schema::hasTable('feature_credits')) {
            return redirect()->route('admin.promotions.index')
                ->with('error', 'Feature credits are not available until the database is migrated.');
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'days' => ['required', 'integer', 'min:1', 'max:400'],
        ]);

        $recipient = User::query()->findOrFail((int) $data['user_id']);
        if (! $recipient->hasRole('publisher')) {
            return redirect()->route('admin.promotions.index')
                ->withInput()
                ->withErrors(['user_id' => 'Featured credits can only be given to publishers.']);
        }
        $site = Site::query()->findOrFail((int) $data['site_id']);
        if ((int) $site->publisher_id !== (int) $recipient->id) {
            return redirect()->route('admin.promotions.index')
                ->withInput()
                ->withErrors(['site_id' => 'That site does not belong to the selected user.']);
        }

        FeatureCredit::query()->create([
            'user_id' => $recipient->id,
            'site_id' => $site->id,
            'days' => (int) $data['days'],
            'granted_by' => auth()->id(),
        ]);

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Featured credit given to '.$recipient->email.' for '.$site->domain.'. They can use it on that site whenever they want.');
    }

    public function updateFeatureOffers(Request $request)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $data = $request->validate([
            'offers' => ['required', 'array'],
            'offers.month.label' => ['required', 'string', 'max:40'],
            'offers.month.price' => ['required', 'numeric', 'min:0.5', 'max:5000'],
            'offers.month.days' => ['required', 'integer', 'min:1', 'max:400'],
            'offers.year.label' => ['required', 'string', 'max:40'],
            'offers.year.price' => ['required', 'numeric', 'min:0.5', 'max:5000'],
            'offers.year.days' => ['required', 'integer', 'min:1', 'max:400'],
        ]);

        $packages = [];
        foreach (['month', 'year'] as $key) {
            $row = $data['offers'][$key];
            $packages[$key] = [
                'label' => $row['label'],
                'price' => $row['price'],
                'days' => $row['days'],
                'active' => $request->boolean('offers.'.$key.'.active'),
            ];
        }

        FeatureOfferSetting::saveOffers($packages);

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Feature packages updated. Publishers pay these euro prices, converted for their location on card.');
    }

    public function preview(Request $request)
    {
        $audience = search_text($request->query('audience')) ?: 'public';
        if (! in_array($audience, array_keys(config('promotions.audiences', [])), true)) {
            $audience = 'public';
        }
        if ($audience === 'all') {
            $audience = 'public';
        }

        $placement = search_text($request->query('placement')) ?: 'content_top';
        if (! in_array($placement, array_keys(config('promotions.banner_placements', [])), true)) {
            $placement = 'content_top';
        }

        return view('admin.promotions.preview', [
            'audience' => $audience,
            'placement' => $placement,
            'track' => false,
            'placementWired' => in_array($audience, config('promotions.wired_placements.'.$placement, []), true),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function featureCreditPublishers()
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'publisher'))
            ->whereHas('sites')
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email']);
    }

    /**
     * @return Collection<int, Site>
     */
    private function sitesForPublisher(int $userId)
    {
        return Site::query()
            ->where('publisher_id', $userId)
            ->orderBy('domain')
            ->get(['id', 'publisher_id', 'site_name', 'domain']);
    }
}
