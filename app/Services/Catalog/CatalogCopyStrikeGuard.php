<?php

namespace App\Services\Catalog;

use App\Models\CatalogCopyEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tracks clipboard copies of catalog URL/domain identity and applies strikes.
 *
 *   strike 1 — warning only; catalog stays fully visible
 *   strike 2 — catalog_hide_until = now + hide_hours
 *
 * Warning-wave and hide-wave rows are kept (admin forensics). The next wave
 * inserts and counts only event ids above catalog_copy_after_id so the same
 * listings — or same-second MySQL timestamps — cannot restage the burst.
 *
 * While hide mode is active, tracking is paused (identity is already masked /
 * eye-gated). Tracking resumes after the window expires or an admin lifts it.
 *
 * Threshold is “~5 pages” of distinct domains inside a short window
 * (defaults: 100 copies / 120 seconds). After a warning, hide, lift, or
 * strike reset the watermark advances so a second full wave is required.
 *
 * A table-cell copy often includes a trailing newline, and a multi-select
 * or CSV dump includes several hosts. Those still count (capped) — rejecting
 * the whole clipboard was a harvest bypass.
 */
class CatalogCopyStrikeGuard
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_WARNING = 'warning';

    public const STATUS_HIDE_MODE = 'hide_mode';

    public const MAX_HOSTS_PER_COPY = 40;

    public function __construct(private InAppNotificationService $notifications) {}

    /**
     * @return array{
     *     status: string,
     *     strike_count: int,
     *     distinct_in_window: int,
     *     threshold: int,
     *     hide_until: string|null,
     *     in_hide_mode: bool,
     *     message: string|null
     * }
     */
    public function record(User $user, ?int $siteId, string $text): array
    {
        if (! $this->tablesReady()) {
            return $this->payload($user, self::STATUS_IGNORED, 0, null);
        }

        // Copy URL now writes /advertiser/go/{id}?sample=1. That is not a
        // publisher domain. On localhost APP_URL, extractHosts() is empty and
        // the site_id fallback would record the listing host — a harvest strike.
        if ($this->looksLikeFirstPartyVisit($text)) {
            return $this->payload($user, self::STATUS_IGNORED, 0, 'Not a domain or URL.');
        }

        $hosts = $this->extractHosts($text);

        if ($siteId !== null) {
            $site = Site::query()->catalogVisible()->find($siteId);
            if (! $site) {
                $siteId = null;
            } elseif ($hosts === []) {
                // Row id known but selection was messy — fall back to listing URL.
                $fallback = $this->listingHosts($site)[0] ?? '';
                if ($fallback !== '') {
                    $hosts = [$fallback];
                }
            } elseif (count($hosts) === 1 && ! in_array($hosts[0], $this->listingHosts($site), true)) {
                // A scripted client can reuse one valid site_id with rotating
                // hosts. Pinning those rows to the listing makes insertIfNew
                // OR-dedupe on site_id and distinctCount collapse to 1.
                $siteId = null;
            }
        }

        if ($hosts === []) {
            return $this->payload($user, self::STATUS_IGNORED, 0, 'Not a domain or URL.');
        }

        // A multi-host dump must not attribute every domain to the row the
        // selection started on.
        $siteId = count($hosts) === 1 ? $siteId : null;

        $cfg = $this->config();
        $windowSeconds = $cfg['window_seconds'];
        $threshold = $cfg['threshold'];

        $result = DB::transaction(function () use ($user, $siteId, $hosts, $windowSeconds, $threshold, $cfg) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Already in hide mode: URLs are masked / eye-gated — no further
            // copy tracking until the window expires or an admin clears it.
            if ($this->inHideMode($locked)) {
                return $this->payload(
                    $locked,
                    self::STATUS_IGNORED,
                    0,
                    'Copy tracking is paused while catalog hide mode is active.'
                );
            }

            foreach ($hosts as $host) {
                $this->insertIfNew($locked, $siteId, $host, $windowSeconds);
            }
            $distinct = $this->distinctCount($locked, $windowSeconds);

            if ($distinct < $threshold) {
                return $this->payload($locked->fresh(), self::STATUS_RECORDED, $distinct, null);
            }

            $strikes = (int) ($locked->catalog_copy_strike_count ?? 0);

            if ($strikes < 1) {
                $locked->catalog_copy_strike_count = 1;
                $locked->catalog_copy_warned_at = now();
                // Keep the warning-wave rows for admin forensics. Strike 2
                // inserts/counts only ids above this cutoff so the same
                // listings cannot restage the burst.
                self::watermarkEvents($locked);
                $locked->save();

                $fresh = $locked->fresh();

                return $this->payload(
                    $fresh,
                    self::STATUS_WARNING,
                    $distinct,
                    'Heads up: copying lots of website addresses from the catalog looks like harvesting. '
                    .'Please stop mass-copying domains. Another wave will temporarily hide site names and URLs.'
                );
            }

            // Strike 2: another full threshold after the post-warning watermark.
            if ($strikes < 2) {
                $locked->catalog_copy_strike_count = 2;
            }
            $locked->catalog_hide_until = now()->addHours($cfg['hide_hours']);
            // Advance again so a lift (or expiry + first copy) cannot restage
            // this hide wave as an instant re-hide.
            self::watermarkEvents($locked);
            $locked->save();

            $fresh = $locked->fresh();

            return $this->payload(
                $fresh,
                self::STATUS_HIDE_MODE,
                $distinct,
                $this->hideModeUserMessage($cfg['hide_hours'])
            );
        });

        if (in_array($result['status'], [self::STATUS_WARNING, self::STATUS_HIDE_MODE], true)) {
            $subject = User::query()->find($user->id) ?? $user;
            $this->announce(
                $subject,
                $result['status'],
                (int) $result['distinct_in_window']
            );
            $this->logEnforcement($subject, $result);
        }

        return $result;
    }

    public function inHideMode(User $user): bool
    {
        return $user->inCatalogHideMode();
    }

    public function hideModeUserMessage(?int $hours = null): string
    {
        $hours = max(1, $hours ?? $this->config()['hide_hours']);
        $label = $hours === 1 ? '1 hour' : $hours.' hours';

        return 'Repeated domain copying detected. Site names and URLs will be hidden for '.$label.' — '
            .'use the eye icon to reveal them one listing at a time.';
    }

    /**
     * True when clipboard/selection text looks like a URL or domain.
     */
    public function looksLikeDomainOrUrl(string $text): bool
    {
        return $this->extractHosts($text) !== [];
    }

    /**
     * First-party catalog visit URLs (/advertiser/go/{id}) are not listings.
     * Publisher URLs that merely contain /go/{id} still count.
     */
    public function looksLikeFirstPartyVisit(string $text): bool
    {
        $raw = trim($text);
        if ($raw === '' || preg_match('#/go/\d+#', $raw) !== 1) {
            return false;
        }

        if (preg_match('#^https?://#i', $raw) !== 1) {
            return true;
        }

        $host = strtolower((string) (parse_url($raw, PHP_URL_HOST) ?? ''));
        $host = Str::of($host)->replaceMatches('/^www\./', '')->toString();

        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            return true;
        }

        $appHost = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?? ''));
        $appHost = Str::of($appHost)->replaceMatches('/^www\./', '')->toString();

        return $appHost !== '' && $host === $appHost;
    }

    /**
     * Distinct listing hosts found in clipboard text (one cell, or a dump).
     *
     * @return list<string>
     */
    public function extractHosts(string $text): array
    {
        $raw = trim($text);
        if ($raw === '') {
            return [];
        }

        // Newlines, tabs, commas, semicolons, and pipes are all dump
        // separators. Counting only whitespace let a CSV paste (or one
        // POST of host1,host2,host3) collapse to a single event.
        $tokens = preg_split('/[\s,;|]+/u', $raw) ?: [];
        $hosts = [];

        foreach ($tokens as $token) {
            $token = trim($token, " \t\n\r\0\x0B\"'<>()[],");
            $host = $this->normalizeHost($token);
            if ($host === '') {
                continue;
            }
            $hosts[$host] = $host;
            if (count($hosts) >= self::MAX_HOSTS_PER_COPY) {
                break;
            }
        }

        return array_values($hosts);
    }

    public function normalizeHost(string $text): string
    {
        $raw = trim($text);
        if ($raw === '' || mb_strlen($raw) > 500) {
            return '';
        }

        // Single-token API. extractHosts() splits dumps first so a trailing
        // newline or a multi-URL selection still counts.
        if (preg_match('/\R/u', $raw) === 1) {
            return '';
        }

        $candidate = $raw;
        if (preg_match('#^https?://#i', $candidate) !== 1) {
            // Bare host or host/path — require at least one dot and TLD-ish end.
            if (preg_match('/^(?:www\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+/i', $candidate) !== 1) {
                return '';
            }
            $candidate = 'https://'.$candidate;
        }

        $parts = parse_url($candidate);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $host = Str::of($host)->replaceMatches('/^www\./', '')->toString();

        if ($host === '' || ! str_contains($host, '.') || strlen($host) > 253) {
            return '';
        }

        return $host;
    }

    /**
     * Hosts that belong to this listing (site_url and domain column).
     *
     * @return list<string>
     */
    private function listingHosts(Site $site): array
    {
        $hosts = [];
        foreach ([(string) $site->site_url, (string) ($site->domain ?? '')] as $raw) {
            $host = $this->normalizeHost($raw);
            if ($host !== '') {
                $hosts[$host] = $host;
            }
        }

        return array_values($hosts);
    }

    /**
     * @return array{threshold: int, window_seconds: int, hide_hours: int}
     */
    private function config(): array
    {
        $cfg = (array) config('catalog.copy_strikes', []);

        return [
            // 5 catalog pages × 20 rows.
            'threshold' => max(1, (int) ($cfg['threshold'] ?? 100)),
            'window_seconds' => max(30, (int) ($cfg['window_seconds'] ?? 120)),
            'hide_hours' => max(1, (int) ($cfg['hide_hours'] ?? 24)),
        ];
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::hasTable('catalog_copy_events')
                && Schema::hasColumn('users', 'catalog_copy_strike_count')
                && Schema::hasColumn('users', 'catalog_hide_until');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ignore existing copy rows for future strike counts. Events stay on file.
     *
     * Used after a warning/hide wave and after admin lift/reset so the same
     * listings cannot immediately restage the next strike.
     */
    public static function watermarkEvents(User $user): void
    {
        try {
            if (! Schema::hasColumn('users', 'catalog_copy_after_id')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $user->catalog_copy_after_id = (int) (CatalogCopyEvent::query()
            ->where('user_id', $user->id)
            ->max('id') ?? 0);
    }

    private function insertIfNew(User $user, ?int $siteId, string $host, int $windowSeconds): void
    {
        $since = now()->subSeconds($windowSeconds);
        $afterId = $this->copyAfterId($user);

        $exists = CatalogCopyEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', CatalogCopyEvent::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->where(function ($q) use ($siteId, $host) {
                $q->where('normalized_host', $host);
                if ($siteId !== null) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->exists();

        if ($exists) {
            return;
        }

        CatalogCopyEvent::create([
            'user_id' => $user->id,
            'site_id' => $siteId,
            'normalized_host' => $host,
            'created_at' => now(),
        ]);
    }

    private function distinctCount(User $user, int $windowSeconds): int
    {
        $since = now()->subSeconds($windowSeconds);
        $afterId = $this->copyAfterId($user);

        // Prefer site_id identity; fall back to host for copies without a row id.
        $withSite = CatalogCopyEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', CatalogCopyEvent::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->whereNotNull('site_id')
            ->distinct()
            ->count('site_id');

        $hostOnly = CatalogCopyEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', CatalogCopyEvent::PLAUSIBLE_SQL_DATETIME_CEIL)
            ->when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
            ->whereNull('site_id')
            ->distinct()
            ->count('normalized_host');

        return $withSite + $hostOnly;
    }

    private function copyAfterId(User $user): int
    {
        return $this->afterIdColumnReady()
            ? (int) ($user->catalog_copy_after_id ?? 0)
            : 0;
    }

    public static function noticeCacheKey(int $userId, string $status): string
    {
        return 'catalog-copy-strike-'.$status.':'.$userId;
    }

    /**
     * Forget warning/hide bells so a later wave after lift/reset can page again.
     */
    public static function forgetNotices(int $userId): void
    {
        Cache::forget(self::noticeCacheKey($userId, self::STATUS_WARNING));
        Cache::forget(self::noticeCacheKey($userId, self::STATUS_HIDE_MODE));
    }

    private function afterIdColumnReady(): bool
    {
        try {
            return Schema::hasColumn('users', 'catalog_copy_after_id');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{status:string, distinct_in_window:int, hide_until?:string|null, strike_count?:int}  $result
     */
    private function logEnforcement(User $user, array $result): void
    {
        $hide = $result['status'] === self::STATUS_HIDE_MODE;

        ActivityLogger::tryLog(
            $hide ? 'catalog_hide_applied' : 'catalog_copy_warned',
            $hide
                ? 'Catalog hide mode applied for '.$user->email.' after a second copy-harvest wave.'
                : 'Catalog copy-harvest warning issued to '.$user->email.'.',
            $user,
            [
                'strikes' => (int) ($user->catalog_copy_strike_count ?? $result['strike_count'] ?? 0),
                'distinct_in_window' => (int) $result['distinct_in_window'],
                'hide_until' => $result['hide_until'] ?? $user->catalog_hide_until?->toIso8601String(),
            ],
            $user->email
        );
    }

    private function announce(User $user, string $status, int $distinct): void
    {
        $key = self::noticeCacheKey((int) $user->id, $status);

        if (Cache::has($key)) {
            return;
        }

        try {
            $this->notifications->notifyAdminsCatalogCopyStrike($user, $status, $distinct);
            // Short debounce only — a 24h lock hid the next offense after an
            // admin lift.
            Cache::put($key, true, now()->addMinutes(10));
        } catch (\Throwable $e) {
            Log::warning('Catalog copy-strike notice failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{
     *     status: string,
     *     strike_count: int,
     *     distinct_in_window: int,
     *     threshold: int,
     *     hide_until: string|null,
     *     in_hide_mode: bool,
     *     message: string|null
     * }
     */
    private function payload(User $user, string $status, int $distinct, ?string $message): array
    {
        $cfg = $this->config();
        $until = $user->catalog_hide_until ?? null;

        return [
            'status' => $status,
            'strike_count' => (int) ($user->catalog_copy_strike_count ?? 0),
            'distinct_in_window' => $distinct,
            'threshold' => $cfg['threshold'],
            'hide_until' => $until?->toIso8601String(),
            'in_hide_mode' => $this->inHideMode($user),
            'message' => $message,
        ];
    }
}
