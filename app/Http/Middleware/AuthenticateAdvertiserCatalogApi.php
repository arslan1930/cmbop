<?php

namespace App\Http\Middleware;

use App\Models\AdvertiserApiToken;
use App\Services\Advertiser\AdvertiserSandbox;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdvertiserCatalogApi
{
    public function handle(Request $request, Closure $next, string $scope = ''): Response
    {
        $plain = $this->bearer($request);
        $token = $plain !== '' ? AdvertiserApiToken::findByPlain($plain) : null;
        $user = $token?->user;

        if ($token === null || $user === null) {
            return response()->json([
                'status' => 'error',
                'error' => 'unauthorized',
                'message' => 'Invalid or missing API token.',
            ], 401);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'status' => 'error',
                'error' => 'forbidden',
                'message' => 'This account is suspended.',
            ], 403);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'status' => 'error',
                'error' => 'forbidden',
                'message' => 'Verify your email before using the catalog API.',
            ], 403);
        }

        if (! $user->hasRole('advertiser')) {
            return response()->json([
                'status' => 'error',
                'error' => 'forbidden',
                'message' => 'Catalog API keys are for advertiser accounts only.',
            ], 403);
        }

        if ($scope !== '' && ! $token->hasScope($scope)
            && ! ($scope === 'sites:read' && $token->hasScope('catalog:read'))) {
            return response()->json([
                'status' => 'error',
                'error' => 'forbidden',
                'message' => 'This key is missing the '.$scope.' scope.',
            ], 403);
        }

        if ($token->isTest() && ! AdvertiserSandbox::schemaReady()) {
            return AdvertiserSandbox::failClosedResponse();
        }

        $token->markUsed($request->ip());
        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
        $request->attributes->set('advertiser_api_token', $token);
        $request->attributes->set('advertiser_api_key_id', $token->id);
        $request->attributes->set('advertiser_api_test', $token->isTest());

        return $next($request);
    }

    private function bearer(Request $request): string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
