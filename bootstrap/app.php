<?php

// bootstrap/app.php

use App\Http\Controllers\Advertiser\AddFundsController;
use App\Http\Middleware\CanonicalHost;
use App\Http\Middleware\DrainQueuedMail;
use App\Http\Middleware\HealHostingerProduction;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\ContentUpload\ContentUploadService;
use App\Support\TrustedProxies;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $appRoot = dirname(__DIR__);
        $loadAppClass = static function (string $relativePath) use ($appRoot): bool {
            $file = $appRoot.DIRECTORY_SEPARATOR.$relativePath;
            if (! is_file($file)) {
                return false;
            }
            require_once $file;

            return true;
        };

        // Trust only listed hops. "*" used to honor client X-Forwarded-For
        // (login limits and some money keys). Hostinger+Cloudflare: TRUSTED_PROXIES=cloudflare.
        $trusted = [];
        if ($loadAppClass('app/Support/TrustedProxies.php')) {
            $trusted = TrustedProxies::addresses() ?: [];
        }
        $middleware->trustProxies(at: $trusted);

        // Gmail List-Unsubscribe=One-Click POSTs have no CSRF token.
        $middleware->validateCsrfTokens(except: [
            'email/unsubscribe/*',
        ]);

        // Public-site locale detection (SaaS dashboards stay English via SetLocale rules)
        // Security headers (CSP, HSTS, nosniff, frame, referrer) on every web response
        // Partial Hostinger uploads often ship bootstrap/app.php without these files.
        if ($loadAppClass('app/Http/Middleware/CanonicalHost.php')) {
            $middleware->prependToGroup('web', CanonicalHost::class);
        }
        $webAppend = [];
        if ($loadAppClass('app/Http/Middleware/SetLocale.php')) {
            $webAppend[] = SetLocale::class;
        }
        if ($loadAppClass('app/Http/Middleware/SecurityHeaders.php')) {
            $webAppend[] = SecurityHeaders::class;
        }
        if ($webAppend !== []) {
            $middleware->appendToGroup('web', $webAppend);
        }

        // Queued mail needs a consumer. Hosts without a worker or a per-minute
        // cron have neither, so ordinary traffic drains the queue after the
        // response is already on its way out.
        if ($loadAppClass('app/Http/Middleware/DrainQueuedMail.php')) {
            $middleware->append(DrainQueuedMail::class);
        }
        if ($loadAppClass('app/Http/Middleware/HealHostingerProduction.php')) {
            $middleware->append(HealHostingerProduction::class);
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Production uses branded resources/views/errors/* pages (APP_DEBUG=false).
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return $request->expectsJson();
        });

        // ValidatePostSize runs before routing. A 5 MB .docx with PHP still at
        // 2M/8M becomes a 413 with no file. Return JSON so the Library fetch
        // does not show a generic "Upload failed" / "over the 10 MB limit".
        $exceptions->render(function (PostTooLargeException $e, $request) {
            if (! $request->expectsJson() || ! class_exists(ContentUploadService::class)) {
                return null;
            }
            $uploads = app(ContentUploadService::class);
            $path = $request->path();
            if (str_contains($path, 'content-submissions/editor-image')) {
                [, $clientBytes] = $uploads->uploadByteHints($request);

                return response()->json([
                    'success' => false,
                    'message' => $uploads->phpImageRejectedMessage($clientBytes),
                ], 422);
            }
            if (! str_contains($path, 'content-library/upload')
                && ! str_contains($path, 'content-submissions/upload')) {
                return null;
            }

            [, $clientBytes] = $uploads->uploadByteHints($request);

            return response()->json([
                'success' => false,
                'message' => $uploads->phpSizeRejectedMessage(null, $clientBytes),
            ], 422);
        });

        // Implicit {deposit} binding becomes NotFoundHttpException after
        // prepareException(). The Eloquent text leaks App\Models\DepositRequest.
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if (! $request->expectsJson() || ! str_contains($request->path(), 'add-funds')) {
                return null;
            }

            $previous = $e->getPrevious();
            $fromDeposit = $previous instanceof ModelNotFoundException
                && str_contains((string) $previous->getModel(), 'DepositRequest');
            $leaksModel = str_contains($e->getMessage(), 'DepositRequest')
                || str_contains($e->getMessage(), 'No query results');

            if (! $fromDeposit && ! $leaksModel) {
                return null;
            }

            $controller = AddFundsController::class;
            if (class_exists($controller) && method_exists($controller, 'missingInvoiceJson')) {
                return $controller::missingInvoiceJson();
            }

            return response()->json([
                'success' => false,
                'message' => 'Invoice not found.',
            ], 404);
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // Auto-approve window (default 72h / 3 days) — every 15 minutes
        $event = $schedule->command('orders:auto-approve')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->sendOutputTo(storage_path('logs/auto-approve.log'));

        $adminEmail = config('mail.admin_email');
        if (filled($adminEmail)) {
            $event->emailOutputOnFailure($adminEmail);
        }

        // Email digests (respect user preferences + admin toggles inside mailables)
        $schedule->command('emails:send-digests --type=weekly')->weeklyOn(1, '8:00');
        $schedule->command('emails:send-digests --type=monthly')->monthlyOn(1, '8:15');

        // Publishers who registered but never listed a site: day 3 + day 7 nudges
        $schedule->command('emails:send-publisher-add-site-reminders')
            ->dailyAt('09:15')
            ->withoutOverlapping();

        // Advertisers who registered but never funded a wallet: day 7 + day 14 nudges
        $schedule->command('emails:send-deposit-reminders')
            ->dailyAt('09:30')
            ->withoutOverlapping();

        // Order reminder cadences. Hourly rather than daily so a stage that comes
        // due at 12h or 36h fires near its mark instead of drifting to the next
        // morning; each command advances an item by at most one stage per run.
        $schedule->command('orders:nudge-publishers')
            ->hourly()
            ->withoutOverlapping();

        $schedule->command('orders:nudge-advertisers')
            ->hourly()
            ->withoutOverlapping();

        // New and discounted listings for advertisers who have bought before.
        // Daily run, per-recipient 15-day clock inside the command.
        $schedule->command('sites:send-new-sites-digest')
            ->dailyAt('10:15')
            ->withoutOverlapping();

        // Content upload: release scheduled orders + 24h reminders; purge expired files
        $schedule->command('orders:release-scheduled')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('content:purge-expired')->dailyAt('03:30');

        // Publisher catalog enrichment (metrics + screenshots) — non-blocking scheduled refresh
        $enrichFreq = config('site_enrichment.refresh_frequency', 'weekly');
        $enrichCommand = $schedule->command('sites:enrich --stale')
            ->withoutOverlapping()
            ->sendOutputTo(storage_path('logs/site-enrichment.log'));

        if ($enrichFreq === 'daily') {
            $enrichCommand->dailyAt('04:15');
        } else {
            $enrichCommand->weeklyOn(2, '4:15'); // Tuesday
        }

        // Notify publishers when timed site discounts expire
        $schedule->command('sites:notify-expired-discounts')
            ->hourly()
            ->withoutOverlapping();

        // Auto-complete file verification when publishers uploaded the txt but forgot to click Check
        $schedule->command('sites:recheck-file-verification --limit=100')
            ->dailyAt('05:10')
            ->withoutOverlapping();

        // Queued mail sits on the "emails" queue until a worker consumes it. Hosts
        // that only offer cron have no resident worker, so drain the backlog here.
        $schedule->command('mail:drain-queue')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground();
    })
    ->create();
