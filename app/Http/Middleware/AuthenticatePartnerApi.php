<?php

namespace App\Http\Middleware;

use App\Services\Partners\PartnerApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartnerApi
{
    public function handle(Request $request, Closure $next, string $scope = ''): Response
    {
        $plain = $this->bearer($request);
        $key = $plain !== '' ? app(PartnerApiKeyService::class)->findByPlain($plain) : null;
        $partner = $key?->partner;
        $user = $partner?->user;

        if (! $key || ! $partner || ! $user) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Invalid or missing API key.',
            ], 401);
        }
        if ($partner->status !== 'active') {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Partner account is not active.',
            ], 403);
        }
        if ($scope !== '' && ! $key->hasScope($scope)) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'This key is missing the '.$scope.' scope.',
            ], 403);
        }

        $key->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => substr((string) $request->ip(), 0, 45),
        ])->save();
        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
        $request->attributes->set('partner', $partner);
        $request->attributes->set('partner_api_key', $key);
        $request->attributes->set('partner_api_key_id', $key->id);

        return $next($request);
    }

    private function bearer(Request $request): string
    {
        $header = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m) === 1) {
            return $m[1];
        }

        return trim((string) $request->header('X-SLB-Key', ''));
    }
}
