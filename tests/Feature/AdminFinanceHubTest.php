<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
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
            ->get(route('admin.finance', [
                'period' => ['month'],
                'date_from' => ['2026-01-01'],
                'date_to' => ['2026-12-31'],
            ]))
            ->assertOk()
            ->assertDontSee('Array to string conversion', false);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'search' => ['TEST-EARN'],
                'type' => [WalletTransaction::TYPE_TRANSFER_IN],
                'date_from' => ['2026-01-01'],
                'date_to' => ['2026-12-31'],
            ]))
            ->assertOk()
            ->assertSee('Wallet ledger')
            ->assertDontSee('Array to string conversion', false);

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
        $this->assertStringContainsString('payable_now', $csv);
        $this->assertStringContainsString('cash_in_bank', $csv);
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
}
