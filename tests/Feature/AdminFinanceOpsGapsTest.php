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
            ->assertSee(route('admin.finance.user', $publisher), false)
            ->getContent();

        $this->assertStringContainsString('id="finance-debt"', $html);
        $this->assertStringContainsString('Find user dossier', $html);
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
            ->assertSee(route('admin.finance.user', $one), false)
            ->assertSee(route('admin.finance.user', $two), false);
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
            ->assertSee(route('admin.deposits', ['search' => $deposit->reference_code]), false)
            ->assertSee(route('admin.orders.show', $order->id), false);

        $this->actingAs($admin)
            ->get(route('admin.finance.user', $publisher))
            ->assertOk()
            ->assertSee(e(route('admin.withdrawals', ['search' => (string) $open->id, 'queue' => 'open'])), false)
            ->assertSee(e(route('admin.withdrawals', ['search' => (string) $paid->id, 'queue' => 'history'])), false);
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
            ->assertDontSee('Clear filters')
            ->assertSee(route('admin.finance.ledger.export', ['user_id' => $publisher->id]), false);

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

    public function test_ledger_unknown_user_id_keeps_scope_and_does_not_export_everyone(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 6,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        app(WalletLedgerService::class)->recordTransferIn($wallet, 6, null, 'LEDGER-OTHER-USER', 'Other user row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['user_id' => 99991]))
            ->assertOk()
            ->assertSee('Showing ledger for')
            ->assertSee('#99991')
            ->assertSee('not found')
            ->assertSee('No ledger rows for this user')
            ->assertDontSee('Other user row')
            ->assertSee(route('admin.finance.ledger.export', ['user_id' => 99991]), false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['user_id' => 99991]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('LEDGER-OTHER-USER', $csv);
        $this->assertStringNotContainsString('Other user row', $csv);
    }

    public function test_ledger_row_shows_signed_amount_wallet_role_and_status(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 40,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $pubWallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 25,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordDeposit($advWallet, 40, null, 'bank', 'LEDGER-CREDIT-ROW');
        app(WalletLedgerService::class)->recordWithdrawal($pubWallet, 25, null, 'pending', 'LEDGER-DEBIT-ROW');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('Advertiser')
            ->assertSee('Publisher')
            ->assertSee('Pending')
            ->assertSee('Completed')
            ->assertSee('+€40.00', false)
            ->assertSee('-€25.00', false)
            ->getContent();

        $this->assertStringContainsString('>Wallet<', $html);
        $this->assertStringContainsString('>Status<', $html);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('wallet_role', $csv);
        $this->assertStringContainsString('status', $csv);
        $this->assertStringContainsString('Advertiser', $csv);
        $this->assertStringContainsString('Publisher', $csv);
        $this->assertStringContainsString('pending', $csv);
    }

    public function test_ledger_search_by_user_id_finds_that_users_rows(): void
    {
        $admin = $this->makeUser('admin');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);

        // Digit search matches transaction id OR user_id. Pin user ids that
        // cannot collide with the two ledger rows created below.
        $publisher = User::factory()->create([
            'id' => 4242,
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $publisher->roles()->attach($pubRole->id);
        $other = User::factory()->create([
            'id' => 4243,
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $other->roles()->attach($advRole->id);

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

        app(WalletLedgerService::class)->recordTransferIn($wallet, 50, null, 'LEDGER-USER-HIT', 'User id hit row');
        app(WalletLedgerService::class)->recordTransferIn($otherWallet, 9, null, 'LEDGER-USER-MISS', 'User id miss row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => (string) $publisher->id]))
            ->assertOk()
            ->assertSee('User id hit row')
            ->assertDontSee('User id miss row');
    }

    public function test_ledger_reference_links_to_related_admin_pages(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 40,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $pubWallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 23,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-LEDGER-LINK',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
        ]);
        $open = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 15,
            'fee' => 0,
            'net_amount' => 15,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'p@example.test'],
            'status' => 'pending',
        ]);
        $paid = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 8,
            'fee' => 0,
            'net_amount' => 8,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'p@example.test'],
            'status' => 'completed',
        ]);

        app(WalletLedgerService::class)->recordDeposit($advWallet, 40, $deposit, 'bank', 'DEP-LEDGER-LINK');
        app(WalletLedgerService::class)->recordWithdrawal($pubWallet, 15, $open, 'pending', 'WD-'.$open->id);
        app(WalletLedgerService::class)->recordWithdrawal($pubWallet, 8, $paid, 'pending', 'WD-'.$paid->id);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee(e(route('admin.deposits', ['search' => 'DEP-LEDGER-LINK'])), false)
            ->assertDontSee(route('admin.deposits.show', $deposit->id), false)
            ->assertSee(e(route('admin.withdrawals', [
                'search' => (string) $open->id,
                'queue' => 'open',
            ])), false)
            ->assertSee(e(route('admin.withdrawals', [
                'search' => (string) $paid->id,
                'queue' => 'history',
            ])), false)
            ->assertDontSee(e(route('admin.withdrawals', [
                'search' => (string) $paid->id,
                'queue' => 'open',
            ])), false);
    }

    public function test_ledger_export_is_newest_first_and_notes_truncation(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 30,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordTransferIn($wallet, 10, null, 'LEDGER-OLD', 'Older export row');
        app(WalletLedgerService::class)->recordTransferIn($wallet, 20, null, 'LEDGER-NEW', 'Newer export row');

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export'))
            ->assertOk()
            ->streamedContent();

        $newPos = strpos($csv, 'LEDGER-NEW');
        $oldPos = strpos($csv, 'LEDGER-OLD');
        $this->assertNotFalse($newPos);
        $this->assertNotFalse($oldPos);
        $this->assertLessThan($oldPos, $newPos);
        $this->assertStringNotContainsString('truncated', $csv);

        config(['billing.ledger_export_limit' => 1]);

        $truncated = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('LEDGER-NEW', $truncated);
        $this->assertStringNotContainsString('LEDGER-OLD', $truncated);
        $this->assertStringContainsString('truncated,limit,1', $truncated);
    }

    public function test_ledger_wallet_role_filter_hides_the_other_wallet(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 40,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $pubWallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 12,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordDeposit($advWallet, 40, null, 'bank', 'LEDGER-ADV-ROLE');
        app(WalletLedgerService::class)->recordTransferIn($pubWallet, 12, null, 'LEDGER-PUB-ROLE', 'Publisher role row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['wallet_role' => 'advertiser']))
            ->assertOk()
            ->assertSee('LEDGER-ADV-ROLE')
            ->assertDontSee('Publisher role row')
            ->assertSee('value="advertiser" selected', false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['wallet_role' => 'advertiser']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('LEDGER-ADV-ROLE', $csv);
        $this->assertStringNotContainsString('LEDGER-PUB-ROLE', $csv);
    }

    public function test_ledger_totals_strip_matches_filtered_rows(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 40,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $pubWallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 25,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordDeposit($advWallet, 40, null, 'bank', 'LEDGER-TOTAL-CREDIT');
        app(WalletLedgerService::class)->recordWithdrawal($pubWallet, 25, null, 'pending', 'LEDGER-TOTAL-DEBIT');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('across these filters, not this page')
            ->assertSee('+€40.00', false)
            ->assertSee('-€25.00', false)
            ->assertSee('+€15.00', false);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['direction' => 'credit']))
            ->assertOk()
            ->assertSee('+€40.00', false)
            ->assertSee('-€0.00', false)
            ->assertDontSee('LEDGER-TOTAL-DEBIT');
    }

    public function test_ledger_clear_filters_keeps_user_and_drops_type(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        app(WalletLedgerService::class)->recordTransferIn($wallet, 10, null, 'LEDGER-CLEAR', 'Clear filters row');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'user_id' => $publisher->id,
                'type' => 'transfer_in',
            ]))
            ->assertOk()
            ->assertSee('Clear filters')
            ->assertSee('Clear filters row')
            ->getContent();

        $this->assertStringContainsString(
            e(route('admin.finance.ledger', ['user_id' => $publisher->id])),
            $html
        );
        $this->assertStringContainsString(
            e(route('admin.finance.ledger.export', [
                'user_id' => $publisher->id,
                'type' => 'transfer_in',
            ])),
            $html
        );
    }

    public function test_ledger_clear_filters_keeps_wallet_and_drops_type(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        app(WalletLedgerService::class)->recordTransferIn($wallet, 10, null, 'LEDGER-CLEAR-WALLET', 'Clear wallet filters row');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'user_id' => $publisher->id,
                'wallet_id' => $wallet->id,
                'type' => 'deposit',
            ]))
            ->assertOk()
            ->assertSee('Clear filters')
            ->assertSee('No ledger rows match these filters')
            ->getContent();

        $this->assertStringContainsString(
            e(route('admin.finance.ledger', [
                'user_id' => $publisher->id,
                'wallet_id' => $wallet->id,
            ])),
            $html
        );
    }

    public function test_ledger_payment_method_filter_groups_card_rails(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 70,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordDeposit($wallet, 40, null, 'stripe', 'LEDGER-CARD-RAIL');
        app(WalletLedgerService::class)->recordDeposit($wallet, 30, null, 'bank', 'LEDGER-BANK-RAIL');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['payment_method' => 'card']))
            ->assertOk()
            ->assertSee('LEDGER-CARD-RAIL')
            ->assertSee('Card')
            ->assertDontSee('LEDGER-BANK-RAIL')
            ->assertSee('value="card" selected', false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['payment_method' => 'card']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('payment_method', $csv);
        $this->assertStringContainsString('LEDGER-CARD-RAIL', $csv);
        $this->assertStringContainsString('stripe', $csv);
        $this->assertStringNotContainsString('LEDGER-BANK-RAIL', $csv);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['payment_method' => 'stripe']))
            ->assertOk()
            ->assertSee('LEDGER-CARD-RAIL')
            ->assertDontSee('LEDGER-BANK-RAIL')
            ->assertSee('value="card" selected', false);
    }

    public function test_ledger_payment_method_filter_finds_withdrawal_via_related(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 20,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $paypal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 12,
            'fee' => 0,
            'net_amount' => 12,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'p@example.test'],
            'status' => 'pending',
        ]);
        $wise = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 8,
            'fee' => 0,
            'net_amount' => 8,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'w@example.test'],
            'status' => 'pending',
        ]);

        $paypalTx = app(WalletLedgerService::class)->recordWithdrawal($wallet, 12, $paypal, 'pending', 'WD-PAYPAL-METHOD');
        app(WalletLedgerService::class)->recordWithdrawal($wallet, 8, $wise, 'pending', 'WD-WISE-METHOD');

        $this->assertSame('paypal', $paypalTx?->payment_method);

        // Older withdrawal rows left payment_method empty; the filter still
        // has to follow the related payout method.
        $paypalTx?->forceFill(['payment_method' => null])->save();

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['payment_method' => 'paypal']))
            ->assertOk()
            ->assertSee('WD-PAYPAL-METHOD')
            ->assertSee('PayPal')
            ->assertDontSee('WD-WISE-METHOD');

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['payment_method' => 'paypal']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('WD-PAYPAL-METHOD', $csv);
        $this->assertStringContainsString('paypal', $csv);
        $this->assertStringNotContainsString('WD-WISE-METHOD', $csv);
    }

    public function test_ledger_payment_method_filter_finds_purchase_via_related_order(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 40,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $walletOrder = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LEDGER-WALLET',
            'subtotal' => 18,
            'tax' => 0,
            'total_amount' => 18,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        $cardOrder = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LEDGER-CARD',
            'subtotal' => 11,
            'tax' => 0,
            'total_amount' => 11,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $walletTx = app(WalletLedgerService::class)->recordPurchase(
            $wallet,
            18,
            0,
            $walletOrder,
            'LEDGER-ORDER-WALLET'
        );
        app(WalletLedgerService::class)->recordPurchase(
            $wallet,
            11,
            0,
            $cardOrder,
            'LEDGER-ORDER-CARD'
        );

        $this->assertNull($walletTx?->payment_method);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['payment_method' => 'wallet']))
            ->assertOk()
            ->assertSee('LEDGER-ORDER-WALLET')
            ->assertSee('Wallet')
            ->assertDontSee('LEDGER-ORDER-CARD');

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['payment_method' => 'wallet']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('LEDGER-ORDER-WALLET', $csv);
        $this->assertStringContainsString('wallet', $csv);
        $this->assertStringNotContainsString('LEDGER-ORDER-CARD', $csv);
    }

    public function test_ledger_wallet_id_filter_hides_other_wallets(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $other = $this->makeUser('advertiser');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);

        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 20,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $otherWallet = Wallet::create([
            'user_id' => $other->id,
            'role_id' => $advRole->id,
            'balance' => 7,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordTransferIn($wallet, 20, null, 'LEDGER-WALLET-HIT', 'Wallet id hit row');
        app(WalletLedgerService::class)->recordTransferIn($otherWallet, 7, null, 'LEDGER-WALLET-MISS', 'Wallet id miss row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['wallet_id' => $wallet->id]))
            ->assertOk()
            ->assertSee('Showing wallet')
            ->assertSee('#'.$wallet->id)
            ->assertSee('Wallet id hit row')
            ->assertDontSee('Wallet id miss row')
            ->assertSee('Clear wallet')
            ->assertSee(e(route('admin.finance.ledger', array_filter([
                'wallet_id' => $wallet->id,
            ]))), false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['wallet_id' => $wallet->id]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('LEDGER-WALLET-HIT', $csv);
        $this->assertStringNotContainsString('LEDGER-WALLET-MISS', $csv);

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['wallet_id' => ' '.$wallet->id.' ']))
            ->assertOk()
            ->assertSee('Showing wallet')
            ->assertSee('Wallet id hit row')
            ->assertDontSee('Wallet id miss row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['wallet_id' => '0'.$wallet->id]))
            ->assertOk()
            ->assertDontSee('Showing wallet')
            ->assertSee('Wallet id miss row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['wallet_id' => 99999]))
            ->assertOk()
            ->assertSee('Showing wallet')
            ->assertSee('not found')
            ->assertSee('No ledger rows for this wallet')
            ->assertDontSee('No ledger rows match these filters');
    }

    public function test_ledger_status_filter_hides_other_statuses(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 40,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $pubWallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 15,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordDeposit($advWallet, 40, null, 'bank', 'LEDGER-STATUS-DONE');
        app(WalletLedgerService::class)->recordWithdrawal($pubWallet, 15, null, 'pending', 'LEDGER-STATUS-OPEN');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('LEDGER-STATUS-OPEN')
            ->assertDontSee('LEDGER-STATUS-DONE')
            ->assertSee('value="pending" selected', false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['status' => 'pending']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('LEDGER-STATUS-OPEN', $csv);
        $this->assertStringNotContainsString('LEDGER-STATUS-DONE', $csv);
    }

    public function test_ledger_empty_state_distinguishes_filters_from_no_rows(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('No wallet transactions yet')
            ->assertDontSee('No ledger rows match these filters');

        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        app(WalletLedgerService::class)->recordTransferIn($wallet, 10, null, 'LEDGER-EMPTY-KEEP', 'Empty state keep row');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['type' => 'deposit']))
            ->assertOk()
            ->assertSee('No ledger rows match these filters')
            ->assertDontSee('No wallet transactions yet')
            ->assertDontSee('Empty state keep row')
            ->getContent();

        $this->assertStringContainsString(
            e(route('admin.finance.ledger')),
            $html
        );
    }

    public function test_dossier_wallet_cards_link_to_that_wallet_ledger(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.finance.user', $publisher))
            ->assertOk()
            ->assertSee(e(route('admin.finance.ledger', [
                'user_id' => $publisher->id,
                'wallet_id' => $wallet->id,
            ])), false);
    }

    public function test_dossier_recent_ledger_uses_type_labels(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordRoleMoveIn($wallet, 10, null, 'LEDGER-DOSSIER-LABEL', 'Dossier label row');

        $this->actingAs($admin)
            ->get(route('admin.finance.user', $publisher))
            ->assertOk()
            ->assertSee('Earnings Moved for Spending')
            ->assertDontSee('role_move_in')
            ->assertDontSee('Role Move In');
    }

    public function test_ledger_export_prefixes_csv_formula_cells(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisher->forceFill([
            'name' => '=1+2',
            'email' => 'formula-user@example.test',
        ])->save();
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 10,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordTransferIn(
            $wallet,
            10,
            null,
            "\t=HYPERLINK(\"http://evil.test\")",
            ' =cmd|calc'
        );

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=1+2", $csv);
        $this->assertStringContainsString("'\t=HYPERLINK", $csv);
        $this->assertStringContainsString("' =cmd|calc", $csv);
    }

    public function test_ledger_export_keeps_columns_when_a_cell_ends_with_backslash(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $publisher->forceFill([
            'name' => 'slash-end\\',
            'email' => 'slash-end@example.test',
        ])->save();
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 3,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordTransferIn($wallet, 3, null, 'LEDGER-SLASH-END', 'Slash end row');

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export'))
            ->assertOk()
            ->streamedContent();

        $row = null;
        foreach (preg_split('/\r\n|\n|\r/', trim($csv)) as $line) {
            if (str_contains($line, 'LEDGER-SLASH-END')) {
                $row = str_getcsv($line, ',', '"', '');
                break;
            }
        }

        $this->assertIsArray($row);
        $this->assertContains('slash-end@example.test', $row);
        $this->assertContains('LEDGER-SLASH-END', $row);
        $this->assertGreaterThanOrEqual(16, count($row));
    }

    public function test_ledger_survives_unknown_related_type(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 5,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $tx = app(WalletLedgerService::class)->recordTransferIn(
            $wallet,
            5,
            null,
            'LEDGER-BAD-MORPH',
            'Unknown morph row'
        );
        $tx->forceFill([
            'related_type' => 'App\\Models\\DoesNotExistLedgerRelated',
            'related_id' => 1,
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('Unknown morph row')
            ->assertSee('LEDGER-BAD-MORPH');

        $tx->forceFill([
            'related_type' => 'Fake\\DepositRequest',
            'related_id' => 1,
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('Unknown morph row');

        $tx->forceFill([
            'related_type' => Withdrawal::class.' ',
            'related_id' => 1,
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('Unknown morph row');
    }

    public function test_ledger_text_search_matches_user_email_and_reference(): void
    {
        $admin = $this->makeUser('admin');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);

        $hitUser = $this->makeUser('publisher');
        $hitUser->forceFill(['email' => 'unique-ledger-hit@example.test'])->save();
        $missUser = $this->makeUser('advertiser');
        $missUser->forceFill(['email' => 'unique-ledger-miss@example.test'])->save();

        $wallet = Wallet::create([
            'user_id' => $hitUser->id,
            'role_id' => $pubRole->id,
            'balance' => 8,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $otherWallet = Wallet::create([
            'user_id' => $missUser->id,
            'role_id' => $advRole->id,
            'balance' => 4,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        app(WalletLedgerService::class)->recordTransferIn($wallet, 8, null, 'LEDGER-TEXT-HIT', 'Text search hit row');
        app(WalletLedgerService::class)->recordTransferIn($otherWallet, 4, null, 'LEDGER-TEXT-MISS', 'Text search miss row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => 'unique-ledger-hit']))
            ->assertOk()
            ->assertSee('Text search hit row')
            ->assertDontSee('Text search miss row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => 'LEDGER-TEXT-HIT']))
            ->assertOk()
            ->assertSee('Text search hit row')
            ->assertDontSee('Text search miss row');
    }

    public function test_ledger_type_filter_does_not_instantiate_model_in_markup(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger'))
            ->assertOk()
            ->assertSee('Moved to Advertiser Wallet')
            ->assertSee('Earnings Moved for Spending')
            ->getContent();

        $this->assertStringNotContainsString('new \\App\\Models\\WalletTransaction', $html);
        $this->assertStringNotContainsString('new App\\Models\\WalletTransaction', $html);
    }

    public function test_ledger_digit_search_matches_wallet_id(): void
    {
        $admin = $this->makeUser('admin');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);

        $hitUser = User::factory()->create([
            'id' => 4301,
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $hitUser->roles()->attach($pubRole->id);
        $missUser = User::factory()->create([
            'id' => 4302,
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $missUser->roles()->attach($advRole->id);

        $wallet = new Wallet([
            'user_id' => $hitUser->id,
            'role_id' => $pubRole->id,
            'balance' => 11,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $wallet->id = 4401;
        $wallet->save();
        $otherWallet = new Wallet([
            'user_id' => $missUser->id,
            'role_id' => $advRole->id,
            'balance' => 6,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $otherWallet->id = 4402;
        $otherWallet->save();

        app(WalletLedgerService::class)->recordTransferIn($wallet, 11, null, 'LEDGER-WALLET-SEARCH-HIT', 'Wallet search hit row');
        app(WalletLedgerService::class)->recordTransferIn($otherWallet, 6, null, 'LEDGER-WALLET-SEARCH-MISS', 'Wallet search miss row');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => '4401']))
            ->assertOk()
            ->assertSee('Wallet search hit row')
            ->assertDontSee('Wallet search miss row');
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

    public function test_ledger_inverted_dates_swap_to_the_intended_range(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $wallet = Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => 30,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $insideEarly = app(WalletLedgerService::class)->recordTransferIn(
            $wallet,
            8,
            null,
            'LEDGER-DATE-EARLY',
            'Inverted date early row'
        );
        $insideLate = app(WalletLedgerService::class)->recordTransferIn(
            $wallet,
            9,
            null,
            'LEDGER-DATE-LATE',
            'Inverted date late row'
        );
        $outside = app(WalletLedgerService::class)->recordTransferIn(
            $wallet,
            7,
            null,
            'LEDGER-DATE-OUT',
            'Inverted date outside row'
        );

        $insideEarly?->forceFill(['created_at' => '2026-08-02 10:00:00'])->save();
        $insideLate?->forceFill(['created_at' => '2026-08-10 10:00:00'])->save();
        $outside?->forceFill(['created_at' => '2026-07-20 10:00:00'])->save();

        $html = $this->actingAs($admin)
            ->get(route('admin.finance.ledger', [
                'date_from' => '2026-08-12',
                'date_to' => '2026-08-01',
            ]))
            ->assertOk()
            ->assertSee('Inverted date early row')
            ->assertSee('Inverted date late row')
            ->assertDontSee('Inverted date outside row')
            ->getContent();

        $this->assertStringContainsString('value="2026-08-01"', $html);
        $this->assertStringContainsString('value="2026-08-12"', $html);

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', [
                'date_from' => '2026-08-12',
                'date_to' => '2026-08-01',
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('LEDGER-DATE-EARLY', $csv);
        $this->assertStringContainsString('LEDGER-DATE-LATE', $csv);
        $this->assertStringNotContainsString('LEDGER-DATE-OUT', $csv);
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
