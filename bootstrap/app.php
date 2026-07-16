<?php
// bootstrap/app.php

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
        // Apple Sign In may POST the callback (form_post response mode)
        $middleware->validateCsrfTokens(except: [
            'auth/apple/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Production uses branded resources/views/errors/* pages (APP_DEBUG=false).
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->expectsJson();
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // 48-hour window — every 15 minutes is enough; everyMinute was unnecessarily aggressive
        $event = $schedule->command('orders:auto-approve')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->sendOutputTo(storage_path('logs/auto-approve.log'));

        $adminEmail = config('mail.admin_email');
        if (filled($adminEmail)) {
            $event->emailOutputOnFailure($adminEmail);
        }
    })
    ->create();