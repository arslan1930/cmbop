<?php

namespace Tests\Feature;

use App\Mail\AdminNewUserRegistered;
use App\Mail\AudienceCampaignMail;
use App\Mail\PublisherAddSiteReminderMail;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AudienceInventoryService;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnboardingSegmentsAndRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function makeUser(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Onboarding Site',
            'site_url' => 'https://onboarding-site.example',
            'domain' => 'onboarding-site.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 500,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Onboarding segment test site description.',
            'verified' => false,
            'active' => false,
        ]);
    }

    private function makeOrder(User $advertiser): Order
    {
        return Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ONB-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    public function test_no_orders_and_no_sites_segment_membership(): void
    {
        $advNoOrder = $this->makeUser('advertiser');
        $advWithOrder = $this->makeUser('advertiser');
        $this->makeOrder($advWithOrder);

        $pubNoSite = $this->makeUser('publisher');
        $pubWithSite = $this->makeUser('publisher');
        $this->makeSite($pubWithSite);

        $inventory = app(AudienceInventoryService::class);

        $noOrderIds = $inventory->collect('advertisers_no_orders')->pluck('id')->all();
        $this->assertContains($advNoOrder->id, $noOrderIds);
        $this->assertNotContains($advWithOrder->id, $noOrderIds);
        $this->assertNotContains($pubNoSite->id, $noOrderIds);

        $noSiteIds = $inventory->collect('publishers_no_sites')->pluck('id')->all();
        $this->assertContains($pubNoSite->id, $noSiteIds);
        $this->assertNotContains($pubWithSite->id, $noSiteIds);
        $this->assertNotContains($advNoOrder->id, $noSiteIds);

        $stats = $inventory->stats();
        $this->assertSame(1, $stats['advertisers_no_orders']);
        $this->assertSame(1, $stats['publishers_no_sites']);
    }

    public function test_audience_inventory_tabs_and_export(): void
    {
        $admin = $this->makeUser('admin');
        $adv = $this->makeUser('advertiser');
        $pub = $this->makeUser('publisher');

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'no_orders']))
            ->assertOk()
            ->assertSee($adv->email, false)
            ->assertSee(route('admin.campaigns.index', ['audience' => 'advertisers_no_orders'], false), false);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'no_sites']))
            ->assertOk()
            ->assertSee($pub->email, false)
            ->assertSee(route('admin.campaigns.index', ['audience' => 'publishers_no_sites'], false), false);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => ['advertisers'], 'q' => [$adv->email]]))
            ->assertOk()
            ->assertSee($adv->email, false)
            ->assertDontSee('Array to string conversion', false);

        $csvAdv = $this->actingAs($admin)
            ->get(route('admin.audiences.export', ['audience' => 'no_orders']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($adv->email, $csvAdv);
        $this->assertStringContainsString('advertisers_no_orders', $csvAdv);

        $csvPub = $this->actingAs($admin)
            ->get(route('admin.audiences.export', ['audience' => 'no_sites']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($pub->email, $csvPub);
        $this->assertStringContainsString('publishers_no_sites', $csvPub);
    }

    public function test_campaign_send_accepts_onboarding_audiences(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $target = $this->makeUser('publisher');
        $withSite = $this->makeUser('publisher');
        $this->makeSite($withSite);

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), [
                'name' => 'Add site nudge',
                'subject' => 'List your first website',
                'body_html' => '<p>Add a site to start receiving orders.</p>',
                'audience' => 'publishers_no_sites',
                'cta_label' => 'Add website',
                'cta_url' => url('/publisher/websites'),
                'respect_preferences' => false,
            ])
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame('publishers_no_sites', $campaign->audience);
        $this->assertSame('Publishers (no sites)', $campaign->audienceLabel());
        $this->assertSame(1, $campaign->recipients_count);

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $m) => $m->hasTo($target->email));
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $m) => $m->hasTo($withSite->email));
    }

    public function test_admin_signup_notice_is_role_specific(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $notifications = app(InAppNotificationService::class);
        $notifications->notifyAdminsNewUser($advertiser);
        $notifications->notifyAdminsNewUser($publisher);

        $advNote = InAppNotification::where('user_id', $admin->id)
            ->where('title', 'New advertiser registered')
            ->first();
        $this->assertNotNull($advNote);
        $this->assertStringContainsString('advertiser', (string) $advNote->message);
        $this->assertStringContainsString('tab=no_orders', (string) $advNote->action_url);

        $pubNote = InAppNotification::where('user_id', $admin->id)
            ->where('title', 'New publisher registered')
            ->first();
        $this->assertNotNull($pubNote);
        $this->assertStringContainsString('publisher', (string) $pubNote->message);
        $this->assertStringContainsString('tab=no_sites', (string) $pubNote->action_url);

        $advMail = (new AdminNewUserRegistered($advertiser, $admin))->render();
        $this->assertStringContainsString('New advertiser registered', $advMail);
        $this->assertStringContainsString('tab=no_orders', $advMail);

        $pubMail = (new AdminNewUserRegistered($publisher, $admin))->render();
        $this->assertStringContainsString('New publisher registered', $pubMail);
        $this->assertStringContainsString('tab=no_sites', $pubMail);
    }

    public function test_publisher_day3_and_day7_reminders(): void
    {
        Mail::fake();

        $day3 = $this->makeUser('publisher', [
            'created_at' => now()->subDays(3)->setTime(11, 0),
            'updated_at' => now()->subDays(3)->setTime(11, 0),
        ]);
        $day7 = $this->makeUser('publisher', [
            'created_at' => now()->subDays(7)->setTime(10, 0),
            'updated_at' => now()->subDays(7)->setTime(10, 0),
        ]);
        $withSite = $this->makeUser('publisher', [
            'created_at' => now()->subDays(3)->setTime(12, 0),
            'updated_at' => now()->subDays(3)->setTime(12, 0),
        ]);
        $this->makeSite($withSite);

        Artisan::call('emails:send-publisher-add-site-reminders');

        Mail::assertQueued(PublisherAddSiteReminderMail::class, function (PublisherAddSiteReminderMail $mail) use ($day3) {
            return $mail->hasTo($day3->email)
                && $mail->step === PublisherAddSiteReminderMail::STEP_DAY3
                && $mail->dedupeKey === 'publisher_add_site:day3:'.$day3->id;
        });
        Mail::assertQueued(PublisherAddSiteReminderMail::class, function (PublisherAddSiteReminderMail $mail) use ($day7) {
            return $mail->hasTo($day7->email)
                && $mail->step === PublisherAddSiteReminderMail::STEP_DAY7;
        });
        Mail::assertNotQueued(PublisherAddSiteReminderMail::class, fn (PublisherAddSiteReminderMail $m) => $m->hasTo($withSite->email));
    }

    public function test_publisher_reminder_skips_unverified_wrong_age_and_opt_out(): void
    {
        Mail::fake();

        $unverified = $this->makeUser('publisher', [
            'email_verified_at' => null,
            'created_at' => now()->subDays(3)->setTime(12, 0),
            'updated_at' => now()->subDays(3)->setTime(12, 0),
        ]);
        // Too new for the day3 catch-up window (min 3 days).
        $wrongAge = $this->makeUser('publisher', [
            'created_at' => now()->subDays(1)->setTime(12, 0),
            'updated_at' => now()->subDays(1)->setTime(12, 0),
        ]);
        $optOut = $this->makeUser('publisher', [
            'created_at' => now()->subDays(3)->setTime(13, 0),
            'updated_at' => now()->subDays(3)->setTime(13, 0),
        ]);
        EmailNotificationPreference::create([
            'user_id' => $optOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);

        Artisan::call('emails:send-publisher-add-site-reminders', ['--step' => 'day3']);

        Mail::assertNotQueued(PublisherAddSiteReminderMail::class, fn (PublisherAddSiteReminderMail $m) => $m->hasTo($unverified->email));
        Mail::assertNotQueued(PublisherAddSiteReminderMail::class, fn (PublisherAddSiteReminderMail $m) => $m->hasTo($wrongAge->email));

        // Preference-off is filtered before queue, so no mailable is queued.
        Mail::assertNotQueued(PublisherAddSiteReminderMail::class, fn (PublisherAddSiteReminderMail $m) => $m->hasTo($optOut->email));
        $mailable = new PublisherAddSiteReminderMail($optOut, PublisherAddSiteReminderMail::STEP_DAY3);
        $mailable->dedupeKey = 'publisher_add_site:day3:'.$optOut->id;
        $this->assertNull($mailable->send(app('mailer')));
    }

    public function test_publisher_reminder_dedupe_and_dry_run(): void
    {
        Mail::fake();

        $user = $this->makeUser('publisher', [
            'created_at' => now()->subDays(7)->setTime(12, 0),
            'updated_at' => now()->subDays(7)->setTime(12, 0),
        ]);

        EmailLog::create([
            'notification_type' => 'publisher_add_site_reminder',
            'dedupe_key' => 'publisher_add_site:day7:'.$user->id,
            'to_email' => $user->email,
            'status' => EmailLog::STATUS_DELIVERED,
            'mailable' => PublisherAddSiteReminderMail::class,
        ]);

        $mailable = new PublisherAddSiteReminderMail($user, PublisherAddSiteReminderMail::STEP_DAY7);
        $mailable->dedupeKey = 'publisher_add_site:day7:'.$user->id;
        $this->assertNull($mailable->send(app('mailer')));

        Artisan::call('emails:send-publisher-add-site-reminders', [
            '--step' => 'day7',
            '--dry-run' => true,
        ]);
        Mail::assertNothingQueued();
    }

    public function test_publisher_reminder_mailables_render_cta(): void
    {
        $user = $this->makeUser('publisher');

        $day3 = (new PublisherAddSiteReminderMail($user, PublisherAddSiteReminderMail::STEP_DAY3))->render();
        $this->assertStringContainsString('List your first website', $day3);
        $this->assertStringContainsString('/publisher/websites', $day3);

        $day7 = (new PublisherAddSiteReminderMail($user, PublisherAddSiteReminderMail::STEP_DAY7))->render();
        $this->assertStringContainsString('Finish setup', $day7);
        $this->assertStringContainsString('Add your website now', $day7);
    }
}
