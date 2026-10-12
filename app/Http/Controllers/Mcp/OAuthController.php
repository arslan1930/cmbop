<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpOAuthAccessToken;
use App\Models\McpOAuthAuthCode;
use App\Models\McpOAuthClient;
use App\Services\Mcp\McpCustomerAuth;
use App\Services\Mcp\McpEndpoints;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OAuthController extends Controller
{
    public function __construct(
        private readonly McpEndpoints $endpoints,
        private readonly McpCustomerAuth $auth,
    ) {}

    public function protectedResource(Request $request, ?string $path = null): JsonResponse
    {
        $path ??= $request->query('path');

        return response()->json($this->endpoints->protectedResourceMetadata(
            is_string($path) && $path !== '' ? $path : $this->endpoints->customerPath()
        ));
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json($this->endpoints->authorizationServerMetadata());
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_name' => ['nullable', 'string', 'min:1', 'max:255'],
            'name' => ['nullable', 'string', 'min:1', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'string', 'max:2048'],
            'logo_uri' => ['nullable', 'string', 'max:2048'],
            'client_uri' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'invalid_client_metadata',
                'error_description' => $validator->errors()->first(),
            ], 400);
        }

        $data = $validator->validated();
        $uris = [];
        foreach ($data['redirect_uris'] as $uri) {
            if (! $this->validRedirectUri((string) $uri)) {
                return response()->json([
                    'error' => 'invalid_redirect_uri',
                    'error_description' => 'One or more redirect_uris are not allowed.',
                ], 400);
            }
            $uris[] = (string) $uri;
        }

        $name = (string) ($data['client_name'] ?? $data['name'] ?? (parse_url($uris[0], PHP_URL_HOST) ?: 'MCP Client'));
        $client = McpOAuthClient::register(
            $name,
            $uris,
            $data['logo_uri'] ?? null,
            $data['client_uri'] ?? null,
        );

        return response()->json([
            'client_id' => $client->id,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => 'mcp:use',
        ], 201);
    }

    public function authorize(Request $request): View|RedirectResponse
    {
        $params = $this->authorizeParams($request);
        if (isset($params['error'])) {
            return $this->failedRedirect($params['redirect_uri'] ?? null, $params['error'], $params['state'] ?? null, $params['error_description'] ?? null);
        }

        /** @var McpOAuthClient $client */
        $client = $params['client'];
        $user = $request->user();
        $denied = $this->auth->advertiserReady($user);
        if ($denied !== null) {
            return view('mcp.oauth.denied', [
                'client' => $client,
                'message' => $denied,
            ]);
        }

        return view('mcp.oauth.authorize', [
            'client' => $client,
            'user' => $user,
            'redirectUri' => $params['redirect_uri'],
            'state' => $params['state'],
            'codeChallenge' => $params['code_challenge'],
            'codeChallengeMethod' => $params['code_challenge_method'],
            'scope' => $params['scope'],
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:2048'],
            'state' => ['nullable', 'string', 'max:512'],
            'code_challenge' => ['required', 'string', 'max:128'],
            'code_challenge_method' => ['required', 'in:S256'],
            'scope' => ['nullable', 'string', 'max:255'],
            'decision' => ['required', 'in:approve,deny'],
        ]);

        $client = McpOAuthClient::query()->find($data['client_id']);
        if ($client === null || ! $client->allowsRedirect($data['redirect_uri'])) {
            abort(404);
        }

        if ($data['decision'] === 'deny') {
            return $this->failedRedirect($data['redirect_uri'], 'access_denied', $data['state'] ?? null, 'The user denied the request.');
        }

        $user = $request->user();
        $denied = $this->auth->advertiserReady($user);
        if ($denied !== null) {
            return $this->failedRedirect($data['redirect_uri'], 'access_denied', $data['state'] ?? null, $denied);
        }

        $issued = McpOAuthAuthCode::issue(
            $client,
            $user,
            $data['redirect_uri'],
            $data['code_challenge'],
            $data['code_challenge_method'],
            $data['scope'] ?: 'mcp:use',
        );

        return redirect()->away($this->withQuery($data['redirect_uri'], [
            'code' => $issued['plain'],
            'state' => $data['state'] ?? null,
        ]));
    }

    public function token(Request $request): JsonResponse
    {
        $grant = (string) $request->input('grant_type');

        return match ($grant) {
            'authorization_code' => $this->exchangeCode($request),
            'refresh_token' => $this->refresh($request),
            default => response()->json([
                'error' => 'unsupported_grant_type',
                'error_description' => 'Use authorization_code or refresh_token.',
            ], 400),
        };
    }

    public function revoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token', '');
        $access = McpOAuthAccessToken::findUsableAccess($token)
            ?? McpOAuthAccessToken::query()->where('token_hash', hash('sha256', $token))->first();
        $refresh = McpOAuthAccessToken::findUsableRefresh($token)
            ?? McpOAuthAccessToken::query()->where('refresh_token_hash', hash('sha256', $token))->first();

        ($access ?? $refresh)?->revoke();

        return response()->json(['revoked' => true]);
    }

    private function exchangeCode(Request $request): JsonResponse
    {
        $code = McpOAuthAuthCode::findUsable((string) $request->input('code', ''));
        $clientId = (string) $request->input('client_id', '');
        $redirect = (string) $request->input('redirect_uri', '');
        $verifier = (string) $request->input('code_verifier', '');

        if ($code === null || $code->client_id !== $clientId || $code->redirect_uri !== $redirect) {
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => 'The authorization code is invalid or expired.',
            ], 400);
        }

        if (! $code->matchesPkce($verifier)) {
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => 'PKCE verification failed.',
            ], 400);
        }

        $client = $code->client;
        $user = $code->user;
        if ($client === null || $user === null) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        $code->markUsed();
        $issued = McpOAuthAccessToken::issue($client, $user, $code->scopes);

        return $this->tokenResponse($issued);
    }

    private function refresh(Request $request): JsonResponse
    {
        $existing = McpOAuthAccessToken::findUsableRefresh((string) $request->input('refresh_token', ''));
        $clientId = (string) $request->input('client_id', '');

        if ($existing === null || ($clientId !== '' && $existing->client_id !== $clientId)) {
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => 'The refresh token is invalid or expired.',
            ], 400);
        }

        $client = $existing->client;
        $user = $existing->user;
        if ($client === null || $user === null) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        $existing->revoke();
        $issued = McpOAuthAccessToken::issue($client, $user, $existing->scopes);

        return $this->tokenResponse($issued);
    }

    /**
     * @param  array{access_token: string, refresh_token: string, token: McpOAuthAccessToken}  $issued
     */
    private function tokenResponse(array $issued): JsonResponse
    {
        return response()->json([
            'access_token' => $issued['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => max(1, $issued['token']->expires_at->getTimestamp() - now()->getTimestamp()),
            'refresh_token' => $issued['refresh_token'],
            'scope' => $issued['token']->scopes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizeParams(Request $request): array
    {
        $client = McpOAuthClient::query()->find((string) $request->query('client_id', ''));
        $redirect = (string) $request->query('redirect_uri', '');
        $state = $request->query('state');
        $challenge = (string) $request->query('code_challenge', '');
        $method = strtoupper((string) $request->query('code_challenge_method', 'S256'));
        $responseType = (string) $request->query('response_type', '');

        if ($client === null) {
            return ['error' => 'invalid_client', 'error_description' => 'Unknown client_id.', 'redirect_uri' => null, 'state' => $state];
        }

        if ($redirect === '' || ! $client->allowsRedirect($redirect)) {
            return ['error' => 'invalid_request', 'error_description' => 'redirect_uri is not registered.', 'redirect_uri' => null, 'state' => $state];
        }

        if ($responseType !== 'code') {
            return ['error' => 'unsupported_response_type', 'redirect_uri' => $redirect, 'state' => $state];
        }

        if ($challenge === '' || $method !== 'S256') {
            return ['error' => 'invalid_request', 'error_description' => 'PKCE S256 is required.', 'redirect_uri' => $redirect, 'state' => $state];
        }

        return [
            'client' => $client,
            'redirect_uri' => $redirect,
            'state' => is_string($state) ? $state : null,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => (string) $request->query('scope', 'mcp:use') ?: 'mcp:use',
        ];
    }

    private function failedRedirect(?string $redirect, string $error, mixed $state, ?string $description = null): RedirectResponse|View
    {
        if ($redirect === null || $redirect === '') {
            return view('mcp.oauth.denied', [
                'client' => null,
                'message' => $description ?: $error,
            ]);
        }

        return redirect()->away($this->withQuery($redirect, [
            'error' => $error,
            'error_description' => $description,
            'state' => is_string($state) ? $state : null,
        ]));
    }

    /**
     * @param  array<string, string|null>  $query
     */
    private function withQuery(string $uri, array $query): string
    {
        $parts = parse_url($uri) ?: [];
        parse_str($parts['query'] ?? '', $existing);
        foreach ($query as $key => $value) {
            if ($value !== null && $value !== '') {
                $existing[$key] = $value;
            }
        }

        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }
        $base .= $parts['path'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' && ($parts['scheme'] ?? '') !== 'http') {
            $base = $uri;
            $existing = array_merge($query);
        }

        $qs = http_build_query($existing);

        return $qs === '' ? $base : $base.'?'.$qs;
    }

    private function validRedirectUri(string $uri): bool
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME);
        if (! is_string($scheme) || parse_url($uri, PHP_URL_USER) !== null || parse_url($uri, PHP_URL_PASS) !== null) {
            return false;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            return Str::isUrl($uri, ['http', 'https']);
        }

        $allowed = config('mcp.custom_schemes', []);

        return is_array($allowed) && in_array($scheme, $allowed, true) && is_string(parse_url($uri, PHP_URL_HOST));
    }
}
