<?php

namespace App\Services\Catalog;

use App\Models\CatalogCopyEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tracks clipboard copies of catalog URL/domain identity and applies strikes.
 *
 *   strike 1 — warning only; catalog stays fully visible
 *   strike 2 — catalog_hide_until = now + 24h
 *
 * While hide mode is active, tracking is paused (identity is already masked /
 * eye-gated). Tracking resumes after the window expires or an admin clears it.
 *
 * Threshold is “~5 pages” of distinct domains inside a short window
 * (defaults: 100 copies / 120 seconds). After a warning the copy window is
 * cleared so a second full wave is required for hide mode (and so MySQL
 * second-precision timestamps cannot stall strike 2).
 */
class CatalogCopyStrikeGuard
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_WARNING = 'warning';

    public const STATUS_HIDE_MODE = 'hide_mode';

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

        $host = $this->normalizeHost($text);

        if ($siteId !== null) {
            $site = Site::query()->catalogVisible()->find($siteId);
            if (! $site) {
                $siteId = null;
            } elseif ($host === '') {
                // Row id known but selection was messy — fall back to listing URL.
                $host = $this->normalizeHost((string) $site->site_url);
            }
        }

        if ($host === '') {
            return $this->payload($user, self::STATUS_IGNORED, 0, 'Not a domain or URL.');
        }

        $cfg = $this->config();
        $windowSeconds = $cfg['window_seconds'];
        $threshold = $cfg['threshold'];

        return DB::transaction(function () use ($user, $siteId, $host, $windowSeconds, $threshold, $cfg) {
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

            $this->insertIfNew($locked, $siteId, $host, $windowSeconds);
            $distinct = $this->distinctCount($locked, $windowSeconds);

            if ($distinct < $threshold) {
                return $this->payload($locked->fresh(), self::STATUS_RECORDED, $distinct, null);
            }

            $strikes = (int) ($locked->catalog_copy_strike_count ?? 0);

            if ($strikes < 1) {
                $locked->catalog_copy_strike_count = 1;
                $locked->catalog_copy_warned_at = now();
                $locked->save();

                // Clear the window so the same burst cannot escalate on the next
                // copy. MySQL timestamps are second-precision, so "created_at >
                // warned_at" would otherwise miss same-second follow-ups and
                // leave strike 2 unreachable in a fast harvest.
                CatalogCopyEvent::query()->where('user_id', $locked->id)->delete();

                return $this->payload(
                    $locked->fresh(),
                    self::STATUS_WARNING,
                    $distinct,
                    'Heads up: copying lots of website addresses from the catalog looks like harvesting. '
                    .'Please stop mass-copying domains. Another wave will temporarily hide site names and URLs.'
                );
            }

            // Strike 2: another full threshold after the post-warning clear.
            if ($strikes < 2) {
                $locked->catalog_copy_strike_count = 2;
            }
            $locked->catalog_hide_until = now()->addHours($cfg['hide_hours']);
            $locked->save();

            return $this->payload(
                $locked->fresh(),
                self::STATUS_HIDE_MODE,
                $distinct,
                'Repeated domain copying detected. Site names and URLs will be hidden for 24 hours — '
                .'use the eye icon to reveal them one listing at a time.'
            );
        });
    }

    public function inHideMode(User $user): bool
    {
        $until = $user->catalog_hide_until ?? null;

        return $until !== null && $until->isFuture();
    }

    /**
     * True when clipboard/selection text looks like a URL or domain.
     */
    public function looksLikeDomainOrUrl(string $text): bool
    {
        return $this->normalizeHost($text) !== '';
    }

    public function normalizeHost(string $text): string
    {
        $raw = trim($text);
        if ($raw === '' || mb_strlen($raw) > 500) {
            return '';
        }

        // Reject multi-line dumps / whole-row copies that are clearly not one host.
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

    private function insertIfNew(User $user, ?int $siteId, string $host, int $windowSeconds): void
    {
        $since = now()->subSeconds($windowSeconds);

        $exists = CatalogCopyEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->when(
                $siteId !== null,
                fn ($q) => $q->where('site_id', $siteId),
                fn ($q) => $q->where('normalized_host', $host)->whereNull('site_id')
            )
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

        // Prefer site_id identity; fall back to host for copies without a row id.
        $withSite = CatalogCopyEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->whereNotNull('site_id')
            ->distinct()
            ->count('site_id');

        $hostOnly = CatalogCopyEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $since)
            ->whereNull('site_id')
            ->distinct()
            ->count('normalized_host');

        return $withSite + $hostOnly;
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
