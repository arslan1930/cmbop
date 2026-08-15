<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\IpUtils;

class WelcomeBonusService
{
    public function isEnabled(): bool
    {
        return WelcomeBonusSetting::isEnabled();
    }

    public function setEnabled(bool $enabled, ?int $updatedBy = null): void
    {
        WelcomeBonusSetting::setEnabled($enabled, $updatedBy);
    }

    public function amount(): float
    {
        $stored = WelcomeBonusSetting::config();
        if (is_array($stored) && isset($stored['amount']) && is_numeric($stored['amount'])) {
            return round(max(0, (float) $stored['amount']), 2);
        }

        return round(max(0, (float) config('welcome_bonus.amount', 20)), 2);
    }

    public function setAmount(float $amount, ?int $updatedBy = null): void
    {
        WelcomeBonusSetting::setAmount($amount, $updatedBy);
    }

    public function canGrant(): bool
    {
        return $this->isEnabled() && $this->claimsTableReady() && $this->bonusColumnsReady();
    }

    /**
     * Preview the grant for this request. Does not write a claim.
     * Call recordClaim() inside the signup transaction before crediting the wallet.
     */
    public function amountFor(Request $request, string $role): float
    {
        if (! $this->canGrant()) {
            return 0.0;
        }

        if ($role !== 'advertiser') {
            return 0.0;
        }

        $amount = $this->amount();
        if ($amount <= 0) {
            return 0.0;
        }

        $cookieName = (string) config('welcome_bonus.cookie_name', 'slb_welcome_claimed');
        $cookie = trim((string) $request->cookie($cookieName, ''));
        if ($cookie !== '') {
            return 0.0;
        }

        $ip = $this->normalizedIp($request);
        if ($ip === null || $this->ipAlreadyClaimed($ip)) {
            return 0.0;
        }

        return $amount;
    }

    /**
     * Persist the claim. Must run inside the same DB transaction as the wallet credit.
     * Returns false when this IP (or user) already claimed — caller must grant 0.
     * Missing or unwritable claims table: refuse the grant. Signup still succeeds.
     */
    public function recordClaim(User $user, Request $request, float $amount, string $source): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $write = function () use ($user, $request, $amount, $source): bool {
            if (! $this->claimsTableReady() || ! $this->bonusColumnsReady() || ! WelcomeBonusSetting::isEnabledForGrant()) {
                return false;
            }

            $allowed = $this->amount();
            if ($allowed <= 0 || $amount > $allowed + 0.001) {
                return false;
            }
            $amount = round(min($amount, $allowed), 2);

            $ip = $this->normalizedIp($request);
            if ($ip === null) {
                return false;
            }

            if ($this->userAlreadyClaimed((int) $user->id) || $this->ipAlreadyClaimed($ip, true)) {
                return false;
            }

            $insert = function () use ($user, $request, $amount, $source, $ip): bool {
                if ($this->userAlreadyClaimed((int) $user->id) || $this->ipAlreadyClaimed($ip, true)) {
                    return false;
                }

                try {
                    WelcomeBonusClaim::query()->create([
                        'user_id' => $user->id,
                        'ip_address' => $ip,
                        'user_agent' => $request->userAgent(),
                        'source' => $source,
                        'amount' => $amount,
                    ]);

                    return true;
                } catch (UniqueConstraintViolationException) {
                    return false;
                } catch (QueryException $e) {
                    if ($this->isUniqueViolation($e)) {
                        return false;
                    }

                    Log::warning('Welcome bonus claim write failed; skipping grant', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);

                    return false;
                }
            };

            return $this->withPlaceLock($ip, $insert);
        };

        // The settings-row lock only holds until this transaction ends.
        if (DB::transactionLevel() > 0) {
            return $write();
        }

        return DB::transaction($write);
    }

    public function queueClaimCookie(): void
    {
        $name = (string) config('welcome_bonus.cookie_name', 'slb_welcome_claimed');

        Cookie::queue(cookie(
            $name,
            '1',
            (int) config('welcome_bonus.cookie_minutes', 525600),
            '/',
            null,
            (bool) config('session.secure', false),
            true,
            false,
            'lax'
        ));
    }

    /**
     * Place key for the once-per-IP lock.
     *
     * Do not use Request::ip() while the app trusts all proxies — that reads
     * client-controlled X-Forwarded-For and lets anyone collect €20 per spoof.
     * CF-Connecting-IP is trusted only when REMOTE_ADDR is a Cloudflare edge.
     */
    public function normalizedIp(Request $request): ?string
    {
        $remote = $this->sanitizeIp($request->server->get('REMOTE_ADDR'));
        $cfConnecting = $this->sanitizeIp($request->headers->get('CF-Connecting-IP'));
        if ($remote !== null && $cfConnecting !== null && $this->isCloudflarePeer($remote)) {
            return $cfConnecting;
        }

        return $remote;
    }

    private function sanitizeIp(mixed $raw): ?string
    {
        $ip = trim((string) $raw);
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if ($ip === '' || strlen($ip) > 45) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $packed = $this->packedIpv4IfMapped($packed);

        // IPv6 privacy addresses rotate inside a /64. Lock the allocation, not
        // the full 128 bits, or one prefix can collect €20 per signup.
        if (strlen($packed) === 16) {
            $packed = substr($packed, 0, 8).str_repeat("\x00", 8);
        }

        $normalized = inet_ntop($packed);

        return $normalized !== false ? $normalized : null;
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
            // A bad CIDR in config must not 500 signup (and roll back a
            // half-created account). Refuse the CF header and use REMOTE_ADDR.
            return false;
        }
    }

    public function ipAlreadyClaimed(string $ip, bool $lock = false): bool
    {
        if (! $this->claimsTableReady()) {
            return true;
        }

        try {
            $query = WelcomeBonusClaim::query()->whereIn('ip_address', $this->ipClaimKeys($ip));
            if ($lock) {
                $query->lockForUpdate();
            }
            if ($query->exists()) {
                return true;
            }

            return $this->legacyPlaceClaimed($ip, $lock);
        } catch (\Throwable) {
            return true;
        }
    }

    private function userAlreadyClaimed(int $userId): bool
    {
        if ($userId < 1 || ! $this->claimsTableReady()) {
            return true;
        }

        try {
            return WelcomeBonusClaim::query()->where('user_id', $userId)->lockForUpdate()->exists();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Rate-limit / claim place key. Same source as the €20 lock: REMOTE_ADDR,
     * or CF-Connecting-IP only from a Cloudflare edge. Never X-Forwarded-For.
     */
    public function placeKey(Request $request): string
    {
        return $this->normalizedIp($request) ?? 'unknown';
    }

    /**
     * Shared signup flood key for form register and Google new-user create.
     */
    public function registerRateLimitKey(Request $request): string
    {
        return 'register:'.$this->placeKey($request);
    }

    /**
     * @return list<string>
     */
    private function ipClaimKeys(string $ip): array
    {
        $keys = [$ip];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $keys[] = '::ffff:'.$ip;
            $keys[] = '::FFFF:'.$ip;
            $keys[] = '::'.$ip;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Exact-string lookup misses leftover rows written before today's
     * normalizer: full 128-bit IPv6, expanded/uppercase IPv4-mapped
     * (::FFFF:1.2.3.4, 0:0:0:0:0:ffff:1.2.3.4). Compare packed form.
     */
    private function legacyPlaceClaimed(string $ip, bool $lock = false): bool
    {
        $wantedV6 = $this->ipv6AllocationKey($ip);
        $wantedV4 = $this->ipv4Key($ip);
        if ($wantedV6 === null && $wantedV4 === null) {
            return false;
        }

        $query = WelcomeBonusClaim::query()->where('ip_address', 'like', '%:%');
        if ($lock) {
            $query->lockForUpdate();
        }

        foreach ($query->pluck('ip_address') as $rowIp) {
            $row = (string) $rowIp;
            if ($wantedV6 !== null && $this->ipv6AllocationKey($row) === $wantedV6) {
                return true;
            }
            if ($wantedV4 !== null && $this->ipv4Key($row) === $wantedV4) {
                return true;
            }
        }

        return false;
    }

    /**
     * Serialize two grants for the same place until the signup transaction
     * commits. Releasing GET_LOCK when recordClaim() returns (still inside
     * Register / Socialite's transaction) lets a second connection pass
     * ipAlreadyClaimed before the first claim row is visible — a second €20
     * when the unique IP index is also missing.
     *
     * MySQL/MariaDB GET_LOCK is session-scoped and survives COMMIT, so it
     * is held until afterCommit / afterRollBack (or the connection closes).
     * Never fall back to a cache lock on MySQL: Hostinger's
     * CACHE_STORE=database lock row is invisible to other connections
     * until COMMIT — the same race GET_LOCK exists to close.
     *
     * Cache locks are the SQLite / test fallback only and are released
     * when recordClaim() returns. RefreshDatabase never commits the outer
     * test transaction, so deferring those to afterCommit would leak
     * across tests and block later same-IP grants.
     */
    private function withPlaceLock(string $ip, callable $insert): bool
    {
        $acquired = $this->acquirePlaceLock($ip);
        if ($acquired === false) {
            return false;
        }
        if ($acquired === null) {
            return $insert();
        }

        [$release, $sessionScoped] = $acquired;

        if (! $sessionScoped) {
            try {
                return $insert();
            } finally {
                $release();
            }
        }

        // Hold GET_LOCK until this signup is visible to other connections.
        // If afterCommit cannot be deferred, keep the lock until the
        // connection closes rather than unlocking before COMMIT.
        if (DB::transactionLevel() > 0) {
            $released = false;
            $safeRelease = function () use ($release, &$released): void {
                if ($released) {
                    return;
                }
                $released = true;
                $release();
            };

            try {
                $hookPending = false;
                DB::afterCommit(function () use ($safeRelease, &$hookPending): void {
                    if ($hookPending) {
                        $safeRelease();
                    }
                });
                $hookPending = true;
                try {
                    DB::afterRollBack($safeRelease);
                } catch (\Throwable) {
                }
            } catch (\Throwable) {
            }
        }

        try {
            return $insert();
        } finally {
            if (DB::transactionLevel() === 0) {
                $release();
            }
        }
    }

    /**
     * @return array{0: callable(): void, 1: bool}|false|null False to refuse the grant, null if no lock store (SQLite only).
     */
    private function acquirePlaceLock(string $ip): array|false|null
    {
        $sql = $this->trySqlPlaceLock($ip);
        if ($sql === 'held') {
            return [function () use ($ip): void {
                $this->releaseSqlPlaceLock($ip);
            }, true];
        }
        if ($sql === 'timeout' || $this->driverUsesSqlPlaceLock()) {
            // MySQL/MariaDB: GET_LOCK is the only lock that works inside the
            // open signup transaction. Timeout or unavailable → no grant.
            return false;
        }

        try {
            $lock = Cache::lock('welcome-bonus-claim:'.$ip, 15);
            $lock->block(8);

            return [function () use ($lock): void {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }, false];
        } catch (LockTimeoutException) {
            return false;
        } catch (\Throwable) {
            return null;
        }
    }

    private function driverUsesSqlPlaceLock(): bool
    {
        try {
            return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return 'held'|'timeout'|'unavailable'
     */
    private function trySqlPlaceLock(string $ip): string
    {
        if (! $this->driverUsesSqlPlaceLock()) {
            return 'unavailable';
        }

        try {
            $row = DB::selectOne('SELECT GET_LOCK(?, 8) AS got', [$this->sqlPlaceLockName($ip)]);
            $got = is_object($row) ? ($row->got ?? $row->GOT ?? null) : null;
            if ($got === null) {
                return 'unavailable';
            }

            return (int) $got === 1 ? 'held' : 'timeout';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    private function releaseSqlPlaceLock(string $ip): void
    {
        try {
            DB::selectOne('SELECT RELEASE_LOCK(?) as released', [$this->sqlPlaceLockName($ip)]);
        } catch (\Throwable) {
        }
    }

    private function sqlPlaceLockName(string $ip): string
    {
        // GET_LOCK names are capped at 64 characters.
        return 'wbclaim:'.$ip;
    }

    /**
     * ::ffff:1.2.3.4 and deprecated ::1.2.3.4 (96-bit zero prefix) are the
     * same place as 1.2.3.4. Leave :: and ::1 as IPv6.
     */
    private function packedIpv4IfMapped(string $packed): string
    {
        if (strlen($packed) !== 16) {
            return $packed;
        }

        if (substr($packed, 0, 12) === str_repeat("\x00", 10)."\xff\xff") {
            return substr($packed, 12);
        }

        if (substr($packed, 0, 12) === str_repeat("\x00", 12)) {
            $tail = substr($packed, 12);
            if ($tail !== "\x00\x00\x00\x00" && $tail !== "\x00\x00\x00\x01") {
                return $tail;
            }
        }

        return $packed;
    }

    /**
     * IPv4, or IPv4-mapped IPv6, as a dotted quad. Used so leftover
     * ::ffff: / ::FFFF: / expanded mapped rows still lock the IPv4 place.
     */
    private function ipv4Key(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $packed = $this->packedIpv4IfMapped($packed);

        if (strlen($packed) !== 4) {
            return null;
        }

        $normalized = inet_ntop($packed);

        return $normalized !== false ? $normalized : null;
    }

    private function ipv6AllocationKey(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        // Mapped / IPv4-compatible rows are the IPv4 place, not a /64.
        if (strlen($this->packedIpv4IfMapped($packed)) === 4) {
            return null;
        }

        $normalized = inet_ntop(substr($packed, 0, 8).str_repeat("\x00", 8));

        return $normalized !== false ? $normalized : null;
    }

    private function claimsTableReady(): bool
    {
        try {
            return Schema::hasTable((new WelcomeBonusClaim)->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Without bonus_balance the welcome credit lands in plain balance and
     * becomes withdrawable cash. Refuse the grant until the columns exist.
     */
    private function bonusColumnsReady(): bool
    {
        try {
            return Schema::hasColumn('wallets', 'bonus_balance');
        } catch (\Throwable) {
            return false;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();

        return $sqlState === '23000'
            || str_contains($message, 'UNIQUE')
            || str_contains($message, 'unique');
    }
}
