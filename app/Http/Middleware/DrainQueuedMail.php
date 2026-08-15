<?php

namespace App\Http\Middleware;

use App\Models\EmailCampaign;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Delivers queued mail using ordinary web traffic as the clock.
 *
 * Platform mail is queued, so it needs something to consume the queue. Shared
 * hosting has no resident worker, and mail:drain-queue only helps if the host
 * runs `schedule:run` every minute — which is exactly the cron plans like this
 * tend not to have. Without either, the jobs table simply grows.
 *
 * This runs in terminate(), after the response has been flushed to the browser,
 * so the visitor never waits for SMTP. Work is capped per request and guarded by
 * a lock, so concurrent requests cannot pile workers on top of each other.
 */
class DrainQueuedMail
{
    /**
     * Jobs to attempt per request (oldest first). Keep this small so php-fpm
     * is not held hostage. A burst of welcome/admin mail queued just before a
     * chat send can leave the newer chat email for the next page view.
     */
    private const MAX_JOBS = 5;

    /** Wall-clock budget per request, in seconds. */
    private const MAX_SECONDS = 8;

    private const LOCK_KEY = 'mail-queue:web-drain';

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        if (! $this->enabled()) {
            return;
        }

        $lock = $this->lock();

        // Another request is already draining; leave the queue to it.
        if ($lock && ! $lock->get()) {
            return;
        }

        try {
            $this->drain();
        } catch (\Throwable $e) {
            Log::warning('Web mail drain failed', ['error' => $e->getMessage()]);
        } finally {
            $lock?->release();
        }
    }

    private function enabled(): bool
    {
        return (bool) config('email_notifications.auto_drain');
    }

    /**
     * @return list<string>
     */
    private function queues(): array
    {
        return collect([config('email_notifications.queue', 'emails'), 'default'])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function lock(): mixed
    {
        try {
            $store = Cache::store()->getStore();

            if (! $store instanceof LockProvider) {
                return null;
            }

            return Cache::store()->lock(self::LOCK_KEY, self::MAX_SECONDS + 5);
        } catch (\Throwable) {
            return null;
        }
    }

    private function drain(): void
    {
        try {
            EmailCampaign::recoverStalled();
        } catch (\Throwable $e) {
            Log::warning('Campaign stall recovery failed', ['error' => $e->getMessage()]);
        }

        $queues = $this->queues();

        $worker = app('queue.worker');
        // sleep: 0 so an empty queue returns immediately instead of parking the
        // php-fpm process for the worker's usual pause.
        $options = new WorkerOptions(maxTries: 3, timeout: 30, sleep: 0);

        $deadline = microtime(true) + self::MAX_SECONDS;
        $handled = 0;

        foreach (EmailCampaign::drainableQueueConnections() as $connection) {
            for (; $handled < self::MAX_JOBS; $handled++) {
                if (microtime(true) >= $deadline || ! $this->hasPending($connection, $queues)) {
                    break;
                }

                $worker->runNextJob($connection, implode(',', $queues), $options);
            }
        }
    }

    /**
     * @param  list<string>  $queues
     */
    private function hasPending(string $connection, array $queues): bool
    {
        $queue = app(QueueFactory::class)->connection($connection);

        foreach ($queues as $name) {
            if ($queue->size($name) > 0) {
                return true;
            }
        }

        return false;
    }
}
