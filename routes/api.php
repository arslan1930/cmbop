<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StripeWebhookController;

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

/*
|--------------------------------------------------------------------------
| Stripe Webhook
|--------------------------------------------------------------------------
| Stripe will POST events here.
| No CSRF, no session, fully stateless (correct approach).
|
*/

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);