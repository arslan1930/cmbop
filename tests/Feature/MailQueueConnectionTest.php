<?php

namespace Tests\Feature;

use App\Mail\PlatformMailable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailQueueConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_queue_connection_follows_app_queue_by_default(): void
    {
        $config = file_get_contents(config_path('email_notifications.php'));

        // Without an explicit override, platform mail must ride the app queue so
        // checkout does not block on SMTP.
        $this->assertStringContainsString(
            "env('MAIL_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync'))",
            $config
        );
    }

    public function test_testing_env_file_exists_so_dotenv_does_not_warn(): void
    {
        $this->assertFileExists(base_path('.env.testing'));
    }

    public function test_env_example_does_not_pin_mail_to_sync(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringNotContainsString('MAIL_QUEUE_CONNECTION=sync', $env);
        $this->assertStringContainsString('MAIL_QUEUE_CONNECTION=', $env);
    }

    public function test_platform_mailables_are_queueable(): void
    {
        $this->assertTrue(
            is_subclass_of(PlatformMailable::class, ShouldQueue::class),
            'PlatformMailable must implement ShouldQueue so Mail::send() queues it.'
        );
    }

    public function test_scheduler_drains_the_mail_queue_every_minute(): void
    {
        // Queued mail never reaches an inbox unless something consumes the queue,
        // and shared hosting cannot keep a worker resident.
        $this->artisan('schedule:list')->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'mail:drain-queue'));

        $this->assertNotNull($scheduled, 'mail:drain-queue must be scheduled.');
        $this->assertSame('* * * * *', $scheduled->expression);
    }

    public function test_drain_is_a_no_op_when_mail_is_sent_synchronously(): void
    {
        config(['email_notifications.queue_connection' => 'sync']);

        $this->artisan('mail:drain-queue')
            ->expectsOutputToContain('there is no queue to drain')
            ->assertSuccessful();
    }

    public function test_drain_can_be_disabled_for_hosts_with_a_dedicated_worker(): void
    {
        config([
            'email_notifications.auto_drain' => false,
            'email_notifications.queue_connection' => 'database',
        ]);

        $this->artisan('mail:drain-queue')
            ->expectsOutputToContain('auto-drain is disabled')
            ->assertSuccessful();
    }

    public function test_drain_skips_quietly_when_the_jobs_table_is_missing(): void
    {
        config([
            'email_notifications.auto_drain' => true,
            'email_notifications.queue_connection' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs_that_do_not_exist',
        ]);

        $this->artisan('mail:drain-queue')
            ->expectsOutputToContain('is not ready')
            ->assertSuccessful();
    }

    public function test_mailables_fall_back_to_sync_when_the_jobs_table_is_missing(): void
    {
        config([
            'email_notifications.queue_connection' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs_that_do_not_exist',
        ]);

        // Queueing onto a backend that cannot store the job would drop the mail.
        $mailable = new class extends PlatformMailable
        {
            public function build(): self
            {
                return $this->html('probe');
            }
        };

        $this->assertSame('sync', $mailable->connection);
    }

    public function test_mailables_use_the_configured_queue_when_the_backend_is_ready(): void
    {
        config([
            'email_notifications.queue_connection' => 'database',
            'email_notifications.queue' => 'emails',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);

        $mailable = new class extends PlatformMailable
        {
            public function build(): self
            {
                return $this->html('probe');
            }
        };

        $this->assertSame('database', $mailable->connection);
        $this->assertSame('emails', $mailable->queue);
    }
}
