<?php

namespace App\Http\Middleware;

use App\Services\Mcp\McpCustomerAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpCustomer
{
    public function __construct(private readonly McpCustomerAuth $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (strtoupper($request->method()) === 'OPTIONS') {
            return $next($request);
        }

        $resolved = $this->auth->authenticate($request);
        $user = $resolved['user'] ?? null;
        $denied = $this->auth->advertiserReady($user);

        if ($denied !== null) {
            $metadata = url('/.well-known/oauth-protected-resource/'.$request->path());

            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32001,
                    'message' => $denied,
                ],
                'id' => null,
            ], 401, [
                'WWW-Authenticate' => 'Bearer realm="SEOLinkBuildings MCP", resource_metadata="'.$metadata.'"',
            ]);
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
