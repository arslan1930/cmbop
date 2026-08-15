<?php

namespace Tests\Feature;

use App\Mail\AudienceCampaignMail;
use App\Models\ActivityLog;
use App\Models\DepositRequest;
use App\Models\EmailCampaign;
use App\Models\EmailNotificationPreference;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AudienceInventoryService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminAudienceInventoryTest extends TestCase
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

    private function attachRole(User $user, string $roleName): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Inventory Site',
            'site_url' => 'https://inventory-site.example',
            'domain' => 'inventory-site.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 500,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Audience inventory test site description.',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    private function deposit(User $user, string $status): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => (string) random_int(100000, 999999),
            'amount' => 50,
            'payment_method' => 'wise',
            'status' => $status,
        ]);
    }

    public function test_inventory_shows_all_and_verified_counts(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('advertiser');
        $this->makeUser('advertiser', ['email_verified_at' => null]);

        $stats = app(AudienceInventoryService::class)->stats();
        $this->assertSame(2, $stats['advertisers']);
        $this->assertSame(2, $stats['advertisers_all']);
        $this->assertSame(1, $stats['advertisers_verified']);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index'))
            ->assertOk()
            ->assertSee('emailable (verified)', false)
            ->assertSee('Campaigns email verified users only', false);
    }

    public function test_search_treats_percent_as_literal(): void
    {
        $admin = $this->makeUser('admin');
        $match = $this->makeUser('advertiser', ['name' => 'Offer 100% Club']);
        $other = $this->makeUser('advertiser', ['name' => 'Offer 100 Club']);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'advertisers', 'q' => '100%']))
            ->assertOk()
            ->assertSee($match->email, false)
            ->assertDontSee($other->email, false);
    }

    public function test_search_is_applied_to_export(): void
    {
        $admin = $this->makeUser('admin');
        $match = $this->makeUser('advertiser', ['email' => 'keep-me@example.com']);
        $other = $this->makeUser('advertiser', ['email' => 'skip-me@example.com']);

        $csv = $this->actingAs($admin)
            ->get(route('admin.audiences.export', ['audience' => 'advertisers', 'q' => 'keep-me']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($match->email, $csv);
        $this->assertStringNotContainsString($other->email, $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    public function test_both_tab_and_export_are_unique(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $dual = $this->makeUser('advertiser');
        $this->attachRole($dual, 'publisher');

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'both']))
            ->assertOk()
            ->assertSee($advertiser->email, false)
            ->assertSee($publisher->email, false)
            ->assertSee($dual->email, false)
            ->assertSee(route('admin.campaigns.index', ['audience' => 'both'], false), false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.audiences.export', ['audience' => 'both']))
            ->assertOk()
            ->streamedContent();

        $this->assertSame(1, substr_count($csv, $dual->email));
        $this->assertStringContainsString('both', $csv);
    }

    public function test_name_links_to_admin_user_profile(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->get(route('admin.audiences.index'))
            ->assertOk()
            ->assertSee(route('admin.users.index', ['user' => $advertiser->id], false), false);
    }

    public function test_paid_and_deposited_intersection_tabs(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser('advertiser');
        Order::create([
            'user_id' => $customer->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CUST-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        $fundedIdle = $this->makeUser('advertiser');
        $this->deposit($fundedIdle, 'completed');

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'paid_orders']))
            ->assertOk()
            ->assertSee($customer->email, false)
            ->assertDontSee($fundedIdle->email, false)
            ->assertSee(route('admin.campaigns.index', ['audience' => 'advertisers_paid_orders'], false), false);

        $abandonedFunded = $this->makeUser('advertiser');
        $this->deposit($abandonedFunded, 'completed');
        Order::create([
            'user_id' => $abandonedFunded->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ABANDON-DEP-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'deposited_no_orders']))
            ->assertOk()
            ->assertSee($fundedIdle->email, false)
            ->assertSee($abandonedFunded->email, false)
            ->assertDontSee($customer->email, false)
            ->assertSee('never became a customer', false);
    }

    public function test_picker_lists_verified_users_before_unverified(): void
    {
        $this->makeUser('advertiser', ['name' => 'Aaa One', 'email_verified_at' => null]);
        $this->makeUser('advertiser', ['name' => 'Aaa Two', 'email_verified_at' => null]);
        $verified = $this->makeUser('advertiser', ['name' => 'Zed Verified']);

        $inventory = app(AudienceInventoryService::class);
        $picker = $inventory->pickerUsers('advertiser', 2);

        $this->assertTrue($picker->contains('id', $verified->id));
        $this->assertCount(2, $picker);
        $this->assertTrue($inventory->pickerIsCapped('advertiser', 2));
        $this->assertFalse($inventory->pickerIsCapped('advertiser', 10));
    }

    public function test_no_active_sites_includes_draft_only_publishers(): void
    {
        $admin = $this->makeUser('admin');
        $draftOnly = $this->makeUser('publisher');
        $this->makeSite($draftOnly, ['active' => false, 'domain' => 'draft-only.example', 'site_url' => 'https://draft-only.example']);
        $live = $this->makeUser('publisher');
        $this->makeSite($live, ['active' => true, 'verified' => true, 'domain' => 'live-site.example', 'site_url' => 'https://live-site.example']);
        $empty = $this->makeUser('publisher');
        $archivedOnly = $this->makeUser('publisher');
        $this->makeSite($archivedOnly, [
            'active' => true,
            'verified' => true,
            'archived_at' => now(),
            'domain' => 'archived-only.example',
            'site_url' => 'https://archived-only.example',
        ]);
        $unverifiedActive = $this->makeUser('publisher');
        $this->makeSite($unverifiedActive, [
            'active' => true,
            'verified' => false,
            'domain' => 'unverified-active.example',
            'site_url' => 'https://unverified-active.example',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'no_active_sites']))
            ->assertOk()
            ->assertSee($draftOnly->email, false)
            ->assertSee($empty->email, false)
            ->assertSee($archivedOnly->email, false)
            ->assertSee($unverifiedActive->email, false)
            ->assertDontSee($live->email, false)
            ->assertSee('no catalog-visible listing', false)
            ->assertDontSee('never paid or were refunded', false);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'no_sites']))
            ->assertOk()
            ->assertSee($empty->email, false)
            ->assertDontSee($draftOnly->email, false);
    }

    public function test_verified_and_country_filters(): void
    {
        $admin = $this->makeUser('admin');
        $de = $this->makeUser('advertiser', ['country' => 'DE']);
        $fr = $this->makeUser('advertiser', ['country' => 'FR']);
        $unverified = $this->makeUser('advertiser', ['email_verified_at' => null, 'country' => 'DE']);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'advertisers', 'verified' => 'yes', 'country' => 'de']))
            ->assertOk()
            ->assertSee($de->email, false)
            ->assertDontSee($fr->email, false)
            ->assertDontSee($unverified->email, false);
    }

    public function test_marketing_and_dual_role_filters(): void
    {
        $admin = $this->makeUser('admin');
        $optedOut = $this->makeUser('advertiser');
        EmailNotificationPreference::create([
            'user_id' => $optedOut->id,
            'preference_key' => 'marketing_emails',
            'enabled' => false,
        ]);
        $dual = $this->makeUser('advertiser');
        $this->attachRole($dual, 'publisher');
        $plain = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'advertisers', 'marketing' => 'opted_in']))
            ->assertOk()
            ->assertSee($plain->email, false)
            ->assertDontSee($optedOut->email, false);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'advertisers', 'exclude_dual_role' => 1]))
            ->assertOk()
            ->assertSee($plain->email, false)
            ->assertDontSee($dual->email, false);

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'both', 'exclude_dual_role' => 1]))
            ->assertOk()
            ->assertSee($plain->email, false)
            ->assertSee($optedOut->email, false)
            ->assertDontSee($dual->email, false);
    }

    public function test_csv_sanitizes_formula_cells_and_logs_export(): void
    {
        $admin = $this->makeUser('admin');
        $risky = $this->makeUser('advertiser', ['name' => '=1+1']);

        $csv = $this->actingAs($admin)
            ->get(route('admin.audiences.export', ['audience' => 'advertisers']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString($risky->email, $csv);

        $this->assertTrue(
            ActivityLog::query()->where('action', 'audience.exported')->exists()
        );
    }

    public function test_empty_filters_show_clear_copy(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', ['tab' => 'advertisers', 'q' => 'nobody-matches-this']))
            ->assertOk()
            ->assertSee('No users match these filters', false);
    }

    public function test_unknown_audience_key_is_empty_for_count_collect_and_send(): void
    {
        $this->makeUser('advertiser');
        $inventory = app(AudienceInventoryService::class);

        $this->assertSame(0, $inventory->count('not-a-segment'));
        $this->assertSame([], $inventory->collect('not-a-segment')->pluck('id')->all());
        $this->assertSame([], $inventory->collectRecipientRows('not-a-segment')->pluck('id')->all());
    }

    public function test_collect_accepts_tab_slug_aliases(): void
    {
        $neverCheckedOut = $this->makeUser('advertiser');
        $inventory = app(AudienceInventoryService::class);

        $this->assertContains(
            $neverCheckedOut->id,
            $inventory->collect('no_orders')->pluck('id')->all()
        );
        $this->assertSame(
            $inventory->collect('advertisers_no_orders')->pluck('id')->all(),
            $inventory->collect('no_orders')->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            $inventory->collect('advertisers_no_orders')->pluck('id')->all(),
            $inventory->collectRecipientRows('no_orders')->pluck('id')->all()
        );
    }

    public function test_collect_recipient_rows_covers_new_segments(): void
    {
        $customer = $this->makeUser('advertiser');
        Order::create([
            'user_id' => $customer->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ROWS-'.random_int(1000, 9999),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $fundedIdle = $this->makeUser('advertiser');
        $this->deposit($fundedIdle, 'completed');
        $draftOnly = $this->makeUser('publisher');
        $this->makeSite($draftOnly, ['active' => false, 'domain' => 'rows-draft.example', 'site_url' => 'https://rows-draft.example']);

        $inventory = app(AudienceInventoryService::class);
        foreach ([
            'advertisers_paid_orders',
            'advertisers_deposited_no_orders',
            'publishers_no_active_sites',
        ] as $key) {
            $this->assertEqualsCanonicalizing(
                $inventory->collect($key)->pluck('id')->all(),
                $inventory->collectRecipientRows($key)->pluck('id')->all(),
                $key
            );
        }
        $this->assertContains($customer->id, $inventory->collectRecipientRows('advertisers_paid_orders')->pluck('id'));
        $this->assertContains($fundedIdle->id, $inventory->collectRecipientRows('advertisers_deposited_no_orders')->pluck('id'));
        $this->assertContains($draftOnly->id, $inventory->collectRecipientRows('publishers_no_active_sites')->pluck('id'));
        $this->assertEqualsCanonicalizing(
            ['id', 'email'],
            array_keys($inventory->collectRecipientRows('advertisers_paid_orders')->first()->getAttributes())
        );
    }

    public function test_inverted_registration_dates_are_swapped(): void
    {
        $admin = $this->makeUser('admin');
        $inside = $this->makeUser('advertiser');
        $inside->forceFill(['created_at' => '2026-06-15 12:00:00'])->save();
        $outside = $this->makeUser('advertiser');
        $outside->forceFill(['created_at' => '2026-01-01 12:00:00'])->save();

        $this->actingAs($admin)
            ->get(route('admin.audiences.index', [
                'tab' => 'advertisers',
                'registered_from' => '2026-06-30',
                'registered_to' => '2026-06-01',
            ]))
            ->assertOk()
            ->assertSee($inside->email, false)
            ->assertDontSee($outside->email, false)
            ->assertSee('value="2026-06-01"', false)
            ->assertSee('value="2026-06-30"', false);
    }

    public function test_campaign_send_accepts_inventory_tab_slug(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $target = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), [
                'name' => 'Never checked out blast',
                'subject' => 'Finish checkout',
                'body_html' => '<p>You have not placed an order yet.</p>',
                'audience' => 'no_orders',
                'cta_label' => 'Browse catalog',
                'cta_url' => url('/advertiser/catalog'),
                'respect_preferences' => false,
            ])
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame('advertisers_no_orders', $campaign->audience);
        $this->assertSame(1, $campaign->recipients_count);
        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($target->email));
    }

    public function test_campaign_send_accepts_new_audience_keys(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $target = $this->makeUser('advertiser');
        $this->deposit($target, 'completed');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), [
                'name' => 'Funded idle',
                'subject' => 'Place your first order',
                'body_html' => '<p>You already have wallet funds.</p>',
                'audience' => 'advertisers_deposited_no_orders',
                'cta_label' => 'Browse catalog',
                'cta_url' => url('/advertiser/catalog'),
                'respect_preferences' => false,
            ])
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame('advertisers_deposited_no_orders', $campaign->audience);
        $this->assertSame('Advertisers (deposited, no paid orders)', $campaign->audienceLabel());
        $this->assertSame(1, $campaign->recipients_count);

        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($target->email));
    }

    public function test_completed_payment_status_counts_as_a_customer_order(): void
    {
        $completed = $this->makeUser('advertiser');
        Order::create([
            'user_id' => $completed->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-COMPLETED-'.random_int(1000, 9999),
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'completed',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $fundedIdle = $this->makeUser('advertiser');
        $this->deposit($fundedIdle, 'completed');

        $inventory = app(AudienceInventoryService::class);
        $this->assertContains($completed->id, $inventory->collect('advertisers_paid_orders')->pluck('id'));
        $this->assertNotContains($completed->id, $inventory->collect('advertisers_no_paid_orders')->pluck('id'));
        $this->assertNotContains($completed->id, $inventory->collect('advertisers_deposited_no_orders')->pluck('id'));
        $this->assertContains($fundedIdle->id, $inventory->collect('advertisers_deposited_no_orders')->pluck('id'));
        $this->assertSame(1, $inventory->count('advertisers_paid_orders'));
        $this->assertSame(1, $inventory->count('advertisers_no_paid_orders'));
    }

    public function test_staff_who_also_have_a_marketplace_role_are_excluded_from_inventory_and_campaigns(): void
    {
        $adminAdvertiser = $this->makeUser('advertiser');
        $this->attachRole($adminAdvertiser, 'admin');
        $marketingPublisher = $this->makeUser('publisher');
        $this->attachRole($marketingPublisher, 'marketing');
        $plain = $this->makeUser('advertiser');

        $inventory = app(AudienceInventoryService::class);

        $this->assertSame([$plain->id], $inventory->collect('advertisers')->pluck('id')->all());
        $this->assertSame([], $inventory->collect('publishers')->pluck('id')->all());
        $this->assertSame([$plain->id], $inventory->collectRecipientRows('advertisers')->pluck('id')->all());
        $this->assertSame([$plain->id], $inventory->pickerUsers('advertiser')->pluck('id')->all());
        $this->assertSame(0, $inventory->pickerUsers('publisher')->count());
        $this->assertSame(0, $inventory->count('selected', [$adminAdvertiser->id, $marketingPublisher->id]));
        $this->assertSame(0, $inventory->collect('selected', [$adminAdvertiser->id])->count());
        $this->assertSame(1, $inventory->stats()['advertisers']);
        $this->assertSame(0, $inventory->stats()['publishers']);
        $this->assertSame(1, $inventory->advertiserCount());
        $this->assertSame(0, $inventory->publisherCount());
        $this->assertSame(1, $inventory->bothUniqueCount());

        $this->assertSame(1, $inventory->queryForRole('advertiser')->whereKey($adminAdvertiser->id)->count());
        $this->assertSame(1, $inventory->queryForRole('publisher')->whereKey($marketingPublisher->id)->count());
    }

    public function test_whitespace_only_email_is_not_a_recipient(): void
    {
        $blank = $this->makeUser('advertiser', ['email' => '   ']);
        $tabOnly = $this->makeUser('advertiser', ['email' => "\t\n"]);
        $noAt = $this->makeUser('advertiser', ['email' => 'not-an-email']);
        $plain = $this->makeUser('advertiser');

        $inventory = app(AudienceInventoryService::class);
        $ids = $inventory->collect('advertisers', null, true)->pluck('id')->all();
        $rowIds = $inventory->collectRecipientRows('advertisers', null, true)->pluck('id')->all();

        $this->assertNotContains($blank->id, $ids);
        $this->assertNotContains($tabOnly->id, $ids);
        $this->assertNotContains($noAt->id, $ids);
        $this->assertContains($plain->id, $ids);
        $this->assertSame($ids, $rowIds);
        $this->assertSame(0, $inventory->count('selected', [$blank->id, $tabOnly->id, $noAt->id], true));
        $this->assertSame(1, $inventory->count('advertisers', null, true));
        $this->assertSame([$plain->id], $inventory->pickerUsers('advertiser')->pluck('id')->all());
    }

    public function test_all_advertisers_campaign_skips_dual_role_staff(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $staffAdvertiser = $this->makeUser('advertiser');
        $this->attachRole($staffAdvertiser, 'admin');
        $target = $this->makeUser('advertiser');

        $this->actingAs($admin)
            ->post(route('admin.campaigns.send'), [
                'name' => 'All advertisers',
                'subject' => 'Marketplace update',
                'body_html' => '<p>Hello advertisers.</p>',
                'audience' => 'advertisers',
                'respect_preferences' => false,
            ])
            ->assertRedirect(route('admin.campaigns.index'))
            ->assertSessionHas('success');

        $campaign = EmailCampaign::query()->latest('id')->first();
        $this->assertSame(1, $campaign->recipients_count);
        $this->assertEquals([$target->id], $campaign->recipients()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        Mail::assertQueued(AudienceCampaignMail::class, 1);
        Mail::assertQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($target->email));
        Mail::assertNotQueued(AudienceCampaignMail::class, fn (AudienceCampaignMail $mail) => $mail->hasTo($staffAdvertiser->email));
    }
}
