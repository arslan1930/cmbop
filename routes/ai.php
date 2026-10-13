<?php

use App\Http\Controllers\Api\Mcp\CustomerMcpController;
use App\Http\Controllers\Mcp\OAuthController;
use App\Http\Middleware\AuthenticateMcpCustomer;
use App\Http\Middleware\McpCors;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;

/*
|--------------------------------------------------------------------------
| SEOLinkBuildings customer MCP + OAuth 2.1
|--------------------------------------------------------------------------
| laravel/mcp powers initialize / ping / tools/list / tools/call.
| OAuth discovery, DCR, PKCE, and consent are first-party so Claude.ai
| Connect works without Laravel Passport.
*/

Route::middleware([McpCors::class])->group(function () {
    Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource'])
        ->name('mcp.oauth.protected-resource');
    Route::get('/.well-known/oauth-protected-resource/{path}', [OAuthController::class, 'protectedResource'])
        ->where('path', '.*')
        ->name('mcp.oauth.protected-resource.nested');
    Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer'])
        ->name('mcp.oauth.authorization-server');
    Route::get('/.well-known/oauth-authorization-server/{path}', [OAuthController::class, 'authorizationServer'])
        ->where('path', '.*')
        ->name('mcp.oauth.authorization-server.nested');

    Route::post('/oauth/register', [OAuthController::class, 'register'])
        ->middleware('throttle:20,1')
        ->name('mcp.oauth.register');
    Route::post('/oauth/token', [OAuthController::class, 'token'])
        ->middleware('throttle:30,1')
        ->name('mcp.oauth.token');
    Route::post('/oauth/revoke', [OAuthController::class, 'revoke'])
        ->middleware('throttle:30,1')
        ->name('mcp.oauth.revoke');

    Route::get('/api/mcp/customer', [CustomerMcpController::class, 'rejectListen'])
        ->middleware('throttle:mcp')
        ->name('mcp.customer.listen');
    Route::match(['POST', 'OPTIONS'], '/api/mcp/customer', [CustomerMcpController::class, 'handle'])
        ->middleware(['throttle:mcp', ReorderJsonAccept::class, AuthenticateMcpCustomer::class])
        ->name('mcp.customer');
    Route::get('/api/mcp/customer/sse', [CustomerMcpController::class, 'sse'])
        ->middleware(['throttle:mcp', AuthenticateMcpCustomer::class])
        ->name('mcp.customer.sse');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])
        ->name('mcp.oauth.authorize');
    Route::post('/oauth/authorize', [OAuthController::class, 'approve'])
        ->middleware('throttle:20,1')
        ->name('mcp.oauth.approve');
});
