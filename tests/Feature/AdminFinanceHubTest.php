<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteFeaturePurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
use App\Services\OrderPaymentService;
use App\Services\Wallet\WalletLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AdminFinanceHubTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_finance_hub_shows_true_revenue_and_liability(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 120,
            'bonus_balance' => 20,
            'reserved_balance' => 30,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-TEST-1',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-FIN-1',
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Fee Site',
            'site_url' => 'https://fee-site.test',
            'domain' => 'fee-site-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Finance hub test site description text.',
            'verified' => true,
            'active' => true,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'additional_price' => 0,
            'publisher_price' => 100,
            'platform_fee_percent' => 15,
            'platform_fee_amount' => 15,
        ]);

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'pending',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Due to pay now')
            ->assertSee('In publisher wallets')
            ->assertSee('Total publisher liability')
            ->assertSee('Order platform fees')
            ->getContent();

        $this->assertStringContainsString('€15.00', $html); // platform fee
        $this->assertStringContainsString('€115.00', $html); // GMV
        $this->assertStringContainsString('€40.00', $html); // open withdrawal / due now
        $this->assertStringContainsString('€80.00', $html); // in wallets

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(15.0, $overview['platform']['order_fees']);
        $this->assertEquals(115.0, $overview['platform']['gmv_completed']);
        $this->assertEquals(100.0, $overview['money_out']['earnings_credited']['amount']);
        $this->assertEquals(40.0, $overview['due_to_pay_now']);
        $this->assertEquals(80.0, $overview['in_publisher_wallets']);
        $this->assertEquals(120.0, $overview['total_publisher_liability']);
        $this->assertEquals(120.0, $overview['payable_now']); // back-compat alias
        $this->assertEquals(100.0, $overview['liability']['advertiser']['cash']); // 120-20
        $this->assertEquals(20.0, $overview['liability']['advertiser']['bonus']);
        $this->assertGreaterThan(0, $overview['cash_split']['cash_in_bank']);
    }

    public function test_manual_deposit_with_stripe_session_is_not_double_counted(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-BANK-SESSION',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
            'stripe_session_id' => 'cs_test_leftover_session',
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(50.0, $overview['money_in']['deposits_completed']['amount']);
        $this->assertEquals(50.0, $overview['money_in']['deposits_completed']['manual']);
        $this->assertEquals(0.0, $overview['money_in']['deposits_completed']['stripe']);
        $this->assertEquals(50.0, $overview['cash_split']['cash_in_bank']);
    }

    public function test_empty_stripe_session_id_is_not_treated_as_card_deposit(): void
    {
        $advertiser = $this->makeUser('advertiser');

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-EMPTY-SESSION',
            'amount' => 40,
            'payment_method' => 'paypal',
            'status' => 'completed',
            'approved_at' => now(),
            'stripe_session_id' => '',
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(40.0, $overview['money_in']['deposits_completed']['amount']);
        $this->assertEquals(0.0, $overview['money_in']['deposits_completed']['stripe']);
        $this->assertEquals(0.0, $overview['money_in']['deposits_completed']['manual']);
        $this->assertEquals(0.0, $overview['cash_split']['cash_in_bank']);
    }

    public function test_earnings_use_snapshotted_publisher_price_not_flat_markup(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-FIN-TIERED',
            'subtotal' => 113,
            'tax' => 0,
            'total_amount' => 113,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Tiered Fee Site',
            'site_url' => 'https://tiered-fee.test',
            'domain' => 'tiered-fee-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Tiered fee finance test site description text.',
            'verified' => true,
            'active' => true,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 113,
            'additional_price' => 0,
            'publisher_price' => 100,
            'platform_fee_percent' => 13,
            'platform_fee_amount' => 13,
        ]);

        $this->assertSame(100.0, $item->publisherPayoutAmount());
        $this->assertSame(13.0, $item->platformFeeAmount());

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        // Flat 15% SQL would report €98.26 (113 / 1.15). Snapshot is €100.
        $this->assertEquals(100.0, $overview['money_out']['earnings_credited']['amount']);
        $this->assertEquals(13.0, $overview['platform']['order_fees']);
        $this->assertEquals(113.0, $overview['platform']['gmv_completed']);
    }

    public function test_stripe_order_method_counts_as_card_cash_in(): void
    {
        $advertiser = $this->makeUser('advertiser');

        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-FIN-STRIPE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'stripe',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(80.0, $overview['money_in']['orders_paid']['gmv']);
        $this->assertEquals(80.0, $overview['money_in']['orders_paid']['stripe_card']);
        $this->assertEquals(80.0, $overview['cash_split']['cash_in_bank']);
    }

    public function test_unfulfilled_card_credit_counts_as_cash_in(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);

        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 25,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordAdjustment(
            $wallet,
            25,
            'credit',
            null,
            OrderPaymentService::unfulfilledCardCreditReference('CHK-LEFT-1'),
            'Card payment credited because listing(s) left the catalog'
        );

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(25.0, $overview['money_in']['unfulfilled_card_credits']);
        $this->assertEquals(25.0, $overview['cash_split']['cash_in_bank']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Leftover card credits')
            ->assertSee('€25.00');
    }

    public function test_featured_site_stripe_counts_as_cash_in_not_wallet(): void
    {
        $publisher = $this->makeUser('publisher');
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Promo Site',
            'site_url' => 'https://promo-site.test',
            'domain' => 'promo-site-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Featured site finance cash-in test description.',
            'verified' => true,
            'active' => true,
        ]);

        SiteFeaturePurchase::create([
            'site_id' => $site->id,
            'user_id' => $publisher->id,
            'amount' => 29,
            'days' => 7,
            'payment_method' => 'stripe',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
        ]);
        SiteFeaturePurchase::create([
            'site_id' => $site->id,
            'user_id' => $publisher->id,
            'amount' => 29,
            'days' => 7,
            'payment_method' => 'wallet',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(29.0, $overview['money_in']['site_feature_stripe']);
        $this->assertEquals(29.0, $overview['cash_split']['cash_in_bank']);
    }

    public function test_withdrawable_sums_per_wallet_not_aggregate_bonus(): void
    {
        $admin = $this->makeUser('admin');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $pubA = $this->makeUser('publisher');
        $pubB = $this->makeUser('publisher');

        // A: all bonus → €0 withdrawable
        Wallet::create([
            'user_id' => $pubA->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'bonus_balance' => 100,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        // B: cash only → €100 withdrawable
        Wallet::create([
            'user_id' => $pubB->id,
            'role_id' => $pubRole->id,
            'balance' => 100,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $liability = app(FinanceOverviewService::class)->walletLiability();

        // Aggregate formula would wrongly return €10; per-wallet must return €100.
        $this->assertEquals(100.0, $liability['in_publisher_wallets']);
        $this->assertEquals(0.0, $liability['due_to_pay_now']);
        $this->assertEquals(100.0, $liability['total_publisher_liability']);
    }

    public function test_ledger_and_user_dossier_pages(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 50,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordTransferIn($wallet, 50, null, 'TEST-EARN', 'Test earnings');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('Wallet ledger')
            ->assertSee('Transfer In', false)
            ->assertSee('Moved to Advertiser Wallet', false)
            ->assertSee('Earnings Moved for Spending', false)
            ->assertSee('Test earnings');

        $this->actingAs($admin)
            ->get(route('admin.finance.user', $publisher))
            ->assertOk()
            ->assertSee('Finance dossier')
            ->assertSee($publisher->email)
            ->assertSee('Publisher wallet');
    }

    public function test_period_csv_export(): void
    {
        $admin = $this->makeUser('admin');

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.export', ['period' => 'month']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('order_fees', $csv);
        $this->assertStringContainsString('refunded_order_fees', $csv);
        $this->assertStringContainsString('payable_now', $csv);
        $this->assertStringContainsString('cash_in_bank', $csv);
        $this->assertStringContainsString('unfulfilled_card_credits', $csv);
        $this->assertStringContainsString('stripe_card_collected', $csv);
        $this->assertStringContainsString('site_feature_stripe', $csv);
        $this->assertStringContainsString('failed_external_collected', $csv);
    }

    public function test_billing_config_exposes_withdrawal_fee_percent(): void
    {
        $this->assertIsFloat((float) config('billing.withdrawal_fee_percent'));
        $this->assertSame(0.01, round((float) config('billing.role_move.min_amount'), 2));
        $this->assertSame(0.0, (float) config('billing.role_move.fee_percent'));
    }

    public function test_record_transfer_in_writes_ledger(): void
    {
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 0,
            'currency' => 'EUR',
        ]);
        $wallet->credit(25);
        app(WalletLedgerService::class)->recordTransferIn($wallet, 25, null, 'OI-1');

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $publisher->id,
            'type' => WalletTransaction::TYPE_TRANSFER_IN,
            'amount' => 25,
            'reference' => 'OI-1',
        ]);
    }

    public function test_invalid_custom_dates_do_not_five_hundred(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->from(route('admin.finance'))
            ->get(route('admin.finance', ['date_from' => 'nope']))
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHasErrors('date_from');

        $this->actingAs($admin)
            ->from(route('admin.finance'))
            ->get(route('admin.finance', [
                'date_from' => '2026-08-10',
                'date_to' => '2026-08-01',
            ]))
            ->assertRedirect(route('admin.finance'))
            ->assertSessionHasErrors('date_to');
    }

    public function test_paid_and_completed_clocks_differ(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $this->seedCompletedPaidOrder($advertiser, $publisher, [
            'paid_at' => Carbon::parse('2026-07-15 12:00:00'),
            'completed_at' => Carbon::parse('2026-08-10 12:00:00'),
        ]);

        $july = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-07-01', '2026-07-31')
        );
        $august = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-08-01', '2026-08-31')
        );

        $this->assertEquals(115.0, $july['money_in']['orders_paid']['gmv']);
        $this->assertEquals(0.0, $july['platform']['gmv_completed']);
        $this->assertEquals(0.0, $july['platform']['order_fees']);
        $this->assertEquals(0.0, $august['money_in']['orders_paid']['gmv']);
        $this->assertEquals(115.0, $august['platform']['gmv_completed']);
        $this->assertEquals(15.0, $august['platform']['order_fees']);
        $this->assertEquals(100.0, $august['money_out']['earnings_credited']['amount']);
    }

    public function test_refunds_use_refund_date_and_fee_margin(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->seedCompletedPaidOrder($advertiser, $publisher, [
            'paid_at' => Carbon::parse('2026-07-15 12:00:00'),
            'completed_at' => Carbon::parse('2026-07-20 12:00:00'),
        ]);

        $order->forceFill([
            'payment_status' => 'refunded',
            'updated_at' => Carbon::parse('2026-08-10 12:00:00'),
        ])->save();

        $july = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-07-01', '2026-07-31')
        );
        $august = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-08-01', '2026-08-31')
        );

        $this->assertEquals(0.0, $july['platform']['refunds']);
        $this->assertEquals(0.0, $july['platform']['refunded_order_fees']);
        $this->assertEquals(115.0, $july['platform']['gmv_completed']);
        $this->assertEquals(15.0, $july['platform']['order_fees']);
        $this->assertEquals(115.0, $august['platform']['refunds']);
        $this->assertEquals(15.0, $august['platform']['refunded_order_fees']);
        $this->assertEquals(-15.0, $august['platform']['margin']);

        $all = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );
        $this->assertEquals(115.0, $all['platform']['gmv_completed']);
        $this->assertEquals(15.0, $all['platform']['order_fees']);
        $this->assertEquals(15.0, $all['platform']['refunded_order_fees']);
        $this->assertEquals(0.0, $all['platform']['margin']);
        $this->assertEquals(100.0, $july['money_out']['earnings_credited']['amount']);
        $this->assertEquals(-100.0, $august['money_out']['earnings_credited']['amount']);
        $this->assertEquals(0.0, $all['money_out']['earnings_credited']['amount']);
        $this->assertEquals(115.0, $all['cash_split']['cash_in_bank']);
        $this->assertEquals(0.0, $all['money_in']['orders_paid']['stripe_card']);
        $this->assertEquals(115.0, $all['money_in']['stripe_card_collected']);
    }

    public function test_refund_ledger_date_is_not_moved_by_later_order_update(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $order = $this->seedCompletedPaidOrder($advertiser, $publisher, [
            'paid_at' => Carbon::parse('2026-07-15 12:00:00'),
            'completed_at' => Carbon::parse('2026-07-20 12:00:00'),
        ]);

        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 115,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'currency' => 'EUR',
        ]);
        $refund = app(WalletLedgerService::class)->recordRefund(
            $wallet,
            115,
            0,
            $order,
            $order->order_number
        );
        $refund->forceFill(['created_at' => Carbon::parse('2026-07-25 12:00:00')])->save();

        $order->forceFill([
            'payment_status' => 'refunded',
            'updated_at' => Carbon::parse('2026-08-10 12:00:00'),
        ])->save();

        $july = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-07-01', '2026-07-31')
        );
        $august = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-08-01', '2026-08-31')
        );

        $this->assertEquals(115.0, $july['platform']['refunds']);
        $this->assertEquals(15.0, $july['platform']['refunded_order_fees']);
        $this->assertEquals(0.0, $august['platform']['refunds']);
        $this->assertEquals(0.0, $august['platform']['refunded_order_fees']);
    }

    public function test_in_progress_refund_does_not_reverse_unearned_fees(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->seedCompletedPaidOrder($advertiser, $publisher, [
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        $order->forceFill([
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'completed_at' => null,
        ])->save();

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(0.0, $overview['platform']['gmv_completed']);
        $this->assertEquals(0.0, $overview['platform']['order_fees']);
        $this->assertEquals(0.0, $overview['platform']['refunded_order_fees']);
        $this->assertEquals(115.0, $overview['platform']['refunds']);
        $this->assertEquals(0.0, $overview['platform']['margin']);
        $this->assertEquals(115.0, $overview['cash_split']['cash_in_bank']);
    }

    public function test_partial_clawback_drops_clawed_line_from_earnings_and_reverses_its_fee(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->seedCompletedPaidOrder($advertiser, $publisher);
        $order->update(['subtotal' => 230, 'total_amount' => 230]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Second Fee Site',
            'site_url' => 'https://second-fee-site.test',
            'domain' => 'second-fee-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Second line for partial clawback finance test.',
            'verified' => true,
            'active' => true,
        ]);

        $clawed = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article-2',
            'price' => 115,
            'additional_price' => 0,
            'publisher_price' => 100,
            'platform_fee_percent' => 15,
            'platform_fee_amount' => 15,
        ]);

        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $clawed->id,
            'opened_by' => $admin->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Live URL was removed after the report window started.',
            'resolved_at' => now(),
            'advertiser_credited' => 115,
            'publisher_debited' => 100,
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(230.0, $overview['money_in']['orders_paid']['gmv']);
        $this->assertEquals(230.0, $overview['cash_split']['cash_in_bank']);
        $this->assertEquals(100.0, $overview['money_out']['earnings_credited']['amount']);
        $this->assertEquals(30.0, $overview['platform']['order_fees']);
        $this->assertEquals(15.0, $overview['platform']['refunded_order_fees']);
        $this->assertEquals(115.0, $overview['platform']['refunds']);
        $this->assertEquals(1, $overview['platform']['refund_orders_count']);
        $this->assertEquals(15.0, $overview['platform']['margin']);
    }

    public function test_partial_clawback_reverses_fee_on_resolution_date_not_completion(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->seedCompletedPaidOrder($advertiser, $publisher, [
            'paid_at' => Carbon::parse('2026-07-15 12:00:00'),
            'completed_at' => Carbon::parse('2026-07-20 12:00:00'),
        ]);
        $order->update(['subtotal' => 230, 'total_amount' => 230]);

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Second Fee Site',
            'site_url' => 'https://second-fee-site.test',
            'domain' => 'second-fee-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Second line for partial clawback period finance test.',
            'verified' => true,
            'active' => true,
        ]);

        $clawed = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article-2',
            'price' => 115,
            'additional_price' => 0,
            'publisher_price' => 100,
            'platform_fee_percent' => 15,
            'platform_fee_amount' => 15,
        ]);

        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $clawed->id,
            'opened_by' => $admin->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Live URL was removed after the report window started.',
            'resolved_at' => Carbon::parse('2026-08-12 12:00:00'),
            'advertiser_credited' => 115,
            'publisher_debited' => 100,
        ]);

        $july = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-07-01', '2026-07-31')
        );
        $august = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-08-01', '2026-08-31')
        );

        $this->assertEquals(30.0, $july['platform']['order_fees']);
        $this->assertEquals(0.0, $july['platform']['refunded_order_fees']);
        $this->assertEquals(0.0, $july['platform']['refunds']);
        $this->assertEquals(30.0, $july['platform']['margin']);
        $this->assertEquals(0.0, $august['platform']['order_fees']);
        $this->assertEquals(15.0, $august['platform']['refunded_order_fees']);
        $this->assertEquals(115.0, $august['platform']['refunds']);
        $this->assertEquals(1, $august['platform']['refund_orders_count']);
        $this->assertEquals(-15.0, $august['platform']['margin']);
    }

    public function test_full_clawback_does_not_double_count_refunds_or_fees(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->seedCompletedPaidOrder($advertiser, $publisher);
        $order->update(['subtotal' => 230, 'total_amount' => 230]);
        $first = $order->items()->first();
        $second = $this->addPricedLine($order, $publisher);

        $this->seedUpheldDispute($admin, $order, $first);
        $this->seedUpheldDispute($admin, $order, $second);
        $order->update(['payment_status' => 'refunded']);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(230.0, $overview['platform']['gmv_completed']);
        $this->assertEquals(230.0, $overview['platform']['refunds']);
        $this->assertEquals(1, $overview['platform']['refund_orders_count']);
        $this->assertEquals(30.0, $overview['platform']['order_fees']);
        $this->assertEquals(30.0, $overview['platform']['refunded_order_fees']);
        $this->assertEquals(0.0, $overview['platform']['margin']);
        $this->assertEquals(0.0, $overview['money_out']['earnings_credited']['amount']);
        $this->assertEquals(230.0, $overview['cash_split']['cash_in_bank']);
    }

    public function test_staggered_full_clawback_keeps_each_line_on_its_resolution_date(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->seedCompletedPaidOrder($advertiser, $publisher, [
            'paid_at' => Carbon::parse('2026-07-15 12:00:00'),
            'completed_at' => Carbon::parse('2026-07-20 12:00:00'),
        ]);
        $order->update(['subtotal' => 230, 'total_amount' => 230]);
        $first = $order->items()->first();
        $second = $this->addPricedLine($order, $publisher);

        $this->seedUpheldDispute($admin, $order, $first, [
            'resolved_at' => Carbon::parse('2026-07-28 12:00:00'),
        ]);
        $this->seedUpheldDispute($admin, $order, $second, [
            'resolved_at' => Carbon::parse('2026-08-12 12:00:00'),
        ]);
        $order->forceFill([
            'payment_status' => 'refunded',
            'updated_at' => Carbon::parse('2026-08-12 12:00:00'),
        ])->save();

        $july = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-07-01', '2026-07-31')
        );
        $august = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, '2026-08-01', '2026-08-31')
        );
        $all = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(115.0, $july['platform']['refunds']);
        $this->assertEquals(15.0, $july['platform']['refunded_order_fees']);
        $this->assertEquals(30.0, $july['platform']['order_fees']);
        $this->assertEquals(15.0, $july['platform']['margin']);
        $this->assertEquals(100.0, $july['money_out']['earnings_credited']['amount']);
        $this->assertEquals(1, $july['platform']['refund_orders_count']);

        $this->assertEquals(115.0, $august['platform']['refunds']);
        $this->assertEquals(15.0, $august['platform']['refunded_order_fees']);
        $this->assertEquals(0.0, $august['platform']['order_fees']);
        $this->assertEquals(-15.0, $august['platform']['margin']);
        $this->assertEquals(-100.0, $august['money_out']['earnings_credited']['amount']);
        $this->assertEquals(1, $august['platform']['refund_orders_count']);

        $this->assertEquals(230.0, $all['platform']['refunds']);
        $this->assertEquals(30.0, $all['platform']['refunded_order_fees']);
        $this->assertEquals(0.0, $all['platform']['margin']);
        $this->assertEquals(0.0, $all['money_out']['earnings_credited']['amount']);
    }

    public function test_failed_after_paid_card_capture_still_counts_as_cash_in(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 80,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'currency' => 'EUR',
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-FIN-FAILED-CARD',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'cancelled',
            'paid_at' => null,
        ]);

        app(WalletLedgerService::class)->recordRefund(
            $wallet,
            80,
            0,
            $order,
            $order->order_number
        );

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(0.0, $overview['money_in']['orders_paid']['gmv']);
        $this->assertEquals(0.0, $overview['money_in']['stripe_card_collected']);
        $this->assertEquals(80.0, $overview['money_in']['failed_external_collected']);
        $this->assertEquals(80.0, $overview['cash_split']['cash_in_bank']);
        $this->assertEquals(80.0, $overview['platform']['refunds']);
    }

    public function test_null_paid_at_does_not_enter_every_period(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $this->seedCompletedPaidOrder($advertiser, $publisher, [
            'paid_at' => null,
            'completed_at' => Carbon::parse('2026-08-10 12:00:00'),
            'created_at' => Carbon::parse('2026-08-10 12:00:00'),
        ]);

        $throughAugust1 = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod(null, null, '2026-08-01')
        );

        $this->assertEquals(0.0, $throughAugust1['money_in']['orders_paid']['gmv']);
    }

    public function test_finance_nav_is_not_active_on_ledger(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/admin\/finance\/ledger"[^>]*\bactive\b/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="[^"]*\/admin\/finance"(?:\/)?(?:\?[^"]*)?"[^>]*\bactive\b/',
            $html
        );

        $blade = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $this->assertStringNotContainsString("routeIs('admin.finance.*')", $blade);
        $this->assertStringContainsString("routeIs('admin.finance.user')", $blade);
    }

    public function test_completed_window_qualifies_order_columns_for_where_has(): void
    {
        $service = app(FinanceOverviewService::class);
        $method = new ReflectionMethod(FinanceOverviewService::class, 'applyCompletedWindow');
        $method->setAccessible(true);

        $query = OrderItem::query()->whereHas('order', function ($inner) use ($method, $service) {
            $method->invoke($service, $inner, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')->endOfDay());
        });

        $sql = $query->toSql();
        $this->assertStringContainsString('orders.completed_at', $sql);
        $this->assertStringContainsString('orders.updated_at', $sql);
        $this->assertStringNotContainsString('COALESCE(completed_at, updated_at)', $sql);
    }

    public function test_ledger_filter_bar_aligns_action_with_inputs(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('finance-ledger-filters', $html);
        $this->assertStringContainsString('finance-ledger-filters__action', $html);
        $this->assertStringContainsString('row g-3 align-items-end', $html);
        $this->assertStringContainsString('for="adminFinanceLedgerFilter"', $html);
        $this->assertStringContainsString('id="adminFinanceLedgerFilter"', $html);
        $this->assertStringContainsString('for="adminFinanceLedgerSearch"', $html);
        $this->assertStringContainsString('form-label small text-muted mb-1', $html);

        $blade = (string) file_get_contents(resource_path('views/admin/finance-ledger.blade.php'));
        $this->assertStringNotContainsString('col-md-1', $blade);
        $this->assertStringNotContainsString('col-md-3', $blade);
        $this->assertStringContainsString('col-lg-auto finance-ledger-filters__action', $blade);

        $css = (string) file_get_contents(public_path('assets/css/admin-components.css'));
        $this->assertStringContainsString('.finance-ledger-filters .slb-search-status:empty', $css);
        $this->assertStringContainsString('.finance-ledger-filters .form-control[type="date"]', $css);
        $this->assertStringContainsString('.finance-ledger-filters__action .btn', $css);
    }

    public function test_overview_toolbar_aligns_actions_with_dossier_input(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Find user dossier')
            ->assertSee('Wallet ledger')
            ->assertSee('Export period CSV')
            ->getContent();

        $this->assertStringContainsString('admin-finance-toolbar', $html);
        $this->assertStringContainsString('admin-finance-toolbar d-flex flex-wrap align-items-end', $html);
        $this->assertStringContainsString('admin-finance-toolbar__form d-flex flex-nowrap align-items-end', $html);
        $this->assertStringContainsString('admin-finance-toolbar__search', $html);
        $this->assertStringContainsString('admin-finance-toolbar__action', $html);
        $this->assertStringContainsString('for="adminFinanceUserSearch"', $html);
        $this->assertStringContainsString('id="adminFinanceUserOpen"', $html);
        $this->assertStringContainsString('id="adminFinanceWalletLedger"', $html);
        $this->assertStringContainsString('id="adminFinanceExport"', $html);
        $this->assertStringContainsString('id="adminFinanceApplyRange"', $html);

        $blade = (string) file_get_contents(resource_path('views/admin/finance.blade.php'));
        $this->assertStringNotContainsString('admin-finance-toolbar d-flex flex-wrap gap-2 align-items-start', $blade);
        $this->assertStringNotContainsString('btn-outline-primary mb-3', $blade);
        $this->assertStringNotContainsString('request()->query()', $blade);

        $css = (string) file_get_contents(public_path('assets/css/admin-components.css'));
        $this->assertStringContainsString('.admin-finance-toolbar__search', $css);
        $this->assertStringContainsString('.admin-finance-toolbar .slb-search-status', $css);
        $this->assertMatchesRegularExpression('/\.admin-finance-toolbar \.slb-search-status\s*\{[^}]*position:\s*absolute/s', $css);
        $this->assertMatchesRegularExpression('/\.admin-finance-toolbar \.slb-search-status\s*\{[^}]*right:\s*0/s', $css);
        $this->assertStringContainsString('.admin-finance-toolbar .slb-search-status:empty', $css);
        $this->assertStringContainsString('.admin-finance-toolbar__action .btn', $css);
        $this->assertStringNotContainsString('.admin-finance-toolbar .btn,', $css);
        $this->assertStringContainsString('.admin-finance-toolbar:has(.slb-search-status:not(:empty))', $css);
        $this->assertMatchesRegularExpression(
            '/\.admin-finance-toolbar:has\(\.slb-search-status:not\(:empty\)\)\s*\{[^}]*padding-bottom:\s*2\.5em/s',
            $css
        );
    }

    public function test_overview_dossier_search_keeps_selected_period(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'week']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="period" value="week"', $html);
        $this->assertStringNotContainsString('<input type="hidden" name="date_from"', $html);
        $this->assertSame(2, substr_count($html, 'name="period" value="week"'));
    }

    public function test_overview_dossier_search_keeps_custom_dates(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="date_from" value="2026-01-01"', $html);
        $this->assertStringContainsString('name="date_to" value="2026-01-31"', $html);
        $this->assertStringNotContainsString('name="period"', $html);
    }

    public function test_overview_period_form_and_presets_keep_dossier_query(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'zz-no-match', 'period' => 'all']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="q" value="zz-no-match"', $html);
        $this->assertStringContainsString('admin-finance-period', $html);
        $this->assertStringContainsString('period=all&amp;q=zz-no-match', $html);
        $this->assertStringContainsString('period=week&amp;q=zz-no-match', $html);
        $this->assertStringContainsString('period=month&amp;q=zz-no-match', $html);

        $this->assertTrue((bool) preg_match('/id="adminFinanceExport"[^>]+href="([^"]+)"/', $html, $export));
        $this->assertStringContainsString('period=all', $export[1]);
        $this->assertStringNotContainsString('q=', $export[1]);
    }

    public function test_overview_export_link_uses_custom_dates_not_dossier_query(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', [
                'q' => 'zz-no-match',
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertTrue((bool) preg_match('/id="adminFinanceExport"[^>]+href="([^"]+)"/', $html, $export));
        $this->assertStringContainsString('date_from=2026-01-01', $export[1]);
        $this->assertStringContainsString('date_to=2026-01-31', $export[1]);
        $this->assertStringNotContainsString('period=', $export[1]);
        $this->assertStringNotContainsString('q=', $export[1]);
    }

    public function test_ledger_rejects_invalid_dates_and_array_search(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['date_from' => 'nope']))
            ->assertOk()
            ->assertSee('Wallet ledger', false);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => ['oops']]))
            ->assertOk()
            ->assertSee('Wallet ledger', false);
    }

    public function test_finance_page_uses_fee_margin_copy(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Est. fee margin', false)
            ->assertSee('Dated by paid date', false)
            ->assertSee('Dated by completed date', false)
            ->assertSee('Dated by refund date', false)
            ->assertSee('Fees − fee reversals − bonuses', false);
    }

    /**
     * @param  array<string, mixed>  $timestamps
     */
    private function seedCompletedPaidOrder(User $advertiser, User $publisher, array $timestamps = []): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-FIN-'.uniqid(),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => array_key_exists('paid_at', $timestamps) ? $timestamps['paid_at'] : now(),
            'completed_at' => array_key_exists('completed_at', $timestamps) ? $timestamps['completed_at'] : now(),
        ]);

        if (array_key_exists('created_at', $timestamps) || array_key_exists('updated_at', $timestamps)) {
            $order->forceFill(array_filter([
                'created_at' => $timestamps['created_at'] ?? null,
                'updated_at' => $timestamps['updated_at'] ?? null,
            ], fn ($value) => $value !== null))->save();
        }

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Fee Site',
            'site_url' => 'https://fee-site.test',
            'domain' => 'fee-site-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Finance hub test site description text.',
            'verified' => true,
            'active' => true,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'additional_price' => 0,
            'publisher_price' => 100,
            'platform_fee_percent' => 15,
            'platform_fee_amount' => 15,
        ]);

        return $order->fresh();
    }

    private function addPricedLine(Order $order, User $publisher): OrderItem
    {
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Second Fee Site',
            'site_url' => 'https://second-fee-site.test',
            'domain' => 'second-fee-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Second line for finance clawback tests.',
            'verified' => true,
            'active' => true,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article-2',
            'price' => 115,
            'additional_price' => 0,
            'publisher_price' => 100,
            'platform_fee_percent' => 15,
            'platform_fee_amount' => 15,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedUpheldDispute(User $admin, Order $order, OrderItem $item, array $overrides = []): OrderItemDispute
    {
        return OrderItemDispute::create(array_merge([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $admin->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Live URL was removed after the report window started.',
            'resolved_at' => now(),
            'advertiser_credited' => 115,
            'publisher_debited' => 100,
        ], $overrides));
    }
}
