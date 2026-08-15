<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFinanceCoverageTest extends TestCase
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

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Coverage Site',
            'site_url' => 'https://coverage-site.test',
            'domain' => 'coverage-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Finance coverage test site description text.',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function completedPaidOrder(User $advertiser, User $publisher, float $total, float $fee, $updatedAt = null): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-COV-'.uniqid(),
            'subtotal' => $total,
            'tax' => 0,
            'total_amount' => $total,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => $updatedAt ?? now(),
        ]);

        $site = $this->makeSite($publisher);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => $total,
            'additional_price' => 0,
            'publisher_price' => $total - $fee,
            'platform_fee_percent' => $total > 0 ? round($fee / $total * 100, 2) : 0,
            'platform_fee_amount' => $fee,
        ]);

        if ($updatedAt) {
            Order::whereKey($order->id)->update([
                'updated_at' => $updatedAt,
                'paid_at' => $updatedAt,
                'completed_at' => $updatedAt,
            ]);
        }

        return $order->fresh();
    }

    public function test_reserved_is_split_advertiser_vs_publisher(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 50,
            'bonus_balance' => 0,
            'reserved_balance' => 30,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 20,
            'bonus_balance' => 0,
            'reserved_balance' => 12,
            'currency' => 'EUR',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Advertiser reserved')
            ->assertSee('Publisher reserved')
            ->assertSee('€30.00')
            ->assertSee('€12.00')
            ->assertDontSee('Reserved (in flight)')
            ->getContent();

        $this->assertStringContainsString('€30.00', $html);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );
        $this->assertEquals(30.0, $overview['liability']['advertiser']['reserved']);
        $this->assertEquals(12.0, $overview['liability']['publisher']['reserved']);
        $this->assertEquals(42.0, $overview['liability']['open_reserved_total']);
    }

    public function test_view_all_links_when_hub_tables_are_truncated(): void
    {
        $admin = $this->makeUser('admin');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = $this->makeUser('publisher');

        for ($i = 1; $i <= 9; $i++) {
            $user = $this->makeUser('publisher');
            $user->update(['email' => "wallet-{$i}@coverage.test"]);
            Wallet::create([
                'user_id' => $user->id,
                'role_id' => $pubRole->id,
                'balance' => 10 + $i,
                'bonus_balance' => 0,
                'reserved_balance' => 0,
                'debt_balance' => $i,
                'currency' => 'EUR',
            ]);
            Withdrawal::create([
                'user_id' => $publisher->id,
                'amount' => $i,
                'fee' => 0,
                'net_amount' => $i,
                'payment_method' => 'paypal',
                'payment_details' => ['email' => 'p@example.test'],
                'status' => 'pending',
            ]);
        }

        $hub = $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('View all 9')
            ->assertSee(route('admin.withdrawals', ['queue' => 'open']), false)
            ->assertSee('list=wallets', false)
            ->assertSee('list=debt', false)
            ->assertDontSee('wallet-1@coverage.test')
            ->getContent();

        $this->assertSame(3, substr_count($hub, 'View all 9'));

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all', 'list' => 'wallets']))
            ->assertOk()
            ->assertSee('wallet-1@coverage.test')
            ->assertSee('Showing 9 of 9')
            ->assertSee('Back to top 8');

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all', 'list' => 'debt']))
            ->assertOk()
            ->assertSee('wallet-1@coverage.test')
            ->assertSee('Showing 9 of 9');
    }

    public function test_invoice_missing_hint_skips_active_tax_invoices_not_cancelled(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $missing = $this->completedPaidOrder($advertiser, $publisher, 80, 10);
        $covered = $this->completedPaidOrder($advertiser, $publisher, 40, 5);
        $cancelledOnly = $this->completedPaidOrder($advertiser, $publisher, 25, 3);

        Invoice::query()
            ->where('order_id', $missing->id)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->delete();
        Invoice::query()
            ->where('order_id', $cancelledOnly->id)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertTrue(
            Invoice::query()
                ->where('order_id', $covered->id)
                ->where('type', Invoice::TYPE_TAX_INVOICE)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->exists()
        );

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );
        $this->assertEquals(2, $overview['invoices']['count']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('2 paid orders have no tax invoice')
            ->assertSee('Backfill missing')
            ->assertSee(route('admin.invoices.index'), false);

        $this->assertNotSame($missing->id, $covered->id);
    }

    public function test_sql_withdrawable_still_sums_per_wallet(): void
    {
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $pubA = $this->makeUser('publisher');
        $pubB = $this->makeUser('publisher');

        Wallet::create([
            'user_id' => $pubA->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'bonus_balance' => 100,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        Wallet::create([
            'user_id' => $pubB->id,
            'role_id' => $pubRole->id,
            'balance' => 100,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $liability = app(FinanceOverviewService::class)->walletLiability();
        $this->assertEquals(100.0, $liability['in_publisher_wallets']);
        $this->assertEquals(100.0, $liability['publisher']['withdrawable']);
        $this->assertCount(1, $liability['top_publisher_wallets']);
        $this->assertEquals(100.0, $liability['top_publisher_wallets'][0]['withdrawable']);
    }

    public function test_week_period_compares_gmv_and_fees_to_previous_window(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $this->completedPaidOrder($advertiser, $publisher, 200, 20, now());
        $this->completedPaidOrder(
            $advertiser,
            $publisher,
            80,
            8,
            now()->startOfWeek()->subDays(3)
        );

        $service = app(FinanceOverviewService::class);
        $overview = $service->overview($service->resolvePeriod('week'));

        $this->assertNotNull($overview['comparison']);
        $this->assertEquals(200.0, $overview['platform']['gmv_completed']);
        $this->assertEquals(20.0, $overview['platform']['order_fees']);
        $this->assertEquals(80.0, $overview['comparison']['gmv_completed']);
        $this->assertEquals(8.0, $overview['comparison']['order_fees']);
        $this->assertEquals(120.0, $overview['comparison']['gmv_delta']);
        $this->assertEquals(12.0, $overview['comparison']['fees_delta']);

        $all = $service->overview($service->resolvePeriod('all'));
        $this->assertNull($all['comparison']);
    }

    public function test_estimated_stripe_fee_is_percent_of_card_volume(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $this->completedPaidOrder($advertiser, $publisher, 100, 15, now());
        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-STRIPE-1',
            'amount' => 50,
            'payment_method' => 'card',
            'status' => 'completed',
            'approved_at' => now(),
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(1.5, $overview['platform']['stripe_fee_percent']);
        $this->assertEquals(150.0, $overview['platform']['estimated_stripe_base']);
        $this->assertEquals(2.25, $overview['platform']['estimated_stripe_fees']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Est. Stripe (not from Stripe)')
            ->assertSee('€2.25');
    }

    public function test_deposit_reconciliation_match_and_mismatch(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 50,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-RECON-1',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
        ]);
        app(WalletLedgerService::class)->recordDeposit($wallet, 50, null, 'bank', 'DEP-RECON-1');

        $matched = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );
        $this->assertTrue($matched['reconciliation']['matched']);
        $this->assertEquals(50.0, $matched['reconciliation']['deposits_completed']);
        $this->assertEquals(50.0, $matched['reconciliation']['ledger_deposits']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Ledger deposits')
            ->assertSee('match');

        app(WalletLedgerService::class)->recordDeposit($wallet, 10, null, 'bank', 'DEP-RECON-EXTRA');

        $mismatch = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );
        $this->assertFalse($mismatch['reconciliation']['matched']);
        $this->assertEquals(10.0, $mismatch['reconciliation']['delta']);
    }

    public function test_unpaid_and_open_withdrawal_aging(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-AGE-1',
            'subtotal' => 60,
            'tax' => 0,
            'total_amount' => 60,
            'payment_method' => 'bank',
            'payment_status' => 'unpaid',
            'status' => 'pending',
        ]);
        $order->forceFill(['created_at' => now()->subDays(5)])->save();

        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 15,
            'fee' => 0,
            'net_amount' => 15,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'p@example.test'],
            'status' => 'pending',
        ]);
        $withdrawal->forceFill(['created_at' => now()->subDays(4)])->save();

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );
        $this->assertEquals(5, $overview['ops']['unpaid_orders']['oldest_days']);
        $this->assertEquals(4, $overview['ops']['open_withdrawals']['oldest_days']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Oldest 5 days')
            ->assertSee('Oldest 4 days');
    }

    public function test_hub_ignores_invalid_dates_and_array_query_params(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance', [
                'period' => ['week'],
                'date_from' => 'not-a-date',
                'date_to' => ['2026-08-01'],
                'list' => ['debt'],
            ]))
            ->assertOk()
            ->assertSee('Finance overview');

        $service = app(FinanceOverviewService::class);
        $period = $service->resolvePeriod('month', 'nope', 'also-bad');
        $this->assertSame('month', $period['key']);
        $this->assertNotNull($period['start']);
    }

    public function test_completed_and_withdrawal_windows_qualify_table_columns(): void
    {
        $service = app(FinanceOverviewService::class);
        $start = now()->startOfWeek();
        $end = now()->endOfDay();

        $completed = new \ReflectionMethod($service, 'applyCompletedWindow');
        $completed->setAccessible(true);
        $orders = Order::query();
        $completed->invoke($service, $orders, $start, $end);
        $orderSql = $orders->toSql();
        $this->assertStringContainsString('orders.completed_at', $orderSql);
        $this->assertStringContainsString('orders.updated_at', $orderSql);
        $this->assertStringNotContainsString('COALESCE(completed_at, updated_at)', $orderSql);

        $coalesce = new \ReflectionMethod($service, 'applyCoalesceWindow');
        $coalesce->setAccessible(true);
        $withdrawals = Withdrawal::query();
        $coalesce->invoke($service, $withdrawals, $start, $end, 'withdrawals.processed_at', 'withdrawals.updated_at');
        $wdSql = $withdrawals->toSql();
        $this->assertStringContainsString('withdrawals.processed_at', $wdSql);
        $this->assertStringContainsString('withdrawals.updated_at', $wdSql);
    }

    public function test_margin_subtracts_refunded_fees_not_order_gmv(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $this->completedPaidOrder($advertiser, $publisher, 115, 15, now());
        $refunded = $this->completedPaidOrder($advertiser, $publisher, 115, 15, now());
        $refunded->forceFill(['payment_status' => 'refunded'])->save();

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(230.0, $overview['platform']['gmv_completed']);
        $this->assertEquals(30.0, $overview['platform']['order_fees']);
        $this->assertEquals(115.0, $overview['platform']['refunds']);
        $this->assertEquals(15.0, $overview['platform']['refunded_order_fees']);
        $this->assertEquals(15.0, $overview['platform']['margin']);
    }

    public function test_manual_deposit_with_stripe_session_is_not_double_counted(): void
    {
        $advertiser = $this->makeUser('advertiser');
        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-OVERLAP-1',
            'amount' => 40,
            'payment_method' => 'wise',
            'stripe_session_id' => 'cs_test_overlap',
            'status' => 'completed',
            'approved_at' => now(),
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(0.0, $overview['money_in']['deposits_completed']['stripe']);
        $this->assertEquals(40.0, $overview['money_in']['deposits_completed']['manual']);
        $this->assertEquals(40.0, $overview['cash_split']['cash_in_bank']);
        $this->assertEquals(0.0, $overview['platform']['estimated_stripe_fees']);
    }

    public function test_cancel_before_complete_refund_does_not_hit_fee_margin(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $this->completedPaidOrder($advertiser, $publisher, 115, 15, now());

        $cancelled = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-CANCEL-REFUND',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'refunded',
            'status' => 'cancelled',
        ]);
        OrderItem::create([
            'order_id' => $cancelled->id,
            'site_id' => $this->makeSite($publisher)->id,
            'site_name' => 'Cancelled',
            'site_url' => 'https://cancelled.test',
            'content_link' => 'https://example.com/cancelled',
            'price' => 80,
            'additional_price' => 0,
            'publisher_price' => 70,
            'platform_fee_percent' => 12.5,
            'platform_fee_amount' => 10,
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(15.0, $overview['platform']['order_fees']);
        $this->assertEquals(0.0, $overview['platform']['refunded_order_fees']);
        $this->assertEquals(15.0, $overview['platform']['margin']);
        $this->assertEquals(80.0, $overview['platform']['refunds']);
    }

    public function test_refunded_card_order_still_counts_toward_stripe_estimate(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->completedPaidOrder($advertiser, $publisher, 100, 15, now());
        $order->forceFill(['payment_status' => 'refunded'])->save();

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(100.0, $overview['money_in']['orders_paid']['stripe_card_refunded']);
        $this->assertEquals(1.5, $overview['platform']['estimated_stripe_fees']);
    }

    public function test_relative_date_strings_do_not_become_a_custom_period(): void
    {
        $service = app(FinanceOverviewService::class);
        $period = $service->resolvePeriod('month', 'yesterday', 'tomorrow');
        $this->assertSame('month', $period['key']);
    }

    public function test_ledger_does_not_mark_finance_hub_nav_active(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/href="'.preg_quote(route('admin.finance'), '/').'"[^>]*class="active"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.finance.ledger'), '/').'"[^>]*class="active"/',
            $html
        );
    }

    public function test_ledger_array_user_id_does_not_resolve_to_user_one(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'user_id' => ['8'],
            ]))
            ->assertOk()
            ->assertDontSee('Showing ledger for');
    }

    public function test_ledger_array_filters_do_not_500_or_select_every_type(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'date_from' => ['2026-01-01'],
                'date_to' => ['2026-12-31'],
                'type' => ['deposit'],
                'direction' => ['credit'],
                'wallet_role' => ['advertiser'],
                'payment_method' => ['card'],
                'status' => ['pending'],
                'wallet_id' => ['8'],
                'search' => ['%'],
            ]))
            ->assertOk()
            ->assertSee('Wallet ledger')
            ->getContent();

        $this->assertStringNotContainsString('value="deposit" selected', $html);
        $this->assertStringNotContainsString('value="credit" selected', $html);
        $this->assertStringNotContainsString('value="advertiser" selected', $html);
        $this->assertStringNotContainsString('value="card" selected', $html);
        $this->assertStringNotContainsString('value="pending" selected', $html);
        $this->assertStringNotContainsString('Showing wallet', $html);
    }

    public function test_period_form_and_shortcuts_keep_list_view(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance', ['list' => 'wallets']))
            ->assertOk()
            ->assertSee('name="list"', false)
            ->assertSee('value="wallets"', false)
            ->assertSee('name="period"', false)
            ->assertSee(e(route('admin.finance', ['period' => 'week', 'list' => 'wallets'])), false)
            ->assertSee(e(route('admin.finance', ['period' => 'month', 'list' => 'wallets'])), false);
    }

    public function test_deposit_reconciliation_ignores_deposit_debits(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 50,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-RECON-CREDIT',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
        ]);
        app(WalletLedgerService::class)->recordDeposit($wallet, 50, null, 'bank', 'DEP-RECON-CREDIT');
        app(WalletLedgerService::class)->record(
            $wallet,
            WalletTransaction::TYPE_DEPOSIT,
            'debit',
            10,
            ['reference' => 'DEP-RECON-DEBIT', 'description' => 'Deposit reversal']
        );

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertTrue($overview['reconciliation']['matched']);
        $this->assertEquals(50.0, $overview['reconciliation']['deposits_completed']);
        $this->assertEquals(50.0, $overview['reconciliation']['ledger_deposits']);
    }

    public function test_ledger_relative_dates_do_not_filter_or_echo(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 9,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        app(WalletLedgerService::class)->recordTransferIn($wallet, 9, null, 'LEDGER-TODAY', 'Today ledger row');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'date_from' => 'yesterday',
                'date_to' => 'yesterday',
            ]))
            ->assertOk()
            ->assertSee('Today ledger row')
            ->getContent();

        $this->assertStringNotContainsString('value="yesterday"', $html);
    }

    public function test_leading_zero_user_id_does_not_resolve_to_user_one(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['user_id' => '0'.$admin->id]))
            ->assertOk()
            ->assertDontSee('Showing ledger for');

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => '0'.$admin->id]))
            ->assertOk()
            ->assertSee('Finance overview');
    }

    public function test_dossier_keeps_earnings_after_completed_then_refunded(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $order = $this->completedPaidOrder($advertiser, $publisher, 115, 15, now());
        $order->forceFill(['payment_status' => 'refunded'])->save();

        $publisherDossier = app(FinanceOverviewService::class)->userDossier($publisher);
        $advertiserDossier = app(FinanceOverviewService::class)->userDossier($advertiser);

        $this->assertEquals(100.0, $publisherDossier['totals']['earnings_as_publisher']);
        $this->assertEquals(15.0, $publisherDossier['totals']['platform_fees_on_their_sites']);
        $this->assertEquals(115.0, $advertiserDossier['totals']['gmv_as_advertiser']);
        $this->assertEquals(1, $advertiserDossier['totals']['paid_orders_count']);
    }

    public function test_bonus_debit_does_not_inflate_issued_bonuses(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 20,
            'bonus_balance' => 20,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        app(WalletLedgerService::class)->recordBonusCredit($wallet, 20);
        app(WalletLedgerService::class)->record(
            $wallet,
            WalletTransaction::TYPE_BONUS_CREDIT,
            'debit',
            5,
            ['reference' => 'BONUS-CLAW', 'description' => 'Bonus clawback']
        );

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(20.0, $overview['platform']['bonuses_issued']);
        $this->assertEquals(20.0, $overview['money_in']['bonuses_issued']['amount']);
    }

    public function test_hub_drops_relative_dates_from_form_and_export(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', [
                'period' => 'week',
                'date_from' => 'yesterday',
                'date_to' => 'tomorrow',
            ]))
            ->assertOk()
            ->assertSee('This week')
            ->getContent();

        $this->assertStringNotContainsString('value="yesterday"', $html);
        $this->assertStringNotContainsString('value="tomorrow"', $html);
        $this->assertStringContainsString(
            e(route('admin.finance.export', ['period' => 'week'])),
            $html
        );
    }

    public function test_ledger_search_leading_zero_does_not_match_transaction_id(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 9,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $tx = app(WalletLedgerService::class)->recordTransferIn(
            $wallet,
            9,
            null,
            'LEDGER-ID-ROW',
            'Unique ledger id row'
        );

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => '0'.$tx->id]))
            ->assertOk()
            ->assertDontSee('Unique ledger id row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => (string) $tx->id]))
            ->assertOk()
            ->assertSee('Unique ledger id row');
    }

    public function test_legacy_order_payment_methods_count_as_card_or_manual_cash(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $stripe = $this->completedPaidOrder($advertiser, $publisher, 100, 15, now());
        $stripe->forceFill(['payment_method' => 'stripe'])->save();

        $bank = $this->completedPaidOrder($advertiser, $publisher, 40, 5, now());
        $bank->forceFill(['payment_method' => 'bank_transfer'])->save();

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-BANK-TRANSFER',
            'amount' => 25,
            'payment_method' => 'bank_transfer',
            'stripe_session_id' => 'cs_test_bank_transfer',
            'status' => 'completed',
            'approved_at' => now(),
        ]);

        $overview = app(FinanceOverviewService::class)->overview(
            app(FinanceOverviewService::class)->resolvePeriod('all')
        );

        $this->assertEquals(100.0, $overview['money_in']['orders_paid']['stripe_card']);
        $this->assertEquals(40.0, $overview['money_in']['orders_paid']['manual']);
        $this->assertEquals(25.0, $overview['money_in']['deposits_completed']['manual']);
        $this->assertEquals(0.0, $overview['money_in']['deposits_completed']['stripe']);
        $this->assertEquals(165.0, $overview['cash_split']['cash_in_bank']);
        $this->assertEquals(1.5, $overview['platform']['estimated_stripe_fees']);
    }
}
