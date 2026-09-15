<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use App\Support\Csv;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        StaffCapability::ensureTable();
        OrderItemDispute::ensureTable();
    }

    public function test_export_buttons_render_on_admin_pages(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee(route('admin.orders.export', absolute: false), false);
        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee(route('admin.users.export', absolute: false), false);
        $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee(route('admin.finance.refunds.export', absolute: false), false);
        $this->actingAs($admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee(route('admin.finance.refunds.export', absolute: false), false);
    }

    public function test_csv_cell_neutralizes_formula_injection(): void
    {
        $this->assertSame("'=SUM(1)", Csv::cell('=SUM(1)'));
        $this->assertSame("'+1", Csv::cell('+1'));
        $this->assertSame("'-1", Csv::cell('-1'));
        $this->assertSame("'@cmd", Csv::cell('@cmd'));
        $this->assertSame('plain', Csv::cell('plain'));
    }

    public function test_orders_export_uses_current_filters(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', [
            'name' => '=SUM(1)',
            'email' => 'csv-buyer@example.com',
        ]);
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Csv Publisher',
            'email' => 'csv-pub@example.com',
        ]);
        $site = $this->siteFor($publisher);
        $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-CSV-PAID',
            'payment_status' => 'paid',
            'status' => 'processing',
        ], 'https://live.example/paid');
        $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-CSV-PENDING',
            'payment_status' => 'pending',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.orders.export', ['payment_status' => 'paid']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('order_number', $csv);
        $this->assertStringContainsString('ORD-CSV-PAID', $csv);
        $this->assertStringContainsString('csv-buyer@example.com', $csv);
        $this->assertStringContainsString('Csv Publisher', $csv);
        $this->assertStringContainsString('https://live.example/paid', $csv);
        $this->assertStringContainsString("'=SUM(1)", $csv);
        $this->assertStringNotContainsString('ORD-CSV-PENDING', $csv);

        $log = ActivityLog::query()->where('action', 'order.exported')->first();
        $this->assertNotNull($log);
        $this->assertSame('paid', data_get($log->properties, 'payment_status'));
        $this->assertSame(1, (int) data_get($log->properties, 'rows_exported'));
    }

    public function test_users_export_respects_filters_and_omits_payout_fields(): void
    {
        $admin = $this->userWithRole('admin', [
            'name' => 'Csv Admin',
            'email' => 'csv-admin@example.com',
        ]);
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Csv Listed Publisher',
            'email' => 'csv-listed-pub@example.com',
            'phone' => '555-0100',
            'country' => 'DE',
            'company_name' => 'Listed GmbH',
        ]);
        $publisher->forceFill([
            'payout_paypal_email' => 'secret-payout@example.com',
            'payout_bank_account' => 'DE00SECRETCSV',
            'payout_crypto_trx_wallet' => 'TSecretWalletCsv',
        ])->save();
        $this->userWithRole('advertiser', [
            'name' => 'Csv Advertiser Skip',
            'email' => 'csv-adv-skip@example.com',
        ]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.users.export', ['q' => 'Listed GmbH', 'role' => 'publisher']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('email,phone,country,company,roles', $csv);
        $this->assertStringContainsString('csv-listed-pub@example.com', $csv);
        $this->assertStringContainsString('Listed GmbH', $csv);
        $this->assertStringContainsString('publisher', $csv);
        $this->assertStringNotContainsString('csv-adv-skip@example.com', $csv);
        $this->assertStringNotContainsString('secret-payout@example.com', $csv);
        $this->assertStringNotContainsString('DE00SECRETCSV', $csv);
        $this->assertStringNotContainsString('TSecretWalletCsv', $csv);
        $this->assertStringNotContainsString('payout_', $csv);

        $log = ActivityLog::query()->where('action', 'user.exported')->first();
        $this->assertNotNull($log);
        $this->assertSame('Listed GmbH', data_get($log->properties, 'q'));
        $this->assertSame('publisher', data_get($log->properties, 'role'));
    }

    public function test_refunds_export_includes_refunded_orders_and_upheld_disputes(): void
    {
        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser', ['email' => 'csv-refund-buyer@example.com']);
        $publisher = $this->userWithRole('publisher', ['email' => 'csv-refund-pub@example.com']);
        $site = $this->siteFor($publisher);

        $refunded = $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-CSV-REFUND',
            'payment_status' => 'refunded',
            'status' => 'cancelled',
            'total_amount' => 80,
        ]);
        $oldRefund = $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-CSV-OLD-REFUND',
            'payment_status' => 'refunded',
            'status' => 'cancelled',
        ]);
        DB::table('orders')->where('id', $oldRefund->id)->update([
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $clawbackOrder = $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-CSV-CLAWBACK',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        OrderItemDispute::create([
            'order_id' => $clawbackOrder->id,
            'order_item_id' => $clawbackOrder->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Live link removed after completion.',
            'resolved_at' => now(),
            'advertiser_credited' => 40,
            'publisher_debited' => 35,
            'debt_created' => 0,
        ]);
        $oldDisputeOrder = $this->orderFor($advertiser, $site, [
            'order_number' => 'ORD-CSV-OLD-CLAWBACK',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        OrderItemDispute::create([
            'order_id' => $oldDisputeOrder->id,
            'order_item_id' => $oldDisputeOrder->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Old clawback.',
            'resolved_at' => now()->subYear(),
            'advertiser_credited' => 10,
            'publisher_debited' => 10,
            'debt_created' => 0,
        ]);

        $all = $this->actingAs($admin)
            ->get(route('admin.finance.refunds.export', ['period' => 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('kind,occurred_at', $all);
        $this->assertStringContainsString('order_refund', $all);
        $this->assertStringContainsString('clawback', $all);
        $this->assertStringContainsString('ORD-CSV-REFUND', $all);
        $this->assertStringContainsString('ORD-CSV-CLAWBACK', $all);
        $this->assertStringContainsString('ORD-CSV-OLD-REFUND', $all);
        $this->assertStringContainsString('ORD-CSV-OLD-CLAWBACK', $all);
        $this->assertStringContainsString('40.00', $all);
        $this->assertStringContainsString('35.00', $all);

        $window = $this->actingAs($admin)
            ->get(route('admin.finance.refunds.export', [
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('ORD-CSV-REFUND', $window);
        $this->assertStringContainsString('ORD-CSV-CLAWBACK', $window);
        $this->assertStringNotContainsString('ORD-CSV-OLD-REFUND', $window);
        $this->assertStringNotContainsString('ORD-CSV-OLD-CLAWBACK', $window);

        $this->assertNotNull($refunded->fresh());
        $log = ActivityLog::query()->where('action', 'finance.refunds_exported')->first();
        $this->assertNotNull($log);
    }

    public function test_support_only_can_export_orders_and_users_but_not_refunds(): void
    {
        $admin = $this->userWithRole('admin');
        $this->restrict($admin, [StaffCapability::SUPPORT]);
        $publisher = $this->userWithRole('publisher', [
            'name' => 'Support Export Pub',
            'email' => 'support-export-pub@example.com',
        ]);
        $this->orderFor(
            $this->userWithRole('advertiser', ['email' => 'support-export-adv@example.com']),
            $this->siteFor($publisher),
            ['order_number' => 'ORD-CSV-SUPPORT']
        );

        $ordersCsv = $this->actingAs($admin)
            ->get(route('admin.orders.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('ORD-CSV-SUPPORT', $ordersCsv);

        $usersCsv = $this->actingAs($admin)
            ->get(route('admin.users.export', ['q' => 'Support Export Pub']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('support-export-pub@example.com', $usersCsv);
        $this->actingAs($admin)
            ->get(route('admin.finance.refunds.export', ['period' => 'all']))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_finance_only_can_export_refunds(): void
    {
        $admin = $this->userWithRole('admin');
        $this->restrict($admin, [StaffCapability::FINANCE]);

        $this->actingAs($admin)
            ->get(route('admin.finance.refunds.export', ['period' => 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->actingAs($admin)
            ->get(route('admin.inbox.index'))
            ->assertRedirect(route('admin.dashboard'));
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

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Csv Export Site',
            'site_url' => 'https://csv-export.example',
            'domain' => 'csv-export.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 50,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function orderFor(User $advertiser, Site $site, array $overrides = [], ?string $liveUrl = null): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CSV-'.uniqid(),
            'reference_code' => 'REF-CSV-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
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
            'price' => 50,
            'live_url' => $liveUrl,
        ]);

        return $order->fresh(['items', 'user']);
    }
}
