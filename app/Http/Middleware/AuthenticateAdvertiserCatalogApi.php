<?php

namespace App\Http\Middleware;

use App\Models\AdvertiserApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdvertiserCatalogApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->bearer($request);
        $token = $plain !== '' ? AdvertiserApiToken::findByPlain($plain) : null;
        $user = $token?->user;

        if ($token === null || $user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or missing API token.',
            ], 401);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This account is suspended.',
            ], 403);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Verify your email before using the catalog API.',
            ], 403);
        }

        if (! $user->hasRole('advertiser')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Catalog API keys are for advertiser accounts only.',
            ], 403);
        }

        $token->markUsed();
        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }

    private function bearer(Request $request): string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m) === 1) {
            return $m[1];
        }

        return trim((string) $request->query('api_token', ''));
    }
}
