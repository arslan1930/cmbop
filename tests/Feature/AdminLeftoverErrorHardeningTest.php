<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Admin\DashboardMetricsService;
use App\Services\Wallet\PayoutProfileService;
use App\Support\ProductionReadiness;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminLeftoverErrorHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * @param  TestResponse  $response
     */
    private function assertSafePage($response): void
    {
        $this->assertNotSame(500, $response->status());
        $response->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    /**
     * @param  TestResponse  $response
     */
    private function assertSafeJsonFailure($response): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);

        $message = (string) ($response->json('message') ?: $response->json('error'));
        $this->assertNotSame('', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('Unknown column', $message);
    }

    public function test_sites_index_still_renders_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($admin)->get(route('admin.sites.index'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_sites_records_html_still_renders_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($admin)->get(route('admin.sites.records'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_user_sites_returns_json_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->getJson(route('admin.users.sites', $publisher->id))
        );
    }

    public function test_order_show_redirects_when_order_items_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LEFT-1',
            'reference_code' => 'REF-LEFT-1',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        Schema::dropIfExists('order_items');

        $response = $this->actingAs($admin)->get(route('admin.orders.show', $order->id));

        $this->assertSafePage($response);
        $response->assertRedirect(route('admin.orders.index'));
    }

    public function test_activity_logs_still_render_when_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('activity_logs');

        $response = $this->actingAs($admin)->get(route('admin.activity-logs.index'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_catalog_activity_still_renders_when_reveal_query_throws(): void
    {
        $admin = $this->userWithRole('admin');

        Schema::dropIfExists('site_url_reveals');
        Schema::create('site_url_reveals', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });

        $response = $this->actingAs($admin)->get(route('admin.catalog-activity'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_bulk_requests_index_still_renders_when_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('bulk_site_requests');

        $response = $this->actingAs($admin)->get(route('admin.bulk-site-requests.index'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_dashboard_still_renders_when_production_readiness_throws(): void
    {
        $admin = $this->userWithRole('admin');

        $this->mock(ProductionReadiness::class, function ($mock) {
            $mock->shouldReceive('dashboardAlerts')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: ops boom'));
        });

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $this->assertSafePage($response);
        $response->assertOk();
    }

    public function test_payout_profile_returns_json_when_save_throws(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');

        $this->mock(PayoutProfileService::class, function ($mock) {
            $mock->shouldReceive('adminUpdateProfile')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: payout boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->postJson(route('admin.users.updatePayoutProfile', $publisher->id), [
                'payment_method' => 'paypal',
                'paypal_email' => 'publisher@example.com',
            ])
        );
    }

    public function test_dashboard_statistics_return_safe_json_when_metrics_throw(): void
    {
        $admin = $this->userWithRole('admin');

        $this->mock(DashboardMetricsService::class, function ($mock) {
            $mock->shouldReceive('statistics')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: metrics boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->getJson(route('admin.dashboard.statistics'))
        );
    }

    public function test_dashboard_json_survives_missing_queue_tables(): void
    {
        $admin = $this->userWithRole('admin');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('bulk_site_request_items');
        Schema::dropIfExists('bulk_site_requests');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('content_moderation_logs');
        Schema::dropIfExists('site_enrichment_runs');
        Schema::enableForeignKeyConstraints();

        if (Schema::hasColumn('users', 'catalog_hide_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('catalog_hide_until');
            });
        }

        $page = $this->actingAs($admin)->get(route('admin.dashboard'));
        $this->assertSafePage($page);
        $page->assertOk();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('open_bulk_requests', 0)
            ->assertJsonPath('failed_mail', 0)
            ->assertJsonPath('moderation_errors', 0)
            ->assertJsonPath('enrichment_failed', 0)
            ->assertJsonPath('catalog_hide', 0)
            ->assertDontSee('SQLSTATE');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('bulk', [])
            ->assertJsonPath('mail', [])
            ->assertJsonPath('moderation', [])
            ->assertJsonPath('enrichment', [])
            ->assertJsonPath('catalog_hide', [])
            ->assertDontSee('SQLSTATE');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.open_bulk_requests', 0)
            ->assertJsonPath('data.failed_mail', 0)
            ->assertDontSee('SQLSTATE');
    }

    public function test_dashboard_statistics_survive_missing_bulk_and_sites_tables(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Live leftover site',
            'site_url' => 'https://leftover-live.example',
            'domain' => 'leftover-live.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Catalog visible leftover fixture',
            'verified' => 1,
            'active' => 1,
        ]);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('bulk_site_request_items');
        Schema::dropIfExists('bulk_site_requests');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.live_sites', 1)
            ->assertJsonPath('data.total_sites', 1)
            ->assertDontSee('SQLSTATE');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('sites');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.live_sites', 0)
            ->assertJsonPath('data.total_sites', 0)
            ->assertDontSee('SQLSTATE');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unverified_sites', 0);
    }

    public function test_action_queue_ok_when_failed_mail_date_is_unparseable(): void
    {
        $admin = $this->userWithRole('admin');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode([
                'displayName' => 'App\\Mail\\WelcomeEmail',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable'],
            ]),
            'exception' => 'SMTP leftover date',
            'failed_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('mail.0.label', 'SMTP leftover date');
    }

    public function test_dashboard_charts_survive_missing_orders_and_role_user(): void
    {
        $admin = $this->userWithRole('admin');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('orders');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.trends'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(30, 'labels')
            ->assertJsonCount(30, 'revenue')
            ->assertDontSee('SQLSTATE');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.distributions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('orders.labels', [])
            ->assertDontSee('SQLSTATE');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_orders', 0);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('unpaid', []);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.stalled-orders'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('items', [])
            ->assertDontSee('SQLSTATE');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('role_user');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.distributions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('roles.labels', [])
            ->assertDontSee('SQLSTATE');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.advertisers', 0)
            ->assertJsonPath('data.admins', 0);
    }

    public function test_dashboard_queues_survive_missing_bulk_items_and_hide_date(): void
    {
        $admin = $this->userWithRole('admin');
        $hidden = User::factory()->create([
            'email_verified_at' => now(),
            'catalog_hide_until' => now()->addHours(6),
        ]);
        DB::table('users')->where('id', $hidden->id)->update([
            'catalog_hide_until' => 'not-a-date',
        ]);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('bulk_site_request_items');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('open_bulk_requests', 0)
            ->assertDontSee('SQLSTATE');

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.action-queue'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('bulk', [])
            ->assertDontSee('SQLSTATE');
    }
}
