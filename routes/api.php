<?php

use App\Http\Controllers\Api\Partner\V1\PartnerApiController;
use App\Http\Controllers\Api\Partner\V1\PartnerSandboxController;
use App\Http\Controllers\Api\Partner\V1\PartnerWebhookController;
use App\Http\Controllers\Api\PaypalWebhookController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\V1\AdvertiserOrderController;
use App\Http\Controllers\Api\V1\AdvertiserSiteController;
use App\Http\Controllers\Api\V1\AdvertiserWalletController;
use App\Http\Controllers\Api\V1\AdvertiserWebhookController;
use App\Http\Controllers\Api\V1\CatalogSearchController;
use App\Http\Middleware\AuthenticateAdvertiserCatalogApi;
use App\Http\Middleware\AuthenticatePartnerApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| These routes are loaded by the RouteServiceProvider within a group
| which is assigned the "api" middleware group. These routes are stateless.
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware([AuthenticateAdvertiserCatalogApi::class, 'throttle:advertiser-api'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/catalog', [CatalogSearchController::class, 'index'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':catalog:read')
            ->name('api.v1.catalog');
        Route::get('/sites/{id}', [AdvertiserSiteController::class, 'show'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':sites:read')
            ->name('api.v1.sites.show');
        Route::get('/wallet', [AdvertiserWalletController::class, 'show'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':wallet:read')
            ->name('api.v1.wallet');
        Route::get('/orders', [AdvertiserOrderController::class, 'index'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':orders:read')
            ->name('api.v1.orders.index');
        Route::post('/orders', [AdvertiserOrderController::class, 'store'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':orders:write')
            ->name('api.v1.orders.store');
        Route::get('/orders/{order}', [AdvertiserOrderController::class, 'show'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':orders:read')
            ->name('api.v1.orders.show');
        Route::post('/orders/{order}/cancel', [AdvertiserOrderController::class, 'cancel'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':orders:write')
            ->name('api.v1.orders.cancel');
        Route::get('/webhooks', [AdvertiserWebhookController::class, 'index'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':webhooks:write')
            ->name('api.v1.webhooks.index');
        Route::post('/webhooks', [AdvertiserWebhookController::class, 'store'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':webhooks:write')
            ->name('api.v1.webhooks.store');
        Route::get('/webhooks/{webhook}', [AdvertiserWebhookController::class, 'show'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':webhooks:write')
            ->name('api.v1.webhooks.show');
        Route::patch('/webhooks/{webhook}', [AdvertiserWebhookController::class, 'update'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':webhooks:write')
            ->name('api.v1.webhooks.update');
        Route::delete('/webhooks/{webhook}', [AdvertiserWebhookController::class, 'destroy'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':webhooks:write')
            ->name('api.v1.webhooks.destroy');
        Route::post('/webhooks/{webhook}/test', [AdvertiserWebhookController::class, 'test'])
            ->middleware(AuthenticateAdvertiserCatalogApi::class.':webhooks:write')
            ->name('api.v1.webhooks.test');
    });

Route::prefix('partner/v1')
    ->middleware([AuthenticatePartnerApi::class, 'throttle:partner-api'])
    ->group(function () {
        Route::get('/me', [PartnerApiController::class, 'me']);
        Route::patch('/me', [PartnerApiController::class, 'meUpdate']);
        Route::get('/taxonomy', [PartnerApiController::class, 'taxonomy']);
        Route::get('/webhooks', [PartnerWebhookController::class, 'index'])->middleware(AuthenticatePartnerApi::class.':webhooks:write');
        Route::post('/webhooks', [PartnerWebhookController::class, 'store'])->middleware(AuthenticatePartnerApi::class.':webhooks:write');
        Route::get('/webhooks/{webhook}', [PartnerWebhookController::class, 'show'])->middleware(AuthenticatePartnerApi::class.':webhooks:write');
        Route::patch('/webhooks/{webhook}', [PartnerWebhookController::class, 'update'])->middleware(AuthenticatePartnerApi::class.':webhooks:write');
        Route::delete('/webhooks/{webhook}', [PartnerWebhookController::class, 'destroy'])->middleware(AuthenticatePartnerApi::class.':webhooks:write');
        Route::post('/webhooks/{webhook}/test', [PartnerWebhookController::class, 'test'])->middleware(AuthenticatePartnerApi::class.':webhooks:write');
        Route::post('/sandbox/orders', [PartnerSandboxController::class, 'createOrder'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::get('/balance', [PartnerApiController::class, 'balance'])->middleware(AuthenticatePartnerApi::class.':balance:read');
        Route::get('/ledger', [PartnerApiController::class, 'ledger'])->middleware(AuthenticatePartnerApi::class.':balance:read');
        Route::get('/earnings', [PartnerApiController::class, 'earnings'])->middleware(AuthenticatePartnerApi::class.':balance:read');
        Route::get('/statements', [PartnerApiController::class, 'statements'])->middleware(AuthenticatePartnerApi::class.':balance:read');
        Route::get('/statements/{period}', [PartnerApiController::class, 'statementShow'])->middleware(AuthenticatePartnerApi::class.':balance:read');
        Route::post('/payouts', [PartnerApiController::class, 'payouts'])->middleware(AuthenticatePartnerApi::class.':payouts:write');
        Route::get('/orders', [PartnerApiController::class, 'ordersIndex'])->middleware(AuthenticatePartnerApi::class.':orders:read');
        Route::get('/orders/summary', [PartnerApiController::class, 'ordersSummary'])->middleware(AuthenticatePartnerApi::class.':orders:read');
        Route::get('/orders/{order}', [PartnerApiController::class, 'orderShow'])->middleware(AuthenticatePartnerApi::class.':orders:read');
        Route::post('/orders/{order}/accept', [PartnerApiController::class, 'accept'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::post('/orders/{order}/reject', [PartnerApiController::class, 'reject'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::post('/orders/{order}/complete', [PartnerApiController::class, 'complete'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::post('/orders/{order}/publish', [PartnerApiController::class, 'complete'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::post('/orders/{order}/restore', [PartnerApiController::class, 'restore'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::get('/orders/{order}/monitoring', [PartnerApiController::class, 'monitoring'])->middleware(AuthenticatePartnerApi::class.':orders:read');
        Route::post('/orders/{order}/corrections/{correction}/decline', [PartnerApiController::class, 'declineCorrection'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::get('/orders/{order}/messages', [PartnerApiController::class, 'messages'])->middleware(AuthenticatePartnerApi::class.':orders:read');
        Route::post('/orders/{order}/messages', [PartnerApiController::class, 'postMessage'])->middleware(AuthenticatePartnerApi::class.':orders:write');
        Route::get('/orders/{order}/timeline', [PartnerApiController::class, 'timeline'])->middleware(AuthenticatePartnerApi::class.':orders:read');
        Route::get('/sites', [PartnerApiController::class, 'sitesIndex'])->middleware(AuthenticatePartnerApi::class.':sites:read');
        Route::post('/sites/bulk', [PartnerApiController::class, 'sitesBulk'])->middleware(AuthenticatePartnerApi::class.':sites:write');
        Route::get('/sites/{site}', [PartnerApiController::class, 'siteShow'])->middleware(AuthenticatePartnerApi::class.':sites:read');
        Route::patch('/sites/{site}', [PartnerApiController::class, 'siteUpdate'])->middleware(AuthenticatePartnerApi::class.':sites:write');
        Route::get('/rates', [PartnerApiController::class, 'ratesIndex'])->middleware(AuthenticatePartnerApi::class.':sites:read');
        Route::patch('/rates/{site}', [PartnerApiController::class, 'rateUpdate'])->middleware(AuthenticatePartnerApi::class.':sites:write');
    });

/*
|--------------------------------------------------------------------------
| Stripe Webhook
|--------------------------------------------------------------------------
| Stripe will POST events here.
| No CSRF, no session, fully stateless (correct approach).
|
*/

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

/*
|--------------------------------------------------------------------------
| PayPal Webhook
|--------------------------------------------------------------------------
| PayPal POSTs signed events here. Stateless, no CSRF.
|
*/
Route::post('/paypal/webhook', [PaypalWebhookController::class, 'handleWebhook']);
