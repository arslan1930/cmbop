<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        StaffCapability::ensureTable();
    }

    public function test_finance_admin_can_open_analytics_and_support_cannot(): void
    {
        $finance = $this->userWithRole('admin', ['email' => 'analytics-finance@example.com']);
        $this->restrict($finance, [StaffCapability::FINANCE]);
        $support = $this->userWithRole('admin', ['email' => 'analytics-support@example.com']);
        $this->restrict($support, [StaffCapability::SUPPORT]);

        $this->actingAs($finance)
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Marketplace analytics')
            ->assertSee('Paid GMV')
            ->assertSee(route('admin.analytics.export', absolute: false), false);

        $this->actingAs($support)
            ->get(route('admin.analytics'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($support)
            ->get(route('admin.analytics.export'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_csv_includes_kpi_and_niche_sections(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($publisher, ['category' => 'SaaS Analytics Niche']);
        $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-ANALYTICS-NOW',
            'total_amount' => 75,
            'paid_at' => now(),
            'payment_status' => 'paid',
        ]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.analytics.export', ['period' => 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('period,section,metric,value', $csv);
        $this->assertStringContainsString('kpi', $csv);
        $this->assertStringContainsString('paid_gmv', $csv);
        $this->assertStringContainsString('paid_orders', $csv);
        $this->assertStringContainsString('live_listings_by_niche', $csv);
        $this->assertStringContainsString('SaaS Analytics Niche', $csv);
        $this->assertStringContainsString('paid_gmv_by_niche', $csv);
        $this->assertStringContainsString('live_listings_by_country', $csv);
    }

    public function test_period_filter_excludes_older_paid_orders(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($publisher, ['category' => 'Period Niche']);
        $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-ANALYTICS-OLD',
            'total_amount' => 888.25,
            'paid_at' => now()->subYear(),
            'payment_status' => 'paid',
        ]);
        $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-ANALYTICS-NEW',
            'total_amount' => 42.50,
            'paid_at' => now(),
            'payment_status' => 'paid',
        ]);

        $month = $this->actingAs($admin)
            ->get(route('admin.analytics.export', ['period' => 'month']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('kpi,paid_gmv,42.5', $month);
        $this->assertStringContainsString('kpi,paid_orders,1', $month);
        $this->assertStringNotContainsString('888.25', $month);

        $all = $this->actingAs($admin)
            ->get(route('admin.analytics.export', ['period' => 'all']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('kpi,paid_gmv,930.75', $all);
        $this->assertStringContainsString('kpi,paid_orders,2', $all);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function restrict(User $user, array $capabilities): void
    {
        StaffCapability::query()->where('user_id', $user->id)->delete();
        foreach ($capabilities as $capability) {
            StaffCapability::query()->create([
                'user_id' => $user->id,
                'capability' => $capability,
            ]);
        }
        app()->forgetInstance(StaffCapabilityService::class);
    }

    private function userWithRole(string $roleName, array $overrides = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'password' => 'password',
            'active_role_id' => $role->id,
        ], $overrides));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function siteFor(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Analytics Site',
            'site_url' => 'https://analytics-mix.example',
            'domain' => 'analytics-mix.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'marketing',
            'price' => 75,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function orderFor(User $advertiser, Site $site, array $overrides = []): Order
    {
        $amount = (float) ($overrides['total_amount'] ?? 75);
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-AN-'.uniqid(),
            'reference_code' => 'REF-AN-'.uniqid(),
            'subtotal' => $amount,
            'tax' => 0,
            'total_amount' => $amount,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => $amount,
        ]);

        return $order->fresh(['items']);
    }
}
