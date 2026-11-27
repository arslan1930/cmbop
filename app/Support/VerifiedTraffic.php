<?php

namespace App\Support;

use App\Models\Site;
use App\Models\SiteTrafficConnection;
use Illuminate\Support\Facades\Schema;

/**
 * Verified monthly traffic from a publisher's Google Analytics / Search Console.
 * Distinct from login Google OAuth and from Ahrefs/Moz metrics_manual.
 */
final class VerifiedTraffic
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_GA4 = 'ga4';

    public const SOURCE_GSC = 'gsc';

    public const SOURCE_ADMIN = 'admin';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/webmasters.readonly',
    ];

    public const BADGE_MAX_AGE_DAYS = 21;

    public const STALE_AFTER_DAYS = 6;

    public const NOTIFY_THROTTLE_HOURS = 24;

    public static function oauthConfigured(): bool
    {
        $id = trim((string) config('services.google_traffic.client_id', ''));
        $secret = trim((string) config('services.google_traffic.client_secret', ''));
        if ($id === '' || $secret === '') {
            return false;
        }

        $placeholders = [
            'your-id',
            'your-secret',
            'your_client_id',
            'your_client_secret',
            'changeme',
            'xxx',
            'null',
            'undefined',
        ];

        return ! in_array(strtolower($id), $placeholders, true)
            && ! in_array(strtolower($secret), $placeholders, true);
    }

    public static function schemaReady(): bool
    {
        try {
            if (! Schema::hasTable('site_traffic_connections')) {
                return false;
            }

            return Site::hasSitesColumn('traffic_source')
                && Site::hasSitesColumn('traffic_verified_at')
                && Site::hasSitesColumn('traffic_admin_override');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function connectAvailable(): bool
    {
        return self::oauthConfigured() && self::schemaReady();
    }

    /**
     * Advertisers see the Verified traffic badge only when Google last
     * succeeded recently and admin has not overridden the listing number.
     */
    public static function showsBadge(?Site $site): bool
    {
        if (! $site || ! self::schemaReady()) {
            return false;
        }

        if ($site->traffic_admin_override !== null && $site->traffic_admin_override !== '') {
            return false;
        }

        $source = (string) ($site->traffic_source ?? self::SOURCE_MANUAL);
        if (! in_array($source, [self::SOURCE_GA4, self::SOURCE_GSC], true)) {
            return false;
        }

        $verifiedAt = $site->traffic_verified_at;
        if (! $verifiedAt) {
            return false;
        }

        try {
            return $verifiedAt->gte(now()->subDays(self::BADGE_MAX_AGE_DAYS));
        } catch (\Throwable) {
            return false;
        }
    }

    public static function registrableHost(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^sc-domain:#', '', $value) ?? $value;
        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : $value;
        }
        $value = strtolower((string) $value);
        $value = preg_replace('#^www\.#', '', $value) ?? $value;
        $value = rtrim($value, '.');

        return $value;
    }

    public static function hostsMatch(string $siteDomain, string $candidate): bool
    {
        $site = self::registrableHost($siteDomain);
        $other = self::registrableHost($candidate);
        if ($site === '' || $other === '') {
            return false;
        }

        return $site === $other;
    }

    public static function connectionTableReady(): bool
    {
        try {
            return Schema::hasTable((new SiteTrafficConnection)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }
}
