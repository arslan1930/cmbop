<?php

namespace Tests\Feature;

use App\Http\Middleware\DrainQueuedMail;
use App\Jobs\SendEmailCampaignJob;
use App\Mail\PlatformMailable;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Platform mail is queued, so something has to consume the queue. The host has
 * no resident worker, and mail:drain-queue only runs if `schedule:run` fires
 * every minute — which this deployment does not do, so a backlog of 212 jobs
 * built up unattended. Ordinary web traffic now drains it.
 */
class WebMailDrainTest extends TestCase
{
    use RefreshDatabase;

    private function useDatabaseMailQueue(): void
    {
        config([
            'queue.default' => 'database',
            'email_notifications.queue_connection' => 'database',
            'email_notifications.queue' => 'emails',
            'email_notifications.auto_drain' => true,
        ]);
    }

    private function queueMail(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            Mail::to("drain{$i}@example.com")->send(new DrainProbeMail);
        }
    }

    public function test_the_drain_is_registered_so_every_request_can_deliver_mail(): void
    {
        $middleware = $this->app->make(Kernel::class)->getGlobalMiddleware();

        $this->assertContains(DrainQueuedMail::class, $middleware);
    }

    public function test_ordinary_traffic_delivers_queued_mail(): void
    {
        $this->useDatabaseMailQueue();
        $this->queueMail(3);

        $this->assertSame(3, DB::table('jobs')->count(), 'Mail should be queued, not sent inline.');

        $this->get('/')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count(), 'A page view should have drained the queue.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_each_request_only_takes_a_bounded_bite(): void
    {
        $this->useDatabaseMailQueue();
        $this->queueMail(9);

        $this->get('/')->assertSuccessful();

        // Capped so a backlog cannot hold a php-fpm process hostage.
        $this->assertSame(4, DB::table('jobs')->count());

        $this->get('/')->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_nothing_is_drained_when_mail_is_sent_inline(): void
    {
        config([
            'email_notifications.queue_connection' => 'sync',
            'email_notifications.auto_drain' => true,
        ]);

        $this->get('/')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_web_drain_runs_campaign_jobs_when_mail_is_sync_but_app_queue_is_database(): void
    {
        Mail::fake();
        $this->seed(RolesTableSeeder::class);
        config([
            'queue.default' => 'database',
            'email_notifications.queue_connection' => 'sync',
            'email_notifications.queue' => 'emails',
            'email_notifications.auto_drain' => true,
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $advertiser->roles()->attach($role->id);

        $campaign = EmailCampaign::create([
            'name' => 'Sync mail drain',
            'subject' => 'Sync mail drain',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        SendEmailCampaignJob::dispatch($campaign->id);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->get('/')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaign->fresh()->status);
    }

    public function test_web_drain_recovers_stalled_campaign_when_both_queues_are_sync(): void
    {
        Mail::fake();
        $this->seed(RolesTableSeeder::class);
        config([
            'queue.default' => 'sync',
            'email_notifications.queue_connection' => 'sync',
            'email_notifications.auto_drain' => true,
        ]);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $advertiser->roles()->attach($role->id);

        $campaign = EmailCampaign::create([
            'name' => 'Inline recover',
            'subject' => 'Inline recover',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);
        $campaign->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $this->get('/')->assertSuccessful();

        $this->assertNotSame(EmailCampaign::STATUS_QUEUED, $campaign->fresh()->status);
    }

    public function test_the_drain_can_be_turned_off_for_hosts_with_a_worker(): void
    {
        $this->useDatabaseMailQueue();
        config(['email_notifications.auto_drain' => false]);
        $this->queueMail(2);

        $this->get('/')->assertSuccessful();

        // Left for the dedicated worker to pick up.
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_a_broken_queue_backend_does_not_break_the_page(): void
    {
        $this->useDatabaseMailQueue();
        config(['queue.connections.database.table' => 'jobs_that_do_not_exist']);

        $this->get('/')->assertSuccessful();
    }

    public function test_the_scheduler_can_be_triggered_over_http_when_cron_is_unavailable(): void
    {
        $secret = str_repeat('s', 40);
        config(['app.cron_secret' => $secret]);

        $this->get('/cron/run/'.$secret)
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_the_scheduler_trigger_is_closed_without_a_strong_secret(): void
    {
        config(['app.cron_secret' => 'short']);

        $this->get('/cron/run/short')->assertNotFound();
    }

    public function test_the_scheduler_trigger_rejects_a_wrong_key(): void
    {
        config(['app.cron_secret' => str_repeat('s', 40)]);

        $this->get('/cron/run/'.str_repeat('x', 40))->assertForbidden();
    }
}

/** Named so the queue can serialise it; anonymous classes cannot be queued. */
class DrainProbeMail extends PlatformMailable
{
    public function build(): self
    {
        return $this->subject('Drain probe')->html('<p>probe</p>');
    }
}
