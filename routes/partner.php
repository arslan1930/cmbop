<?php

use App\Http\Controllers\Partner\AuthController as PartnerAuthController;
use App\Http\Controllers\Partner\EmailActionController as PartnerEmailActionController;
use App\Http\Controllers\Partner\HomeController as PartnerHomeController;
use App\Http\Controllers\Partner\MoneyController as PartnerMoneyController;
use App\Http\Controllers\Partner\OrderController as PartnerOrderController;
use App\Http\Controllers\Partner\SettingsController as PartnerSettingsController;
use App\Http\Controllers\Partner\SiteController as PartnerSiteController;
use App\Http\Middleware\EnsurePartnerDashboardAuth;
use App\Http\Middleware\EnsurePartnerFullLogin;
use App\Support\PartnerDashboard;
use Illuminate\Support\Facades\Route;

$registerPartnerDashboard = static function (): void {
    Route::get('/robots.txt', [PartnerHomeController::class, 'robots'])->name('robots');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [PartnerAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [PartnerAuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login.store');
        Route::get('/forgot-password', [PartnerAuthController::class, 'showForgot'])->name('password.request');
        Route::post('/forgot-password', [PartnerAuthController::class, 'sendReset'])
            ->middleware('throttle:password-email')
            ->name('password.email');
        Route::get('/reset-password/{token}', [PartnerAuthController::class, 'showReset'])->name('password.reset');
        Route::post('/reset-password', [PartnerAuthController::class, 'reset'])
            ->middleware('throttle:password-update')
            ->name('password.update');
    });

    Route::post('/logout', [PartnerAuthController::class, 'logout'])->name('logout');

    Route::get('/a/{token}', [PartnerEmailActionController::class, 'open'])
        ->middleware('throttle:20,1')
        ->name('action');
    Route::get('/email-change/confirm/{token}', [PartnerEmailActionController::class, 'confirmEmail'])
        ->middleware('throttle:20,1')
        ->name('email.confirm');
    Route::get('/email-change/cancel/{token}', [PartnerEmailActionController::class, 'cancelEmail'])
        ->middleware('throttle:20,1')
        ->name('email.cancel');

    Route::middleware(EnsurePartnerDashboardAuth::class)->group(function () {
        Route::get('/', [PartnerHomeController::class, 'index'])->name('home');
        Route::get('/orders', [PartnerOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [PartnerOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/content', [PartnerOrderController::class, 'download'])->name('orders.content');
        Route::post('/orders/{order}/accept', [PartnerOrderController::class, 'accept'])->name('orders.accept');
        Route::post('/orders/{order}/reject', [PartnerOrderController::class, 'reject'])->name('orders.reject');
        Route::post('/orders/{order}/submit', [PartnerOrderController::class, 'submit'])->name('orders.submit');
        Route::post('/orders/{order}/reply', [PartnerOrderController::class, 'reply'])->name('orders.reply');
        Route::post('/orders/{order}/corrections/{correction}/decline', [PartnerOrderController::class, 'decline'])->name('orders.decline');
        Route::get('/orders/{order}/attachments/{attachment}', [PartnerOrderController::class, 'attachment'])->name('orders.attachment');
        Route::post('/orders/{order}/fixed', [PartnerOrderController::class, 'markFixed'])->name('orders.fixed');
        Route::get('/sites', [PartnerSiteController::class, 'index'])->name('sites.index');

        Route::middleware(EnsurePartnerFullLogin::class)->group(function () {
            Route::get('/money', [PartnerMoneyController::class, 'index'])->name('money.index');
            Route::post('/money/withdraw', [PartnerMoneyController::class, 'withdraw'])
                ->middleware('throttle:5,1')
                ->name('money.withdraw');
            Route::post('/money/withdrawals/{id}/cancel', [PartnerMoneyController::class, 'cancel'])
                ->middleware('throttle:10,1')
                ->name('money.cancel');
            Route::post('/money/instant', [PartnerMoneyController::class, 'requestInstant'])->name('money.instant');
            Route::get('/settings', [PartnerSettingsController::class, 'index'])->name('settings.index');
            Route::post('/settings/name', [PartnerSettingsController::class, 'updateName'])->name('settings.name');
            Route::post('/settings/password', [PartnerSettingsController::class, 'updatePassword'])->name('settings.password');
            Route::post('/settings/notifications', [PartnerSettingsController::class, 'updateNotifications'])->name('settings.notifications');
            Route::post('/settings/email', [PartnerSettingsController::class, 'requestEmailChange'])->name('settings.email');
            Route::post('/settings/keys', [PartnerSettingsController::class, 'issueKey'])->name('settings.keys');
            Route::post('/settings/keys/{key}/revoke', [PartnerSettingsController::class, 'revokeKey'])->name('settings.keys.revoke');
            Route::post('/settings/webhooks', [PartnerSettingsController::class, 'saveWebhook'])->name('settings.webhooks');
            Route::post('/settings/webhooks/{endpoint}/disable', [PartnerSettingsController::class, 'disableWebhook'])->name('settings.webhooks.disable');
        });
    });
};

$domain = PartnerDashboard::host();
Route::domain($domain)->name('partner.')->group($registerPartnerDashboard);

if (PartnerDashboard::pathFallbackEnabled()) {
    Route::prefix(PartnerDashboard::pathPrefix())->name('partner.path.')->group($registerPartnerDashboard);
}
