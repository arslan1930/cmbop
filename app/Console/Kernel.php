<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Register commands
     */
    protected $commands = [
        \App\Console\Commands\AutoApproveOrders::class,
    ];

    /**
     * Define scheduled commands
     */
    protected function schedule(Schedule $schedule): void
    {
        // Kept in sync with bootstrap/app.php (Laravel 11+ uses bootstrap schedule)
        $schedule->command('orders:auto-approve')
                 ->everyFifteenMinutes()
                 ->withoutOverlapping();
    }

    /**
     * Register command files
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}