<?php

use App\Http\Controllers\Api\PaypalWebhookController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\V1\CatalogMcpController;
use App\Http\Controllers\Api\V1\CatalogSearchController;
use App\Http\Middleware\AuthenticateAdvertiserCatalogApi;
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

Route::middleware(['throttle:30,1'])->prefix('v1')->group(function () {
    Route::get('/mcp', [CatalogMcpController::class, 'discover'])
        ->name('api.v1.mcp.discover');
    Route::options('/mcp', [CatalogMcpController::class, 'options'])
        ->name('api.v1.mcp.options');
});

Route::middleware(['throttle:30,1', AuthenticateAdvertiserCatalogApi::class, 'throttle:60,1'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/catalog', [CatalogSearchController::class, 'index'])
            ->name('api.v1.catalog');
        Route::post('/mcp', [CatalogMcpController::class, 'handle'])
            ->name('api.v1.mcp');
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
