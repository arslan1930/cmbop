<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AgencySiteImport;
use App\Models\DepositRequest;
use App\Models\EmailCampaign;
use App\Models\Order;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\ActivityLogger;
use App\Support\AdminActivityDisplay;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $role = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'name' => 'Ada Admin',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->admin->roles()->attach($role->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_open_activity_history(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Activity History', false)
            ->assertDontSee('Every dashboard action', false)
            ->assertSee('Append-only log of actions recorded by ActivityLogger', false)
            ->getContent();

        foreach (['logUser', 'logAction', 'logFrom', 'logTo'] as $id) {
            $this->assertStringContainsString('for="'.$id.'"', $html);
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }

    public function test_user_filter_is_literal_and_escapes_like_wildcards(): void
    {
        $this->makeLog(['user_name' => 'Alice 100%', 'user_email' => 'alice-percent@example.com', 'description' => 'Percent user']);
        $this->makeLog(['user_name' => 'Alice 1000', 'user_email' => 'alice-thousand@example.com', 'description' => 'Thousand user']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['user' => '100%']))
            ->assertOk()
            ->assertSee('Percent user', false)
            ->assertDontSee('Thousand user', false);
    }

    public function test_invalid_and_inverted_dates_are_ignored_with_a_warning(): void
    {
        $this->makeLog(['description' => 'Kept when dates are bad']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', [
                'user' => ['injected'],
                'action' => ['login'],
                'from' => ['2026-01-01'],
                'to' => ['2026-12-31'],
            ]))
            ->assertOk()
            ->assertSee('Use a valid From date.', false);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['from' => 'not-a-date', 'to' => 'also-bad']))
            ->assertOk()
            ->assertSee('Use a valid From date.', false)
            ->assertSee('Use a valid To date.', false)
            ->assertSee('Kept when dates are bad', false);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['from' => '2026-08-16', 'to' => '2026-08-15']))
            ->assertOk()
            ->assertSee('From date must be on or before To date.', false)
            ->assertSee('Kept when dates are bad', false);
    }

    public function test_date_filter_uses_app_timezone_window(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);
        Carbon::setTestNow(Carbon::parse('2026-08-15 00:30:00', 'Europe/Berlin'));

        $today = $this->makeLog(['description' => 'Today in Berlin', 'action' => 'site.activated']);
        $today->forceFill([
            'created_at' => Carbon::parse('2026-08-14 22:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-14 22:30:00', 'UTC'),
        ])->save();

        $yesterday = $this->makeLog(['description' => 'Yesterday in Berlin', 'action' => 'site.updated']);
        $yesterday->forceFill([
            'created_at' => Carbon::parse('2026-08-14 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-14 10:00:00', 'UTC'),
        ])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['from' => '2026-08-15', 'to' => '2026-08-15']))
            ->assertOk()
            ->assertSee('Today in Berlin', false)
            ->assertDontSee('Yesterday in Berlin', false);
    }

    public function test_empty_states_distinguish_filters_from_first_run(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('No activity recorded yet.', false)
            ->assertDontSee('No events match these filters.', false);

        $this->makeLog(['description' => 'Only an edit', 'action' => 'site.updated']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['action' => 'site.activated']))
            ->assertOk()
            ->assertSee('No events match these filters.', false)
            ->assertSee('Reset filters', false)
            ->assertDontSee('No activity recorded yet.', false)
            ->assertDontSee('Only an edit', false);
    }

    public function test_details_show_reason_changes_and_subject_link(): void
    {
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Audit Link Site',
            'site_url' => 'https://audit-link.example',
            'domain' => 'audit-link.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'description' => 'Audit site',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);

        $this->makeLog([
            'action' => 'site.updated',
            'description' => 'Staff modified site',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'subject_label' => 'Audit Link Site',
            'properties' => [
                'reason' => 'Price correction',
                'changes' => ['price' => ['from' => 40, 'to' => 55]],
            ],
        ]);

        $this->makeLog([
            'action' => 'site.deleted',
            'description' => 'Staff deleted a gone site',
            'subject_type' => Site::class,
            'subject_id' => 999999,
            'subject_label' => 'Gone Site',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Edited site', false)
            ->assertSee('Reason: Price correction', false)
            ->assertSee('Changed: Price', false)
            ->assertSee('Gone Site', false)
            ->assertSee('Removed', false)
            ->getContent();

        $this->assertStringContainsString(route('admin.sites.edit', $site->id), $html);
        $this->assertStringNotContainsString(route('admin.sites.edit', 999999), $html);
    }

    public function test_role_filter_and_past_page_redirect(): void
    {
        $this->makeLog(['role' => 'admin', 'description' => 'Admin row']);
        $this->makeLog(['role' => 'marketing', 'description' => 'Marketer row']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['role' => 'marketing']))
            ->assertOk()
            ->assertSee('Marketer row', false)
            ->assertDontSee('Admin row', false);

        for ($i = 0; $i < 26; $i++) {
            $this->makeLog(['description' => 'Page filler '.$i]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['page' => 99]))
            ->assertRedirect();
    }

    public function test_export_streams_csv_for_admin_and_blocks_marketers(): void
    {
        $this->makeLog([
            'action' => 'site.approved',
            'description' => 'Approved for export',
            'subject_label' => 'Export Site',
        ]);

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('when,user_name,user_email,role,action,subject,description,properties,ip_address', $csv);
        $this->assertStringContainsString('site.approved', $csv);
        $this->assertStringContainsString('Approved for export', $csv);

        $this->makeLog([
            'action' => 'catalog_pace_exempted',
            'description' => 'Legacy pace code should export as the live action',
            'subject_label' => 'Alias Export User',
        ]);

        $aliased = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('catalog_activity.exempt_toggled', $aliased);
        $this->assertStringNotContainsString('catalog_pace_exempted', $aliased);

        $marketerRole = Role::where('name', 'marketing')->firstOrFail();
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketerRole->id,
        ]);
        $marketer->roles()->attach($marketerRole->id);

        $this->actingAs($marketer)
            ->get(route('admin.activity-logs.export'))
            ->assertRedirect(route('marketing.dashboard'));
    }

    public function test_export_refuses_invalid_dates_instead_of_dumping_all_rows(): void
    {
        $this->makeLog(['description' => 'Must not be exported on bad dates']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.export', ['from' => 'not-a-date']))
            ->assertRedirect(route('admin.activity-logs.index', ['from' => 'not-a-date']))
            ->assertSessionHas('error', 'Use a valid From date.');
    }

    public function test_export_refuses_when_more_rows_match_than_the_cap(): void
    {
        config(['activity_logs.export_limit' => 2]);
        $this->makeLog(['description' => 'Export cap one']);
        $this->makeLog(['description' => 'Export cap two']);
        $this->makeLog(['description' => 'Export cap three']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('More than 2 events match', false)
            ->assertDontSee('Export CSV', false);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.export'))
            ->assertRedirect(route('admin.activity-logs.index'))
            ->assertSessionHas('error');
    }

    public function test_company_update_is_logged(): void
    {
        $user = User::factory()->create([
            'company_name' => 'Old Co',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.users.updateCompany', $user->id), [
                'company_name' => 'New Co',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'user.company_updated',
            'user_id' => $this->admin->id,
            'subject_id' => $user->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.users.updateCompany', $user->id), [
                'company_name' => 'New Co',
            ])
            ->assertOk();

        $this->assertSame(1, ActivityLog::query()->where('action', 'user.company_updated')->count());
    }

    public function test_deposit_and_withdrawal_rows_link_to_finance_not_json_show(): void
    {
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $deposit = DepositRequest::create([
            'user_id' => $customer->id,
            'reference_code' => 'DEP-AUDIT-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'completed',
        ]);
        $withdrawal = Withdrawal::create([
            'user_id' => $customer->id,
            'amount' => 20,
            'fee' => 0,
            'net_amount' => 20,
            'payment_method' => 'wise',
            'status' => 'completed',
        ]);

        $this->makeLog([
            'action' => 'deposit.approved',
            'description' => 'Approved a deposit',
            'subject_type' => DepositRequest::class,
            'subject_id' => $deposit->id,
            'subject_label' => 'Deposit #'.$deposit->id,
            'properties' => ['user_id' => $customer->id],
        ]);
        $this->makeLog([
            'action' => 'withdrawal.status_updated',
            'description' => 'Paid a withdrawal',
            'subject_type' => Withdrawal::class,
            'subject_id' => $withdrawal->id,
            'subject_label' => 'Withdrawal #'.$withdrawal->id,
            'properties' => ['from' => 'processing', 'to' => 'completed'],
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->getContent();

        $dossier = route('admin.finance.user', $customer->id);
        $this->assertStringContainsString($dossier, $html);
        $this->assertStringNotContainsString(route('admin.deposits.show', $deposit->id), $html);
        $this->assertStringNotContainsString(route('admin.withdrawals.show', $withdrawal->id), $html);
    }

    public function test_deposit_with_missing_user_links_to_deposits_list_not_a_404_dossier(): void
    {
        $log = $this->makeLog([
            'action' => 'deposit.approved',
            'description' => 'Approved an orphan deposit',
            'subject_type' => DepositRequest::class,
            'subject_id' => 42,
            'subject_label' => 'Deposit #42',
        ]);

        $url = AdminActivityDisplay::subjectUrl($log, [
            'existingDepositIds' => [42 => 999999],
            'existingUserIds' => [],
        ]);

        $this->assertSame(route('admin.deposits'), $url);
        $this->assertNotSame(route('admin.finance.user', 999999), $url);
        $this->assertNotSame(route('admin.deposits.show', 42), $url);
    }

    public function test_regranting_marketing_does_not_write_another_grant_row(): void
    {
        $marketerRole = Role::where('name', 'marketing')->firstOrFail();
        $member = User::factory()->create(['email_verified_at' => now()]);
        $member->roles()->attach($marketerRole->id);

        $this->actingAs($this->admin)
            ->postJson(route('admin.users.updateRoles', $member->id), [
                'marketing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, ActivityLog::query()->where('action', 'user.marketing_granted')->count());
    }

    public function test_retired_catalog_pace_code_filters_with_the_live_action(): void
    {
        $this->makeLog([
            'action' => 'catalog_activity.exempt_toggled',
            'description' => 'Granted a live pace exemption',
            'subject_label' => 'Live Exempt User',
        ]);
        $this->makeLog([
            'action' => 'catalog_pace_exempted',
            'description' => 'Granted a legacy pace exemption',
            'subject_label' => 'Legacy Exempt User',
        ]);
        $this->makeLog([
            'action' => 'site.approved',
            'description' => 'Unrelated approval',
            'subject_label' => 'Other Site',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['action' => 'catalog_pace_exempted']))
            ->assertOk()
            ->assertSee('Live Exempt User', false)
            ->assertSee('Legacy Exempt User', false)
            ->assertDontSee('Other Site', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'value="catalog_activity.exempt_toggled"'));
        $this->assertStringNotContainsString('value="catalog_pace_exempted"', $html);
        $this->assertStringContainsString('Toggled catalog pace exemption (2)', $html);
        $this->assertStringNotContainsString('<code class="small text-muted">catalog_pace_exempted</code>', $html);
        $this->assertStringContainsString('<code class="small text-muted">catalog_activity.exempt_toggled</code>', $html);
    }

    public function test_agency_import_row_links_to_publisher_not_a_dead_import_id(): void
    {
        $publisher = User::factory()->create([
            'name' => 'Agency Publisher',
            'email' => 'agency-pub@example.com',
            'email_verified_at' => now(),
        ]);

        $log = $this->makeLog([
            'action' => 'agency_import.submitted',
            'description' => 'Submitted agency CSV import #42',
            'subject_type' => AgencySiteImport::class,
            'subject_id' => 42,
            'subject_label' => 'Agency import #42',
            'properties' => ['publisher_id' => $publisher->id, 'import_id' => 42],
        ]);

        $lookup = AdminActivityDisplay::preload([$log]);
        $this->assertSame(
            route('admin.users.show', $publisher->id),
            AdminActivityDisplay::subjectUrl($log, $lookup)
        );

        $gone = $this->makeLog([
            'action' => 'agency_import.submitted',
            'description' => 'Submitted agency CSV import #43',
            'subject_type' => AgencySiteImport::class,
            'subject_id' => 43,
            'subject_label' => 'Agency import #43',
            'properties' => ['publisher_id' => 999999, 'import_id' => 43],
        ]);

        $this->assertNull(AdminActivityDisplay::subjectUrl($gone, AdminActivityDisplay::preload([$gone])));
    }

    public function test_dispute_row_links_to_the_order_and_has_a_label(): void
    {
        $log = $this->makeLog([
            'action' => 'dispute.upheld',
            'description' => 'Upheld a clawback',
            'subject_type' => Order::class,
            'subject_id' => 7,
            'subject_label' => 'Order #ORD-7',
            'properties' => ['dispute_id' => 3, 'advertiser_credited' => 115],
        ]);

        $this->assertSame(
            route('admin.orders.show', 7),
            AdminActivityDisplay::subjectUrl($log, [
                'existingOrderIds' => [7 => true],
            ])
        );

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Upheld order dispute', false)
            ->getContent();

        $this->assertStringContainsString('value="dispute.upheld"', $html);
    }

    public function test_batch_payout_and_inbox_rows_have_labels_and_safe_links(): void
    {
        $this->makeLog([
            'action' => 'withdrawal.batch_completed',
            'description' => 'Batch marked withdrawals paid',
            'subject_label' => 'PAYOUT-TEST-1',
        ]);
        $this->makeLog([
            'action' => 'problem.report_updated',
            'description' => 'Updated problem report #9',
            'subject_type' => ProblemReport::class,
            'subject_id' => 9,
            'subject_label' => 'Checkout broken',
        ]);
        $campaign = EmailCampaign::create([
            'name' => 'August promo',
            'subject' => 'August promo',
            'body_html' => '<p>Hi</p>',
            'audience' => 'advertisers',
            'recipients_count' => 0,
            'status' => EmailCampaign::STATUS_QUEUED,
            'respect_preferences' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->makeLog([
            'action' => 'campaign.queued',
            'description' => 'Queued a campaign',
            'subject_type' => EmailCampaign::class,
            'subject_id' => $campaign->id,
            'subject_label' => 'August promo',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Batch marked withdrawals paid', false)
            ->assertSee('Updated problem report', false)
            ->assertSee('Queued campaign', false)
            ->getContent();

        $this->assertStringContainsString(route('admin.withdrawals'), $html);
        $this->assertStringContainsString(route('admin.community.index', ['tab' => 'problems']), $html);
        $this->assertStringContainsString(route('admin.campaigns.show', $campaign), $html);
        $this->assertStringNotContainsString(route('admin.campaigns.show', 999999), $html);
    }

    public function test_search_activate_does_not_match_deactivated(): void
    {
        $this->makeLog([
            'action' => 'site.activated',
            'description' => 'Ada Admin activated site "Live Site"',
            'subject_label' => 'Live Site',
        ]);
        $this->makeLog([
            'action' => 'site.deactivated',
            'description' => 'Ada Admin deactivated site "Offline Site"',
            'subject_label' => 'Offline Site',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['q' => 'activate']))
            ->assertOk()
            ->assertSee('Live Site', false)
            ->assertDontSee('Offline Site', false);
    }

    public function test_gone_site_does_not_link_to_unrelated_user_id_in_properties(): void
    {
        $other = User::factory()->create([
            'name' => 'Unrelated User',
            'email_verified_at' => now(),
        ]);

        $this->makeLog([
            'action' => 'site.updated',
            'description' => 'Edited a site that was later removed',
            'subject_type' => Site::class,
            'subject_id' => 999999,
            'subject_label' => 'Stale Site',
            'properties' => ['user_id' => $other->id],
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Stale Site', false)
            ->assertSee('Removed', false)
            ->assertDontSee('0 → 1', false)
            ->getContent();

        $this->assertStringNotContainsString(route('admin.users.show', $other->id), $html);
        $this->assertStringNotContainsString(route('admin.users.index', ['user' => $other->id]), $html);
        $this->assertStringNotContainsString(route('admin.sites.edit', 999999), $html);
    }

    public function test_gone_order_does_not_link_to_unrelated_site_id_in_properties(): void
    {
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Unrelated Order Site',
            'site_url' => 'https://unrelated-order.example',
            'domain' => 'unrelated-order.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 20,
            'publication_time' => 'permanent',
            'description' => 'Unrelated',
            'link_type' => 'dofollow',
            'verified' => false,
            'active' => false,
        ]);

        $this->makeLog([
            'action' => 'order.status_overridden',
            'description' => 'Moved an order that was later removed',
            'subject_type' => Order::class,
            'subject_id' => 888888,
            'subject_label' => 'ORD-GONE',
            'properties' => ['site_id' => $site->id, 'from' => 'processing', 'to' => 'completed'],
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('ORD-GONE', false)
            ->assertSee('Removed', false)
            ->getContent();

        $this->assertStringNotContainsString(route('admin.sites.edit', $site->id), $html);
        $this->assertStringNotContainsString(route('admin.orders.show', 888888), $html);
    }

    public function test_flag_status_change_is_hidden_and_observed_actions_appear_in_filter(): void
    {
        $this->makeLog([
            'action' => 'site.approved',
            'description' => 'Approved a listing',
            'subject_label' => 'Flag Site',
            'properties' => ['from' => 0, 'to' => 1],
        ]);
        $this->makeLog([
            'action' => 'payment.status_updated',
            'description' => 'Set payment to paid',
            'subject_label' => 'ORD-FLAG',
            'properties' => ['from' => 'pending', 'to' => 'paid'],
        ]);
        $this->makeLog([
            'action' => 'site.verified_file',
            'description' => 'Verified via file',
            'subject_label' => 'File Verified Site',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Approved a listing', false)
            ->assertSee('Verified site (file)', false)
            ->assertSee('pending → paid', false)
            ->assertDontSee('0 → 1', false)
            ->getContent();

        $this->assertStringContainsString('value="site.verified_file"', $html);
    }

    public function test_numeric_user_filter_matches_actor_id(): void
    {
        $otherRole = Role::where('name', 'admin')->firstOrFail();
        $other = User::factory()->create([
            'name' => 'Other Admin',
            'email' => 'other-admin@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $otherRole->id,
        ]);
        $other->roles()->attach($otherRole->id);

        $this->makeLog([
            'user_id' => $other->id,
            'user_name' => $other->name,
            'user_email' => $other->email,
            'description' => 'Other admin row',
        ]);
        $this->makeLog(['description' => 'Ada admin row']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['user' => (string) $other->id]))
            ->assertOk()
            ->assertSee('Other admin row', false)
            ->assertDontSee('Ada admin row', false);
    }

    public function test_logger_accepts_explicit_actor_and_try_log_swallows_failures(): void
    {
        Auth::logout();
        $actor = $this->admin;

        $log = ActivityLogger::log(
            'site.approved',
            'Job approved a site',
            null,
            ['job' => true],
            'Queued Site',
            $actor
        );

        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('admin', $log->role);

        $dispatcher = ActivityLog::getEventDispatcher();

        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Activity log failed' && ($context['action'] ?? null) === 'broken.action';
        });

        ActivityLog::creating(function () {
            throw new \RuntimeException('table missing');
        });

        $this->assertNull(ActivityLogger::tryLog('broken.action', 'Should not throw'));
        ActivityLog::flushEventListeners();
        if ($dispatcher) {
            ActivityLog::setEventDispatcher($dispatcher);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function test_index_and_export_ok_when_created_at_is_unparseable(): void
    {
        $log = $this->makeLog(['description' => 'Leftover activity stamp']);
        DB::table('activity_logs')->where('id', $log->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->assertNull($log->fresh()->created_at);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Leftover activity stamp', false)
            ->assertDontSee('Something went wrong');

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('Leftover activity stamp', $csv);
    }

    public function test_date_filter_excludes_unparseable_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));
        $today = $this->makeLog(['description' => 'Today real stamp']);
        $leftover = $this->makeLog(['description' => 'Leftover filter stamp']);
        DB::table('activity_logs')->where('id', $leftover->id)->update([
            'created_at' => 'not-a-date',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', [
                'from' => '2026-08-16',
                'to' => '2026-08-16',
            ]))
            ->assertOk()
            ->assertSee('Today real stamp', false)
            ->assertDontSee('Leftover filter stamp', false);
    }

    public function test_overridden_library_article_has_a_label(): void
    {
        $this->makeLog([
            'action' => 'content.overridden',
            'description' => 'Ada Admin approved library article #12',
            'subject_label' => 'Article #12',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Overrode library article', false);
    }

    public function test_user_id_pin_survives_apply_and_export_query(): void
    {
        $otherRole = Role::where('name', 'admin')->firstOrFail();
        $other = User::factory()->create([
            'name' => 'Pinned Admin',
            'email' => 'pinned-admin@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $otherRole->id,
        ]);
        $other->roles()->attach($otherRole->id);

        $this->makeLog([
            'user_id' => $other->id,
            'user_name' => $other->name,
            'user_email' => $other->email,
            'description' => 'Pinned actor row',
        ]);
        $this->makeLog(['description' => 'Ada should hide']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['user_id' => $other->id]))
            ->assertOk()
            ->assertSee('Pinned actor row', false)
            ->assertDontSee('Ada should hide', false)
            ->assertSee('Actor filter', false)
            ->getContent();

        $this->assertStringContainsString('name="user_id"', $html);
        $this->assertStringContainsString(
            route('admin.activity-logs.export', ['user_id' => $other->id]),
            $html
        );

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['user_id' => ['x']]))
            ->assertOk()
            ->assertSee('Ada should hide', false)
            ->assertSee('Pinned actor row', false);
    }

    public function test_missing_campaign_is_removed_not_a_dead_show_link(): void
    {
        $this->makeLog([
            'action' => 'campaign.queued',
            'description' => 'Queued a gone campaign',
            'subject_type' => EmailCampaign::class,
            'subject_id' => 999999,
            'subject_label' => 'Gone promo',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertSee('Gone promo', false)
            ->assertSee('Removed', false)
            ->getContent();

        $this->assertStringNotContainsString(route('admin.campaigns.show', 999999), $html);
    }

    private function makeLog(array $overrides = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'user_id' => $this->admin->id,
            'user_name' => $this->admin->name,
            'user_email' => $this->admin->email,
            'role' => 'admin',
            'action' => 'site.updated',
            'description' => 'Staff edited a site',
            'subject_label' => 'Example Site',
        ], $overrides));
    }
}
