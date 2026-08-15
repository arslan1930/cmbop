<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AdBannerController as AdminAdBannerController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\AudienceController as AdminAudienceController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\BulkSiteRequestController as AdminBulkSiteRequestController;
use App\Http\Controllers\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\CatalogActivityController as AdminCatalogActivityController;
use App\Http\Controllers\Admin\CommunityFeedbackController;
use App\Http\Controllers\Admin\ContentLibraryController as AdminContentLibraryController;
use App\Http\Controllers\Admin\ContentModerationController as AdminContentModerationController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DepositApproveConfirmController as AdminDepositApproveConfirmController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
use App\Http\Controllers\Admin\EmailCenterController as AdminEmailCenterController;
// Publisher and Advertiser controllers
use App\Http\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\OrderDisputeController as AdminOrderDisputeController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Admin\SiteEnrichmentController;
use App\Http\Controllers\Admin\SiteRatingController;
use App\Http\Controllers\Admin\StalledOrderController as AdminStalledOrderController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WelcomeBonusSettingController as AdminWelcomeBonusSettingController;
use App\Http\Controllers\Admin\WithdrawalMarkPaidConfirmController;
use App\Http\Controllers\Advertiser\AddFundsController;
use App\Http\Controllers\Advertiser\AnalyticsController;
use App\Http\Controllers\Advertiser\BillingController as AdvertiserBillingController;
use App\Http\Controllers\Advertiser\CatalogController;
use App\Http\Controllers\Advertiser\CatalogCopyTrackController;
use App\Http\Controllers\Advertiser\ContentLibraryController;
use App\Http\Controllers\Advertiser\ContentModerationController as AdvertiserContentModerationController;
use App\Http\Controllers\Advertiser\ContentSubmissionController;
use App\Http\Controllers\Advertiser\DashboardController as AdvertiserDashboardController;
use App\Http\Controllers\Advertiser\GuestPostWizardController;
use App\Http\Controllers\Advertiser\PaymentMethodController;
use App\Http\Controllers\Advertiser\ProjectController;
use App\Http\Controllers\Advertiser\ReportsController;
use App\Http\Controllers\Advertiser\SavedSitesController;
use App\Http\Controllers\Advertiser\ScheduledOrdersController;
use App\Http\Controllers\Advertiser\SiteUrlConcealController;
use App\Http\Controllers\Advertiser\SiteUrlRevealController;
use App\Http\Controllers\Advertiser\SiteVisitController;
use App\Http\Controllers\Advertiser\WebsiteSuggestionController;
use App\Http\Controllers\AnnouncementClickController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\BannerClickController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EmailUnsubscribeController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Marketing\PanelController as MarketingPanelController;
use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
// BlogController for public blog pages
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionTrackController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\Publisher\BalanceController;
use App\Http\Controllers\Publisher\BillingController as PublisherBillingController;
use App\Http\Controllers\Publisher\BulkSiteRequestController as PublisherBulkSiteRequestController;
use App\Http\Controllers\Publisher\DashboardController;
use App\Http\Controllers\Publisher\OrderController;
use App\Http\Controllers\Publisher\PublisherReportsController;
use App\Http\Controllers\Publisher\SiteClaimController;
use App\Http\Controllers\Publisher\SiteController;
use App\Http\Controllers\Publisher\SitePromotionController;
use App\Http\Controllers\Publisher\SiteVerificationController;
use App\Http\Controllers\Publisher\WithdrawalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\RedirectMarketingFromAdmin;
use App\Http\Middleware\RoleMiddleware;
use App\Models\Site;
use App\Models\User;
use App\Services\Marketing\CatalogTeaserService;
use App\Support\PublicI18n;
use App\Support\RobotsTxt;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public marketing routes (multilingual: en unprefixed UK English,
| de|fr|nl|es|it|us prefixed). Authenticated SaaS + login/register stay English-only.
|--------------------------------------------------------------------------
*/

$prefixedLocalePattern = PublicI18n::prefixedPattern();
$supportedLocalePattern = PublicI18n::supportedPattern();

// Stacked locale cleanup: /nl/fr → /nl
Route::get('/{locale}/{nested}', function ($locale, $nested) {
    $prefixed = PublicI18n::prefixed();

    if (in_array($locale, $prefixed, true) && in_array($nested, $prefixed, true)) {
        $remaining = array_slice(request()->segments(), 2);
        $newPath = $remaining ? '/'.implode('/', $remaining) : '';

        return Redirect::to('/'.$locale.$newPath, 301);
    }

    return app()->make('router')->dispatch(request());
})->where(['locale' => $prefixedLocalePattern, 'nested' => $prefixedLocalePattern]);

// Locale-prefixed auth → English auth (SaaS stays English)
Route::get('/{locale}/login', fn () => Redirect::to('/login', 301))
    ->where('locale', $prefixedLocalePattern)
    ->name('locale.login.redirect');
Route::get('/{locale}/register', fn () => Redirect::to('/register', 301))
    ->where('locale', $prefixedLocalePattern)
    ->name('locale.register.redirect');

$registerPublicMarketingRoutes = function () {
    Route::get('/', function (CatalogTeaserService $teasers) {
        return view('home', [
            'catalogPreview' => $teasers->teasers(8),
        ]);
    })->name('home');
    Route::get('/contact', fn () => view('pages.contact'))->name('contact');
    Route::get('/about', [MarketingPageController::class, 'about'])->name('about');
    Route::get('/faq', [MarketingPageController::class, 'faq'])->name('faq');
    Route::get('/pricing', [MarketingPageController::class, 'pricing'])->name('pricing');
    Route::get('/marketplace', [MarketingPageController::class, 'marketplace'])->name('marketplace');
    Route::get('/how-it-works', [MarketingPageController::class, 'howItWorks'])->name('how-it-works');
    Route::get('/become-a-publisher', [MarketingPageController::class, 'becomePublisher'])->name('become-a-publisher');
    Route::get('/why-choose-us', [MarketingPageController::class, 'whyChooseUs'])->name('why-choose-us');
    Route::get('/privacy-policy', fn () => view('pages.privacy-policy'))->name('privacy-policy');
    Route::get('/terms-of-services', fn () => view('pages.terms-of-services'))->name('terms-of-services');
    Route::get('/cookie-policy', [MarketingPageController::class, 'cookiePolicy'])->name('cookie-policy');
    Route::get('/refund-policy', [MarketingPageController::class, 'refundPolicy'])->name('refund-policy');
    Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
    Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
        ->middleware('throttle:10,1')
        ->name('newsletter.subscribe');
};

// English (canonical, no prefix)
Route::group([], $registerPublicMarketingRoutes);

// Prefixed locales
Route::group([
    'prefix' => '{locale}',
    'where' => ['locale' => $prefixedLocalePattern],
    'as' => 'locale.',
], $registerPublicMarketingRoutes);

// SEO: sitemap index + per-locale sitemaps + robots + llms.txt
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-{locale}.xml', [SitemapController::class, 'locale'])
    ->where('locale', $supportedLocalePattern)
    ->name('sitemap.locale');
Route::get('/robots.txt', function () {
    return response(RobotsTxt::render(), 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('robots');
Route::get('/llms.txt', function () {
    $path = public_path('llms.txt');
    abort_unless(is_file($path), 404);

    return response((string) file_get_contents($path), 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('llms');

/*
| Public media fallback — when public/storage symlink is broken (Hostinger
| MEDIA_PATH mismatch), /storage/... 404s. /media/... streams from the public
| disk so admin/catalog previews still work until ops runs media:ensure-link.
*/
Route::get('/media/{path}', [PublicMediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.public');

/*
| Legacy /js and /css URLs — Hostinger production already serves /assets/*
| but not /js or /css. Keep old paths working and prefer the assets/ copies.
*/
Route::get('/js/{path}', function (string $path) {
    $path = str_replace(['..', '\\'], '', $path);
    $candidates = [
        public_path('assets/js/'.$path),
        public_path('js/'.$path),
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            return response()->file($file, [
                'Content-Type' => str_ends_with($path, '.css') ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }
    }
    abort(404);
})->where('path', '.*')->name('legacy.js');

// Legacy /css/* URLs (cached pages, old links) now resolve from assets/css,
// which is the only stylesheet directory.
Route::get('/css/{path}', function (string $path) {
    $path = str_replace(['..', '\\'], '', $path);
    $file = public_path('assets/css/'.$path);

    if (is_file($file)) {
        return response()->file($file, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    abort(404);
})->where('path', '.*')->name('legacy.css');

// Ad banner / announcement click tracking (public)
Route::get('/banners/{banner}/click', BannerClickController::class)
    ->middleware('throttle:60,1')
    ->name('banners.click');
Route::get('/announcements/{announcement}/click', AnnouncementClickController::class)
    ->middleware('throttle:60,1')
    ->name('announcements.click');
Route::post('/promotions/track', PromotionTrackController::class)
    ->middleware('throttle:60,1')
    ->name('promotions.track');

// External cron fallback for hosts without a real scheduler. This completes orders
// and releases publisher payouts, so it stays closed unless a strong secret is set
// (the app scheduler already runs orders:auto-approve on its own).
Route::get('/cron/orders-auto-approve/{key}', function ($key) {
    $secret = (string) config('app.cron_secret', '');

    if (strlen($secret) < 32) {
        abort(404);
    }

    if (! hash_equals($secret, (string) $key)) {
        abort(403);
    }

    Artisan::call('orders:auto-approve');

    return response()->json([
        'status' => 'success',
        'message' => 'Orders auto-approved',
    ]);
})->middleware('throttle:6,1')->name('cron.orders-auto-approve');

// Whole scheduler for hosts that cannot run `php artisan schedule:run` every
// minute. Point an external pinger here and everything scheduled runs — mail
// drain, auto-approve, scheduled publishing, reminders and digests. Same secret
// gate as above, since these tasks move money and send mail.
Route::get('/cron/run/{key}', function ($key) {
    $secret = (string) config('app.cron_secret', '');

    if (strlen($secret) < 32) {
        abort(404);
    }

    if (! hash_equals($secret, (string) $key)) {
        abort(403);
    }

    Artisan::call('schedule:run');

    return response()->json([
        'status' => 'success',
        'message' => 'Scheduler run',
    ]);
})->middleware('throttle:6,1')->name('cron.run');

// ✅ UPDATED: Guest middleware for login/register pages
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::get('/login', [LoginController::class, 'show'])->name('login');
});

// Google OAuth must stay outside `guest`: the callback authenticates the user in-request,
// and a lost OAuth "state" session should still be able to complete via stateless fallback.
Route::get('auth/google', [SocialiteController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('auth/google/callback', [SocialiteController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// Registration routes
Route::post('/register', [RegisterController::class, 'register'])
    ->middleware('throttle:register');

// Authentication routes (login, logout)
Route::post('/login', [LoginController::class, 'login'])->name('login.post');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Forgot Password
Route::get('/forgot-password', [ForgotPasswordController::class, 'show'])->name('password.request');
Route::post('/forgot-password', [ForgotPasswordController::class, 'send'])->name('password.email');

// Reset Password
Route::get('/reset-password/{token}', [ResetPasswordController::class, 'show'])->name('password.reset');
Route::post('/reset-password', [ResetPasswordController::class, 'update'])->name('password.update');

// Email Verification Notice (user can see this page if they are logged in)
Route::get('/email/verify', function () {
    return view('auth.verify-email');
})->middleware('auth')->name('verification.notice');

// Email verification link (no auth required — user clicks from email)
// Must stay public: signup does not log the user in before they verify.
Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    // Relative signature — host/scheme must not be part of the HMAC (email
    // links are prefixed with a public origin that may differ from APP_URL).
    // Ignore tracker params email clients often append (utm_*, fbclid, …).
    if (! $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params())) {
        return redirect('/login')->with(
            'error',
            'This verification link is invalid or has expired. Please sign in and resend a new verification email, or use “Resend verification” on the login page.'
        );
    }

    $user = User::findOrFail($id);

    if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        abort(403, 'Invalid verification link.');
    }

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    // Do not auto-login — send the user to sign in manually after verify.
    if (Auth::check()) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    return redirect('/login')->with(
        'message',
        'Email verified successfully. Please sign in to continue.'
    );
})->middleware('throttle:6,1')->name('verification.verify');

// Marketing unsubscribe (signed GET confirm + POST one-click). Same route name
// so one signature works for both methods. CSRF is excepted for Gmail POSTs.
Route::match(['get', 'post'], '/email/unsubscribe/{user}', EmailUnsubscribeController::class)
    ->whereNumber('user')
    ->middleware('throttle:30,1')
    ->name('email.unsubscribe');

// Resend verification email (requires login)
Route::post('/email/verification-notification', function (Request $request) {
    $request->user()->sendEmailVerificationNotification();

    return back()->with('message', 'Verification link sent!');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

// ✅ NEW: Resend verification WITHOUT login (AJAX)
Route::post('/email/resend', function (Request $request) {

    $request->validate([
        'email' => 'required|email',
    ]);

    $user = User::where('email', $request->email)->first();

    if ($user && ! $user->hasVerifiedEmail()) {
        $user->sendEmailVerificationNotification();
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Verification email resent successfully.',
    ]);

})->middleware('throttle:3,1')->name('verification.resend');

// ✅ NEW: Role Switch (Dropdown) Route
Route::post('/switch-role', [RoleController::class, 'switchRole'])
    ->middleware('auth')
    ->name('switch.role');

// Shared staff ops (sites / bulk / enrichment) — registered under /marketing and /admin
$registerStaffOpsRoutes = function () {
    Route::get('/sites', [AdminSiteController::class, 'index'])
        ->name('sites.index');
    Route::get('/sites/create', [AdminSiteController::class, 'createForPublisher'])
        ->name('sites.create');
    Route::post('/sites', [AdminSiteController::class, 'storeForPublisher'])
        ->name('sites.store');
    Route::get('/staff-handbook', fn () => view('admin.staff-handbook'))
        ->name('staff-handbook');
    Route::get('/users/{id}/sites', [AdminSiteController::class, 'userSites'])
        ->name('users.sites');
    // Disk-stream preview when public/storage symlink is broken (Hostinger MEDIA_PATH).
    // Must be registered before /sites/{id}… wildcards.
    Route::get('/sites/media/{path}', [PublicMediaController::class, 'show'])
        ->where('path', '.*')
        ->name('sites.media');
    Route::get('/sites/{id}/edit', [AdminSiteController::class, 'edit'])
        ->name('sites.edit');
    Route::put('/sites/{id}', [AdminSiteController::class, 'update'])
        ->name('sites.update');
    Route::post('/sites/{id}/upload-image', [AdminSiteController::class, 'uploadImage'])
        ->name('sites.upload-image');
    // Admin: any site. Marketing: pending / not-live (!verified && !active) only.
    Route::delete('/sites/{id}', [AdminSiteController::class, 'destroy'])
        ->name('sites.destroy');

    Route::get('/bulk-site-requests', [AdminBulkSiteRequestController::class, 'index'])
        ->name('bulk-site-requests.index');
    Route::get('/bulk-site-requests/{id}', [AdminBulkSiteRequestController::class, 'show'])
        ->name('bulk-site-requests.show');
    Route::post('/bulk-site-requests/{id}/sheet-sent', [AdminBulkSiteRequestController::class, 'markSheetSent'])
        ->name('bulk-site-requests.sheet-sent');
    Route::post('/bulk-site-requests/{id}/notes', [AdminBulkSiteRequestController::class, 'updateNotes'])
        ->name('bulk-site-requests.notes');
    Route::post('/bulk-site-requests/{id}/seed', [AdminBulkSiteRequestController::class, 'seed'])
        ->name('bulk-site-requests.seed');
    Route::post('/bulk-site-requests/{id}/done', [AdminBulkSiteRequestController::class, 'done'])
        ->name('bulk-site-requests.done');
    Route::post('/bulk-site-requests/{id}/cancel', [AdminBulkSiteRequestController::class, 'cancel'])
        ->name('bulk-site-requests.cancel');

    Route::get('/site-enrichment', [SiteEnrichmentController::class, 'index'])
        ->name('site-enrichment.index');
    Route::post('/sites/{id}/enrich', [SiteEnrichmentController::class, 'enrich'])
        ->name('sites.enrich');
    Route::post('/sites/{id}/refresh-metrics', [SiteEnrichmentController::class, 'refreshMetrics'])
        ->name('sites.refresh-metrics');
    Route::post('/sites/{id}/refresh-screenshot', [SiteEnrichmentController::class, 'refreshScreenshot'])
        ->name('sites.refresh-screenshot');
    Route::post('/sites/{id}/manual-metrics', [SiteEnrichmentController::class, 'manualMetrics'])
        ->name('sites.manual-metrics');
    Route::post('/site-enrichment/rerun-failed', [SiteEnrichmentController::class, 'rerunFailed'])
        ->name('site-enrichment.rerun-failed');
    Route::post('/site-enrichment/queue-stale', [SiteEnrichmentController::class, 'queueStale'])
        ->name('site-enrichment.queue-stale');
    Route::post('/sites/{id}/allow-api-metrics', [SiteEnrichmentController::class, 'allowApiOverwrite'])
        ->name('sites.allow-api-metrics');

    // Activate/deactivate: admin and marketing (shared Sites Management).
    Route::post('/sites/{id}/active', [AdminSiteController::class, 'toggleActive'])
        ->name('sites.active');

    Route::get('/promotions', [AdminPromotionController::class, 'index'])->name('promotions.index');
    Route::prefix('promotions')->name('promotions.')->group(function () {
        Route::get('preview', [AdminPromotionController::class, 'preview'])->name('preview');

        Route::resource('announcements', AdminAnnouncementController::class)->except(['show']);
        Route::post('announcements/{announcement}/toggle', [AdminAnnouncementController::class, 'toggle'])
            ->name('announcements.toggle');
        Route::post('announcements/{announcement}/duplicate', [AdminAnnouncementController::class, 'duplicate'])
            ->name('announcements.duplicate');
        Route::post('announcements/{id}/restore', [AdminAnnouncementController::class, 'restore'])
            ->name('announcements.restore');

        Route::resource('banners', AdminAdBannerController::class)->except(['show']);
        Route::post('banners/{banner}/toggle', [AdminAdBannerController::class, 'toggle'])
            ->name('banners.toggle');
        Route::post('banners/{banner}/duplicate', [AdminAdBannerController::class, 'duplicate'])
            ->name('banners.duplicate');
        Route::post('banners/{id}/restore', [AdminAdBannerController::class, 'restore'])
            ->name('banners.restore');
    });
};

// ✅ Marketing panel — dedicated /marketing workspace + personal task history
Route::middleware(['auth', 'verified', RoleMiddleware::class.':marketing'])
    ->prefix('marketing')->name('marketing.')
    ->group(function () use ($registerStaffOpsRoutes) {
        Route::get('/dashboard', [MarketingPanelController::class, 'dashboard'])
            ->name('dashboard');
        Route::get('/dashboard/queue-counts', [MarketingPanelController::class, 'queueCounts'])
            ->name('dashboard.queue-counts');
        Route::get('/history', [MarketingPanelController::class, 'history'])
            ->name('history');
        $registerStaffOpsRoutes();
    });

// ✅ Admin panel — /admin (ops + money/users/growth). Marketers hitting /admin are redirected.
Route::middleware(['auth', 'verified', RedirectMarketingFromAdmin::class, RoleMiddleware::class.':admin'])
    ->prefix('admin')->name('admin.')
    ->group(function () use ($registerStaffOpsRoutes) {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])
            ->name('dashboard');
        $registerStaffOpsRoutes();

        // Records sheet routes must be registered before /sites/{id} wildcards.
        Route::get('/sites/records', [AdminSiteController::class, 'records'])
            ->name('sites.records');
        Route::get('/sites/records/export', [AdminSiteController::class, 'exportRecords'])
            ->name('sites.records.export');

        Route::post('/sites/{id}/verify', [AdminSiteController::class, 'verify'])
            ->name('sites.verify');
        // sites.active is registered in shared staff ops (admin + permitted marketing).

        Route::get('/site-ratings', [SiteRatingController::class, 'index'])
            ->name('site-ratings.index');
        Route::post('/site-ratings', [SiteRatingController::class, 'store'])
            ->name('site-ratings.store');
        Route::put('/site-ratings/{id}', [SiteRatingController::class, 'update'])
            ->name('site-ratings.update');
        Route::delete('/site-ratings/{id}', [SiteRatingController::class, 'destroy'])
            ->name('site-ratings.destroy');

        Route::get('/community', [CommunityFeedbackController::class, 'index'])
            ->name('community.index');
        Route::patch('/community/problems/{id}', [CommunityFeedbackController::class, 'updateProblem'])
            ->name('community.problems.update');
        Route::patch('/community/suggestions/{id}', [CommunityFeedbackController::class, 'updateSuggestion'])
            ->name('community.suggestions.update');
        Route::patch('/community/websites/{id}', [CommunityFeedbackController::class, 'updateWebsiteSuggestion'])
            ->name('community.websites.update');
        Route::post('/community/claims/{id}/approve', [CommunityFeedbackController::class, 'approveClaim'])
            ->name('community.claims.approve');
        Route::post('/community/claims/{id}/reject', [CommunityFeedbackController::class, 'rejectClaim'])
            ->name('community.claims.reject');

        Route::get('/activity-logs', [AdminActivityLogController::class, 'index'])
            ->name('activity-logs.index');

        Route::get('/catalog-activity', [AdminCatalogActivityController::class, 'index'])
            ->name('catalog-activity');
        Route::post('/catalog-activity/{user}/exempt', [AdminCatalogActivityController::class, 'toggleExempt'])
            ->name('catalog-activity.exempt');
        Route::post('/catalog-activity/{user}/clear-copy-hide', [AdminCatalogActivityController::class, 'clearCopyHide'])
            ->name('catalog-activity.clear-copy-hide');

        Route::get('/dashboard/statistics', [AdminDashboardController::class, 'getStatistics'])
            ->name('dashboard.statistics');
        Route::get('/dashboard/trends', [AdminDashboardController::class, 'getTrends'])
            ->name('dashboard.trends');
        Route::get('/dashboard/distributions', [AdminDashboardController::class, 'getDistributions'])
            ->name('dashboard.distributions');
        Route::get('/dashboard/action-queue', [AdminDashboardController::class, 'getActionQueue'])
            ->name('dashboard.action-queue');
        Route::get('/dashboard/finance', [AdminDashboardController::class, 'getFinanceStrip'])
            ->name('dashboard.finance');
        Route::get('/dashboard/queue-counts', [AdminDashboardController::class, 'getQueueCounts'])
            ->name('dashboard.queue-counts');

        Route::get('/dashboard/stalled-orders', [AdminStalledOrderController::class, 'index'])
            ->name('dashboard.stalled-orders');
        Route::post('/orders/items/{orderItem}/remind-publisher', [AdminStalledOrderController::class, 'remindPublisher'])
            ->name('orders.remind-publisher');

        Route::get('/users', [UserController::class, 'index'])
            ->name('users.index');
        Route::post('/users/{id}/update-company', [UserController::class, 'updateCompany'])
            ->name('users.updateCompany');
        Route::post('/users/{id}/payout-profile', [UserController::class, 'updatePayoutProfile'])
            ->name('users.updatePayoutProfile');
        Route::post('/users/{id}/roles', [UserController::class, 'updateRoles'])
            ->name('users.updateRoles');

        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments');
        Route::get('/payments/data', [AdminPaymentController::class, 'getPaymentsData'])->name('payments.data');
        Route::get('/payments/export', [AdminPaymentController::class, 'export'])->name('payments.export');
        Route::get('/payments/{id}', [AdminPaymentController::class, 'show'])->name('payments.show');
        Route::post('/payments/{id}/update-status', [AdminPaymentController::class, 'updatePaymentStatus'])->name('payments.updateStatus');

        Route::get('/invoices', [AdminInvoiceController::class, 'index'])->name('invoices.index');
        Route::post('/invoices/generate', [AdminInvoiceController::class, 'generate'])->name('invoices.generate');
        Route::post('/invoices/backfill-missing', [AdminInvoiceController::class, 'backfillMissing'])->name('invoices.backfill-missing');
        Route::post('/invoices/regenerate-missing-pdfs', [AdminInvoiceController::class, 'regenerateMissingPdfs'])->name('invoices.regenerate-missing-pdfs');
        Route::get('/invoices/{invoice}', [AdminInvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/download', [AdminInvoiceController::class, 'download'])->name('invoices.download');
        Route::get('/invoices/{invoice}/view', [AdminInvoiceController::class, 'viewPdf'])->name('invoices.view');
        Route::post('/invoices/{invoice}/resend', [AdminInvoiceController::class, 'resend'])->name('invoices.resend');
        Route::post('/invoices/{invoice}/cancel', [AdminInvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('/invoices/{invoice}/regenerate-pdf', [AdminInvoiceController::class, 'regeneratePdf'])->name('invoices.regenerate-pdf');

        Route::get('/finance', [AdminFinanceController::class, 'index'])->name('finance');
        Route::get('/finance/export', [AdminFinanceController::class, 'export'])->name('finance.export');
        Route::get('/finance/ledger', [AdminFinanceController::class, 'ledger'])->name('finance.ledger');
        Route::get('/finance/ledger/export', [AdminFinanceController::class, 'ledgerExport'])->name('finance.ledger.export');
        Route::get('/finance/users/{user}', [AdminFinanceController::class, 'user'])->name('finance.user');
        Route::post('/finance/wallets/{wallet}/clear-debt', [AdminFinanceController::class, 'clearDebt'])->name('finance.wallets.clear-debt');

        Route::get('/deposits', [AdminDepositController::class, 'index'])->name('deposits');
        Route::get('/deposits/{id}', [AdminDepositController::class, 'show'])->name('deposits.show');
        Route::post('/deposits/{id}/approve', [AdminDepositController::class, 'approve'])->name('deposits.approve');
        Route::post('/deposits/{id}/reject', [AdminDepositController::class, 'reject'])->name('deposits.reject');
        Route::get('/deposits/{deposit}/approve-confirm', [AdminDepositApproveConfirmController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('deposits.approve-confirm.show')
            ->whereNumber('deposit');
        Route::post('/deposits/{deposit}/approve-confirm', [AdminDepositApproveConfirmController::class, 'confirm'])
            ->middleware('throttle:12,1')
            ->name('deposits.approve-confirm')
            ->whereNumber('deposit');

        Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals');
        Route::get('/withdrawals/data', [AdminWithdrawalController::class, 'getWithdrawalsData'])->name('withdrawals.data');
        Route::get('/withdrawals/statistics', [AdminWithdrawalController::class, 'getStatistics'])->name('withdrawals.statistics');
        Route::get('/withdrawals/export', [AdminWithdrawalController::class, 'exportCsv'])->name('withdrawals.export');
        Route::post('/withdrawals/batch', [AdminWithdrawalController::class, 'batchUpdate'])->name('withdrawals.batch');
        Route::get('/withdrawals/{withdrawal}/mark-paid-confirm', [WithdrawalMarkPaidConfirmController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('withdrawals.mark-paid-confirm.show')
            ->whereNumber('withdrawal');
        Route::post('/withdrawals/{withdrawal}/mark-paid-confirm', [WithdrawalMarkPaidConfirmController::class, 'confirm'])
            ->middleware('throttle:12,1')
            ->name('withdrawals.mark-paid-confirm')
            ->whereNumber('withdrawal');
        Route::get('/withdrawals/{id}', [AdminWithdrawalController::class, 'show'])->name('withdrawals.show')->whereNumber('id');
        Route::post('/withdrawals/{id}/status', [AdminWithdrawalController::class, 'updateStatus'])->name('withdrawals.update-status')->whereNumber('id');
        Route::post('/withdrawals/{id}/processing', [AdminWithdrawalController::class, 'markProcessing'])->name('withdrawals.processing')->whereNumber('id');
        Route::post('/withdrawals/{id}/paid', [AdminWithdrawalController::class, 'markPaid'])->name('withdrawals.paid')->whereNumber('id');
        Route::post('/withdrawals/{id}/reject', [AdminWithdrawalController::class, 'reject'])->name('withdrawals.reject')->whereNumber('id');

        Route::post('blogs/sync-curated', [AdminBlogController::class, 'syncCurated'])
            ->name('blogs.sync-curated');
        Route::post('blogs/upload-image', [AdminBlogController::class, 'uploadImage'])->name('blogs.upload-image');
        Route::delete('blogs/content-image', [AdminBlogController::class, 'deleteContentImage'])->name('blogs.delete-content-image');
        Route::resource('blogs', AdminBlogController::class);
        Route::post('blogs/{id}/toggle-status', [AdminBlogController::class, 'toggleStatus'])->name('blogs.toggle-status');

        Route::get('/emails', [AdminEmailCenterController::class, 'index'])->name('emails.index');
        Route::get('/emails/preview/{key}', [AdminEmailCenterController::class, 'preview'])->name('emails.preview');
        Route::get('/emails/logs/{emailLog}', [AdminEmailCenterController::class, 'showLog'])->name('emails.log');
        Route::post('/emails/test', [AdminEmailCenterController::class, 'sendTest'])
            ->middleware('throttle:5,1')
            ->name('emails.test');
        Route::post('/emails/retry', [AdminEmailCenterController::class, 'retryFailed'])->name('emails.retry');
        Route::post('/emails/settings', [AdminEmailCenterController::class, 'updateSettings'])->name('emails.settings');

        Route::post('/promotions/welcome-bonus', [AdminWelcomeBonusSettingController::class, 'toggle'])
            ->name('promotions.welcome-bonus.toggle');
        Route::post('/promotions/welcome-bonus/amount', [AdminWelcomeBonusSettingController::class, 'updateAmount'])
            ->name('promotions.welcome-bonus.amount');

        Route::get('/audiences', [AdminAudienceController::class, 'index'])->name('audiences.index');
        Route::get('/audiences/export', [AdminAudienceController::class, 'export'])
            ->middleware('throttle:12,1')
            ->name('audiences.export');
        Route::get('/campaigns', [AdminCampaignController::class, 'index'])->name('campaigns.index');
        Route::match(['get', 'post'], '/campaigns/recipient-count', [AdminCampaignController::class, 'recipientCount'])
            ->middleware('throttle:30,1')
            ->name('campaigns.recipient-count');
        Route::post('/campaigns/preview', [AdminCampaignController::class, 'preview'])
            ->middleware('throttle:20,1')
            ->name('campaigns.preview');
        Route::post('/campaigns/send', [AdminCampaignController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('campaigns.send');

        Route::get('/moderation', [AdminContentModerationController::class, 'index'])->name('moderation.index');
        Route::post('/moderation/settings', [AdminContentModerationController::class, 'updateSettings'])->name('moderation.settings');
        Route::post('/moderation/logs/{log}/override', [AdminContentModerationController::class, 'override'])->name('moderation.override');

        Route::get('/content-library', [AdminContentLibraryController::class, 'index'])->name('content-library.index');
        Route::get('/content-library/{submission}', [AdminContentLibraryController::class, 'show'])->name('content-library.show');
        Route::get('/content-library/{submission}/preview', [AdminContentLibraryController::class, 'preview'])->name('content-library.preview');
        Route::get('/content-library/{submission}/download', [AdminContentLibraryController::class, 'download'])->name('content-library.download');

        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/data', [AdminOrderController::class, 'data'])->name('orders.data');
        Route::get('/orders/items/{orderItem}/content', [AdminOrderController::class, 'downloadContent'])
            ->name('orders.content.download');
        Route::get('/orders/{id}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{id}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
        Route::post('/orders/{id}/disputes', [AdminOrderDisputeController::class, 'open'])->name('orders.disputes.open');
        Route::post('/order-disputes/{id}/uphold', [AdminOrderDisputeController::class, 'uphold'])->name('orders.disputes.uphold');
        Route::post('/order-disputes/{id}/dismiss', [AdminOrderDisputeController::class, 'dismiss'])->name('orders.disputes.dismiss');
    });

// Public + authenticated feedback (report a problem / suggestion box)
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/feedback/problem', [FeedbackController::class, 'storeProblem'])
        ->name('feedback.problem');
    Route::post('/feedback/suggestion', [FeedbackController::class, 'storeSuggestion'])
        ->name('feedback.suggestion');
});

// ✅ Common routes for all authenticated users
Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/profile', function () {
        return view('profile.index');
    })->name('profile');

    Route::post('/profile/update', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::post('/profile/password', [ProfileController::class, 'password'])
        ->name('profile.password');

    // ✅ ADD THESE TWO
    Route::post('/profile/social', [ProfileController::class, 'social'])
        ->name('profile.social');

    Route::post('/profile/billing', [ProfileController::class, 'billing'])
        ->name('profile.billing');

    Route::get('/profile/notifications', [NotificationPreferenceController::class, 'edit'])
        ->name('profile.notifications');
    Route::post('/profile/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('profile.notifications.update');

    // A claimer's own ownership claims (visible to advertisers and publishers alike).
    Route::get('/site-claims', [SiteClaimController::class, 'index'])
        ->name('site-claims.index');

    // Chat routes
    Route::prefix('chat')->group(function () {
        Route::get('/unread-summary', [ChatController::class, 'unreadSummary'])->name('chat.unread-summary');
        Route::get('/messages/{orderId}', [ChatController::class, 'getMessages'])->name('chat.messages');
        Route::post('/send/{orderId}', [ChatController::class, 'sendMessage'])
            ->middleware('throttle:30,1')
            ->name('chat.send');
        // Image upload disabled — orphan public-disk uploads without message binding.
        // Route::post('/upload-image', [ChatImageController::class, 'upload'])->name('chat.upload-image');
        // ChatImageController left in place but unused.
    });

    // In-app notification center (does not affect email notifications)
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/all', [NotificationController::class, 'all'])->name('all');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{id}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('/{id}/unread', [NotificationController::class, 'markUnread'])->name('unread');
        Route::post('/{id}/archive', [NotificationController::class, 'archive'])->name('archive');
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::get('/order/{orderId}/timeline', [NotificationController::class, 'orderTimeline'])->name('order-timeline');
    });

});

// ✅ Advertiser - Routes for managing campaigns, catalog, and projects
Route::middleware(['auth', 'verified', RoleMiddleware::class.':advertiser'])
    ->prefix('advertiser')->name('advertiser.')
    ->group(function () {

        Route::get('/dashboard', [AdvertiserDashboardController::class, 'index'])->name('dashboard');

        // Spending history chart
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
        Route::get('/analytics/export.csv', [AnalyticsController::class, 'exportCsv'])->name('analytics.export-csv');
        Route::get('/analytics/export.pdf', [AnalyticsController::class, 'exportPdf'])->name('analytics.export-pdf');
        Route::post('/analytics/budget', [AnalyticsController::class, 'saveBudget'])
            ->middleware('throttle:20,1')
            ->name('analytics.budget');

        // Balance / wallet routes
        Route::get('/balance', [App\Http\Controllers\Advertiser\BalanceController::class, 'index'])->name('balance');
        Route::post('/balance/transfer', [App\Http\Controllers\Advertiser\BalanceController::class, 'transferToPublisher'])
            ->middleware('throttle:10,1')
            ->name('balance.transfer');
        Route::get('/balance/history', [App\Http\Controllers\Advertiser\BalanceController::class, 'getTransferHistory'])->name('balance.history');
        Route::get('/balance/transactions', [App\Http\Controllers\Advertiser\BalanceController::class, 'transactions'])->name('balance.transactions');
        Route::get('/balance/transactions/{source}/{id}', [App\Http\Controllers\Advertiser\BalanceController::class, 'transactionShow'])->name('balance.transactions.show');
        Route::get('/balance/analytics', [App\Http\Controllers\Advertiser\BalanceController::class, 'analytics'])->name('balance.analytics');
        Route::get('/balance/export', [App\Http\Controllers\Advertiser\BalanceController::class, 'export'])->name('balance.export');
        Route::post('/balance/withdraw', [App\Http\Controllers\Advertiser\BalanceController::class, 'requestWithdrawal'])
            ->middleware('throttle:10,1')
            ->name('balance.withdraw');

        // Campaigns (orphaned UI) — redirect to dashboard until product ships nav entry
        Route::get('/campaigns', function () {
            return redirect()->route('advertiser.dashboard');
        })->name('campaigns');

        // Place a guest post wizard (market → publishers → content → pay)
        Route::get('/place-guest-post', [GuestPostWizardController::class, 'start'])
            ->name('wizard.start');
        Route::get('/place-guest-post/market', [GuestPostWizardController::class, 'market'])
            ->name('wizard.market');
        Route::post('/place-guest-post/market', [GuestPostWizardController::class, 'saveMarket'])
            ->name('wizard.market.save');
        Route::get('/place-guest-post/publishers', [GuestPostWizardController::class, 'publishers'])
            ->name('wizard.publishers');
        Route::get('/place-guest-post/content', [GuestPostWizardController::class, 'content'])
            ->name('wizard.content');
        Route::get('/place-guest-post/pay', [GuestPostWizardController::class, 'pay'])
            ->name('wizard.pay');
        Route::post('/place-guest-post/exit', [GuestPostWizardController::class, 'exit'])
            ->name('wizard.exit');

        // Catelog routes
        Route::get('/catalog', [CatalogController::class, 'index'])
            ->name('catalog');

        // Typeahead for the main search box — JSON only, never a full page.
        Route::get('/catalog/suggest', [CatalogController::class, 'suggest'])
            ->middleware('throttle:60,1')
            ->name('catalog.suggest');

        // Live search / filter results fragment (HTML partial, same query as index).
        Route::get('/catalog/results', [CatalogController::class, 'results'])
            ->middleware('throttle:120,1')
            ->name('catalog.results');

        // Bulk deals rail fragment — follows country= like the listing (Option 1).
        Route::get('/catalog/bulk-deals', [CatalogController::class, 'bulkDeals'])
            ->middleware('throttle:120,1')
            ->name('catalog.bulk-deals');

        // One publisher domain per request. Throttled on top of the daily
        // allowance so a script cannot burn a funded account's unlimited quota
        // faster than a person could click.
        Route::post('/catalog/sites/{site}/reveal-url', SiteUrlRevealController::class)
            ->middleware('throttle:120,1')
            ->name('catalog.reveal-url');

        // Hide sticks across reloads; the disclosure row is kept for audit/pace.
        Route::post('/catalog/sites/{site}/hide-url', SiteUrlConcealController::class)
            ->middleware('throttle:120,1')
            ->name('catalog.hide-url');

        // Clipboard copies of URL/domain identity → strike ladder (warn → 24h hide).
        Route::post('/catalog/copy-track', CatalogCopyTrackController::class)
            ->middleware('throttle:180,1')
            ->name('catalog.copy-track');

        // Opening a site goes through us so the listing can offer "Open site"
        // without the domain ever appearing in the page.
        Route::get('/go/{site}', SiteVisitController::class)
            ->middleware('throttle:120,1')
            ->name('catalog.visit');

        // Suggest a website missing from the catalog
        Route::post('/website-suggestions', [WebsiteSuggestionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('website-suggestions.store');

        // Claim ownership of a catalog listing (shown per site in catalog UI)
        Route::post('/sites/claim', [SiteClaimController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('sites.claim');

        // Favorites
        Route::post('/favorites/save', [CatalogController::class, 'saveFavorites'])->name('favorites.save');

        // Blacklist
        Route::post('/blacklist/save', [CatalogController::class, 'saveBlacklist'])->name('blacklist.save');

        // Dedicated Saved Sites manager (favorites + blacklist)
        Route::get('/saved-sites', [SavedSitesController::class, 'index'])->name('saved-sites');
        Route::post('/saved-sites/favorites/remove', [SavedSitesController::class, 'removeFavorite'])
            ->name('saved-sites.favorites.remove');
        Route::post('/saved-sites/blacklist/remove', [SavedSitesController::class, 'removeBlacklist'])
            ->name('saved-sites.blacklist.remove');
        Route::post('/saved-sites/move/blacklist', [SavedSitesController::class, 'moveToBlacklist'])
            ->name('saved-sites.move.blacklist');
        Route::post('/saved-sites/move/favorites', [SavedSitesController::class, 'moveToFavorites'])
            ->name('saved-sites.move.favorites');

        // Publisher site ratings — only after order approval/completion
        Route::post('/ratings', [App\Http\Controllers\Advertiser\SiteRatingController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('ratings.store');
        Route::post('/ratings/batch', [App\Http\Controllers\Advertiser\SiteRatingController::class, 'storeBatch'])
            ->middleware('throttle:20,1')
            ->name('ratings.batch');

        // Cart (Session)
        Route::post('/cart/save', [CatalogController::class, 'saveCart'])->name('cart.save');
        Route::get('/cart/get', [CatalogController::class, 'getCart'])->name('cart.get');
        Route::get('/cart/count', [CatalogController::class, 'getCartCount'])->name('cart.count');
        Route::post('/cart/add', [CatalogController::class, 'addToCart'])->name('cart.add');
        Route::post('/cart/assign-article', [CatalogController::class, 'assignCartArticle'])
            ->name('cart.assign-article');
        Route::post('/cart/remove', [CatalogController::class, 'removeFromCart'])->name('cart.remove');
        Route::post('/cart/update', [CatalogController::class, 'updateCartQuantity'])->name('cart.update');
        Route::post('/cart/clear', [CatalogController::class, 'clearCart'])->name('cart.clear');

        // Checkout routes
        Route::get('/checkout', [CatalogController::class, 'checkout'])->name('checkout');
        Route::post('/checkout/schedule', [CatalogController::class, 'saveCheckoutSchedule'])
            ->middleware('throttle:30,1')
            ->name('checkout.schedule');
        // IMPORTANT: This route accepts both POST (create order) and GET (Stripe callback)
        Route::match(['get', 'post'], '/checkout/process', [CatalogController::class, 'processOrder'])->name('checkout.process');

        // Legacy Google Docs scan (kept for admin/tools; checkout uses native uploads)
        Route::post('/content-moderation/scan', [AdvertiserContentModerationController::class, 'scan'])
            ->middleware('throttle:30,1')
            ->name('content-moderation.scan');

        // Content Library (upload → evaluate → select sites → order)
        Route::get('/content-library', [ContentLibraryController::class, 'index'])
            ->name('content-library');
        Route::get('/content-library/results', [ContentLibraryController::class, 'results'])
            ->name('content-library.results');
        Route::post('/content-library/upload', [ContentLibraryController::class, 'upload'])
            ->middleware('throttle:30,1')
            ->name('content-library.upload');
        Route::get('/content-library/{submission}/order', [ContentLibraryController::class, 'orderInCatalog'])
            ->name('content-library.order');
        Route::post('/content-library/order', [ContentLibraryController::class, 'orderInCatalog'])
            ->name('content-library.order.post');

        // Native content upload workflow
        Route::get('/content-submissions/config', [ContentSubmissionController::class, 'config'])
            ->name('content-submissions.config');
        Route::get('/content-submissions/drafts', [ContentSubmissionController::class, 'drafts'])
            ->name('content-submissions.drafts');
        Route::post('/content-submissions/upload', [ContentSubmissionController::class, 'upload'])
            ->middleware('throttle:30,1')
            ->name('content-submissions.upload');
        Route::patch('/content-submissions/{submission}', [ContentSubmissionController::class, 'updateDraft'])
            ->name('content-submissions.update');
        Route::put('/content-submissions/{submission}/content', [ContentSubmissionController::class, 'updateContent'])
            ->name('content-submissions.content');
        Route::post('/content-submissions/editor-image', [ContentSubmissionController::class, 'uploadEditorImage'])
            ->middleware('throttle:30,1')
            ->name('content-submissions.editor-image');
        Route::get('/content-submissions/{submission}/preview', [ContentSubmissionController::class, 'preview'])
            ->name('content-submissions.preview');
        Route::get('/content-submissions/{submission}/download', [ContentSubmissionController::class, 'download'])
            ->name('content-submissions.download');
        Route::delete('/content-submissions/{submission}', [ContentSubmissionController::class, 'destroy'])
            ->name('content-submissions.destroy');
        Route::post('/content-submissions/{submission}/archive', [ContentSubmissionController::class, 'archive'])
            ->name('content-submissions.archive');
        Route::post('/content-submissions/{submission}/restore', [ContentSubmissionController::class, 'restore'])
            ->name('content-submissions.restore');

        Route::get('/scheduled-orders', [ScheduledOrdersController::class, 'index'])
            ->name('scheduled-orders');
        Route::post('/scheduled-orders/{order}', [ScheduledOrdersController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('scheduled-orders.update');

        // PROJECTS CRUD routes
        Route::post('/projects', [ProjectController::class, 'store'])
            ->name('projects.store');

        Route::get('/projects', [ProjectController::class, 'index'])
            ->name('projects.index');

        Route::put('/projects/{project}', [ProjectController::class, 'update'])
            ->name('projects.update');

        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])
            ->name('projects.destroy');

        // Orders
        Route::get('/orders', [CatalogController::class, 'orders'])->name('orders');
        Route::get('/orders/list', [CatalogController::class, 'getOrders'])->name('orders.list');
        Route::get('/orders/statistics', [CatalogController::class, 'getOrderStatistics'])->name('orders.statistics');
        Route::get('/orders/{id}', [CatalogController::class, 'getOrder'])->name('orders.get');

        // Order actions
        Route::post('/orders/{id}/approve', [CatalogController::class, 'approveOrder'])->name('orders.approve');
        Route::post('/orders/{id}/request-modification', [CatalogController::class, 'requestModification'])->name('order.modification');
        Route::post('/orders/{id}/fulfill-content-revision', [CatalogController::class, 'fulfillContentRevision'])
            ->middleware('throttle:20,1')
            ->name('orders.fulfill-content-revision');
        Route::get('/orders/{id}/content-revision-options', [CatalogController::class, 'contentRevisionLibraryOptions'])
            ->middleware('throttle:60,1')
            ->name('orders.content-revision-options');
        Route::post('/orders/{id}/retry-payment', [CatalogController::class, 'retryPayment'])->name('orders.retry-payment');
        Route::post('/orders/{id}/recheck-live-url', [CatalogController::class, 'recheckLiveUrl'])->name('orders.recheck-live-url');
        Route::post('/orders/{id}/report-link-removed', [CatalogController::class, 'reportLinkRemoved'])->name('orders.report-link-removed');

        // OTHER PAGES
        Route::get('/add-funds', [AddFundsController::class, 'index'])->name('add-funds');
        Route::post('/add-funds', [AddFundsController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('add-funds.store');
        Route::get('/add-funds/wise-qr', [AddFundsController::class, 'wiseQr'])
            ->middleware('throttle:60,1')
            ->name('add-funds.wise-qr');
        Route::get('/add-funds/status/{id}', [AddFundsController::class, 'getStatus'])->name('add-funds.status');
        Route::post('/add-funds/{deposit}/mark-paid', [AddFundsController::class, 'markPaid'])
            ->middleware('throttle:10,1')
            ->name('add-funds.mark-paid');

        // Saved cards (Stripe Customer + PaymentMethods)
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::post('/payment-methods/setup', [PaymentMethodController::class, 'createSetupSession'])->name('payment-methods.setup');
        Route::get('/payment-methods/setup-success', [PaymentMethodController::class, 'setupSuccess'])->name('payment-methods.setup-success');
        Route::post('/payment-methods/{paymentMethodId}/default', [PaymentMethodController::class, 'setDefault'])->name('payment-methods.default');
        Route::delete('/payment-methods/{paymentMethodId}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');

        // Stripe Checkout routes
        Route::post('/create-checkout-session', [AddFundsController::class, 'createCheckoutSession'])
            ->middleware('throttle:10,1')
            ->name('create-checkout-session');
        Route::get('/checkout-success', [AddFundsController::class, 'checkoutSuccess'])->name('checkout.success');
        Route::post('/add-funds/pay-saved-card', [AddFundsController::class, 'payWithSavedCard'])
            ->middleware('throttle:10,1')
            ->name('add-funds.pay-saved-card');

        // Order payment with Stripe (legacy alias → same as checkout.process)
        Route::post('/create-order-payment', [CatalogController::class, 'processOrder'])
            ->middleware('throttle:12,1')
            ->name('create-order-payment');

        // Reports
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
        Route::get('/reports/statistics', [ReportsController::class, 'getStatistics'])->name('reports.statistics');
        Route::get('/reports/funds', [ReportsController::class, 'getFundsActivity'])->name('reports.funds');
        Route::get('/reports/orders', [ReportsController::class, 'getOrderReport'])->name('reports.orders');

        // Route::get('/reports/funds-data', [ReportsController::class, 'getFundsActivity'])->name('reports.funds');
        // Route::get('/reports/orders-data', [ReportsController::class, 'getOrderReport'])->name('reports.orders');

        // Invoice route
        Route::get('/invoice/{referenceCode}', [InvoiceController::class, 'showInvoice'])->name('invoice');

        // Billing & Invoices (automated PDF invoices / receipts)
        Route::get('/billing', [AdvertiserBillingController::class, 'index'])->name('billing.index');
        Route::get('/billing/invoices/{invoice}', [AdvertiserBillingController::class, 'show'])->name('billing.show');
        Route::get('/billing/invoices/{invoice}/download', [AdvertiserBillingController::class, 'download'])->name('billing.download');
        Route::get('/billing/invoices/{invoice}/view', [AdvertiserBillingController::class, 'viewPdf'])->name('billing.view');

        // Save billing info route
        Route::post('/save-billing-info', [AddFundsController::class, 'saveBillingInfo'])->name('save-billing-info');

        // Get billing info route
        Route::get('/get-billing-info', [AddFundsController::class, 'getBillingInfo'])->name('get-billing-info');

    });

// ✅ Publisher
Route::middleware(['auth', 'verified', RoleMiddleware::class.':publisher'])
    ->prefix('publisher')->name('publisher.')
    ->group(function () {

        // Balance
        Route::get('/balance', [BalanceController::class, 'index'])->name('balance');
        Route::post('/balance/transfer', [BalanceController::class, 'transferToAdvertiser'])
            ->middleware('throttle:10,1')
            ->name('balance.transfer');
        Route::get('/balance/history', [BalanceController::class, 'getTransferHistory'])->name('balance.history');

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/statistics', [DashboardController::class, 'getStatistics'])->name('dashboard.statistics');
        Route::get('/dashboard/recent-orders', [DashboardController::class, 'getRecentOrders'])->name('dashboard.recent');
        Route::get('/dashboard/weekly-earnings', [DashboardController::class, 'getWeeklyEarnings'])->name('dashboard.weekly-earnings');
        Route::get('/dashboard/order-status', [DashboardController::class, 'getOrderStatusDistribution'])->name('dashboard.order-status');
        Route::get('/dashboard/monthly-earnings', [DashboardController::class, 'getMonthlyEarnings'])->name('dashboard.monthly-earnings');

        // Websites Management
        Route::get('/websites', [SiteController::class, 'index'])->name('websites');
        Route::post('/websites/store', [SiteController::class, 'store'])->name('sites.store');
        Route::get('/websites/ajax', [SiteController::class, 'ajax'])->name('sites.ajax');
        Route::get('/websites/bulk-template', [SiteController::class, 'bulkTemplate'])
            ->name('sites.bulk-template');
        Route::post('/websites/bulk-import', [SiteController::class, 'bulkImport'])
            ->middleware('throttle:5,1')
            ->name('sites.bulk-import');
        Route::post('/websites/bulk-request', [PublisherBulkSiteRequestController::class, 'store'])->name('bulk-sites.request');
        Route::get('/websites/bulk-complete', [PublisherBulkSiteRequestController::class, 'completeIndex'])->name('bulk-sites.complete');
        Route::post('/websites/bulk-complete/{id}', [PublisherBulkSiteRequestController::class, 'completeStore'])->name('bulk-sites.complete.store');
        Route::get('/websites/bulk-review', [PublisherBulkSiteRequestController::class, 'reviewIndex'])->name('bulk-sites.review');
        Route::post('/websites/bulk-review/submit', [PublisherBulkSiteRequestController::class, 'submitForReview'])->name('bulk-sites.review.submit');
        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('/sites/{id}/edit-data', [SiteController::class, 'editData'])->name('sites.edit-data');
        Route::put('/sites/{id}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('/sites/{id}', [SiteController::class, 'destroy'])->name('sites.destroy');
        Route::post('/sites/{id}/archive', [SiteController::class, 'archive'])->name('sites.archive');
        Route::post('/sites/{id}/unarchive', [SiteController::class, 'unarchive'])->name('sites.unarchive');
        Route::post('/sites/{id}/accept-assignment', [SiteController::class, 'acceptAssignment'])
            ->middleware('throttle:30,1')
            ->name('sites.accept-assignment');
        Route::post('/sites/{id}/reject-assignment', [SiteController::class, 'rejectAssignment'])
            ->middleware('throttle:30,1')
            ->name('sites.reject-assignment');
        Route::get('/countries/{country}/languages', [SiteController::class, 'getCountryLanguages'])->name('countries.languages');

        // Site promotions: feature, bulk discount, timed custom discount
        Route::get('/promotions/wallet', [SitePromotionController::class, 'walletSummary'])
            ->name('promotions.wallet');
        Route::post('/sites/{id}/feature', [SitePromotionController::class, 'feature'])
            ->name('sites.feature');
        Route::post('/sites/{id}/feature/checkout', [SitePromotionController::class, 'featureCheckout'])
            ->middleware('throttle:10,1')
            ->name('sites.feature.checkout');
        Route::get('/sites/{id}/feature/success', [SitePromotionController::class, 'featureSuccess'])
            ->name('sites.feature.success');
        Route::post('/sites/{id}/bulk-discount', [SitePromotionController::class, 'joinBulk'])
            ->name('sites.bulk-join');
        Route::delete('/sites/{id}/bulk-discount', [SitePromotionController::class, 'leaveBulk'])
            ->name('sites.bulk-leave');
        Route::post('/sites/{id}/discount', [SitePromotionController::class, 'setDiscount'])
            ->name('sites.discount');
        Route::delete('/sites/{id}/discount', [SitePromotionController::class, 'clearDiscount'])
            ->name('sites.discount.clear');
        Route::post('/sites/claim', [SiteClaimController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('sites.claim');
        Route::post('/sites/{id}/verification/start', [SiteVerificationController::class, 'start'])
            ->middleware('throttle:20,1')
            ->name('sites.verification.start');
        Route::post('/sites/{id}/verification/check', [SiteVerificationController::class, 'check'])
            ->middleware('throttle:20,1')
            ->name('sites.verification.check');

        // Tasks / Orders
        Route::get('/tasks', [OrderController::class, 'index'])->name('tasks');
        Route::get('/orders/data', [OrderController::class, 'getOrders'])->name('orders.data');
        Route::get('/orders/locate', [OrderController::class, 'locateOrderItem'])->name('orders.locate');
        Route::get('/orders/statistics', [OrderController::class, 'getStatistics'])->name('orders.statistics');
        Route::get('/orders/{id}/details', [OrderController::class, 'getOrderDetails'])->name('orders.details');
        Route::post('/orders/{id}/accept', [OrderController::class, 'acceptOrder'])->name('orders.accept');
        Route::post('/orders/{id}/reject', [OrderController::class, 'rejectOrder'])->name('orders.reject');
        Route::post('/orders/{id}/request-content-revision', [OrderController::class, 'requestContentRevision'])
            ->middleware('throttle:20,1')
            ->name('orders.request-content-revision');
        Route::post('/orders/{id}/complete', [OrderController::class, 'submitLiveUrl'])->name('orders.complete');
        Route::post('/orders/{id}/resubmit', [OrderController::class, 'resubmitLiveUrl'])->name('orders.resubmit');
        Route::post('/orders/{id}/social-posts', [OrderController::class, 'updateSocialPostUrls'])->name('orders.social-posts');
        Route::post('/orders/{id}/revision-fixed', [OrderController::class, 'markRevisionFixed'])->name('orders.revision-fixed');
        Route::get('/content/{submission}/download', [OrderController::class, 'downloadContent'])
            ->name('content.download');

        // Withdraw
        Route::get('/withdraw', [WithdrawalController::class, 'index'])->name('withdraw');
        Route::post('/withdraw/request', [WithdrawalController::class, 'requestWithdrawal'])
            ->middleware('throttle:5,1')
            ->name('withdraw.request');
        Route::get('/withdrawals/history', [WithdrawalController::class, 'getHistory'])->name('withdrawals.history');
        Route::get('/withdrawals/statistics', [WithdrawalController::class, 'getStatistics'])->name('withdrawals.statistics');
        Route::post('/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancelWithdrawal'])
            ->middleware('throttle:10,1')
            ->name('withdrawals.cancel');

        // Payout documents (completed withdrawal statements)
        Route::get('/billing', [PublisherBillingController::class, 'index'])->name('billing.index');
        Route::get('/billing/documents/{invoice}', [PublisherBillingController::class, 'show'])->name('billing.show');
        Route::get('/billing/documents/{invoice}/download', [PublisherBillingController::class, 'download'])->name('billing.download');
        Route::get('/billing/documents/{invoice}/view', [PublisherBillingController::class, 'viewPdf'])->name('billing.view');

        // Reports
        Route::get('/reports', [PublisherReportsController::class, 'index'])->name('reports');
        Route::get('/reports/statistics', [PublisherReportsController::class, 'getStatistics'])->name('reports.statistics');
        Route::get('/reports/orders', [PublisherReportsController::class, 'getOrders'])->name('reports.orders');
        Route::get('/reports/orders/{orderItemId}/details', [PublisherReportsController::class, 'getOrderDetails'])->name('reports.order.details');
        Route::get('/reports/withdrawals', [PublisherReportsController::class, 'getWithdrawals'])->name('reports.withdrawals');
    });
