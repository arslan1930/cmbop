<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\AdBannerController as AdminAdBannerController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\AudienceController as AdminAudienceController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\CommunityFeedbackController;
use App\Http\Controllers\Admin\ContentModerationController as AdminContentModerationController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
// Publisher and Advertiser controllers
use App\Http\Controllers\Admin\EmailCenterController as AdminEmailCenterController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Admin\SiteEnrichmentController;
use App\Http\Controllers\Admin\SiteRatingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Advertiser\AddFundsController;
use App\Http\Controllers\Advertiser\AnalyticsController;
use App\Http\Controllers\Advertiser\BillingController as AdvertiserBillingController;
use App\Http\Controllers\Advertiser\CatalogController;
use App\Http\Controllers\Advertiser\ContentLibraryController;
use App\Http\Controllers\Advertiser\ContentModerationController as AdvertiserContentModerationController;
use App\Http\Controllers\Advertiser\ContentSubmissionController;
use App\Http\Controllers\Advertiser\GuestPostWizardController;
use App\Http\Controllers\Advertiser\PaymentMethodController;
use App\Http\Controllers\Advertiser\ProjectController;
use App\Http\Controllers\Advertiser\ReportsController;
use App\Http\Controllers\Advertiser\SavedSitesController;
use App\Http\Controllers\Advertiser\WebsiteSuggestionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\BannerClickController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatImageController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
// BlogController for public blog pages
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Publisher\BalanceController;
use App\Http\Controllers\Publisher\DashboardController;
use App\Http\Controllers\Publisher\OrderController;
use App\Http\Controllers\Publisher\PublisherReportsController;
use App\Http\Controllers\Publisher\SiteClaimController;
use App\Http\Controllers\Publisher\SiteController;
use App\Http\Controllers\Publisher\SitePromotionController;
use App\Http\Controllers\Publisher\WithdrawalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\RoleMiddleware;
use App\Models\ContentSubmission;
use App\Models\Site;
use App\Models\User;
use App\Services\PlatformFeeService;
use App\Support\PublicI18n;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public marketing routes (multilingual: en unprefixed, de|fr|nl prefixed)
| Authenticated SaaS + login/register stay English-only.
|--------------------------------------------------------------------------
*/

// Stacked locale cleanup: /nl/fr → /nl
Route::get('/{locale}/{nested}', function ($locale, $nested) {
    $prefixed = PublicI18n::prefixed();

    if (in_array($locale, $prefixed, true) && in_array($nested, $prefixed, true)) {
        $remaining = array_slice(request()->segments(), 2);
        $newPath = $remaining ? '/'.implode('/', $remaining) : '';

        return Redirect::to('/'.$locale.$newPath, 301);
    }

    return app()->make('router')->dispatch(request());
})->where(['locale' => 'de|fr|nl', 'nested' => 'de|fr|nl']);

// Locale-prefixed auth → English auth (SaaS stays English)
Route::get('/{locale}/login', fn () => Redirect::to('/login', 301))
    ->where('locale', 'de|fr|nl')
    ->name('locale.login.redirect');
Route::get('/{locale}/register', fn () => Redirect::to('/register', 301))
    ->where('locale', 'de|fr|nl')
    ->name('locale.register.redirect');

$registerPublicMarketingRoutes = function () {
    Route::get('/', fn () => view('home'))->name('home');
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
    'where' => ['locale' => 'de|fr|nl'],
    'as' => 'locale.',
], $registerPublicMarketingRoutes);

// SEO: sitemap index + per-locale sitemaps + robots
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-{locale}.xml', [SitemapController::class, 'locale'])
    ->where('locale', 'en|de|fr|nl')
    ->name('sitemap.locale');
Route::get('/robots.txt', function () {
    $base = rtrim(config('app.url'), '/');
    $body = "User-agent: *\nAllow: /\n"
        ."Disallow: /admin/\nDisallow: /advertiser/\nDisallow: /publisher/\n"
        ."Disallow: /profile\nDisallow: /chat/\nDisallow: /notifications\n\n"
        ."Sitemap: {$base}/sitemap.xml\n";

    return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('robots');

// Ad banner click tracking (public)
Route::get('/banners/{banner}/click', BannerClickController::class)
    ->middleware('throttle:60,1')
    ->name('banners.click');

Route::get('/cron/orders-auto-approve/{key}', function ($key) {

    if ($key !== env('CRON_SECRET')) {
        abort(403);
    }

    Artisan::call('orders:auto-approve');

    return response()->json([
        'status' => 'success',
        'message' => 'Orders auto-approved',
    ]);
});

// ✅ UPDATED: Guest middleware for login/register pages
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::get('/login', [LoginController::class, 'show'])->name('login');

    // Google Social Login Routes
    Route::get('auth/google', [SocialiteController::class, 'redirectToGoogle'])->name('auth.google');
    Route::get('auth/google/callback', [SocialiteController::class, 'handleGoogleCallback'])->name('auth.google.callback');
});

// Registration routes
Route::post('/register', [RegisterController::class, 'register'])
    ->middleware('throttle:5,1'); // 5 requests per minute

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
    if (! $request->hasValidSignature()) {
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

    return redirect('/login')->with('message', 'Email verified successfully. You can now login.');
})->middleware('throttle:6,1')->name('verification.verify');

// Resend verification email (requires login)
Route::post('/email/verification-notification', function (Request $request) {
    $request->user()->sendEmailVerificationNotification();

    return back()->with('message', 'Verification link sent!');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

// ✅ NEW: Resend verification WITHOUT login (AJAX)
Route::post('/email/resend', function (Request $request) {

    $request->validate([
        'email' => 'required|email|exists:users,email',
    ]);

    $user = User::where('email', $request->email)->first();

    if ($user->hasVerifiedEmail()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Email already verified.',
        ]);
    }

    $user->sendEmailVerificationNotification();

    return response()->json([
        'status' => 'success',
        'message' => 'Verification email resent successfully.',
    ]);

})->middleware('throttle:3,1')->name('verification.resend');

// ✅ NEW: Role Switch (Dropdown) Route
Route::post('/switch-role', [RoleController::class, 'switchRole'])
    ->middleware('auth')
    ->name('switch.role');

// ✅ Admin panel (admin + marketing share the prefix; permissions split inside)
Route::middleware(['auth', 'verified', RoleMiddleware::class.':admin,marketing'])
    ->prefix('admin')->name('admin.')
    ->group(function () {

        // ---- Shared: dashboard, sites (no delete), activity logs ----
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])
            ->name('dashboard');

        Route::get('/sites', [AdminSiteController::class, 'index'])
            ->name('sites.index');
        Route::get('/users/{id}/sites', [AdminSiteController::class, 'userSites'])
            ->name('users.sites');
        Route::get('/sites/{id}/edit', [AdminSiteController::class, 'edit'])
            ->name('sites.edit');
        Route::put('/sites/{id}', [AdminSiteController::class, 'update'])
            ->name('sites.update');
        Route::post('/sites/{id}/upload-image', [AdminSiteController::class, 'uploadImage'])
            ->name('sites.upload-image');
        Route::post('/sites/{id}/verify', [AdminSiteController::class, 'verify'])
            ->name('sites.verify');
        Route::post('/sites/{id}/active', [AdminSiteController::class, 'toggleActive'])
            ->name('sites.active');

        // Publisher catalog enrichment (metrics + screenshots)
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

        // Publisher site ratings management
        Route::get('/site-ratings', [SiteRatingController::class, 'index'])
            ->name('site-ratings.index');
        Route::post('/site-ratings', [SiteRatingController::class, 'store'])
            ->name('site-ratings.store');
        Route::put('/site-ratings/{id}', [SiteRatingController::class, 'update'])
            ->name('site-ratings.update');
        Route::delete('/site-ratings/{id}', [SiteRatingController::class, 'destroy'])
            ->name('site-ratings.destroy');

        // Community: problem reports, suggestions, website suggestions, site claims
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

        // ---- Admin only: payments, orders money, users/roles, blogs, delete sites ----
        Route::middleware([RoleMiddleware::class.':admin'])->group(function () {

            Route::get('/dashboard/statistics', [AdminDashboardController::class, 'getStatistics'])
                ->name('dashboard.statistics');
            Route::get('/dashboard/trends', [AdminDashboardController::class, 'getTrends'])
                ->name('dashboard.trends');
            Route::get('/dashboard/distributions', [AdminDashboardController::class, 'getDistributions'])
                ->name('dashboard.distributions');
            Route::get('/dashboard/action-queue', [AdminDashboardController::class, 'getActionQueue'])
                ->name('dashboard.action-queue');
            Route::get('/dashboard/queue-counts', [AdminDashboardController::class, 'getQueueCounts'])
                ->name('dashboard.queue-counts');

            // Users management + role assignment
            Route::get('/users', [UserController::class, 'index'])
                ->name('users.index');
            Route::post('/users/{id}/update-company', [UserController::class, 'updateCompany'])
                ->name('users.updateCompany');
            Route::post('/users/{id}/roles', [UserController::class, 'updateRoles'])
                ->name('users.updateRoles');

            // Delete sites — admin only
            Route::delete('/sites/{id}', [AdminSiteController::class, 'destroy'])
                ->name('sites.destroy');

            // Payments / orders money
            Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments');
            Route::get('/payments/data', [AdminPaymentController::class, 'getPaymentsData'])->name('payments.data');
            Route::get('/payments/{id}', [AdminPaymentController::class, 'show'])->name('payments.show');
            Route::post('/payments/{id}/update-status', [AdminPaymentController::class, 'updatePaymentStatus'])->name('payments.updateStatus');

            // Billing invoices (PDF system — separate from payment gateway)
            Route::get('/invoices', [AdminInvoiceController::class, 'index'])->name('invoices.index');
            Route::post('/invoices/generate', [AdminInvoiceController::class, 'generate'])->name('invoices.generate');
            Route::get('/invoices/{invoice}', [AdminInvoiceController::class, 'show'])->name('invoices.show');
            Route::get('/invoices/{invoice}/download', [AdminInvoiceController::class, 'download'])->name('invoices.download');
            Route::post('/invoices/{invoice}/resend', [AdminInvoiceController::class, 'resend'])->name('invoices.resend');
            Route::post('/invoices/{invoice}/cancel', [AdminInvoiceController::class, 'cancel'])->name('invoices.cancel');

            // Deposits
            Route::get('/deposits', [AdminDepositController::class, 'index'])->name('deposits');
            Route::get('/deposits/{id}', [AdminDepositController::class, 'show'])->name('deposits.show');
            Route::post('/deposits/{id}/approve', [AdminDepositController::class, 'approve'])->name('deposits.approve');
            Route::post('/deposits/{id}/reject', [AdminDepositController::class, 'reject'])->name('deposits.reject');

            // Withdrawals
            Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals');
            Route::get('/withdrawals/data', [AdminWithdrawalController::class, 'getWithdrawalsData'])->name('admin.withdrawals.data');
            Route::get('/withdrawals/{id}', [AdminWithdrawalController::class, 'show'])->name('admin.withdrawals.show');
            Route::post('/withdrawals/{id}/status', [AdminWithdrawalController::class, 'updateStatus'])->name('admin.withdrawals.update-status');
            Route::get('/withdrawals/statistics', [AdminWithdrawalController::class, 'getStatistics'])->name('admin.withdrawals.statistics');

            // Blogs
            Route::resource('blogs', AdminBlogController::class);
            Route::get('blogs/{id}/toggle-status', [AdminBlogController::class, 'toggleStatus'])->name('blogs.toggle-status');
            Route::post('blogs/upload-image', [AdminBlogController::class, 'uploadImage'])->name('blogs.upload-image');

            // Email Center — manage/monitor emails without changing send flows
            Route::get('/emails', [AdminEmailCenterController::class, 'index'])->name('emails.index');
            Route::get('/emails/preview/{key}', [AdminEmailCenterController::class, 'preview'])->name('emails.preview');
            Route::post('/emails/test', [AdminEmailCenterController::class, 'sendTest'])->name('emails.test');
            Route::post('/emails/retry', [AdminEmailCenterController::class, 'retryFailed'])->name('emails.retry');
            Route::post('/emails/settings', [AdminEmailCenterController::class, 'updateSettings'])->name('emails.settings');

            // Promotions Center — announcements + sized ad banners
            Route::get('/promotions', [AdminPromotionController::class, 'index'])->name('promotions.index');
            Route::prefix('promotions')->name('promotions.')->group(function () {
                Route::resource('announcements', AdminAnnouncementController::class)->except(['show']);
                Route::post('announcements/{announcement}/toggle', [AdminAnnouncementController::class, 'toggle'])
                    ->name('announcements.toggle');

                Route::resource('banners', AdminAdBannerController::class)->except(['show']);
                Route::post('banners/{banner}/toggle', [AdminAdBannerController::class, 'toggle'])
                    ->name('banners.toggle');
            });

            // Audience inventory (Advertisers / Publishers) + email campaigns
            Route::get('/audiences', [AdminAudienceController::class, 'index'])->name('audiences.index');
            Route::get('/audiences/export', [AdminAudienceController::class, 'export'])->name('audiences.export');
            Route::get('/campaigns', [AdminCampaignController::class, 'index'])->name('campaigns.index');
            Route::post('/campaigns/preview', [AdminCampaignController::class, 'preview'])->name('campaigns.preview');
            Route::post('/campaigns/send', [AdminCampaignController::class, 'send'])->name('campaigns.send');

            // Content compliance & moderation
            Route::get('/moderation', [AdminContentModerationController::class, 'index'])->name('moderation.index');
            Route::post('/moderation/settings', [AdminContentModerationController::class, 'updateSettings'])->name('moderation.settings');
            Route::post('/moderation/logs/{log}/override', [AdminContentModerationController::class, 'override'])->name('moderation.override');

            Route::get('/reports', function () {
                return view('admin.reports');
            })->name('reports');

            Route::get('/settings', function () {
                return view('admin.settings');
            })->name('settings');
        });
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

    // Chat routes
    Route::prefix('chat')->group(function () {
        Route::get('/unread-summary', [ChatController::class, 'unreadSummary'])->name('chat.unread-summary');
        Route::get('/messages/{orderId}', [ChatController::class, 'getMessages'])->name('chat.messages');
        Route::post('/send/{orderId}', [ChatController::class, 'sendMessage'])->name('chat.send');
        Route::post('/upload-image', [ChatImageController::class, 'upload'])->name('chat.upload-image');

    });

    // In-app notification center (does not affect email notifications)
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/all', [NotificationController::class, 'all'])->name('all');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{id}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('/{id}/archive', [NotificationController::class, 'archive'])->name('archive');
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::get('/order/{orderId}/timeline', [NotificationController::class, 'orderTimeline'])->name('order-timeline');
    });

});

// ✅ Advertiser - Routes for managing campaigns, catalog, and projects
Route::middleware(['auth', 'verified', RoleMiddleware::class.':advertiser'])
    ->prefix('advertiser')->name('advertiser.')
    ->group(function () {

        Route::get('/dashboard', function () {
            $user = auth()->user();
            $orders = $user->orders();

            $stats = [
                'total' => (clone $orders)->count(),
                'completed' => (clone $orders)->where('status', 'completed')->count(),
                'in_progress' => (clone $orders)->whereIn('status', ['pending', 'processing', 'review'])->count(),
                'cancelled' => (clone $orders)->where('status', 'cancelled')->count(),
            ];

            $recentOrders = $user->orders()
                ->with(['items' => function ($q) {
                    $q->select('id', 'order_id', 'site_name', 'site_url');
                }])
                ->latest()
                ->take(5)
                ->get();

            // Recommended placements for the advertiser's next buy (CV1)
            $recommendedSites = Site::query()
                ->where('active', 1)
                ->where(function ($q) {
                    $q->where('verified', 1)->orWhere('verified', true);
                })
                ->orderByDesc('dr')
                ->orderByDesc('traffic')
                ->take(3)
                ->get()
                ->map(function ($site) {
                    $site->display_price = app(PlatformFeeService::class)
                        ->advertiserBase((float) $site->price);

                    return $site;
                });

            $hasOrderableArticle = ContentSubmission::query()
                ->where('user_id', $user->id)
                ->whereNull('order_id')
                ->whereNull('archived_at')
                ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
                ->latest('id')
                ->limit(20)
                ->get()
                ->contains(fn (ContentSubmission $s) => $s->canBeOrdered());

            return view('advertiser.dashboard', compact(
                'stats',
                'recentOrders',
                'recommendedSites',
                'hasOrderableArticle'
            ));
        })->name('dashboard');

        // Spending history chart
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');

        // Balance / wallet routes
        Route::get('/balance', [App\Http\Controllers\Advertiser\BalanceController::class, 'index'])->name('balance');
        Route::post('/balance/transfer', [App\Http\Controllers\Advertiser\BalanceController::class, 'transferToPublisher'])->name('balance.transfer');
        Route::get('/balance/history', [App\Http\Controllers\Advertiser\BalanceController::class, 'getTransferHistory'])->name('balance.history');
        Route::get('/balance/transactions', [App\Http\Controllers\Advertiser\BalanceController::class, 'transactions'])->name('balance.transactions');
        Route::get('/balance/transactions/{source}/{id}', [App\Http\Controllers\Advertiser\BalanceController::class, 'transactionShow'])->name('balance.transactions.show');
        Route::get('/balance/analytics', [App\Http\Controllers\Advertiser\BalanceController::class, 'analytics'])->name('balance.analytics');
        Route::get('/balance/export', [App\Http\Controllers\Advertiser\BalanceController::class, 'export'])->name('balance.export');
        Route::post('/balance/withdraw', [App\Http\Controllers\Advertiser\BalanceController::class, 'requestWithdrawal'])->name('balance.withdraw');

        // Campaigns routes
        Route::get('/campaigns', [ProjectController::class, 'index'])
            ->name('campaigns');

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

        // Suggest a website missing from the catalog
        Route::post('/website-suggestions', [WebsiteSuggestionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('website-suggestions.store');

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
        // IMPORTANT: This route accepts both POST (create order) and GET (Stripe callback)
        Route::match(['get', 'post'], '/checkout/process', [CatalogController::class, 'processOrder'])->name('checkout.process');

        // Legacy Google Docs scan (kept for admin/tools; checkout uses native uploads)
        Route::post('/content-moderation/scan', [AdvertiserContentModerationController::class, 'scan'])
            ->middleware('throttle:30,1')
            ->name('content-moderation.scan');

        // Content Library (upload → evaluate → select sites → order)
        Route::get('/content-library', [ContentLibraryController::class, 'index'])
            ->name('content-library');
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

        Route::get('/scheduled-orders', [ContentSubmissionController::class, 'scheduledOrders'])
            ->name('scheduled-orders');
        Route::post('/scheduled-orders/{order}', [ContentSubmissionController::class, 'updateSchedule'])
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
        Route::get('/orders/{id}', [CatalogController::class, 'getOrder'])->name('orders.get');

        // Order actions
        Route::post('/orders/{id}/approve', [CatalogController::class, 'approveOrder'])->name('orders.approve');
        Route::post('/orders/{id}/request-modification', [CatalogController::class, 'requestModification'])->name('order.modification');
        Route::post('/orders/{id}/retry-payment', [CatalogController::class, 'retryPayment'])->name('orders.retry-payment');
        Route::post('/orders/{id}/recheck-live-url', [CatalogController::class, 'recheckLiveUrl'])->name('orders.recheck-live-url');

        // OTHER PAGES
        Route::get('/add-funds', [AddFundsController::class, 'index'])->name('add-funds');
        Route::post('/add-funds', [AddFundsController::class, 'store'])->name('add-funds.store');
        Route::get('/add-funds/status/{id}', [AddFundsController::class, 'getStatus'])->name('add-funds.status');
        Route::post('/add-funds/{deposit}/mark-paid', [AddFundsController::class, 'markPaid'])->name('add-funds.mark-paid');

        // Saved cards (Stripe Customer + PaymentMethods)
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::post('/payment-methods/setup', [PaymentMethodController::class, 'createSetupSession'])->name('payment-methods.setup');
        Route::get('/payment-methods/setup-success', [PaymentMethodController::class, 'setupSuccess'])->name('payment-methods.setup-success');
        Route::post('/payment-methods/{paymentMethodId}/default', [PaymentMethodController::class, 'setDefault'])->name('payment-methods.default');
        Route::delete('/payment-methods/{paymentMethodId}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');

        // Stripe Checkout routes
        Route::post('/create-checkout-session', [AddFundsController::class, 'createCheckoutSession'])->name('create-checkout-session');
        Route::get('/checkout-success', [AddFundsController::class, 'checkoutSuccess'])->name('checkout.success');
        Route::post('/add-funds/pay-saved-card', [AddFundsController::class, 'payWithSavedCard'])->name('add-funds.pay-saved-card');

        // Order payment with Stripe
        Route::post('/create-order-payment', [CatalogController::class, 'createOrderPayment'])->name('create-order-payment');

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
        Route::post('/balance/transfer', [BalanceController::class, 'transferToAdvertiser'])->name('balance.transfer');
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
        Route::get('/websites/bulk-template', [SiteController::class, 'bulkTemplate'])->name('sites.bulk-template');
        Route::post('/websites/bulk-store', [SiteController::class, 'bulkStore'])->name('sites.bulk-store');
        Route::post('/websites/bulk-import', [SiteController::class, 'bulkImport'])->name('sites.bulk-import');
        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::put('/sites/{id}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('/sites/{id}', [SiteController::class, 'destroy'])->name('sites.destroy');
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

        // Tasks / Orders
        Route::get('/tasks', [OrderController::class, 'index'])->name('tasks');
        Route::get('/orders/data', [OrderController::class, 'getOrders'])->name('orders.data');
        Route::get('/orders/statistics', [OrderController::class, 'getStatistics'])->name('orders.statistics');
        Route::get('/orders/{id}/details', [OrderController::class, 'getOrderDetails'])->name('orders.details');
        Route::post('/orders/{id}/accept', [OrderController::class, 'acceptOrder'])->name('orders.accept');
        Route::post('/orders/{id}/reject', [OrderController::class, 'rejectOrder'])->name('orders.reject');
        Route::post('/orders/{id}/complete', [OrderController::class, 'submitLiveUrl'])->name('orders.complete');
        Route::post('/orders/{id}/resubmit', [OrderController::class, 'resubmitLiveUrl'])->name('orders.resubmit');
        Route::get('/content/{submission}/download', [OrderController::class, 'downloadContent'])
            ->name('content.download');

        // Withdraw
        Route::get('/withdraw', [WithdrawalController::class, 'index'])->name('withdraw');
        Route::post('/withdraw/request', [WithdrawalController::class, 'requestWithdrawal'])->name('withdraw.request');
        Route::get('/withdrawals/history', [WithdrawalController::class, 'getHistory'])->name('withdrawals.history');
        Route::get('/withdrawals/statistics', [WithdrawalController::class, 'getStatistics'])->name('withdrawals.statistics');
        Route::post('/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancelWithdrawal'])->name('withdrawals.cancel');

        // Reports
        Route::get('/reports', [PublisherReportsController::class, 'index'])->name('reports');
        Route::get('/reports/statistics', [PublisherReportsController::class, 'getStatistics'])->name('reports.statistics');
        Route::get('/reports/orders', [PublisherReportsController::class, 'getOrders'])->name('reports.orders');
        Route::get('/reports/orders/{orderItemId}/details', [PublisherReportsController::class, 'getOrderDetails'])->name('reports.order.details');
        Route::get('/reports/withdrawals', [PublisherReportsController::class, 'getWithdrawals'])->name('reports.withdrawals');
    });
