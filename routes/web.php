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
use App\Http\Controllers\Advertiser\ProjectController;
use App\Http\Controllers\Advertiser\CatalogController;
use App\Http\Controllers\Advertiser\CampaignController;
use App\Http\Controllers\Advertiser\AddFundsController;
use App\Http\Controllers\Advertiser\ReportsController;

use App\Http\Controllers\InvoiceController;
// BlogController for public blog pages
use App\Http\Controllers\BlogController;

use App\Http\Controllers\Auth\SocialiteController;




Route::get('/', function () {
    return view('home');
});

// OTHER PAGES
Route::get('/contact', function () {
    return view('pages.contact');
});

Route::get('/blog', function () {
    return view('pages.blog');
});

Route::get('/privacy-policy', function () {
    return view('pages.privacy-policy');
});

Route::get('/terms-of-services', function () {
    return view('pages.terms-of-services');
});

// ========== PUBLIC BLOG ROUTES ==========
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

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


// ✅ Admin
Route::middleware(['auth','verified', RoleMiddleware::class . ':admin'])
    ->prefix('admin')->name('admin.')
    ->group(function () {


        Route::get('/dashboard', function () {
            return view('admin.dashboard');
        })->name('dashboard');

        

        // Users management
        Route::get('/users', [UserController::class, 'index'])
            ->name('users.index');
        
        // Update Company (AJAX)
        Route::post('/users/{id}/update-company', [UserController::class, 'updateCompany'])
            ->name('users.updateCompany');    

    //  Sites routes
    Route::get('/sites', [AdminSiteController::class, 'index'])
    ->name('sites.index');

    Route::get('/users/{id}/sites', [AdminSiteController::class, 'userSites'])
    ->name('users.sites');

    // edit page
    Route::get('/sites/{id}/edit', [AdminSiteController::class, 'edit'])
    ->name('sites.edit');

    // update (AJAX)
    // Route::post('/sites/{id}/update', [AdminSiteController::class, 'update'])
    // ->name('sites.update');    

    // UPDATE (AJAX uses this)
    Route::put('/sites/{id}', [AdminSiteController::class, 'update'])
        ->name('sites.update');

    // Image upload (AJAX)
    Route::post('/sites/{id}/upload-image', [AdminSiteController::class, 'uploadImage'])->name('sites.upload-image');    

    // DELETE (AJAX uses this)
    Route::delete('/sites/{id}', [AdminSiteController::class, 'destroy'])
        ->name('sites.destroy');

    // VERIFY / UNVERIFY (AJAX toggle)
    Route::post('/sites/{id}/verify', [AdminSiteController::class, 'verify'])
        ->name('sites.verify');

    // ACTIVE / INACTIVE (AJAX toggle)
    Route::post('/sites/{id}/active', [AdminSiteController::class, 'toggleActive'])
        ->name('sites.active');

        
        // Payments Routes
        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments');
        Route::get('/payments/data', [AdminPaymentController::class, 'getPaymentsData'])->name('payments.data');
        Route::get('/payments/{id}', [AdminPaymentController::class, 'show'])->name('payments.show');
        Route::post('/payments/{id}/update-status', [AdminPaymentController::class, 'updatePaymentStatus'])->name('payments.updateStatus'); 
        

        // Deposits Routes
        Route::get('/deposits', [AdminDepositController::class, 'index'])->name('deposits');
        Route::get('/deposits/{id}', [AdminDepositController::class, 'show'])->name('deposits.show');
        Route::post('/deposits/{id}/approve', [AdminDepositController::class, 'approve'])->name('deposits.approve');
        Route::post('/deposits/{id}/reject', [AdminDepositController::class, 'reject'])->name('deposits.reject');

            
    // Withdrawals Routes
    Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals');
    Route::get('/withdrawals/data', [AdminWithdrawalController::class, 'getWithdrawalsData'])->name('admin.withdrawals.data');
    Route::get('/withdrawals/{id}', [AdminWithdrawalController::class, 'show'])->name('admin.withdrawals.show');
    Route::post('/withdrawals/{id}/status', [AdminWithdrawalController::class, 'updateStatus'])->name('admin.withdrawals.update-status');
    Route::get('/withdrawals/statistics', [AdminWithdrawalController::class, 'getStatistics'])->name('admin.withdrawals.statistics');
     
    // Admin Blogs Routes
    Route::resource('blogs', AdminBlogController::class);
    Route::get('blogs/{id}/toggle-status', [AdminBlogController::class, 'toggleStatus'])->name('blogs.toggle-status');
    Route::post('blogs/upload-image', [AdminBlogController::class, 'uploadImage'])->name('blogs.upload-image');
    

    // Reports site 
    Route::get('/reports', function () {
            return view('admin.reports');
        })->name('reports');

    // Settings
    Route::get('/settings', function () {   
            return view('admin.settings');
        })->name('settings'); 
    
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

        // Chat routes
    Route::prefix('chat')->group(function () {
    Route::get('/messages/{orderId}', [App\Http\Controllers\ChatController::class, 'getMessages'])->name('chat.messages');
    Route::post('/send/{orderId}', [App\Http\Controllers\ChatController::class, 'sendMessage'])->name('chat.send');
    Route::post('/upload-image', [App\Http\Controllers\ChatImageController::class, 'upload'])->name('chat.upload-image');


    
});


});

// ✅ Advertiser - Routes for managing campaigns, catalog, and projects
Route::middleware(['auth','verified', RoleMiddleware::class . ':advertiser'])
    ->prefix('advertiser')->name('advertiser.')
    ->group(function () {

        Route::get('/dashboard', function () {
            return view('advertiser.dashboard');
        })->name('dashboard');

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

        Route::get('/dashboard', function () {
            return view('publisher.dashboard');
        })->name('dashboard');

    

        // Balance
    Route::get('/balance', [\App\Http\Controllers\Publisher\BalanceController::class, 'index'])->name('balance');
    Route::post('/balance/transfer', [\App\Http\Controllers\Publisher\BalanceController::class, 'transferToAdvertiser'])->name('balance.transfer');
    Route::get('/balance/history', [\App\Http\Controllers\Publisher\BalanceController::class, 'getTransferHistory'])->name('balance.history');



            // Dashboard
    Route::get('/dashboard', [App\Http\Controllers\Publisher\DashboardController::class, 'index'])->name('dashboard');
    
    
    // Dashboard AJAX Endpoints
    Route::get('/dashboard/statistics', [App\Http\Controllers\Publisher\DashboardController::class, 'getStatistics'])->name('dashboard.statistics');
    Route::get('/dashboard/recent-orders', [App\Http\Controllers\Publisher\DashboardController::class, 'getRecentOrders'])->name('dashboard.recent');
    Route::get('/dashboard/earnings-chart', [App\Http\Controllers\Publisher\DashboardController::class, 'getEarningsChart'])->name('dashboard.earnings');
    Route::get('/dashboard/top-sites', [App\Http\Controllers\Publisher\DashboardController::class, 'getTopSites'])->name('dashboard.top-sites');
    Route::get('/dashboard/wallet-balance', [App\Http\Controllers\Publisher\DashboardController::class, 'getWalletBalance'])->name('dashboard.wallet');
    Route::get('/dashboard/pending-tasks', [App\Http\Controllers\Publisher\DashboardController::class, 'getPendingTasks'])->name('dashboard.pending-tasks');
    
    // Websites Management
    Route::get('/websites', [App\Http\Controllers\Publisher\SiteController::class, 'index'])->name('websites');
    Route::post('/websites/store', [App\Http\Controllers\Publisher\SiteController::class, 'store'])->name('sites.store');
    Route::get('/websites/ajax', [App\Http\Controllers\Publisher\SiteController::class, 'ajax'])->name('sites.ajax');
    Route::put('/sites/{id}', [App\Http\Controllers\Publisher\SiteController::class, 'update'])->name('sites.update');
    Route::delete('/sites/{id}', [App\Http\Controllers\Publisher\SiteController::class, 'destroy'])->name('sites.destroy');
    
    // Tasks/Orders
    Route::get('/tasks', [App\Http\Controllers\Publisher\OrderController::class, 'index'])->name('tasks');
    Route::get('/orders/data', [App\Http\Controllers\Publisher\OrderController::class, 'getOrders'])->name('orders.data');
    Route::get('/orders/{id}/details', [App\Http\Controllers\Publisher\OrderController::class, 'getOrderDetails'])->name('orders.details');
    Route::post('/orders/{id}/accept', [App\Http\Controllers\Publisher\OrderController::class, 'acceptOrder'])->name('orders.accept');
    Route::post('/orders/{id}/reject', [App\Http\Controllers\Publisher\OrderController::class, 'rejectOrder'])->name('orders.reject');
    Route::post('/orders/{id}/complete', [App\Http\Controllers\Publisher\OrderController::class, 'submitLiveUrl'])->name('orders.complete');
    Route::post('/orders/{id}/resubmit', [App\Http\Controllers\Publisher\OrderController::class, 'resubmitLiveUrl'])->name('orders.resubmit');
    
    // Dashboard Statistics
    Route::get('/dashboard/statistics', [App\Http\Controllers\Publisher\DashboardController::class, 'getStatistics'])->name('dashboard.statistics');
    Route::get('/dashboard/recent-orders', [App\Http\Controllers\Publisher\DashboardController::class, 'getRecentOrders'])->name('dashboard.recent');

    // Chart Data Routes - ADD THESE
    Route::get('/dashboard/weekly-earnings', [App\Http\Controllers\Publisher\DashboardController::class, 'getWeeklyEarnings'])->name('dashboard.weekly-earnings');
    Route::get('/dashboard/order-status', [App\Http\Controllers\Publisher\DashboardController::class, 'getOrderStatusDistribution'])->name('dashboard.order-status');
    Route::get('/dashboard/monthly-earnings', [App\Http\Controllers\Publisher\DashboardController::class, 'getMonthlyEarnings'])->name('dashboard.monthly-earnings');


        // FIXED: Update this route to use SiteController instead of closure
        Route::get('/websites', [SiteController::class, 'index'])->name('websites');

        // Index (main page) - you can keep this or remove it since /websites now does the same
        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');

        // Store
        // Route::post('/sites/store', [SiteController::class, 'store'])->name('sites.store');

        // AJAX listing
        // Route::get('/sites/ajax', [SiteController::class, 'ajax'])->name('sites.ajax');

        // Update (used by AJAX)
        Route::put('/sites/{id}', [SiteController::class, 'update'])->name('sites.update');

        // Delete
        Route::delete('/sites/{id}', [SiteController::class, 'destroy'])->name('sites.destroy');

         // Make sure this route is correct
        Route::get('/countries/{country}/languages', [SiteController::class, 'getCountryLanguages'])->name('publisher.countries.languages');


        // OTHER PAGES
        Route::get('/tasks', [OrderController::class, 'index'])->name('tasks');

        Route::get('/orders/data', [OrderController::class, 'getOrders'])->name('publisher.orders.data');
    Route::get('/orders/statistics', [OrderController::class, 'getStatistics'])->name('publisher.orders.statistics');
    Route::post('/orders/{id}/accept', [OrderController::class, 'acceptOrder'])->name('publisher.orders.accept');
    Route::post('/orders/{id}/reject', [OrderController::class, 'rejectOrder'])->name('publisher.orders.reject');
    Route::post('/orders/{id}/complete', [OrderController::class, 'submitLiveUrl'])->name('publisher.orders.complete');
    Route::post('/orders/{id}/resubmit', [OrderController::class, 'resubmitLiveUrl'])->name('publisher.orders.resubmit');

    // Order details endpoint
    Route::get('/orders/{id}/details', [OrderController::class, 'getOrderDetails'])->name('publisher.orders.details');

        // Withdraw page
        Route::get('/withdraw', [WithdrawalController::class, 'index'])->name('withdraw');
        Route::post('/withdraw/request', [WithdrawalController::class, 'requestWithdrawal'])->name('withdraw.request');

        // Optional additional routes
    Route::get('/withdrawals/history', [WithdrawalController::class, 'getHistory'])->name('publisher.withdrawals.history');
    Route::get('/withdrawals/statistics', [WithdrawalController::class, 'getStatistics'])->name('publisher.withdrawals.statistics');
    Route::post('/withdrawals/{id}/cancel', [WithdrawalController::class, 'cancelWithdrawal'])->name('publisher.withdrawals.cancel');

    // Reports page
    Route::get('/reports', [PublisherReportsController::class, 'index'])->name('reports');
    Route::get('/reports/statistics', [PublisherReportsController::class, 'ge   tStatistics'])->name('reports.statistics');
    Route::get('/reports/orders', [PublisherReportsController::class, 'getOrders'])->name('reports.orders');
    Route::get('/reports/orders/{orderItemId}/details', [PublisherReportsController::class, 'getOrderDetails'])->name('reports.order.details');
    Route::get('/reports/withdrawals', [PublisherReportsController::class, 'getWithdrawals'])->name('reports.withdrawals');
});