<?php

namespace Tests\Feature;

use App\Jobs\SendEmailCampaignJob;
use App\Mail\AudienceCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\AudienceInventoryService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function campaignPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Spring update',
            'subject' => 'Platform update',
            'body_html' => '<p>Hello partners.</p>',
            'audience' => 'advertisers',
            'respect_preferences' => '1',
        ], $overrides);
    }

    public function test_guest_is_redirected_from_campaigns(): void
    {
        $this->get(route('admin.campaigns.index'))
            ->assertRedirect(route('login'));
    }

    public function test_advertiser_cannot_access_campaigns(): void
    {
        $this->actingAs($this->makeUser('advertiser'))
            ->get(route('admin.campaigns.index'))
            ->assertForbidden();
    }

    public function test_marketing_is_redirected_from_campaigns(): void
    {
        $this->actingAs($this->makeUser('marketing'))
            ->get(route('admin.campaigns.index'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_admin_index_loads_and_preselects_audience(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->get(route('admin.campaigns.index', ['audience' => 'publishers_no_sites']))
            ->assertOk()
            ->assertSee('value="publishers_no_sites"', false)
            ->assertSee('name="respect_preferences" value="0"', false)
            ->assertSee('id="previewStatus"', false)
            ->assertSee('data-slb-confirm="Send this campaign', false)
            ->assertSee('id="campaignConfirmFallback"', false)
            ->assertSee('id="previewFrame"', false)
            ->assertSee('sandbox', false)
            ->assertSee('requestSubmit() throws if the submitter is disabled', false)
            ->assertSee("method: 'POST'", false)
            ->assertSee("Accept': 'application/json, text/html'", false)
            ->assertSee('name="include_unverified" value="0"', false)
            ->assertSee('Advertisers: never checked out', false)
            ->assertSee('value="advertisers_no_paid_orders"', false);

        $html = $this->actingAs($admin)
            ->get(route('admin.campaigns.index'))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="campaignSendBtn"[^>]*>/',
            $html
        );
        preg_match('/<button[^>]*id="campaignSendBtn"[^>]*>/', $html, $button);
        $this->assertStringNotContainsString('data-slb-confirm', $button[0] ?? '');
    }

    public function test_preview_returns_html_for_valid_payload(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->post(route('admin.campaigns.preview'), [
                'subject' => 'Preview subject',
                'body_html' => '<p>Preview body</p>',
            ])
            ->assertOk()
            ->assertSee('Preview body', false)
            ->assertSee('/email/unsubscribe/preview-id', false)
            ->getContent();

        $this->assertStringNotContainsString('/email/unsubscribe/'.$admin->id, $html);
        $this->assertStringNotContainsString((string) $admin->email, $html);
    }

    public function test_preview_rejects_empty_body(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.campaigns.preview'), [
                'subject' => 'Has a subject',
                'body_html' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body_html']);
    }

    public function test_recipient_count_matches_collect_for_core_audiences(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $both = $this->makeUser('advertiser');
        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $both->roles()->attach($pubRole->id);

        $inventory = app(AudienceInventoryService::class);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'advertisers']))
            ->assertOk()
            ->assertJson([
                'count' => $inventory->collect('advertisers')->count(),
                'label' => 'Advertisers',
                'unverified_excluded' => 0,
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'publishers']))
            ->assertOk()
            ->assertJson([
                'count' => $inventory->collect('publishers')->count(),
                'label' => 'Publishers',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'both']))
            ->assertOk()
            ->assertJson([
                'count' => $inventory->collect('both')->count(),
                'label' => 'Advertisers + Publishers',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', [
                'audience' => 'selected',
                'user_ids' => [$advertiser->id, $publisher->id],
            ]))
            ->assertOk()
            ->assertJson([
                'count' => 2,
                'label' => 'Selected users',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'selected']))
            ->assertOk()
            ->assertJson([
                'count' => 0,
                'label' => 'Selected users',
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'not-a-segment']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['audience']);

        $this->actingAs($admin)
            ->postJson(route('admin.campaigns.recipient-count'), [
                'audience' => 'selected',
                'user_ids' => [$advertiser->id, $publisher->id],
            ])
            ->assertOk()
            ->assertJson([
                'count' => 2,
                'label' => 'Selected users',
            ]);

        $this->actingAs($admin)
            ->postJson(route('admin.campaigns.recipient-count'), [
                'audience' => 'selected',
                'user_ids' => [$advertiser->id, 999999],
            ])
            ->assertOk()
            ->assertJson([
                'count' => 1,
                'label' => 'Selected users',
            ]);
    }

    public function test_hidden_zero_disables_preference_gate(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'Campaign queued for 1 recipient'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertFalse($campaign->respect_preferences);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->skipped_count);

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedOut->email));
    }

    public function test_preference_checkbox_one_skips_opted_out_users(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        $optedIn = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '1',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'Campaign queued for 2 recipient'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertTrue($campaign->respect_preferences);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, $campaign->skipped_count);
        $this->assertSame(
            EmailCampaignRecipient::SKIP_PREFERENCE,
            $campaign->recipients()->where('user_id', $optedOut->id)->value('skip_reason')
        );

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedIn->email));
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedOut->email));
    }

    public function test_campaign_routes_are_throttled(): void
    {
        $routes = collect(app('router')->getRoutes());

        $preview = $routes->first(fn ($route) => $route->getName() === 'admin.campaigns.preview');
        $send = $routes->first(fn ($route) => $route->getName() === 'admin.campaigns.send');
        $count = $routes->first(fn ($route) => $route->getName() === 'admin.campaigns.recipient-count');

        $this->assertNotNull($preview);
        $this->assertNotNull($send);
        $this->assertNotNull($count);
        $this->assertContains('throttle:20,1', $preview->gatherMiddleware());
        $this->assertContains('throttle:6,1', $send->gatherMiddleware());
        $this->assertContains('throttle:30,1', $count->gatherMiddleware());
    }

    public function test_preview_strips_javascript_links(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.preview'), [
                'subject' => 'Safe preview',
                'body_html' => '<p>Go <a href="javascript:alert(1)">here</a></p>',
            ])
            ->assertOk()
            ->assertDontSee('javascript:', false)
            ->assertSee('here', false);
    }

    public function test_preview_rejects_javascript_cta_url(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->postJson(route('admin.campaigns.preview'), [
                'subject' => 'Bad CTA',
                'body_html' => '<p>Hello</p>',
                'cta_label' => 'Click',
                'cta_url' => 'javascript:alert(1)',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cta_url']);
    }

    public function test_unverified_advertisers_are_excluded_by_default(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $verified = $this->makeUser('advertiser');
        $unverified = $this->makeUser('advertiser');
        $unverified->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($admin)
            ->getJson(route('admin.campaigns.recipient-count', ['audience' => 'advertisers']))
            ->assertOk()
            ->assertJson([
                'count' => 1,
                'unverified_excluded' => 1,
            ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
                'include_unverified' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'));

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($verified->email));
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($unverified->email));
    }

    public function test_include_unverified_sends_to_unverified_users(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $unverified = $this->makeUser('advertiser');
        $unverified->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
                'include_unverified' => '1',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($unverified->email));
    }

    public function test_selected_audience_rejects_admin_ids(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $otherAdmin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'audience' => 'selected',
                'user_ids' => [$otherAdmin->id],
                'respect_preferences' => '0',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error', 'No recipients found for that audience.');

        Mail::assertNothingQueued();
    }

    public function test_selected_audience_stores_marketplace_ids_only(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'audience' => 'selected',
                'user_ids' => [$admin->id, $advertiser->id],
                'respect_preferences' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertEquals([$advertiser->id], array_map('intval', $campaign->selected_user_ids ?? []));
        $this->assertEquals(
            [$advertiser->id],
            $campaign->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->all()
        );
        Mail::assertQueued(AudienceCampaignMail::class, 1);
    }

    public function test_no_paid_orders_excludes_paid_but_keeps_abandoned_checkout(): void
    {
        $admin = $this->makeUser('admin');
        $neverCheckedOut = $this->makeUser('advertiser');
        $abandoned = $this->makeUser('advertiser');
        $paid = $this->makeUser('advertiser');

        Order::create([
            'user_id' => $abandoned->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ABANDON-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        Order::create([
            'user_id' => $paid->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAID-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        $inventory = app(AudienceInventoryService::class);

        $neverIds = $inventory->collect('advertisers_never_checked_out')->pluck('id')->all();
        $this->assertContains($neverCheckedOut->id, $neverIds);
        $this->assertNotContains($abandoned->id, $neverIds);
        $this->assertNotContains($paid->id, $neverIds);
        $this->assertSame(
            $inventory->collect('advertisers_no_orders')->pluck('id')->all(),
            $neverIds
        );

        $refunded = $this->makeUser('advertiser');
        Order::create([
            'user_id' => $refunded->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-REFUND-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'refunded',
            'status' => 'cancelled',
        ]);

        $noPaidIds = $inventory->collect('advertisers_no_paid_orders')->pluck('id')->all();
        $this->assertContains($neverCheckedOut->id, $noPaidIds);
        $this->assertContains($abandoned->id, $noPaidIds);
        $this->assertNotContains($paid->id, $noPaidIds);
        $this->assertNotContains($refunded->id, $noPaidIds);

        $paidIds = $inventory->collect('advertisers_paid_orders')->pluck('id')->all();
        $this->assertContains($paid->id, $paidIds);
        $this->assertContains($refunded->id, $paidIds);
        $this->assertNotContains($abandoned->id, $paidIds);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'no_paid_orders']))
            ->assertOk()
            ->assertSee($abandoned->email, false)
            ->assertDontSee($paid->email, false)
            ->assertSee(route('admin.campaigns.index', ['audience' => 'advertisers_no_paid_orders'], false), false);
    }

    public function test_http_send_queues_job_without_sending_mail(): void
    {
        Queue::fake();
        Mail::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success', fn ($msg) => str_contains((string) $msg, 'Campaign queued for 1 recipient'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaign->status);
        $this->assertSame(0, $campaign->sent_count);
        $this->assertSame(1, $campaign->recipients()->where('status', EmailCampaignRecipient::STATUS_PENDING)->count());
        $this->assertSame($advertiser->id, $campaign->recipients()->value('user_id'));

        Queue::assertPushed(SendEmailCampaignJob::class, fn (SendEmailCampaignJob $job) => $job->campaignId === $campaign->id);
        Mail::assertNothingQueued();
    }

    public function test_job_skips_opted_out_and_queues_mail_for_the_rest(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        $optedIn = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '1',
            ]));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, $campaign->skipped_count);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_SKIPPED,
            $campaign->recipients()->where('user_id', $optedOut->id)->value('status')
        );
        $this->assertSame(
            EmailCampaignRecipient::SKIP_PREFERENCE,
            $campaign->recipients()->where('user_id', $optedOut->id)->value('skip_reason')
        );

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedIn->email) && $mail->skipUserPreference === false);
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($optedOut->email));
    }

    public function test_all_skipped_recipients_mark_campaign_failed(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '1',
            ]))
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $campaign->status);
        $this->assertSame(0, $campaign->sent_count);
        $this->assertSame(1, $campaign->skipped_count);
        Mail::assertNothingQueued();
    }

    public function test_job_exception_marks_campaign_failed_not_sending(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Crash',
            'subject' => 'Crash',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        $job = new class($campaign->id, SendEmailCampaignJob::MAX_FAIL_STREAK) extends SendEmailCampaignJob
        {
            protected function processPending(EmailCampaign $campaign): bool
            {
                throw new \RuntimeException('boom');
            }
        };
        $job->handle();

        $fresh = $campaign->fresh();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $fresh->status);
        $this->assertNotSame(EmailCampaign::STATUS_SENDING, $fresh->status);
        $this->assertSame(0, $fresh->sent_count);
        $this->assertSame(1, $fresh->skipped_count);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_FAILED,
            $campaign->recipients()->where('user_id', $advertiser->id)->value('status')
        );
    }

    public function test_job_exception_recounts_recipients_already_queued(): void
    {
        $admin = $this->makeUser('admin');
        $queuedUser = $this->makeUser('advertiser');
        $pendingUser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Partial',
            'subject' => 'Partial',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 2,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $queuedUser->id,
            'email' => $queuedUser->email,
            'status' => EmailCampaignRecipient::STATUS_QUEUED,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $pendingUser->id,
            'email' => $pendingUser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        $job = new class($campaign->id, SendEmailCampaignJob::MAX_FAIL_STREAK) extends SendEmailCampaignJob
        {
            protected function processPending(EmailCampaign $campaign): bool
            {
                throw new \RuntimeException('boom');
            }
        };
        $job->handle();

        $fresh = $campaign->fresh();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $fresh->status);
        $this->assertSame(1, $fresh->sent_count);
        $this->assertSame(1, $fresh->skipped_count);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_QUEUED,
            $campaign->recipients()->where('user_id', $queuedUser->id)->value('status')
        );
        $this->assertSame(
            EmailCampaignRecipient::STATUS_FAILED,
            $campaign->recipients()->where('user_id', $pendingUser->id)->value('status')
        );
        $this->assertSame(
            EmailCampaignRecipient::SKIP_ERROR,
            $campaign->recipients()->where('user_id', $pendingUser->id)->value('skip_reason')
        );
    }

    public function test_sent_mail_syncs_email_log_and_marks_recipient_delivered(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), $this->campaignPayload([
                'respect_preferences' => '0',
            ]))
            ->assertRedirect(route('admin.campaigns.index'));

        $campaign = EmailCampaign::query()->latest('id')->first();
        $recipient = $campaign->recipients()->where('user_id', $advertiser->id)->first();
        $this->assertSame(EmailCampaignRecipient::STATUS_DELIVERED, $recipient->status);
        $this->assertNotNull($recipient->email_log_id);

        $log = EmailLog::query()->find($recipient->email_log_id);
        $this->assertNotNull($log);
        $this->assertSame($campaign->id, (int) data_get($log->meta, 'campaign_id'));
        $this->assertSame($advertiser->id, (int) data_get($log->meta, 'user_id'));
    }

    public function test_stale_campaign_mail_skips_recipient_after_72_hours(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Stale',
            'subject' => 'Stale',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_QUEUED,
        ]);

        $mailable = new AudienceCampaignMail($campaign, $advertiser);
        $mailable->skipUserPreference = true;
        $mailable->dedupeKey = 'stale-campaign-test';
        $mailable->queuedAt = now()->subHours(80)->toIso8601String();
        $this->assertNull($mailable->send(app('mailer')));

        $this->assertSame(EmailCampaignRecipient::STATUS_SKIPPED, $row->fresh()->status);
        $this->assertSame(EmailCampaignRecipient::SKIP_STALE, $row->fresh()->skip_reason);
    }

    public function test_campaign_mail_is_not_stale_at_30_hours(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Fresh enough',
            'subject' => 'Fresh enough',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_QUEUED,
        ]);

        $mailable = new AudienceCampaignMail($campaign, $advertiser);
        $mailable->skipUserPreference = true;
        $mailable->dedupeKey = 'fresh-campaign-test';
        $mailable->queuedAt = now()->subHours(30)->toIso8601String();
        $mailable->to($advertiser->email);
        $this->assertNotNull($mailable->send(app('mailer')));

        $this->assertSame(EmailCampaignRecipient::STATUS_DELIVERED, $row->fresh()->status);
    }

    public function test_log_sync_does_not_break_mail_when_recipients_table_is_missing(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'No table',
            'subject' => 'No recipient table',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);

        Schema::drop('email_campaign_recipients');

        $mailable = new AudienceCampaignMail($campaign, $advertiser);
        $mailable->skipUserPreference = true;
        $mailable->dedupeKey = 'missing-recipients-table';
        $mailable->to($advertiser->email);

        $this->assertNotNull($mailable->send(app('mailer')));
        $this->assertDatabaseHas('email_logs', [
            'subject' => 'No recipient table',
            'to_email' => $advertiser->email,
        ]);
    }

    public function test_log_sync_updates_open_dedupe_row_instead_of_duplicating(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Retry sync',
            'subject' => 'Retry sync',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_QUEUED,
        ]);
        $failed = EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'dedupe_key' => 'campaign-retry-dedupe',
            'to_email' => $advertiser->email,
            'subject' => 'Retry sync',
            'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP down',
            'attempts' => 1,
            'meta' => ['failed_job_uuid' => 'keep-me'],
        ]);

        $mailable = new AudienceCampaignMail($campaign, $advertiser);
        $mailable->skipUserPreference = true;
        $mailable->dedupeKey = 'campaign-retry-dedupe';
        $mailable->to($advertiser->email);
        $this->assertNotNull($mailable->send(app('mailer')));

        $this->assertSame(1, EmailLog::query()->count());
        $fresh = $failed->fresh();
        $this->assertSame(EmailLog::STATUS_DELIVERED, $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertSame(2, $fresh->attempts);
        $this->assertSame($campaign->id, (int) data_get($fresh->meta, 'campaign_id'));
        $this->assertSame('keep-me', data_get($fresh->meta, 'failed_job_uuid'));
        $this->assertSame(EmailCampaignRecipient::STATUS_DELIVERED, $row->fresh()->status);
        $this->assertSame($failed->id, $row->fresh()->email_log_id);
    }

    public function test_queued_mail_honors_unsubscribe_before_send(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Late opt-out',
            'subject' => 'Late opt-out',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => true,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_QUEUED,
        ]);

        EmailNotificationPreference::updateOrCreate(
            [
                'user_id' => $advertiser->id,
                'preference_key' => 'marketing_emails',
            ],
            ['enabled' => false]
        );

        $mailable = new AudienceCampaignMail($campaign, $advertiser);
        $mailable->dedupeKey = 'audience_campaign:'.$campaign->id.':user:'.$advertiser->id;
        $mailable->to($advertiser->email);

        $this->assertFalse($mailable->skipUserPreference);
        $this->assertNull($mailable->send(app('mailer')));
        $this->assertSame(EmailCampaignRecipient::STATUS_SKIPPED, $row->fresh()->status);
        $this->assertSame(EmailCampaignRecipient::SKIP_PREFERENCE, $row->fresh()->skip_reason);
        $this->assertSame(0, $campaign->fresh()->sent_count);
        $this->assertSame(1, $campaign->fresh()->skipped_count);
    }

    public function test_mailable_failed_marks_recipient_failed(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'SMTP fail',
            'subject' => 'SMTP fail',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'sent_count' => 1,
            'status' => EmailCampaign::STATUS_SENT,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_QUEUED,
        ]);

        $mailable = new AudienceCampaignMail($campaign, $advertiser);
        $mailable->dedupeKey = 'audience_campaign:'.$campaign->id.':user:'.$advertiser->id;
        $mailable->failed(new \RuntimeException('SMTP down'));

        $fresh = $row->fresh();
        $this->assertSame(EmailCampaignRecipient::STATUS_FAILED, $fresh->status);
        $this->assertSame(EmailCampaignRecipient::SKIP_ERROR, $fresh->skip_reason);
        $this->assertNotNull($fresh->email_log_id);
        $this->assertSame(0, $campaign->fresh()->sent_count);
        $this->assertSame(1, $campaign->fresh()->skipped_count);
        $this->assertSame(EmailCampaign::STATUS_FAILED, $campaign->fresh()->status);
        $this->assertDatabaseHas('email_logs', [
            'id' => $fresh->email_log_id,
            'status' => EmailLog::STATUS_FAILED,
            'dedupe_key' => $mailable->dedupeKey,
        ]);
    }

    public function test_successful_send_after_failure_marks_recipient_delivered(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Retry after fail',
            'subject' => 'Retry after fail',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'sent_count' => 0,
            'skipped_count' => 1,
            'status' => EmailCampaign::STATUS_FAILED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_FAILED,
            'skip_reason' => EmailCampaignRecipient::SKIP_ERROR,
        ]);

        $mailable = new AudienceCampaignMail($campaign, $advertiser);
        $mailable->skipUserPreference = true;
        $mailable->dedupeKey = 'audience_campaign:'.$campaign->id.':user:'.$advertiser->id;
        $mailable->to($advertiser->email);
        $this->assertNotNull($mailable->send(app('mailer')));

        $fresh = $row->fresh();
        $this->assertSame(EmailCampaignRecipient::STATUS_DELIVERED, $fresh->status);
        $this->assertNull($fresh->skip_reason);
        $this->assertSame(1, $campaign->fresh()->sent_count);
        $this->assertSame(0, $campaign->fresh()->skipped_count);
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_job_skips_pending_when_campaign_type_is_disabled(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        EmailNotificationSetting::updateOrCreate(
            ['type' => 'audience_campaign'],
            ['enabled' => false]
        );
        EmailNotificationSetting::flushCache('audience_campaign');

        $campaign = EmailCampaign::create([
            'name' => 'Disabled type',
            'subject' => 'Disabled type',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        (new SendEmailCampaignJob($campaign->id))->handle();

        $fresh = $campaign->fresh();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $fresh->status);
        $this->assertSame(0, $fresh->sent_count);
        $this->assertSame(1, $fresh->skipped_count);
        $this->assertSame(
            EmailCampaignRecipient::SKIP_DISABLED,
            $campaign->recipients()->where('user_id', $advertiser->id)->value('skip_reason')
        );
    }

    public function test_job_does_not_reprocess_a_finished_campaign(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Already sent',
            'subject' => 'Already sent',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'sent_count' => 1,
            'status' => EmailCampaign::STATUS_SENT,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        (new SendEmailCampaignJob($campaign->id))->handle();

        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_PENDING,
            $campaign->recipients()->where('user_id', $advertiser->id)->value('status')
        );
        Mail::assertNothingQueued();
    }

    public function test_job_processes_one_batch_and_redispatches(): void
    {
        Queue::fake();
        Mail::fake();

        $admin = $this->makeUser('admin');
        $users = [];
        for ($i = 0; $i < SendEmailCampaignJob::BATCH_SIZE + 5; $i++) {
            $users[] = $this->makeUser('advertiser');
        }

        $campaign = EmailCampaign::create([
            'name' => 'Batch',
            'subject' => 'Batch',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => count($users),
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        foreach ($users as $user) {
            EmailCampaignRecipient::create([
                'email_campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => EmailCampaignRecipient::STATUS_PENDING,
            ]);
        }

        (new SendEmailCampaignJob($campaign->id))->handle();

        $fresh = $campaign->fresh();
        $this->assertSame(EmailCampaign::STATUS_SENDING, $fresh->status);
        $this->assertSame(
            SendEmailCampaignJob::BATCH_SIZE,
            $campaign->recipients()->where('status', EmailCampaignRecipient::STATUS_QUEUED)->count()
        );
        $this->assertSame(
            5,
            $campaign->recipients()->where('status', EmailCampaignRecipient::STATUS_PENDING)->count()
        );
        Queue::assertPushed(SendEmailCampaignJob::class, fn (SendEmailCampaignJob $job) => $job->campaignId === $campaign->id);
        Mail::assertQueued(AudienceCampaignMail::class, SendEmailCampaignJob::BATCH_SIZE);
    }

    public function test_job_timeout_redispatches_instead_of_wiping_pending(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Timeout',
            'subject' => 'Timeout',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        (new SendEmailCampaignJob($campaign->id))->failed(new \RuntimeException('Job timed out'));

        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaign->fresh()->status);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_PENDING,
            $campaign->recipients()->where('user_id', $advertiser->id)->value('status')
        );
        Queue::assertPushed(SendEmailCampaignJob::class, fn (SendEmailCampaignJob $job) => $job->campaignId === $campaign->id);
    }

    public function test_index_redispatches_a_stale_queued_campaign(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Lost job',
            'subject' => 'Lost job',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);
        $campaign->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $this->actingAs($admin)
            ->get(route('admin.campaigns.index'))
            ->assertOk();

        Queue::assertPushed(SendEmailCampaignJob::class, fn (SendEmailCampaignJob $job) => $job->campaignId === $campaign->id);
        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaign->fresh()->status);
    }

    public function test_first_job_exception_redispatches_instead_of_wiping_pending(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Blip',
            'subject' => 'Blip',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        $job = new class($campaign->id) extends SendEmailCampaignJob
        {
            protected function processPending(EmailCampaign $campaign): bool
            {
                throw new \RuntimeException('deadlock');
            }
        };
        $job->handle();

        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaign->fresh()->status);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_PENDING,
            $campaign->recipients()->where('user_id', $advertiser->id)->value('status')
        );
        Queue::assertPushed(
            SendEmailCampaignJob::class,
            fn (SendEmailCampaignJob $queued) => $queued->campaignId === $campaign->id && $queued->failStreak === 1
        );
    }

    public function test_job_failed_before_claim_does_not_wipe_pending(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Unclaimed',
            'subject' => 'Unclaimed',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);

        (new SendEmailCampaignJob($campaign->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame(EmailCampaign::STATUS_QUEUED, $campaign->fresh()->status);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_PENDING,
            $campaign->recipients()->where('user_id', $advertiser->id)->value('status')
        );
        Queue::assertNothingPushed();
    }

    public function test_drain_command_recovers_stalled_campaigns_when_auto_drain_is_off(): void
    {
        Queue::fake();
        config([
            'email_notifications.auto_drain' => false,
            'email_notifications.queue_connection' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Cron recover',
            'subject' => 'Cron recover',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);
        $campaign->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $this->artisan('mail:drain-queue')
            ->expectsOutputToContain('auto-drain is disabled')
            ->assertSuccessful();

        Queue::assertPushed(SendEmailCampaignJob::class, fn (SendEmailCampaignJob $job) => $job->campaignId === $campaign->id);
    }

    public function test_stall_recovery_gives_up_when_fail_streak_is_exhausted(): void
    {
        Queue::fake();

        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Loop',
            'subject' => 'Loop',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'status' => EmailCampaign::STATUS_SENDING,
            'respect_preferences' => false,
            'created_by' => $admin->id,
        ]);
        EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_PENDING,
        ]);
        $campaign->rememberFailStreak(SendEmailCampaignJob::MAX_FAIL_STREAK);
        $campaign->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $this->assertSame(0, EmailCampaign::recoverStalled());

        $fresh = $campaign->fresh();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $fresh->status);
        $this->assertSame(
            EmailCampaignRecipient::STATUS_FAILED,
            $campaign->recipients()->where('user_id', $advertiser->id)->value('status')
        );
        Queue::assertNothingPushed();
    }

    public function test_orphaned_queued_recipients_expire_after_campaign_max_age(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $campaign = EmailCampaign::create([
            'name' => 'Orphan',
            'subject' => 'Orphan',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 1,
            'sent_count' => 1,
            'status' => EmailCampaign::STATUS_SENT,
            'respect_preferences' => false,
            'created_by' => $admin->id,
            'sent_at' => now()->subHours(80),
        ]);
        $row = EmailCampaignRecipient::create([
            'email_campaign_id' => $campaign->id,
            'user_id' => $advertiser->id,
            'email' => $advertiser->email,
            'status' => EmailCampaignRecipient::STATUS_QUEUED,
        ]);
        $row->forceFill(['updated_at' => now()->subHours(80)])->save();

        EmailCampaign::recoverStalled();

        $this->assertSame(EmailCampaignRecipient::STATUS_SKIPPED, $row->fresh()->status);
        $this->assertSame(EmailCampaignRecipient::SKIP_STALE, $row->fresh()->skip_reason);
        $this->assertSame(0, $campaign->fresh()->sent_count);
        $this->assertSame(EmailCampaign::STATUS_FAILED, $campaign->fresh()->status);
    }
}
