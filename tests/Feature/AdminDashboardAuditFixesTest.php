<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\DashboardMetricsService;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use App\Support\MoneyDisplay;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDashboardAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $this->seed(RolesTableSeeder::class);
        $role = Role::where('name', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makePublisher(array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(array_merge([
            'active_role_id' => $role->id,
            'email_verified_at' => now(),
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Audit Site',
            'site_url' => 'https://audit-site.example',
            'domain' => 'audit-site.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Dashboard audit fixture',
            'verified' => 1,
            'active' => 1,
        ], $overrides));
    }

    public function test_test_accounts_are_excluded_from_dashboard_until_toggled(): void
    {
        $admin = $this->makeAdmin();
        $live = $this->makePublisher(['name' => 'Live Publisher', 'email' => 'live-pub@example.com']);
        $test = $this->makePublisher(['name' => 'Test Publisher', 'email' => 'test-pub@example.com']);
        $test->forceFill(['is_test' => true])->save();

        DepositRequest::create([
            'user_id' => $test->id,
            'reference_code' => '333222',
            'amount' => 25,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);
        DepositRequest::create([
            'user_id' => $live->id,
            'reference_code' => '333223',
            'amount' => 15,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);

        $hidden = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_deposits', 1)
            ->json();

        $this->assertSame(1, $hidden['needs_attention_urgent']);

        $stats = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $stats['total_users']);
        $this->assertFalse($stats['include_test_data']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['show_test' => 1]))
            ->assertOk()
            ->assertSee('Showing test data', false);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_deposits', 2)
            ->assertJsonPath('needs_attention_urgent', 2);

        $shown = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->json('data');
        $this->assertSame(3, $shown['total_users']);
        $this->assertTrue($shown['include_test_data']);
    }

    public function test_admin_can_mark_and_filter_test_accounts(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makePublisher(['name' => 'Flag Me', 'email' => 'flag-me@example.com']);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $member))
            ->post(route('admin.users.test-flag', $member), ['is_test' => 1])
            ->assertRedirect(route('admin.users.show', $member));

        $this->assertTrue($member->fresh()->isTestAccount());

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['flag' => 'test']))
            ->assertOk()
            ->assertSee('flag-me@example.com', false)
            ->assertSee('Test', false);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $member))
            ->post(route('admin.users.test-flag', $member), ['is_test' => 0])
            ->assertRedirect();

        $this->assertFalse($member->fresh()->isTestAccount());
    }

    public function test_enrichment_queue_shows_friendly_sql_error(): void
    {
        $admin = $this->makeAdmin();
        $publisher = $this->makePublisher();
        $site = $this->makeSite($publisher, [
            'site_name' => 'SQL Fail Site',
            'site_url' => 'https://sql-fail.example',
            'domain' => 'sql-fail.example',
        ]);
        SiteEnrichmentRun::create([
            'site_id' => $site->id,
            'type' => 'screenshot',
            'provider' => 'thum_io',
            'status' => 'failed',
            'error' => "SQLSTATE[42S22]: Unknown column 'screenshot_path' in 'SET'",
            'triggered_by' => 'system',
        ]);

        $queue = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->json('enrichment.0');

        $this->assertSame('Could not save the screenshot (database column missing).', $queue['error']);
        $this->assertStringContainsString('screenshot_path', (string) $queue['error_raw']);
        $this->assertStringNotContainsString('SQLSTATE', (string) $queue['error']);

        $this->actingAs($admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk()
            ->assertSee('Could not save the screenshot (database column missing).', false)
            ->assertSee('SQLSTATE[42S22]', false);
    }

    public function test_screenshot_persist_degrades_when_column_missing(): void
    {
        $publisher = $this->makePublisher();
        $site = $this->makeSite($publisher);
        if (Schema::hasColumn('sites', 'screenshot_path')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->dropColumn('screenshot_path');
            });
            Site::forgetColumnCache();
        }

        config([
            'site_enrichment.enabled' => true,
            'site_enrichment.screenshots.provider' => 'none',
        ]);

        $run = app(SiteEnrichmentService::class)->refreshScreenshot($site->fresh(), 'test');
        $this->assertContains($run->status, ['failed', 'partial', 'success']);
        $this->assertDoesNotMatchRegularExpression('/SQLSTATE/', (string) $run->error);
    }

    public function test_needs_attention_splits_urgent_and_backlog_and_excludes_enrichment(): void
    {
        $admin = $this->makeAdmin();
        $publisher = $this->makePublisher();
        $site = $this->makeSite($publisher);
        SiteEnrichmentRun::create([
            'site_id' => $site->id,
            'type' => 'metrics',
            'provider' => 'manual',
            'status' => 'failed',
            'error' => 'Provider timed out',
            'triggered_by' => 'admin',
        ]);
        Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-AUDIT-1',
            'reference_code' => 'REF-AUDIT-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'bank',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_payments', 1)
            ->assertJsonPath('enrichment_failed', 1)
            ->assertJsonPath('needs_attention_urgent', 1)
            ->assertJsonPath('needs_attention_backlog', 0)
            ->assertJsonPath('needs_attention', 1);
    }

    public function test_fee_margin_breakdown_and_minus_format(): void
    {
        $this->assertSame('−€40.00', format_eur_admin(-40));
        $this->assertSame('€0.00', format_eur_admin(0));
        $this->assertSame('€12.50', format_eur_admin(12.5));
        $this->assertSame(MoneyDisplay::MINUS.'€40.00', format_eur_admin(-40));

        $admin = $this->makeAdmin();
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        $buyer = User::factory()->create([
            'active_role_id' => $advertiser->id,
            'email_verified_at' => now(),
        ]);
        $buyer->roles()->attach($advertiser->id);

        $wallet = Wallet::create([
            'user_id' => $buyer->id,
            'role_id' => $advertiser->id,
            'balance' => 40,
            'bonus_balance' => 40,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        WalletTransaction::create([
            'user_id' => $buyer->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_BONUS_CREDIT,
            'direction' => 'credit',
            'amount' => 40,
            'bonus_amount' => 40,
            'balance_after' => 40,
            'bonus_balance_after' => 40,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Welcome bonus',
        ]);

        $json = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.finance'))
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, (float) $json['fees']);
        $this->assertSame(40.0, (float) $json['bonuses_issued']);
        $this->assertSame(-40.0, (float) $json['margin']);

        $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('−€40.00', false)
            ->assertSee('Est. fee margin', false);
    }

    public function test_all_sites_and_enrichment_index_paginate_fifty(): void
    {
        $admin = $this->makeAdmin();
        $publisher = $this->makePublisher();
        for ($i = 1; $i <= 51; $i++) {
            $site = $this->makeSite($publisher, [
                'site_name' => 'Paged Site '.$i,
                'site_url' => 'https://paged-site-'.$i.'.example',
                'domain' => 'paged-site-'.$i.'.example',
            ]);
            SiteEnrichmentRun::create([
                'site_id' => $site->id,
                'type' => 'metrics',
                'provider' => 'manual',
                'status' => 'failed',
                'error' => 'Capture failed '.$i,
                'triggered_by' => 'admin',
            ]);
        }

        $sites = $this->actingAs($admin)
            ->get(route('admin.sites.index', ['all' => 1]))
            ->assertOk();
        $sitesHtml = $sites->getContent();
        $this->assertSame(50, substr_count($sitesHtml, 'data-review-name="Paged Site'));
        $sites->assertSee('Showing 1–50 of 51', false);
        $sites->assertSee('page=2', false);

        $records = $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk();
        $recordsHtml = $records->getContent();
        $records->assertSee('Showing 51 sites', false);
        $records->assertSee('page=2', false);
        preg_match_all('/paged-site-(\d+)\.example/', $recordsHtml, $recordMatches);
        $this->assertCount(50, array_unique($recordMatches[1] ?? []));

        $enrichment = $this->actingAs($admin)
            ->get(route('admin.site-enrichment.index'))
            ->assertOk();
        $html = $enrichment->getContent();
        preg_match_all('/title="Capture failed (\d+)"/', $html, $attentionErrors);
        $this->assertCount(50, $attentionErrors[1] ?? []);
        $enrichment->assertSee('page=2', false);
    }

    public function test_role_distribution_note_and_pipeline_empty_flag(): void
    {
        $admin = $this->makeAdmin();

        $json = $this->actingAs($admin)
            ->getJson(route('admin.dashboard.distributions'))
            ->assertOk()
            ->json();

        $this->assertTrue($json['orders']['empty']);
        $this->assertStringContainsString('Assigned roles', (string) $json['roles']['note']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Users by assigned role', false)
            ->assertSee('Active role counts', false);
    }

    public function test_metrics_service_can_include_test_data(): void
    {
        $this->makeAdmin();
        $test = $this->makePublisher();
        $test->forceFill(['is_test' => true])->save();

        $hidden = app(DashboardMetricsService::class)->statistics();
        $shown = app(DashboardMetricsService::class)->includeTestData(true)->statistics();

        $this->assertSame($hidden['total_users'] + 1, $shown['total_users']);
    }
}
