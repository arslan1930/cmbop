<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Hostinger Business caps PHP at 120 processes. Overlapping schedule:run
 * ticks, a forked mail drain, and chat/order polling were the pile-up paths.
 */
class SchedulerProcessCapTest extends TestCase
{
    public function test_every_scheduled_command_has_an_overlap_mutex_and_runs_on_one_server(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events());
        $this->assertGreaterThan(10, $events->count(), 'Expected the production schedule to be registered.');

        foreach ($events as $event) {
            $command = (string) $event->command;
            $this->assertTrue(
                $event->withoutOverlapping,
                $command.' must use withoutOverlapping() so a slow tick cannot stack artisan processes.'
            );
            $this->assertTrue(
                $event->onOneServer,
                $command.' must use onOneServer() so CLI schedule:run and POST /cron/run cannot both fire it.'
            );
            $this->assertFalse(
                $event->runInBackground,
                $command.' must not runInBackground() — a forked PHP process counts against the 120-process cap.'
            );
        }
    }

    public function test_mail_drain_uses_a_capped_queue_work_and_is_not_backgrounded(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'mail:drain-queue'));

        $this->assertNotNull($scheduled);
        $this->assertSame('* * * * *', $scheduled->expression);
        $this->assertStringContainsString('--max-time=40', (string) $scheduled->command);
        $this->assertLessThanOrEqual(10, (int) $scheduled->expiresAt);

        $source = (string) file_get_contents(app_path('Console/Commands/DrainMailQueue.php'));
        $this->assertStringContainsString("'--stop-when-empty' => true", $source);
        $this->assertStringContainsString("'--max-time'", $source);
    }

    public function test_webhook_deliveries_are_time_boxed_on_the_schedule(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect(app(Schedule::class)->events());

        $partner = $events->first(fn ($event) => str_contains((string) $event->command, 'partners:deliver-webhooks'));
        $advertiser = $events->first(fn ($event) => str_contains((string) $event->command, 'advertisers:deliver-webhooks'));

        $this->assertNotNull($partner);
        $this->assertNotNull($advertiser);
        $this->assertStringContainsString('--max-seconds=40', (string) $partner->command);
        $this->assertStringContainsString('--max-seconds=40', (string) $advertiser->command);
        $this->assertSame('* * * * *', $partner->expression);
        $this->assertSame('* * * * *', $advertiser->expression);
    }

    public function test_removed_auto_approve_http_cron_stays_gone_and_artisan_is_scheduled(): void
    {
        config(['app.cron_secret' => str_repeat('a', 40)]);

        $this->get('/cron/orders-auto-approve/'.str_repeat('a', 40))->assertNotFound();

        $this->artisan('schedule:list')->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'orders:auto-approve'));

        $this->assertNotNull($scheduled, 'orders:auto-approve is the replacement for GET /cron/orders-auto-approve/{token}.');
        $this->assertSame('*/15 * * * *', $scheduled->expression);

        $docs = (string) file_get_contents(base_path('docs/ops-mail-reminders.md'));
        $this->assertStringContainsString('GET /cron/orders-auto-approve/{token}', $docs);
        $this->assertStringContainsString('php artisan orders:auto-approve', $docs);
        $this->assertStringContainsString('flock -n /tmp/laravel-schedule.lock', $docs);
    }

    public function test_dashboard_polling_is_at_least_thirty_seconds_and_skips_hidden_tabs(): void
    {
        $chat = (string) file_get_contents(public_path('js/order-chat.js'));
        $this->assertStringContainsString('var POLL_MS = 30000;', $chat);
        $this->assertStringContainsString('document.hidden', $chat);
        $this->assertSame(
            md5($chat),
            md5((string) file_get_contents(public_path('assets/js/order-chat.js'))),
            'Both order-chat.js copies must stay identical.'
        );

        $thread = (string) file_get_contents(public_path('js/order-inline-thread.js'));
        $this->assertStringContainsString('var POLL_MS = 30000;', $thread);
        $this->assertStringContainsString('document.hidden', $thread);

        $nc = (string) file_get_contents(public_path('assets/js/notification-center.js'));
        $this->assertStringContainsString('}, 60000);', $nc);
        $this->assertStringContainsString('document.hidden', $nc);
        $this->assertStringNotContainsString('45000', $nc);

        $ordersJs = (string) file_get_contents(public_path('assets/js/advertiser-orders.js'));
        $this->assertStringContainsString('}, 60000);', $ordersJs);

        $tasks = (string) file_get_contents(resource_path('views/publisher/tasks.blade.php'));
        $this->assertStringContainsString('}, 60000);', $tasks);
        $this->assertStringContainsString('document.hidden', $tasks);

        $advertiserLayout = (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php'));
        $this->assertStringContainsString('setInterval(refreshHeaderAlerts, 60000)', $advertiserLayout);

        $publisherLayout = (string) file_get_contents(resource_path('views/publisher/layouts/app.blade.php'));
        $this->assertStringContainsString('setInterval(refreshHeaderAlerts, 60000)', $publisherLayout);
    }

    public function test_google_drive_token_refresh_has_an_http_timeout(): void
    {
        $source = (string) file_get_contents(app_path('Services/Backup/GoogleDriveClient.php'));
        $this->assertStringContainsString('Http::asForm()->timeout(20)->connectTimeout(10)', $source);
    }

    public function test_console_kernel_schedule_stays_empty(): void
    {
        $kernel = (string) file_get_contents(app_path('Console/Kernel.php'));
        $this->assertStringContainsString('Intentionally empty', $kernel);
        $this->assertStringNotContainsString("command('", $kernel);
    }
}
