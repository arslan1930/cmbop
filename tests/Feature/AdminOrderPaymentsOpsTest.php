<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Billing\BillingDocumentService;
use App\Services\CheckoutIntentService;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdminOrderPaymentsOpsTest extends TestCase
{
    use CreatesContentSubmissions;
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

        $this->actingAs($admin)
            ->getJson(route('admin.payments.show', $order->id))
            ->assertOk()
            ->assertJsonPath('data.allowed_statuses', [])
            ->assertJsonMissingPath('data.stripe_response');
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

    public function test_scheduled_filter_uses_publication_mode_not_status_column(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $site = $this->makeSite($this->makeUser('publisher'), 'sched');
        $live = $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-SCHED-LIVE',
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDay(),
        ]);
        $legacy = $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-SCHED-LEGACY',
            'status' => 'scheduled',
            'publication_mode' => 'immediate',
            'scheduled_publish_at' => now()->addDay(),
        ]);
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-SCHED-PLAIN',
            'status' => 'pending',
            'publication_mode' => 'immediate',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['status' => 'scheduled']))
            ->assertOk()
            ->assertJsonFragment(['order_number' => $live->order_number])
            ->assertJsonFragment(['order_number' => $legacy->order_number])
            ->assertJsonMissing(['order_number' => 'PAY-SCHED-PLAIN']);
    }

    public function test_failed_paid_card_can_later_be_refunded(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 5,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'fail-refund'), [
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 80,
            'reference_code' => 'PAY-FAIL-THEN-REFUND',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $order->reference_code, 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
                'notes' => 'Clicked failed by mistake',
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('cancelled', $order->status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(85.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => $order->order_number]))
            ->assertOk()
            ->assertJsonPath('data.0.allowed_statuses', ['failed', 'refunded']);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(85.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_paid_row_can_save_notes_without_a_money_move(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'notes-only'), [
            'payment_method' => 'wise',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now()->subHour(),
            'total_amount' => 80,
        ]);
        $paidAt = $order->paid_at?->toDateTimeString();

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => $order->order_number]))
            ->assertOk()
            ->assertJsonPath('data.0.allowed_statuses', ['paid', 'failed', 'refunded']);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
                'notes' => 'Wise #8891 matched on statement',
                'payment_reference' => 'WISE-8891',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertSame('Wise #8891 matched on statement', $order->admin_notes);
        $this->assertSame('WISE-8891', $order->payment_reference);
        $this->assertSame($paidAt, $order->paid_at?->toDateTimeString());
        $this->assertEqualsWithDelta(10.0, (float) $wallet->fresh()->balance, 0.01);
    }

    public function test_wallet_fail_first_sibling_does_not_take_other_leftover(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 120,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $site = $this->makeSite($this->makeUser('publisher'), 'wallet-sib-left');
        $first = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 50,
            'subtotal' => 50,
            'reference_code' => 'PAY-WALLET-SIB-LEFT',
        ]);
        $this->makeOrder($advertiser, $site, [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 50,
            'subtotal' => 50,
            'reference_code' => 'PAY-WALLET-SIB-LEFT',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'PAY-OTHER-SIB-LEFT', 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $first->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(50.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(70.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(40.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'PAY-OTHER-SIB-LEFT'),
            0.01
        );
    }

    public function test_wallet_fail_without_intent_does_not_steal_other_leftover(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 135,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'wallet-no-peek'), [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 115,
            'subtotal' => 115,
            'reference_code' => 'PAY-WALLET-NO-PEEK-HOLD',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'PAY-WALLET-OTHER-LEFTOVER', 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(115.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(95.0, $wallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'PAY-WALLET-OTHER-LEFTOVER'),
            0.01
        );
    }

    public function test_wallet_fail_does_not_steal_another_checkout_leftover_bonus(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 100,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'wallet-iso'), [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 80,
            'reference_code' => 'PAY-WALLET-THIS',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $order->reference_code, 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'PAY-WALLET-OTHER', 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_unpaid_fail_does_not_steal_another_checkout_leftover_bonus(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'unpaid-iso'), [
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
            'total_amount' => 80,
            'reference_code' => 'PAY-UNPAID-THIS',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'PAY-UNPAID-OTHER', 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'PAY-UNPAID-OTHER'),
            0.01
        );
    }

    public function test_admin_failed_releases_wallet_hold_when_order_already_cancelled(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 115,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'paid-cancel-wallet'), [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'cancelled',
            'paid_at' => now(),
            'total_amount' => 115,
            'subtotal' => 115,
            'reference_code' => 'PAY-PAID-CANCEL-WALLET',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(115.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_admin_failed_credits_cancelled_paid_card_order(): void
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
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'paid-cancel-card'), [
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'cancelled',
            'paid_at' => now(),
            'total_amount' => 80,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(80.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_wallet_refund_without_intent_restores_promo_in_the_hold(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 115,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'wallet-refund'), [
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 115,
            'subtotal' => 115,
            'reference_code' => 'PAY-WALLET-NO-PEEK',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(115.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(95.0, $wallet->withdrawableBalance(), 0.01);
    }

    public function test_refund_without_this_order_bonus_does_not_steal_another_leftover(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'no-peek'), [
            'payment_method' => 'wise',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 80,
            'reference_code' => 'PAY-NO-PEEK-THIS',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'PAY-NO-PEEK-OTHER', 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'PAY-NO-PEEK-OTHER'),
            0.01
        );
    }

    public function test_refund_does_not_steal_another_checkout_leftover_bonus(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 40,
            'bonus_balance' => 0,
            'bonus_reserved' => 40,
            'currency' => 'EUR',
        ]);
        $site = $this->makeSite($this->makeUser('publisher'), 'bonus-iso');
        $order = $this->makeOrder($advertiser, $site, [
            'payment_method' => 'wise',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 80,
            'reference_code' => 'PAY-BONUS-THIS',
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $order->reference_code, 20);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, 'PAY-BONUS-OTHER', 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
            ])
            ->assertOk();

        $wallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(
            20.0,
            app(CheckoutIntentService::class)->peekBonus($advertiser->id, 'PAY-BONUS-OTHER'),
            0.01
        );
    }

    public function test_unpaid_cancelled_fail_issues_failure_doc_not_refund_receipt(): void
    {
        $admin = $this->makeUser('admin');
        $order = $this->makeOrder($advertiser = $this->makeUser('advertiser'), $this->makeSite($this->makeUser('publisher'), 'unpaid-cancel'), [
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'cancelled',
        ]);

        $this->mock(BillingDocumentService::class, function ($mock) {
            $mock->shouldReceive('handlePaymentFailed')->once();
            $mock->shouldReceive('handlePaymentRefunded')->never();
        });

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame($advertiser->id, $order->user_id);
    }

    public function test_paid_fail_issues_refund_receipt_not_failure_doc(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'paid-fail-doc'), [
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $this->mock(BillingDocumentService::class, function ($mock) {
            $mock->shouldReceive('handlePaymentRefunded')->once();
            $mock->shouldReceive('handlePaymentFailed')->never();
        });

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'failed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_refund_still_succeeds_when_post_commit_notification_throws(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        $order = $this->makeOrder($advertiser, $this->makeSite($this->makeUser('publisher'), 'post-commit'), [
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
            'total_amount' => 80,
        ]);

        $this->mock(InAppNotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyRefundCredited')
                ->once()
                ->andThrow(new \RuntimeException('bell down'));
        });

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'refunded',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(90.0, (float) $wallet->fresh()->balance, 0.01);
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
        $this->assertStringContainsString('willMoveMoney', $html);
        $this->assertStringContainsString('does not credit the wallet again', $html);
        $this->assertStringContainsString('Choose a payment status first.', $html);
        $this->assertStringContainsString(json_encode(route('admin.payments.data', absolute: false)), $html);
        $this->assertStringNotContainsString('const PAYMENTS_DATA = '.json_encode(route('admin.payments.data')), $html);
        $blade = file_get_contents(resource_path('views/admin/payments.blade.php'));
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/sweetalert2@11', $blade);
        $this->assertStringContainsString("route('admin.payments.data', absolute: false)", $blade);
    }

    public function test_cannot_mark_paid_when_listing_left_the_catalog(): void
    {
        $admin = $this->makeUser('admin');
        $site = $this->makeSite($this->makeUser('publisher'), 'hidden-mark-paid');
        $order = $this->makeOrder($this->makeUser('advertiser'), $site, [
            'order_number' => 'PAY-HIDDEN-1',
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $site->update(['verified' => false, 'active' => false]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_cannot_mark_paid_when_library_article_has_an_incomplete_link(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $site = $this->makeSite($this->makeUser('publisher'), 'lib-unready');
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-LIB-UNREADY-1',
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $order->items()->first()->update([
            'content_submission_id' => $submission->id,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
            'anchor_text' => $submission->anchor_text,
            'target_url' => $submission->target_url,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $order->items()->first()->id,
            'target_url' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_cannot_mark_paid_when_library_article_was_deleted(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $site = $this->makeSite($this->makeUser('publisher'), 'lib-missing');
        $order = $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-LIB-MISSING-1',
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $order->items()->first()->update([
            'content_submission_id' => null,
            'content_path' => 'content-uploads/gone.docx',
            'content_original_name' => 'article.docx',
            'anchor_text' => 'stale anchor',
            'target_url' => 'https://stale.example/backlink',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_mark_paid_refreshes_stale_library_link_fields(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $site = $this->makeSite($this->makeUser('publisher'), 'lib-refresh');
        $submission = $this->createApprovedSubmission($advertiser);
        $order = $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-LIB-REFRESH-1',
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = $order->items()->first();
        $item->update([
            'content_submission_id' => $submission->id,
            'content_path' => $submission->path,
            'content_original_name' => $submission->original_filename,
            'anchor_text' => 'old publisher anchor',
            'target_url' => 'https://old.example/backlink',
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'anchor_text' => 'fresh publisher anchor',
            'target_url' => 'https://example.com/tools',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('fresh publisher anchor', $item->anchor_text);
        $this->assertSame('https://example.com/tools', $item->target_url);
    }

    public function test_cannot_mark_paid_when_library_line_only_has_a_download_url(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $site = $this->makeSite($this->makeUser('publisher'), 'lib-download-only');
        $order = $this->makeOrder($advertiser, $site, [
            'order_number' => 'PAY-LIB-DOWNLOAD-1',
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $order->items()->first()->update([
            'content_submission_id' => null,
            'content_path' => null,
            'content_original_name' => null,
            'content_link' => '/content-submissions/99/download',
            'anchor_text' => 'stale anchor',
            'target_url' => 'https://stale.example/backlink',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->paid_at);
    }
}
