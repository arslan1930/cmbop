<?php

namespace App\Http\Middleware;

use App\Support\HostingerMediaPath;
use App\Support\ProductionRepair;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hostinger has no SSH from this agent.
 * Before the response when promotions tables are missing, then after
 * flush: repair MEDIA_PATH / APP_URL / storage link (at most every few hours).
 * Migrations stay on `php artisan ops:production-ready --repair`.
 * The scheduler stays on system cron or POST /cron/run.
 * Mail still drains via DrainQueuedMail.
 */
class HealHostingerProduction
{
    private const HEAL_LOCK = 'ops:hostinger-heal';

    // v2: bust the 6-hour skip left by a failed migrate (flag was set anyway).
    public const HEAL_FLAG = 'ops:hostinger-healed-v2';

    public const HEAL_RETRY = 'ops:hostinger-heal-retry';

    private bool $healedThisRequest = false;

    public function handle(Request $request, Closure $next)
    {
        // terminate() is too late for Promotions: the hub already rendered
        // Unknown / the red banner. Heal first when storage is incomplete.
        if ($this->enabled() && ! $this->healCooldownCached() && ! ProductionRepair::promotionsStorageReady()) {
            $this->healOnce();
        }

        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->healOnce();
    }

    private function enabled(): bool
    {
        if (app()->runningInConsole() || ProductionRepair::runningAutomatedTest()) {
            return false;
        }

        if (! (bool) config('app.web_heal', true)) {
            return false;
        }

        return app()->environment('production')
            || HostingerMediaPath::looksLikeHostinger();
    }

    private function healOnce(): void
    {
        if ($this->healedThisRequest) {
            return;
        }

        if ($this->healCooldownCached()) {
            return;
        }

        $lock = $this->lock(self::HEAL_LOCK, 120);
        if ($lock && ! $lock->get()) {
            return;
        }

        $this->healedThisRequest = true;

        try {
            $notes = app(ProductionRepair::class)->run(true, false);
            $skippedMigrate = in_array('migrate skipped on web request', $notes, true);
            $repairFailed = collect($notes)->contains(function ($note): bool {
                return is_string($note) && (
                    str_contains($note, 'failed')
                    || str_starts_with($note, 'migrate --force exited')
                );
            });
            if (! $repairFailed && (ProductionRepair::migrateCompleted($notes) || $skippedMigrate)) {
                Cache::put(self::HEAL_FLAG, true, now()->addHours(6));
            } else {
                // Always throttle. A leftover claims table (or settings-only
                // ensureTable) used to leave promotionsStorageReady false and
                // re-run full migrate on every request.
                Cache::put(self::HEAL_RETRY, true, now()->addMinutes(5));
                Log::warning('Hostinger production heal did not cache skip; migrate incomplete', [
                    'notes' => $notes,
                ]);
            }
            Log::info('Hostinger production heal ran', ['notes' => $notes]);
        } catch (\Throwable $e) {
            Log::warning('Hostinger production heal failed', ['error' => $e->getMessage()]);
        } finally {
            $lock?->release();
        }
    }

    private function healCooldownCached(): bool
    {
        try {
            return (bool) Cache::get(self::HEAL_FLAG) || (bool) Cache::get(self::HEAL_RETRY);
        } catch (\Throwable) {
            // Cache down / no cache table: still migrate so Promotions can load.
            return false;
        }
    }

    private function lock(string $name, int $seconds): mixed
    {
        try {
            $store = Cache::store()->getStore();
            if (! $store instanceof LockProvider) {
                return null;
            }

            return Cache::store()->lock($name, $seconds);
        } catch (\Throwable) {
            return null;
        }
    }
}
