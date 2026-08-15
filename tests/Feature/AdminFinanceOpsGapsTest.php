<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFinanceOpsGapsTest extends TestCase
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

    public function test_hub_shows_clawback_debt_card_and_indebted_publisher(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 42.5,
            'currency' => 'EUR',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Clawback debt')
            ->assertSee('€42.50')
            ->assertSee('1 publisher wallet blocked')
            ->assertSee($publisher->email)
            ->assertSee(route('admin.finance.user', $publisher, false), false)
            ->getContent();

        $this->assertStringContainsString('id="finance-debt"', $html);
        $this->assertStringContainsString('Find user dossier', $html);
        $this->assertStringContainsString('href="'.route('admin.withdrawals', [], false).'"', $html);
        $this->assertStringContainsString('href="'.route('admin.finance.user', $publisher, false).'"', $html);
        $this->assertStringNotContainsString('href="'.route('admin.finance.user', $publisher).'"', $html);
        $this->assertStringContainsString('href="'.route('admin.finance', ['period' => 'week'], false).'"', $html);
        $this->assertStringContainsString('href="'.route('admin.finance.ledger', [], false).'"', $html);
        $this->assertStringContainsString('href="'.route('admin.invoices.index', [], false).'"', $html);
    }

    public function test_user_search_redirects_unique_match_to_dossier(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advertiser->update(['email' => 'unique-dossier@example.test']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'unique-dossier@example.test']))
            ->assertRedirect(route('admin.finance.user', $advertiser));

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => (string) $advertiser->id]))
            ->assertRedirect(route('admin.finance.user', $advertiser));
    }

    public function test_user_search_lists_multiple_matches(): void
    {
        $admin = $this->makeUser('admin');
        $one = $this->makeUser('advertiser');
        $two = $this->makeUser('publisher');
        $one->update(['name' => 'Alpha Ledger', 'email' => 'alpha-ledger@example.test']);
        $two->update(['name' => 'Alpha Wallet', 'email' => 'alpha-wallet@example.test']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'Alpha']))
            ->assertOk()
            ->assertSee('2 users match')
            ->assertSee($one->email)
            ->assertSee($two->email)
            ->assertSee(route('admin.finance.user', $one, false), false)
            ->assertSee(route('admin.finance.user', $two, false), false);
    }

    public function test_dossier_rows_deep_link_to_admin_money_pages(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 20,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-DOSSIER-1',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-DOSSIER-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $open = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 10,
            'fee' => 0,
            'net_amount' => 10,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'p@example.test'],
            'status' => 'pending',
        ]);
        $paid = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 15,
            'fee' => 0,
            'net_amount' => 15,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'p@example.test'],
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.finance.user', $advertiser))
            ->assertOk()
            ->assertSee(route('admin.deposits', ['search' => $deposit->reference_code], false), false)
            ->assertSee(route('admin.orders.show', $order->id, false), false);

        $this->actingAs($admin)
            ->get(route('admin.finance.user', $publisher))
            ->assertOk()
            ->assertSee(e(route('admin.withdrawals.show', $open->id, false)), false)
            ->assertSee(e(route('admin.withdrawals.show', $paid->id, false)), false);
    }

    public function test_ledger_shows_user_filter_and_exports_csv(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $other = $this->makeUser('advertiser');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);

        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 50,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $otherWallet = Wallet::create([
            'user_id' => $other->id,
            'role_id' => $advRole->id,
            'balance' => 9,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordTransferIn($wallet, 50, null, 'LEDGER-KEEP', 'Keep this row');
        app(WalletLedgerService::class)->recordTransferIn($otherWallet, 9, null, 'LEDGER-SKIP', 'Skip this row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['user_id' => $publisher->id]))
            ->assertOk()
            ->assertSee('Showing ledger for')
            ->assertSee($publisher->email)
            ->assertSee('Keep this row')
            ->assertDontSee('Skip this row')
            ->assertSee(route('admin.finance.ledger.export', ['user_id' => $publisher->id], false), false)
            ->assertSee(route('admin.finance.user', $publisher->id, false), false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['user_id' => $publisher->id]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('user_email', $csv);
        $this->assertStringContainsString($publisher->email, $csv);
        $this->assertStringContainsString('LEDGER-KEEP', $csv);
        $this->assertStringNotContainsString('LEDGER-SKIP', $csv);
        $this->assertStringNotContainsString($other->email, $csv);
    }

    public function test_ledger_ignores_array_search_and_invalid_dates(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'search' => ['injected'],
                'date_from' => 'not-a-date',
                'user_id' => ['x'],
            ]))
            ->assertOk()
            ->assertSee('Wallet ledger');
    }

    public function test_period_export_invalid_dates_stay_on_this_host(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->from(route('admin.finance'))
            ->get(route('admin.finance.export', ['date_from' => 'nope']))
            ->assertRedirect(route('admin.finance', [], false))
            ->assertSessionHasErrors('date_from');
    }

    public function test_withdrawals_page_honors_search_query_param(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals', ['search' => '88', 'queue' => 'history']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("q.get('search')", $html);
    }
}
