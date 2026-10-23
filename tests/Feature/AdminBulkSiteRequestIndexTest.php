<?php

namespace Tests\Feature;

use App\Console\Commands\RemindBulkPublishers;
use App\Mail\BulkSiteRequestPublisherReminder;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\MarketingOpsQueues;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminBulkSiteRequestIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $publisher;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
            'name' => 'Index Admin',
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Index Marketer',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
            'name' => 'Index Publisher',
            'email' => 'index.publisher@example.com',
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);
    }

    public function test_advertiser_cannot_open_the_staff_queue(): void
    {
        $this->actingAs($this->advertiser)
            ->get(route('admin.bulk-site-requests.index'))
            ->assertForbidden();
    }

    public function test_index_shows_next_step_age_pill_and_drops_duplicate_waiting_pill(): void
    {
        $bulk = $this->makeRequestedBulk('needs-prices', 68);
        $bulk->forceFill(['created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)])->save();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index'))
            ->assertOk()
            ->assertSee('Req #', false)
            ->assertSee('#'.$bulk->id, false)
            ->assertSee('Needs prices for 68 URLs', false)
            ->assertSee('bulk-age-pill--red', false)
            ->assertSee('Overdue', false)
            ->assertSee('Mine', false)
            ->assertSee('data-bulk-mine-toggle', false)
            ->assertSee('Export CSV', false)
            ->assertSee('Assign selected', false)
            ->assertDontSee('data-bulk-waiting-on-you', false)
            ->assertDontSee('Sites: waiting</a>', false)
            ->getContent();

        $this->assertStringContainsString(route('admin.bulk-site-requests.show', $bulk), $html);
        $this->assertStringContainsString('Send for review', $html);
        $this->assertStringContainsString('form-live', $html);
    }

    public function test_blocks_new_bulk_only_when_it_still_blocks_with_tooltip(): void
    {
        $open = $this->makeRequestedBulk('still-blocks', 2);
        $done = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 1,
            'completed_at' => now(),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', ['status' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertTrue($open->blocksPublisherNewBulk());
        $this->assertFalse($done->fresh()->blocksPublisherNewBulk());
        $this->assertStringContainsString('Blocks new bulk', $html);
        $this->assertStringContainsString('Clears when every leftover row is Done', $html);
    }

    public function test_overdue_filter_uses_configured_red_days(): void
    {
        config(['bulk_site_requests.sla.red_days' => 7]);
        $stale = $this->makeRequestedBulk('stale-overdue', 1);
        $stale->forceFill(['created_at' => now()->subDays(8)])->save();
        $fresh = $this->makeRequestedBulk('fresh-ok', 1);

        $this->assertSame(1, MarketingOpsQueues::bulkOverdue()->count());

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', [
                'status' => MarketingOpsQueues::FILTER_OVERDUE,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.bulk-site-requests.show', $stale), $html);
        $this->assertStringNotContainsString(route('admin.bulk-site-requests.show', $fresh), $html);
    }

    public function test_mine_toggle_and_handler_counts_filter_the_queue(): void
    {
        $mine = $this->makeRequestedBulk('mine-row', 1);
        $mine->forceFill(['handled_by' => $this->admin->id])->save();
        $theirs = $this->makeRequestedBulk('theirs-row', 1);
        $theirs->forceFill(['handled_by' => $this->marketer->id])->save();

        $mineHtml = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', ['mine' => 1]))
            ->assertOk()
            ->assertSee('aria-pressed="true"', false)
            ->getContent();
        $this->assertStringContainsString(route('admin.bulk-site-requests.show', $mine), $mineHtml);
        $this->assertStringNotContainsString(route('admin.bulk-site-requests.show', $theirs), $mineHtml);

        $handlerHtml = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', [
                'status' => 'all',
                'handler' => $this->marketer->id,
            ]))
            ->assertOk()
            ->assertSee('Index Marketer', false)
            ->getContent();
        $this->assertStringContainsString(route('admin.bulk-site-requests.show', $theirs), $handlerHtml);
        $this->assertStringNotContainsString(route('admin.bulk-site-requests.show', $mine), $handlerHtml);
    }

    public function test_column_headers_sort_pending_and_waiting(): void
    {
        $older = $this->makeRequestedBulk('sort-old', 2);
        $older->forceFill(['created_at' => now()->subDays(4)])->save();
        $newer = $this->makeRequestedBulk('sort-new', 9);
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        $oldest = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', ['sort' => 'oldest']))
            ->assertOk()
            ->getContent();
        $this->assertLessThan(
            strpos($oldest, '#'.$newer->id),
            strpos($oldest, '#'.$older->id)
        );

        $pending = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', ['sort' => 'pending']))
            ->assertOk()
            ->getContent();
        $this->assertLessThan(
            strpos($pending, '#'.$older->id),
            strpos($pending, '#'.$newer->id)
        );
    }

    public function test_assign_single_and_bulk_updates_handler(): void
    {
        $first = $this->makeRequestedBulk('assign-one', 1);
        $second = $this->makeRequestedBulk('assign-two', 1);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.assign', $first), [
                'handled_by' => $this->marketer->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame($this->marketer->id, (int) $first->fresh()->handled_by);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.bulk-assign'), [
                'ids' => [$first->id, $second->id],
                'handled_by' => $this->admin->id,
            ])
            ->assertRedirect();
        $this->assertSame($this->admin->id, (int) $first->fresh()->handled_by);
        $this->assertSame($this->admin->id, (int) $second->fresh()->handled_by);
        $this->assertSame('Assigned bulk request', activity_action_label('bulk_request.assigned'));
    }

    public function test_remind_uses_existing_mail_and_records_last_reminded(): void
    {
        Mail::fake();
        $bulk = $this->makeWaitingOnPublisher('remind-me');

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', [
                'status' => MarketingOpsQueues::FILTER_WAITING_PUBLISHER,
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Remind', $html);
        $this->assertStringContainsString('Sites: waiting', $html);
        $this->assertStringContainsString('Waiting on publisher to add details for 1 site', $html);

        $this->actingAs($this->admin)
            ->post(route('admin.bulk-site-requests.remind', $bulk))
            ->assertRedirect()
            ->assertSessionHas('success', 'Reminder sent to the publisher.');

        Mail::assertQueued(BulkSiteRequestPublisherReminder::class, 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.publisher_reminded',
            'subject_id' => $bulk->id,
        ]);

        $after = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', [
                'status' => MarketingOpsQueues::FILTER_WAITING_PUBLISHER,
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Last reminded', $after);
    }

    public function test_automatic_reminder_command_is_disabled_by_default(): void
    {
        Mail::fake();
        $this->makeWaitingOnPublisher('auto-off');
        BulkSiteRequest::query()->update(['created_at' => now()->subDays(10)]);

        $this->assertFalse((bool) config('bulk_site_requests.publisher_reminder.auto_enabled'));

        $this->artisan(RemindBulkPublishers::class)
            ->expectsOutputToContain('Automatic bulk publisher reminders are disabled')
            ->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_automatic_reminder_sends_only_when_enabled(): void
    {
        Mail::fake();
        config([
            'bulk_site_requests.publisher_reminder.auto_enabled' => true,
            'bulk_site_requests.publisher_reminder.min_age_days' => 3,
            'bulk_site_requests.publisher_reminder.cooldown_days' => 7,
        ]);
        $bulk = $this->makeWaitingOnPublisher('auto-on');
        $bulk->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('bulk:remind-publishers')
            ->expectsOutputToContain('Sent 1')
            ->assertSuccessful();

        Mail::assertQueued(BulkSiteRequestPublisherReminder::class, 1);
    }

    public function test_csv_export_omits_prices_and_includes_next_step(): void
    {
        $bulk = $this->makeRequestedBulk('csv-row', 4);

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', ['export' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Req #', $csv);
        $this->assertStringContainsString((string) $bulk->id, $csv);
        $this->assertStringContainsString('Needs prices for 4 URLs', $csv);
        $this->assertStringNotContainsString('40', $csv);
        $this->assertStringNotContainsString('markup', strtolower($csv));
        $this->assertStringNotContainsString('advertiser', strtolower($csv));
    }

    public function test_bulk_cancel_requires_reason_and_notifies(): void
    {
        Mail::fake();
        $bulk = $this->makeRequestedBulk('cancel-me', 1);

        $this->actingAs($this->admin)
            ->from(route('admin.bulk-site-requests.index'))
            ->post(route('admin.bulk-site-requests.bulk-cancel'), [
                'ids' => [$bulk->id],
                'reason' => 'too short',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)
            ->from(route('admin.bulk-site-requests.index'))
            ->post(route('admin.bulk-site-requests.bulk-cancel'), [
                'ids' => [$bulk->id],
                'reason' => 'Cancelled from the queue during review.',
            ])
            ->assertRedirect();

        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
    }

    public function test_publish_ready_button_reuses_publish_now_when_sites_are_ready(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SEEDED,
            'estimated_count' => 1,
            'seeded_at' => now(),
        ]);
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'added_from_bulk_request' => true,
            'site_name' => 'Ready Publish',
            'site_url' => 'https://ready-publish.example',
            'domain' => 'ready-publish.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 25,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Ready publish description. ', 3),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_STAFF_HOLD,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $site->site_url,
            'domain' => $site->domain,
            'price' => 25,
            'site_id' => $site->id,
        ]);

        $this->assertTrue($bulk->fresh()->canPublishReady());

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bulk-site-requests.index', ['status' => 'all']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Publish ready', $html);
        $this->assertStringContainsString(route('admin.bulk-site-requests.publish-now', $bulk, false), $html);
    }

    public function test_sla_and_next_step_helpers(): void
    {
        config([
            'bulk_site_requests.sla.amber_days' => 3,
            'bulk_site_requests.sla.red_days' => 7,
        ]);
        $bulk = $this->makeRequestedBulk('sla-row', 3);
        $bulk->forceFill(['created_at' => now()->subDays(3)])->save();
        $fresh = $bulk->fresh();
        $this->assertSame('amber', $fresh->slaTone());
        $this->assertSame('Needs prices for 3 URLs', $fresh->nextStepText());
        $this->assertNotNull($fresh->blocksNewBulkTooltip());
    }

    private function makeRequestedBulk(string $prefix, int $pending): BulkSiteRequest
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => $pending,
        ]);
        for ($i = 1; $i <= $pending; $i++) {
            BulkSiteRequestItem::create([
                'bulk_site_request_id' => $bulk->id,
                'site_url' => 'https://'.$prefix.'-'.$i.'.example',
                'domain' => $prefix.'-'.$i.'.example',
                'price' => 40,
            ]);
        }

        return $bulk;
    }

    private function makeWaitingOnPublisher(string $prefix): BulkSiteRequest
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
            'seeded_at' => now(),
        ]);
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'added_from_bulk_request' => true,
            'site_name' => 'Waiting '.$prefix,
            'site_url' => 'https://'.$prefix.'.example',
            'domain' => $prefix.'.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 30,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Waiting publisher description. ', 3),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $site->site_url,
            'domain' => $site->domain,
            'price' => 30,
            'site_id' => $site->id,
        ]);

        return $bulk;
    }
}
