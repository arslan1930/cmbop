<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminOrderPaymentsOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
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

    private function makeSite(User $publisher, string $suffix = 'ops'): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Payments Ops '.$suffix,
            'site_url' => 'https://payments-ops-'.$suffix.'.example',
            'domain' => 'payments-ops-'.$suffix.'.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Payments ops site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, Site $site, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'PAY-OPS-'.random_int(1000, 9999),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 80,
        ]);

        return $order->fresh(['items', 'user']);
    }

    public function test_payments_data_is_slim_and_lists_allowed_statuses(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advertiser->forceFill([
            'stripe_customer_id' => 'cus_secret',
            'payout_bank_account' => 'DE00SECRET',
        ])->save();
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher')), [
            'order_number' => 'PAY-SLIM-1',
            'stripe_response' => ['secret' => 'should-not-leak'],
        ]);

        $payload = $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => 'PAY-SLIM-1']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.order_number', 'PAY-SLIM-1')
            ->assertJsonPath('data.0.user.email', $advertiser->email)
            ->assertJsonPath('summary.unpaid_count', 1)
            ->json();

        $row = $payload['data'][0];
        $this->assertSame(['pending', 'paid', 'failed'], $row['allowed_statuses']);
        $this->assertArrayNotHasKey('stripe_response', $row);
        $this->assertArrayNotHasKey('stripe_customer_id', $row['user']);
        $this->assertArrayNotHasKey('payout_bank_account', $row['user']);
        $this->assertArrayNotHasKey('password', $row['user']);
    }

    public function test_paid_in_flight_order_can_be_refunded_with_notes_and_reference(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 5,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher')), [
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 90,
            'subtotal' => 80,
            'tax' => 10,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
                'notes' => 'Customer cancelled before publish',
                'payment_reference' => 'pi_test_123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Customer cancelled before publish', $order->admin_notes);
        $this->assertSame('pi_test_123', $order->payment_reference);
        $this->assertNotNull($order->paid_at);
        $this->assertEqualsWithDelta(95.0, (float) $wallet->fresh()->balance, 0.01);
    }

    public function test_refund_uses_order_total_not_line_sum(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $site = $this->makeSite($this->makeUser('publisher'), 'tax');
        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'bank',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
            'subtotal' => 80,
            'tax' => 15,
            'additional_price' => 5,
            'total_amount' => 100,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(100.0, (float) $wallet->fresh()->balance, 0.01);
    }

    public function test_paid_completed_row_has_no_allowed_statuses(): void
    {
        $admin = $this->makeUser('admin');
        $order = $this->makeOrder($this->makeUser('advertiser'), $this->makeSite($this->makeUser('publisher')), [
            'order_number' => 'PAY-DONE-1',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => 'PAY-DONE-1']))
            ->assertOk()
            ->assertJsonPath('data.0.allowed_statuses', []);

        $show = $this->actingAs($admin)
            ->getJson(route('admin.payments.show', $order->id))
            ->assertOk()
            ->assertJsonPath('data.allowed_statuses', [])
            ->assertJsonMissingPath('data.stripe_response');
        $this->assertStringContainsString('no-store', (string) $show->headers->get('Cache-Control'));
    }

    public function test_export_csv_uses_current_filters(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $site = $this->makeSite($this->makeUser('publisher'), 'csv');
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-CSV-UNPAID',
            'payment_status' => 'pending',
        ]);
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-CSV-PAID',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.payments.export', ['payment_status' => 'unpaid']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('PAY-CSV-UNPAID', $csv);
        $this->assertStringNotContainsString('PAY-CSV-PAID', $csv);
        $this->assertStringContainsString('order_number', $csv);
    }

    public function test_date_field_paid_at_filters_settled_rows(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $site = $this->makeSite($this->makeUser('publisher'), 'dates');
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-OLD-CREATE',
            'payment_status' => 'paid',
            'status' => 'processing',
            'created_at' => now()->subDays(10),
            'paid_at' => now(),
        ]);
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-OLD-PAID',
            'payment_status' => 'paid',
            'status' => 'processing',
            'created_at' => now(),
            'paid_at' => now()->subDays(10),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', [
                'date_field' => 'paid_at',
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonFragment(['order_number' => 'PAY-OLD-CREATE'])
            ->assertJsonMissing(['order_number' => 'PAY-OLD-PAID']);
    }

    public function test_per_page_is_capped(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['per_page' => 9999]))
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
    }

    public function test_failed_from_paid_clears_paid_at(): void
    {
        $admin = $this->makeUser('admin');
        $order = $this->makeOrder($this->makeUser('advertiser'), $this->makeSite($this->makeUser('publisher')), [
            'payment_method' => 'bank',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
                'notes' => 'Transfer reversed',
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('cancelled', $order->status);
        $this->assertNull($order->paid_at);
        $this->assertSame('Transfer reversed', $order->admin_notes);
    }

    public function test_search_and_update_survive_missing_payment_columns(): void
    {
        $admin = $this->makeUser('admin');
        $order = $this->makeOrder($this->makeUser('advertiser'), $this->makeSite($this->makeUser('publisher')), [
            'order_number' => 'PAY-MISS-1',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        foreach (['payment_reference', 'admin_notes'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        $this->assertFalse(Schema::hasColumn('orders', 'payment_reference'));
        $this->assertFalse(Schema::hasColumn('orders', 'admin_notes'));

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => 'PAY-MISS-1']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.order_number', 'PAY-MISS-1');

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
                'notes' => 'Wire matched after column repair',
                'payment_reference' => 'WISE-REPAIR-1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('Wire matched after column repair', $order->admin_notes);
        $this->assertSame('WISE-REPAIR-1', $order->payment_reference);
    }

    public function test_payments_page_defaults_unpaid_and_confirms_money_moves(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("$('#paymentStatusFilter').val('unpaid')", $html);
        $this->assertStringContainsString('Swal.fire({', $html);
        $this->assertStringContainsString('does not refund the Stripe charge', $html);
        $this->assertStringContainsString('Use a dispute clawback', $html);
        $blade = file_get_contents(resource_path('views/admin/payments.blade.php'));
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/sweetalert2@11', $blade);
    }
}
