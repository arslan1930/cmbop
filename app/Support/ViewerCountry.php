<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

class ViewerCountry
{
    public function code(?Request $request = null): ?string
    {
        $request ??= request();
        if (! $request instanceof Request) {
            return null;
        }

        if ($this->allowsLocalOverride()) {
            $forced = strtoupper(trim((string) config('fx.fake_country', '')));
            if ($forced === 'UK') {
                $forced = 'GB';
            }
            if ($forced !== '' && preg_match('/^[A-Z]{2}$/', $forced) === 1) {
                return $forced;
            }

            $header = $this->headerCountry($request);
            if ($header !== null) {
                return $header;
            }
        }

        $peer = $this->sanitizeIp($request->server->get('REMOTE_ADDR'));
        if ($peer !== null && $this->isCloudflarePeer($peer)) {
            $header = $this->headerCountry($request);
            if ($header !== null) {
                return $header;
            }
        }

        return $this->countryFromIp($request);
    }

    public function displayCurrency(?Request $request = null): string
    {
        if ($this->allowsLocalOverride()) {
            $forced = $this->normalizeForcedCurrency((string) config('fx.force_display', ''));
            if ($forced !== null) {
                return $forced;
            }
        }

        $map = config('fx.country_currency', []);
        $country = $this->code($request);
        if ($country === null || ! is_array($map)) {
            return 'EUR';
        }

        $currency = strtoupper((string) ($map[$country] ?? ''));

        return $currency !== '' ? $currency : 'EUR';
    }

    public function isUs(?Request $request = null): bool
    {
        return $this->displayCurrency($request) === 'USD';
    }

    private function normalizeForcedCurrency(string $raw): ?string
    {
        $value = strtolower(trim($raw));

        return match ($value) {
            'usd', 'dollar', 'dollars', 'cad', 'aud' => 'USD',
            'gbp', 'pound', 'pounds' => 'GBP',
            'eur', 'euro', 'euros' => 'EUR',
            default => null,
        };
    }

    private function allowsLocalOverride(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function headerCountry(Request $request): ?string
    {
        $raw = strtoupper(trim((string) $request->headers->get('CF-IPCountry', '')));
        if ($raw === 'UK') {
            $raw = 'GB';
        }
        if (preg_match('/^[A-Z]{2}$/', $raw) !== 1 || in_array($raw, ['XX', 'T1'], true)) {
            return null;
        }

        return $raw;
    }

    private function sanitizeIp(mixed $ip): ?string
    {
        $ip = is_string($ip) ? trim($ip) : '';
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    /**
     * Country of the visitor IP. Cloudflare's header wins when the peer is
     * Cloudflare. Otherwise the public address is looked up. Loopback on a
     * local machine uses this server's live location. Tests stay on EUR.
     */
    private function countryFromIp(Request $request): ?string
    {
        $ip = $this->sanitizeIp($request->ip());
        $lookupSelf = false;
        if ($ip === null || ! $this->isPublicIp($ip)) {
            if (! app()->environment('local')) {
                return null;
            }
            $lookupSelf = true;
            $ip = 'self';
        }

        try {
            $country = Cache::remember('viewer-country.'.$ip, 21600, function () use ($ip, $lookupSelf) {
                $url = $lookupSelf ? 'https://ipwho.is/' : 'https://ipwho.is/'.$ip;
                $response = Http::timeout(2)->acceptJson()->get($url);
                if (! $response->ok()) {
                    return null;
                }
                $code = strtoupper(trim((string) $response->json('country_code')));
                if ($code === 'UK') {
                    $code = 'GB';
                }

                return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
            });
        } catch (\Throwable) {
            return null;
        }

        return is_string($country) && $country !== '' ? $country : null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function isCloudflarePeer(string $ip): bool
    {
        $cidrs = config('welcome_bonus.cloudflare_cidrs', []);
        if (! is_array($cidrs) || $cidrs === []) {
            return false;
        }

        try {
            return IpUtils::checkIp($ip, $cidrs);
        } catch (\Throwable) {
            return false;
        }
    }
}
