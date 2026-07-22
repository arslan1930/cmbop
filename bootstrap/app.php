<?php

// bootstrap/app.php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Public-site locale detection (SaaS dashboards stay English via SetLocale rules)
        // Security headers (CSP, HSTS, nosniff, frame, referrer) on every web response
        $middleware->appendToGroup('web', [
            SetLocale::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Production uses branded resources/views/errors/* pages (APP_DEBUG=false).
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return $request->expectsJson();
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

        // Content upload: release scheduled orders + 24h reminders; purge expired files
        $schedule->command('orders:release-scheduled')
            ->everyFiveMinutes()
            ->withoutOverlapping();
        $schedule->command('content:purge-expired')->dailyAt('03:30');

        // Publisher catalog enrichment (metrics + screenshots) — non-blocking scheduled refresh
        $enrichFreq = config('site_enrichment.refresh_frequency', 'weekly');
        $enrichCommand = $schedule->command('sites:enrich --stale --sync')
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
    })
    ->create();
