<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\RoleController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Middleware\RoleMiddleware;
// Publisher and Advertiser controllers
use App\Http\Controllers\Publisher\SiteController;
use App\Http\Controllers\Publisher\OrderController;
use App\Http\Controllers\Publisher\WithdrawalController;
use App\Http\Controllers\Publisher\PublisherReportsController;
use App\Http\Controllers\Publisher\BalanceController;
use App\Http\Controllers\Publisher\DashboardController;


use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\EmailCenterController as AdminEmailCenterController;
use App\Http\Controllers\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\AdBannerController as AdminAdBannerController;
use App\Http\Controllers\Admin\AudienceController as AdminAudienceController;
use App\Http\Controllers\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\ContentModerationController as AdminContentModerationController;
use App\Http\Controllers\Advertiser\ContentModerationController as AdvertiserContentModerationController;
use App\Http\Controllers\Advertiser\ContentSubmissionController;
use App\Http\Controllers\Advertiser\ContentLibraryController;
use App\Http\Controllers\BannerClickController;
use App\Http\Controllers\Advertiser\ProjectController;
use App\Http\Controllers\Advertiser\CatalogController;
use App\Http\Controllers\Advertiser\AnalyticsController;
use App\Http\Controllers\Advertiser\CampaignController;
use App\Http\Controllers\Advertiser\AddFundsController;
use App\Http\Controllers\Advertiser\ReportsController;

use App\Http\Controllers\InvoiceController;
// BlogController for public blog pages
use App\Http\Controllers\BlogController;
use App\Http\Controllers\NewsletterController;

use App\Http\Controllers\Auth\SocialiteController;


/*
|--------------------------------------------------------------------------
| Public Routes with Multi-language Support
|--------------------------------------------------------------------------
*/

// Redirect if there are multiple locale segments (e.g., /nl/fr, /de/en, etc.)
Route::get('/{locale}/{nested}', function ($locale, $nested) {
    $availableLocales = ['de', 'fr', 'nl'];
    
    // If first segment is a locale and second segment is also a locale, redirect to the first one
    if (in_array($locale, $availableLocales) && in_array($nested, $availableLocales)) {
        // Get the remaining path segments
        $segments = request()->segments();
        // Remove the first two segments
        $remainingSegments = array_slice($segments, 2);
        // Build the new path
        $newPath = $remainingSegments ? '/' . implode('/', $remainingSegments) : '';
        
        return Redirect::to('/' . $locale . $newPath);
    }
    
    // Otherwise, try to match the route normally
    return app()->make('router')->dispatch(request());
})->where(['locale' => 'de|fr|nl', 'nested' => 'de|fr|nl']);

// Routes with optional locale prefix
Route::group(['prefix' => '{locale?}', 'where' => ['locale' => 'de|fr|nl']], function () {
    
    // Homepage
    Route::get('/', function () {
        return view('home');
    })->name('home');

    // Contact page
    Route::get('/contact', function () {
        return view('pages.contact');
    })->name('contact');

    // Privacy Policy
    Route::get('/privacy-policy', function () {
        return view('pages.privacy-policy');
    })->name('privacy-policy');

    // Terms of Service
    Route::get('/terms-of-services', function () {
        return view('pages.terms-of-services');
    })->name('terms-of-services');

    Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
        ->middleware('throttle:10,1')
        ->name('newsletter.subscribe');

    // Blog routes
    Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

    // Auth routes (GET only)
    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('login');
        Route::get('/register', [RegisterController::class, 'show'])->name('register');
    });
});


// Routes start 
Route::get('/', function () {
    return view('home');
});

// OTHER PAGES
Route::get('/contact', function () {
    return view('pages.contact');
});

Route::get('/privacy-policy', function () {
    return view('pages.privacy-policy');
});

Route::get('/terms-of-services', function () {
    return view('pages.terms-of-services');
});

Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
    ->middleware('throttle:10,1');

// ========== PUBLIC BLOG ROUTES ==========
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

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
        'message' => 'Orders auto-approved'
    ]);
});


// ✅ UPDATED: Guest middleware for login/register pages
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::get('/login', [LoginController::class, 'show'])->name('login');

    // Google Social Login Routes
    Route::get('auth/google', [SocialiteController::class, 'redirectToGoogle'])->name('auth.google');
    Route::get('auth/google/callback', [SocialiteController::class, 'handleGoogleCallback'])->name('auth.google.callback');

    Route::get('auth/apple', [SocialiteController::class, 'redirectToApple'])->name('auth.apple');
    Route::match(['get', 'post'], 'auth/apple/callback', [SocialiteController::class, 'handleAppleCallback'])->name('auth.apple.callback');
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

// Email verification link (no auth required, user clicks link from email)
Route::get('/email/verify/{id}/{hash}', function ($id, $hash) {

    $user = User::findOrFail($id);

    // Validate the hash matches user's email
    if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
        abort(403, 'Invalid verification link.');
    }

    // Mark as verified if not already
    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return redirect('/login')->with('message', 'Email verified successfully. You can now login.');
})->name('verification.verify');

// Resend verification email (requires login)
Route::post('/email/verification-notification', function (Request $request) {
    $request->user()->sendEmailVerificationNotification();
    return back()->with('message', 'Verification link sent!');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

// ✅ NEW: Resend verification WITHOUT login (AJAX)
Route::post('/email/resend', function (Request $request) {

    $request->validate([
        'email' => 'required|email|exists:users,email'
    ]);

    $user = User::where('email', $request->email)->first();

    if ($user->hasVerifiedEmail()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Email already verified.'
        ]);
    }

    $user->sendEmailVerificationNotification();

    return response()->json([
        'status' => 'success',
        'message' => 'Verification email resent successfully.'
    ]);

})->middleware('throttle:3,1')->name('verification.resend');


// ✅ NEW: Role Switch (Dropdown) Route
Route::post('/switch-role', [RoleController::class, 'switchRole'])
    ->middleware('auth')
    ->name('switch.role');


// ✅ Admin panel (admin + marketing share the prefix; permissions split inside)
Route::middleware(['auth','verified', RoleMiddleware::class . ':admin,marketing'])
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
        Route::get('/site-enrichment', [\App\Http\Controllers\Admin\SiteEnrichmentController::class, 'index'])
            ->name('site-enrichment.index');
        Route::post('/sites/{id}/enrich', [\App\Http\Controllers\Admin\SiteEnrichmentController::class, 'enrich'])
            ->name('sites.enrich');
        Route::post('/sites/{id}/refresh-metrics', [\App\Http\Controllers\Admin\SiteEnrichmentController::class, 'refreshMetrics'])
            ->name('sites.refresh-metrics');
        Route::post('/sites/{id}/refresh-screenshot', [\App\Http\Controllers\Admin\SiteEnrichmentController::class, 'refreshScreenshot'])
            ->name('sites.refresh-screenshot');
        Route::post('/sites/{id}/manual-metrics', [\App\Http\Controllers\Admin\SiteEnrichmentController::class, 'manualMetrics'])
            ->name('sites.manual-metrics');
        Route::post('/site-enrichment/rerun-failed', [\App\Http\Controllers\Admin\SiteEnrichmentController::class, 'rerunFailed'])
            ->name('site-enrichment.rerun-failed');

        // Publisher site ratings management
        Route::get('/site-ratings', [\App\Http\Controllers\Admin\SiteRatingController::class, 'index'])
            ->name('site-ratings.index');
        Route::post('/site-ratings', [\App\Http\Controllers\Admin\SiteRatingController::class, 'store'])
            ->name('site-ratings.store');
        Route::put('/site-ratings/{id}', [\App\Http\Controllers\Admin\SiteRatingController::class, 'update'])
            ->name('site-ratings.update');
        Route::delete('/site-ratings/{id}', [\App\Http\Controllers\Admin\SiteRatingController::class, 'destroy'])
            ->name('site-ratings.destroy');

        Route::get('/activity-logs', [AdminActivityLogController::class, 'index'])
            ->name('activity-logs.index');

        // ---- Admin only: payments, orders money, users/roles, blogs, delete sites ----
        Route::middleware([RoleMiddleware::class . ':admin'])->group(function () {

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

// ✅ Common routes for all authenticated users
Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/profile', function () {
        return view('profile.index');
    })->name('profile');

    Route::post('/profile/update', [\App\Http\Controllers\ProfileController::class, 'update'])
        ->name('profile.update');

    Route::post('/profile/password', [\App\Http\Controllers\ProfileController::class, 'password'])
        ->name('profile.password');

    // ✅ ADD THESE TWO
    Route::post('/profile/social', [\App\Http\Controllers\ProfileController::class, 'social'])
        ->name('profile.social');

    Route::post('/profile/billing', [\App\Http\Controllers\ProfileController::class, 'billing'])
        ->name('profile.billing');

    Route::get('/profile/notifications', [\App\Http\Controllers\NotificationPreferenceController::class, 'edit'])
        ->name('profile.notifications');
    Route::post('/profile/notifications', [\App\Http\Controllers\NotificationPreferenceController::class, 'update'])
        ->name('profile.notifications.update');

        // Chat routes
    Route::prefix('chat')->group(function () {
    Route::get('/unread-summary', [App\Http\Controllers\ChatController::class, 'unreadSummary'])->name('chat.unread-summary');
    Route::get('/messages/{orderId}', [App\Http\Controllers\ChatController::class, 'getMessages'])->name('chat.messages');
    Route::post('/send/{orderId}', [App\Http\Controllers\ChatController::class, 'sendMessage'])->name('chat.send');
    Route::post('/upload-image', [App\Http\Controllers\ChatImageController::class, 'upload'])->name('chat.upload-image');    
    
    });

    // In-app notification center (does not affect email notifications)
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [App\Http\Controllers\NotificationController::class, 'index'])->name('index');
        Route::get('/all', [App\Http\Controllers\NotificationController::class, 'all'])->name('all');
        Route::get('/unread-count', [App\Http\Controllers\NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [App\Http\Controllers\NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{id}/read', [App\Http\Controllers\NotificationController::class, 'markRead'])->name('read');
        Route::post('/{id}/archive', [App\Http\Controllers\NotificationController::class, 'archive'])->name('archive');
        Route::delete('/{id}', [App\Http\Controllers\NotificationController::class, 'destroy'])->name('destroy');
        Route::get('/order/{orderId}/timeline', [App\Http\Controllers\NotificationController::class, 'orderTimeline'])->name('order-timeline');
    });

});

// ✅ Advertiser - Routes for managing campaigns, catalog, and projects
Route::middleware(['auth','verified', RoleMiddleware::class . ':advertiser'])
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
            $recommendedSites = \App\Models\Site::query()
                ->where('active', 1)
                ->where(function ($q) {
                    $q->where('verified', 1)->orWhere('verified', true);
                })
                ->orderByDesc('dr')
                ->orderByDesc('traffic')
                ->take(3)
                ->get()
                ->map(function ($site) {
                    $site->display_price = round(
                        (float) $site->price * \App\Services\CartPricingService::PLATFORM_MARKUP_RATE,
                        2
                    );
                    return $site;
                });

            return view('advertiser.dashboard', compact(
                'stats',
                'recentOrders',
                'recommendedSites'
            ));
        })->name('dashboard');

        // Spending history chart
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');

        // Balance routes
        Route::get('/balance', [App\Http\Controllers\Advertiser\BalanceController::class, 'index'])->name('balance');
        Route::post('/balance/transfer', [App\Http\Controllers\Advertiser\BalanceController::class, 'transferToPublisher'])->name('balance.transfer');
        Route::get('/balance/history', [App\Http\Controllers\Advertiser\BalanceController::class, 'getTransferHistory'])->name('balance.history');

        // Campaigns routes
        Route::get('/campaigns', [ProjectController::class, 'index'])
        ->name('campaigns');

        // Catelog routes
        Route::get('/catalog', [CatalogController::class, 'index'])
        ->name('catalog');    

        // Favorites 
        Route::post('/favorites/save', [CatalogController::class, 'saveFavorites'])->name('favorites.save');
        
        // Blacklist 
        Route::post('/blacklist/save', [CatalogController::class, 'saveBlacklist'])->name('blacklist.save');

        // Publisher site ratings — only after order approval/completion
        Route::post('/ratings', [\App\Http\Controllers\Advertiser\SiteRatingController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('ratings.store');
        Route::post('/ratings/batch', [\App\Http\Controllers\Advertiser\SiteRatingController::class, 'storeBatch'])
            ->middleware('throttle:20,1')
            ->name('ratings.batch');

        // Cart (Session)
        Route::post('/cart/save', [CatalogController::class, 'saveCart'])->name('cart.save');
        Route::get('/cart/get', [CatalogController::class, 'getCart'])->name('cart.get');
        Route::get('/cart/count', [CatalogController::class, 'getCartCount'])->name('cart.count');
        Route::post('/cart/add', [CatalogController::class, 'addToCart'])->name('cart.add');
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
        Route::post('/content-library/order', [ContentLibraryController::class, 'startOrder'])
            ->name('content-library.order');

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
        Route::get('/content-submissions/{submission}/preview', [ContentSubmissionController::class, 'preview'])
            ->name('content-submissions.preview');
        Route::get('/content-submissions/{submission}/download', [ContentSubmissionController::class, 'download'])
            ->name('content-submissions.download');
        Route::delete('/content-submissions/{submission}', [ContentSubmissionController::class, 'destroy'])
            ->name('content-submissions.destroy');

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
        Route::get('/orders',      [CatalogController::class, 'orders'])->name('orders');
        Route::get('/orders/list', [CatalogController::class, 'getOrders'])->name('orders.list');
        Route::get('/orders/{id}', [CatalogController::class, 'getOrder'])->name('orders.get');
        
        // Order actions
        Route::post('/orders/{id}/approve', [CatalogController::class, 'approveOrder'])->name('orders.approve');
        Route::post('/orders/{id}/request-modification', [CatalogController::class, 'requestModification'])->name('order.modification');
        


        // OTHER PAGES
        Route::get('/add-funds', [AddFundsController::class, 'index'])->name('add-funds');
        Route::post('/add-funds', [AddFundsController::class, 'store'])->name('add-funds.store');
        Route::get('/add-funds/status/{id}', [AddFundsController::class, 'getStatus'])->name('add-funds.status');


        // Stripe Checkout routes
        Route::post('/create-checkout-session', [AddFundsController::class, 'createCheckoutSession'])->name('create-checkout-session');
        Route::get('/checkout-success', [AddFundsController::class, 'checkoutSuccess'])->name('checkout.success');

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

       


// Save billing info route
Route::post('/save-billing-info', [AddFundsController::class, 'saveBillingInfo'])->name('save-billing-info');

// Get billing info route
Route::get('/get-billing-info', [AddFundsController::class, 'getBillingInfo'])->name('get-billing-info');
        

});

// ✅ Publisher
Route::middleware(['auth','verified', RoleMiddleware::class . ':publisher'])
    ->prefix('publisher')->name('publisher.')
    ->group(function () {

        // Balance
        Route::get('/balance', [\App\Http\Controllers\Publisher\BalanceController::class, 'index'])->name('balance');
        Route::post('/balance/transfer', [\App\Http\Controllers\Publisher\BalanceController::class, 'transferToAdvertiser'])->name('balance.transfer');
        Route::get('/balance/history', [\App\Http\Controllers\Publisher\BalanceController::class, 'getTransferHistory'])->name('balance.history');

        // Dashboard
        Route::get('/dashboard', [App\Http\Controllers\Publisher\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/statistics', [App\Http\Controllers\Publisher\DashboardController::class, 'getStatistics'])->name('dashboard.statistics');
        Route::get('/dashboard/recent-orders', [App\Http\Controllers\Publisher\DashboardController::class, 'getRecentOrders'])->name('dashboard.recent');
        Route::get('/dashboard/weekly-earnings', [App\Http\Controllers\Publisher\DashboardController::class, 'getWeeklyEarnings'])->name('dashboard.weekly-earnings');
        Route::get('/dashboard/order-status', [App\Http\Controllers\Publisher\DashboardController::class, 'getOrderStatusDistribution'])->name('dashboard.order-status');
        Route::get('/dashboard/monthly-earnings', [App\Http\Controllers\Publisher\DashboardController::class, 'getMonthlyEarnings'])->name('dashboard.monthly-earnings');

        // Websites Management
        Route::get('/websites', [App\Http\Controllers\Publisher\SiteController::class, 'index'])->name('websites');
        Route::post('/websites/store', [App\Http\Controllers\Publisher\SiteController::class, 'store'])->name('sites.store');
        Route::get('/websites/ajax', [App\Http\Controllers\Publisher\SiteController::class, 'ajax'])->name('sites.ajax');
        Route::get('/websites/bulk-template', [App\Http\Controllers\Publisher\SiteController::class, 'bulkTemplate'])->name('sites.bulk-template');
        Route::post('/websites/bulk-import', [App\Http\Controllers\Publisher\SiteController::class, 'bulkImport'])->name('sites.bulk-import');
        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::put('/sites/{id}', [SiteController::class, 'update'])->name('sites.update');
        Route::delete('/sites/{id}', [SiteController::class, 'destroy'])->name('sites.destroy');
        Route::get('/countries/{country}/languages', [SiteController::class, 'getCountryLanguages'])->name('countries.languages');

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