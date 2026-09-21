<?php

namespace App\Services\Catalog;

use App\Models\Order;
use App\Models\Site;
use App\Models\SiteUrlReveal;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Decides whether an advertiser may see a publisher's domain, and records it
 * when they do.
 *
 * Everyday catalog (not in copy-strike hide mode): full name + URL — no eye.
 *
 * Copy-strike hide mode (`catalog_hide_until` in the future): name + URL are
 * masked until the eye reveals them. States in that mode:
 *
 *   masked    default under hide mode; partial host / name leave the server
 *   revealed  they asked, we logged it, and it stays open until they conceal
 *
 * Conceal is a display preference on top of a disclosure: the audit row stays,
 * concealed_at flips, and a refresh keeps the mask until they open it again.
 *
 * Catalog HTML should ask this class rather than reading site_url directly so
 * hide-mode masking stays consistent.
 */
class SiteUrlVisibility
{
    /** @var array<int, array<int, bool>> */
    private array $revealCache = [];

    private static ?bool $tableAvailableCache = null;

    private static ?bool $concealColumnAvailableCache = null;

    /** @internal Clear memoised schema flags after self-heal / tests. */
    public static function forgetSchemaCache(): void
    {
        self::$tableAvailableCache = null;
        self::$concealColumnAvailableCache = null;
    }

    /**
     * Best-effort: create site_url_reveals (+ concealed_at) when migrations were skipped.
     */
    public function ensureSchema(): void
    {
        app(CatalogRevealSchema::class)->ensure();
    }

    /**
     * The host with the middle of the name replaced.
     *
     * Keeps the TLD and the first few characters so listings stay
     * distinguishable in a long table without being searchable.
     */
    public function mask(?string $url): string
    {
        $host = $this->host($url);

        if ($host === '') {
            return '••••••';
        }

        $parts = explode('.', $host);

        if (count($parts) < 2) {
            return substr($host, 0, 3).'******';
        }

        $tld = array_pop($parts);
        $name = implode('.', $parts);
        $visible = min(4, max(2, strlen($name)));

        return substr($name, 0, $visible).'***.'.$tld;
    }

    public function host(?string $url): string
    {
        try {
            $raw = trim((string) $url);
            if ($raw === '') {
                return '';
            }
            // Leftover javascript:/data:/vbscript: must not paint as a host.
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) === 1
                && preg_match('#^https?:#i', $raw) !== 1) {
                return '';
            }

            return (string) Str::of($raw)
                ->replaceMatches('/^(https?:\/\/)?(www\.)?/', '')
                ->before('/')
                ->trim();
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * Leading-dot TLD label from the host (e.g. ".de", ".com").
     * Same last-label rule as mask() — not a full public-suffix list.
     */
    public function tld(?string $url): string
    {
        $host = $this->host($url);
        if ($host === '') {
            return '';
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return '';
        }

        $tld = strtolower((string) array_pop($parts));

        return $tld !== '' ? '.'.$tld : '';
    }

    /**
     * Always-https site root for surfaces that must show https://… (bulk rail).
     * Keeps www/subdomains like rootedUrl(); only the scheme is forced to https.
     */
    public function httpsRootedUrl(?string $url): string
    {
        $rooted = $this->rootedUrl($url);
        if ($rooted === '') {
            return '';
        }

        return (string) preg_replace('#^https?://#i', 'https://', $rooted, 1);
    }

    /**
     * Scheme + host only (keeps www/subdomains; drops /path ?query #hash).
     *
     * Catalog rows show this under the listing name so buyers see the site root
     * without deep article paths the webmaster may have pasted into site_url.
     */
    public function rootedUrl(?string $url): string
    {
        try {
            $raw = trim((string) $url);
            if ($raw === '') {
                return '';
            }
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) === 1
                && preg_match('#^https?://#i', $raw) !== 1) {
                return '';
            }

            $candidate = preg_match('#^https?://#i', $raw) === 1
                ? $raw
                : 'https://'.$raw;

            $parts = parse_url($candidate);
            if (! is_array($parts) || empty($parts['host'])) {
                return '';
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            if ($scheme !== 'http' && $scheme !== 'https') {
                return '';
            }

            $host = strtolower(rtrim((string) $parts['host'], '.'));
            if ($host === '') {
                return '';
            }

            return $scheme.'://'.$host;
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * Rooted URL for this advertiser — full when visible, https://mask when not.
     *
     * Outside copy-strike hide mode every authenticated viewer gets the real
     * rooted URL (no eye). Inside hide mode the URL stays masked until reveal.
     */
    public function rootedUrlFor(?User $user, Site $site): string
    {
        try {
            $raw = $this->leftoverSiteUrl($site);
            $rooted = $this->rootedUrl($raw);

            if ($this->showsFullIdentity($user, $site)) {
                // Fail closed: leftover javascript:/data: must not fall back to host() paint.
                return $rooted;
            }

            $scheme = 'https';
            if (preg_match('#^(https?):#i', $raw, $m) === 1) {
                $scheme = strtolower($m[1]);
            }

            $maskedHost = $this->mask($raw);
            if ($maskedHost === '' || $maskedHost === '••••••') {
                return $rooted !== '' ? $scheme.'://••••••' : '';
            }

            return $scheme.'://'.$maskedHost;
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * What this person should see for this site.
     *
     * Outside hide mode → always the real host for authenticated users.
     * Inside hide mode → real only after eye reveal (or staff/owner bypass).
     */
    public function hostFor(?User $user, Site $site): string
    {
        try {
            $raw = $this->leftoverSiteUrl($site);

            return $this->showsFullIdentity($user, $site)
                ? $this->host($raw)
                : $this->mask($raw);
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * Partial site name for hide-mode rows (keeps a little shape, not searchable).
     */
    public function maskName(?string $name): string
    {
        $raw = trim((string) $name);
        if ($raw === '') {
            return '••••••';
        }

        $len = mb_strlen($raw);
        $visible = min(3, max(1, (int) floor($len / 4)));

        return mb_substr($raw, 0, $visible).str_repeat('•', max(4, min(8, $len - $visible)));
    }

    /**
     * Listing name for the catalog.
     *
     * Outside copy-strike hide mode the real name is always shown. In hide mode
     * the name is masked until the same eye reveal that unlocks the URL.
     */
    public function nameFor(?User $user, Site $site): string
    {
        return $this->showsFullIdentity($user, $site)
            ? (string) $site->site_name
            : $this->maskName($site->site_name);
    }

    public function inHideMode(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return $user->inCatalogHideMode();
        } catch (\Throwable) {
            return false;
        }
    }

    public function showsFullIdentity(?User $user, Site $site): bool
    {
        if (! $user) {
            return false;
        }

        if (! $this->inHideMode($user)) {
            return true;
        }

        return $this->canSee($user, $site);
    }

    public function canSee(?User $user, Site $site): bool
    {
        if (! $user) {
            return false;
        }

        // Staff and the publisher who owns the listing are not the audience this
        // is protecting anyone from.
        if ($user->isAdmin() || $user->isMarketing()) {
            return true;
        }

        if ((int) $site->publisher_id === (int) $user->id) {
            return true;
        }

        // Normal advertisers see full identity. Mask + eye only apply while
        // copy-strike hide mode is active.
        if (! $this->inHideMode($user)) {
            return true;
        }

        return $this->hasRevealed($user, $site);
    }

    /**
     * Currently visible in the UI (disclosed and not manually hidden).
     */
    public function hasRevealed(User $user, Site $site): bool
    {
        $userId = (int) $user->id;
        $siteId = (int) $site->id;

        if (isset($this->revealCache[$userId][$siteId])) {
            return $this->revealCache[$userId][$siteId];
        }

        $this->warmFor($user, [$siteId]);

        return $this->revealCache[$userId][$siteId] ?? false;
    }

    /**
     * They have been handed this domain before, even if they hid it again.
     *
     * Re-opening a concealed address must not count against pace — the
     * disclosure already happened.
     */
    public function hasEverSeen(User $user, Site $site): bool
    {
        $this->ensureSchema();

        if (! $this->tableAvailable()) {
            return false;
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('site_id', $site->id)
            ->exists();
    }

    /**
     * Load the reveal state for a page of listings in one query.
     *
     * Without this a 20-row catalog asks the same question 20 times.
     *
     * @param  iterable<int|Site>  $sites
     */
    public function warmFor(?User $user, iterable $sites): void
    {
        if (! $user) {
            return;
        }

        $this->ensureSchema();

        if (! $this->tableAvailable()) {
            return;
        }

        $ids = collect($sites)
            ->map(fn ($site) => $site instanceof Site ? (int) $site->id : (int) $site)
            ->filter()
            ->unique();

        $userId = (int) $user->id;
        $this->revealCache[$userId] ??= [];

        $unknown = $ids->reject(fn (int $id) => array_key_exists($id, $this->revealCache[$userId]));

        if ($unknown->isEmpty()) {
            return;
        }

        $visibleQuery = SiteUrlReveal::query()
            ->where('user_id', $userId)
            ->whereIn('site_id', $unknown->all());

        if ($this->concealColumnAvailable()) {
            $visibleQuery->whereNull('concealed_at');
        }

        $revealed = $visibleQuery
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        foreach ($unknown as $id) {
            $this->revealCache[$userId][$id] = $revealed->has($id);
        }
    }

    /**
     * Record that this advertiser has seen the domain, and hand it back.
     *
     * Idempotent: asking twice is one disclosure. Re-opening after a manual hide
     * clears concealed_at without writing a second row or counting against pace.
     */
    public function reveal(User $user, Site $site, string $source = SiteUrlReveal::SOURCE_CATALOG): string
    {
        $this->ensureSchema();

        if (! $this->tableAvailable()) {
            // Do not pretend the address stuck — the UI would remask on refresh
            // and hide would say "Open the address before you can hide it."
            throw new \RuntimeException(
                'Could not save this website address. Please try again in a moment, or contact support if it keeps happening.'
            );
        }

        $row = SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('site_id', $site->id)
            ->first();

        if (! $row) {
            try {
                $row = SiteUrlReveal::create([
                    'user_id' => $user->id,
                    'site_id' => $site->id,
                    'source' => $source,
                    'ip_address' => request()?->ip(),
                ]);
            } catch (\Throwable $e) {
                // A race on the unique index means someone else already recorded
                // it, which is the outcome we wanted anyway.
                Log::debug('Site URL reveal already recorded', ['error' => $e->getMessage()]);
                $row = SiteUrlReveal::query()
                    ->where('user_id', $user->id)
                    ->where('site_id', $site->id)
                    ->first();
            }
        }

        if (! $row) {
            throw new \RuntimeException(
                'Could not save this website address. Please try again in a moment.'
            );
        }

        if ($this->concealColumnAvailable()) {
            $raw = $row->getAttributes()['concealed_at'] ?? null;
            if ($raw !== null && $raw !== '') {
                // Query-builder write: leftover concealed_at would 500 Eloquent save().
                SiteUrlReveal::query()->whereKey($row->id)->update(['concealed_at' => null]);
                $row->setAttribute('concealed_at', null);
            }
        }

        $this->revealCache[(int) $user->id][(int) $site->id] = true;

        return $this->host($site->site_url);
    }

    /**
     * Hide the domain in the UI until they click the eye again.
     *
     * Keeps the disclosure row so audits and pace history stay intact.
     */
    public function conceal(User $user, Site $site): void
    {
        $this->ensureSchema();

        if (! $this->tableAvailable() || ! $this->concealColumnAvailable()) {
            $this->revealCache[(int) $user->id][(int) $site->id] = false;

            return;
        }

        $row = SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->where('site_id', $site->id)
            ->first();

        if (! $row) {
            throw new \InvalidArgumentException(
                'Reveal this address with the eye first — then you can hide it again.'
            );
        }

        $raw = $row->getAttributes()['concealed_at'] ?? null;
        $parsed = $row->concealed_at;
        if ($raw === null || $raw === '' || $parsed === null) {
            // Heal leftover unparseable concealed_at the same way as a fresh hide.
            SiteUrlReveal::query()->whereKey($row->id)->update(['concealed_at' => now()]);
        }

        $this->revealCache[(int) $user->id][(int) $site->id] = false;
    }

    /**
     * Has this advertiser actually bought anything?
     *
     * Gates nothing — browsing is unlimited — but it is the column that answers
     * "customer or research project" on the admin activity screen.
     */
    public function isEstablished(User $user): bool
    {
        try {
            $hasPaidOrder = Order::query()
                ->where('user_id', $user->id)
                ->where('payment_status', 'paid')
                ->exists();

            if ($hasPaidOrder) {
                return true;
            }

            return $user->depositRequests()
                ->whereIn('status', ['approved', 'completed'])
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('Could not classify advertiser', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Domains this advertiser has ever been shown — including ones they hid.
     *
     * Search uses this so typing a host they already know still finds the row
     * after they closed the eye.
     *
     * @return Collection<int, int>
     */
    public function revealedSiteIds(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        $this->ensureSchema();

        if (! $this->tableAvailable()) {
            return collect();
        }

        return SiteUrlReveal::query()
            ->where('user_id', $user->id)
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id);
    }

    private function tableAvailable(): bool
    {
        if (self::$tableAvailableCache !== null) {
            return self::$tableAvailableCache;
        }

        try {
            return self::$tableAvailableCache = Schema::hasTable('site_url_reveals');
        } catch (\Throwable) {
            return self::$tableAvailableCache = false;
        }
    }

    private function concealColumnAvailable(): bool
    {
        if (self::$concealColumnAvailableCache !== null) {
            return self::$concealColumnAvailableCache;
        }

        try {
            return self::$concealColumnAvailableCache = Schema::hasColumn('site_url_reveals', 'concealed_at');
        } catch (\Throwable) {
            return self::$concealColumnAvailableCache = false;
        }
    }

    /** @internal Reset memoised state between tests. */
    public function flush(): void
    {
        $this->revealCache = [];
        self::forgetSchemaCache();
    }

    /**
     * Leftover Hostinger site_url without 500ing when the column/accessor throws.
     */
    private function leftoverSiteUrl(Site $site): string
    {
        try {
            if (method_exists($site, 'leftoverStringAttribute')) {
                return (string) ($site->leftoverStringAttribute('site_url') ?? '');
            }

            return trim((string) ($site->site_url ?? ''));
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }
}
