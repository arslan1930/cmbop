<?php

namespace App\Services\VerifiedTraffic;

use App\Support\VerifiedTraffic;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Read-only Google Analytics Admin/Data + Search Console HTTP calls.
 * Login Socialite (`services.google`) is not used here.
 */
class GoogleTrafficClient
{
    public function oauthRedirectUrl(): string
    {
        return rtrim(request()->getSchemeAndHttpHost(), '/').'/publisher/sites/google/callback';
    }

    public function oauthDriver(?string $state = null): GoogleProvider
    {
        /** @var GoogleProvider $driver */
        $driver = new GoogleProvider(
            request(),
            (string) config('services.google_traffic.client_id'),
            (string) config('services.google_traffic.client_secret'),
            $this->oauthRedirectUrl(),
        );

        $params = [
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
        ];
        if (filled($state)) {
            $params['state'] = $state;
        }

        // Always stateless: we send our own encrypted CSRF state and must not
        // let Socialite overwrite it with its session state (login Google stays separate).
        $driver->stateless()
            ->setScopes(VerifiedTraffic::SCOPES)
            ->with($params);

        return $driver;
    }

    public function userFromCallback(): SocialiteUser
    {
        $code = (string) request('code');
        if ($code === '') {
            throw new GoogleTrafficException('Google did not return an authorization code.', 401);
        }

        $response = Http::asForm()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_traffic.client_id'),
                'client_secret' => config('services.google_traffic.client_secret'),
                'redirect_uri' => $this->oauthRedirectUrl(),
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);

        if (! $response->successful()) {
            throw new GoogleTrafficException('Google sign-in failed. Try connecting again.', $response->status());
        }

        $json = $response->json();
        $access = (string) ($json['access_token'] ?? '');
        if ($access === '') {
            throw new GoogleTrafficException('Google did not return an access token', 401);
        }

        $user = new SocialiteUser;
        $user->token = $access;
        $user->refreshToken = $json['refresh_token'] ?? null;
        $user->expiresIn = (int) ($json['expires_in'] ?? 3600);

        try {
            $info = Http::withToken($access)
                ->timeout(10)
                ->acceptJson()
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');
            if ($info->successful()) {
                $payload = $info->json();
                $user->id = (string) ($payload['sub'] ?? '');
                $user->email = $payload['email'] ?? null;
            }
        } catch (\Throwable) {
            // Identity is optional; tokens are enough to read Analytics / Search Console.
        }

        return $user;
    }

    /**
     * @return list<array{id: string, name: string, host: string, match: bool}>
     */
    public function listGa4Properties(string $accessToken, string $siteDomain): array
    {
        $summaries = $this->getJson(
            $accessToken,
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries',
            ['pageSize' => 200]
        );

        $out = [];
        foreach (($summaries['accountSummaries'] ?? []) as $account) {
            foreach (($account['propertySummaries'] ?? []) as $property) {
                $id = (string) ($property['property'] ?? '');
                if ($id === '') {
                    continue;
                }
                $name = (string) ($property['displayName'] ?? $id);
                $host = $this->ga4PropertyHost($accessToken, $id);
                $out[] = [
                    'id' => $id,
                    'name' => $name,
                    'host' => $host,
                    'match' => $host !== '' && VerifiedTraffic::hostsMatch($siteDomain, $host),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: string, name: string, host: string, match: bool}>
     */
    public function listGscSites(string $accessToken, string $siteDomain): array
    {
        $payload = $this->getJson($accessToken, 'https://www.googleapis.com/webmasters/v3/sites');
        $out = [];
        foreach (($payload['siteEntry'] ?? []) as $entry) {
            $url = (string) ($entry['siteUrl'] ?? '');
            if ($url === '') {
                continue;
            }
            $out[] = [
                'id' => $url,
                'name' => $url,
                'host' => VerifiedTraffic::registrableHost($url),
                'match' => VerifiedTraffic::hostsMatch($siteDomain, $url),
            ];
        }

        return $out;
    }

    /**
     * Organic sessions last 30 days, else total sessions when organic is 0.
     *
     * @return array{sessions: int, organic: int, total: int}
     */
    public function ga4Sessions(string $accessToken, string $propertyId): array
    {
        $property = str_starts_with($propertyId, 'properties/')
            ? $propertyId
            : 'properties/'.$propertyId;

        $total = $this->runGa4Report($accessToken, $property, false);
        $organic = $this->runGa4Report($accessToken, $property, true);
        $sessions = $organic > 0 ? $organic : $total;

        return [
            'sessions' => $sessions,
            'organic' => $organic,
            'total' => $total,
        ];
    }

    public function gscClicks(string $accessToken, string $siteUrl): int
    {
        $end = now()->subDay()->toDateString();
        $start = now()->subDays(28)->toDateString();
        $encoded = rawurlencode($siteUrl);
        $payload = $this->postJson(
            $accessToken,
            'https://www.googleapis.com/webmasters/v3/sites/'.$encoded.'/searchAnalytics/query',
            [
                'startDate' => $start,
                'endDate' => $end,
            ]
        );

        $clicks = 0.0;
        foreach (($payload['rows'] ?? []) as $row) {
            $clicks += (float) ($row['clicks'] ?? 0);
        }
        if ($clicks <= 0 && isset($payload['rows'][0]['clicks'])) {
            $clicks = (float) $payload['rows'][0]['clicks'];
        }

        return (int) round($clicks);
    }

    /**
     * @return array{access_token: string, expires_in: int}|null
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $response = Http::asForm()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_traffic.client_id'),
                'client_secret' => config('services.google_traffic.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            Log::notice('Verified traffic token refresh failed', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $json = $response->json();
        $token = (string) ($json['access_token'] ?? '');
        if ($token === '') {
            return null;
        }

        return [
            'access_token' => $token,
            'expires_in' => (int) ($json['expires_in'] ?? 3600),
        ];
    }

    public function revokeToken(?string $token): void
    {
        if (! filled($token)) {
            return;
        }

        try {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', [
                'token' => $token,
            ]);
        } catch (\Throwable $e) {
            Log::notice('Verified traffic token revoke failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ga4PropertyHost(string $accessToken, string $propertyId): string
    {
        $payload = $this->getJson(
            $accessToken,
            'https://analyticsadmin.googleapis.com/v1beta/'.$propertyId.'/dataStreams',
            ['pageSize' => 50]
        );

        foreach (($payload['dataStreams'] ?? []) as $stream) {
            $uri = (string) ($stream['webStreamData']['defaultUri'] ?? '');
            if ($uri !== '') {
                return VerifiedTraffic::registrableHost($uri);
            }
        }

        return '';
    }

    private function runGa4Report(string $accessToken, string $property, bool $organicOnly): int
    {
        $body = [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'yesterday']],
            'metrics' => [['name' => 'sessions']],
        ];
        if ($organicOnly) {
            $body['dimensionFilter'] = [
                'filter' => [
                    'fieldName' => 'sessionDefaultChannelGroup',
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => 'Organic Search',
                    ],
                ],
            ];
        }

        $payload = $this->postJson(
            $accessToken,
            'https://analyticsdata.googleapis.com/v1beta/'.$property.':runReport',
            $body
        );

        $value = $payload['rows'][0]['metricValues'][0]['value'] ?? 0;

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getJson(string $accessToken, string $url, array $query = []): array
    {
        $response = $this->http($accessToken)->get($url, $query);
        $this->throwIfFailed($response, $url);

        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $accessToken, string $url, array $body): array
    {
        $response = $this->http($accessToken)->post($url, $body);
        $this->throwIfFailed($response, $url);

        return is_array($response->json()) ? $response->json() : [];
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(25)
            ->retry(2, 800, function ($exception, $request) {
                $status = method_exists($exception, 'response') && $exception->response
                    ? $exception->response->status()
                    : 0;

                return in_array($status, [429, 500, 502, 503, 504], true);
            }, throw: false);
    }

    private function throwIfFailed(Response $response, string $url): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $message = 'Google API '.$status;
        if (in_array($status, [401, 403], true)) {
            $message = 'Google access was denied or revoked';
        }

        Log::notice('Verified traffic Google call failed', [
            'status' => $status,
            'host' => parse_url($url, PHP_URL_HOST),
        ]);

        throw new GoogleTrafficException($message, $status);
    }
}
