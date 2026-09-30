<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Admin\FinanceOverviewService;
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

        $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all']))
            ->assertOk()
            ->assertSee('Clawback debt')
            ->assertSee('Find user or money');

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', ['period' => 'all', 'focus' => 'debt']))
            ->assertOk()
            ->assertSee('Clawback debt')
            ->assertSee('€42.50')
            ->assertSee($publisher->email)
            ->assertSee(route('admin.finance.user', $publisher), false)
            ->getContent();

        $this->assertStringContainsString('id="finance-debt"', $html);
        $this->assertStringContainsString('staff-sites-strip', $html);
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

    public function test_user_search_does_not_treat_like_wildcards_as_match_all(): void
    {
        $admin = $this->makeUser('admin');
        $one = $this->makeUser('advertiser');
        $two = $this->makeUser('publisher');
        $one->update(['name' => 'Alice Example', 'email' => 'alice-wild@example.test']);
        $two->update(['name' => 'Bob Example', 'email' => 'bob-wild@example.test']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => '%%']))
            ->assertOk()
            ->assertSee('Type at least 2 characters to find a user dossier.')
            ->assertDontSee('No users match')
            ->assertDontSee($one->email)
            ->assertDontSee($two->email);

        // "%@" is 2 typed characters but only "@" after wildcard strip — that
        // would LIKE-match every email if the 2-char floor used the raw query.
        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => '%@']))
            ->assertOk()
            ->assertSee('Type at least 2 characters to find a user dossier.')
            ->assertDontSee($one->email)
            ->assertDontSee($two->email);
    }

    public function test_user_search_uses_character_length_not_bytes(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advertiser->update(['name' => 'éxample person', 'email' => 'accent-dossier@example.test']);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'é']))
            ->assertOk()
            ->assertSee('Type at least 2 characters to find a user dossier.')
            ->assertDontSee($advertiser->email);
    }

    public function test_user_search_does_not_claim_eight_when_more_match(): void
    {
        $admin = $this->makeUser('admin');
        $hidden = null;
        foreach (range(1, 9) as $i) {
            $user = $this->makeUser('advertiser');
            $user->update([
                'name' => sprintf('Gamma Match %02d', $i),
                'email' => sprintf('gamma-match-%02d@example.test', $i),
            ]);
            if ($i === 9) {
                $hidden = $user;
            }
        }

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'Gamma Match']))
            ->assertOk()
            ->assertSee('9 users match')
            ->assertDontSee('More than 8 users match')
            ->getContent();

        $this->assertStringContainsString('gamma-match-01@example.test', $html);
        $this->assertStringContainsString('gamma-match-08@example.test', $html);
        $this->assertStringContainsString($hidden->email, $html);
    }

    public function test_user_search_treats_underscore_as_literal(): void
    {
        $admin = $this->makeUser('admin');
        $exact = $this->makeUser('advertiser');
        $wildcard = $this->makeUser('publisher');
        $exact->update(['name' => 'foo_bar dossier', 'email' => 'foo-bar-underscore@example.test']);
        $wildcard->update(['name' => 'fooXbar dossier', 'email' => 'fooxbar-wild@example.test']);

        // Unescaped LIKE "%foo_bar%" would also match fooXbar and stay on the list.
        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'foo_bar']))
            ->assertRedirect(route('admin.finance.user', $exact));
    }

    public function test_user_search_does_not_treat_leading_zero_id_as_user_key(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advertiser->update(['name' => 'Zero Pad Person', 'email' => 'zero-pad@example.test']);
        $padded = '0'.(string) $advertiser->id;

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => $padded]))
            ->assertOk()
            ->assertSee('No users or money records match');
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

        $log = ActivityLog::query()->where('action', 'finance.ledger_exported')->first();
        $this->assertNotNull($log);
        $this->assertSame($publisher->id, (int) data_get($log->properties, 'user_id'));
        $this->assertGreaterThanOrEqual(1, (int) data_get($log->properties, 'rows_exported'));
    }

    public function test_ledger_search_treats_underscore_as_literal(): void
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

        app(WalletLedgerService::class)->recordTransferIn($wallet, 10, null, 'ORD_1', 'underscore ref');
        app(WalletLedgerService::class)->recordTransferIn($wallet, 11, null, 'ORDX1', 'wildcard ref');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => 'ORD_1']))
            ->assertOk()
            ->assertSee('underscore ref')
            ->assertDontSee('wildcard ref');
    }

    public function test_ledger_wildcard_only_search_does_not_dump_all_rows(): void
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

        app(WalletLedgerService::class)->recordTransferIn($wallet, 10, null, 'LEDGER-WILD-1', 'wildcard dump keep');

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => '%%']))
            ->assertOk()
            ->assertDontSee('wildcard dump keep');

        $csv = $this->actingAs($admin)
            ->get(route('admin.finance.ledger.export', ['search' => '%%']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('wildcard dump keep', $csv);
        $this->assertStringNotContainsString('LEDGER-WILD-1', $csv);
    }

    public function test_ledger_search_does_not_treat_leading_zero_as_row_id(): void
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

        $row = app(WalletLedgerService::class)->recordTransferIn($wallet, 10, null, 'LEDGER-PAD', 'padded id row');
        $padded = '0'.(string) $row->id;

        $this->actingAs($admin)
            ->get(route('admin.finance.ledger', ['search' => $padded]))
            ->assertOk()
            ->assertDontSee('padded id row');
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

    public function test_withdrawals_page_honors_search_query_param(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.withdrawals', ['search' => '88', 'queue' => 'history']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("q.get('search')", $html);
    }

    public function test_overview_live_strip_splits_due_now_and_drops_duplicate_withdrawal_card(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $advertiser = $this->makeUser('advertiser');

        $this->seedPublisherWallet($publisher, 25);

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'pending',
        ]);
        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 20,
            'fee' => 0,
            'net_amount' => 20,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'processing',
        ]);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-QUEUE-1',
            'amount' => 75,
            'payment_method' => 'bank',
            'status' => 'pending',
            'user_marked_paid_at' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Due now')
            ->assertSee('Pending deposits')
            ->assertSee('Unpaid orders')
            ->assertSee('Clawback debt')
            ->assertSee('1 to approve · 1 to send')
            ->assertSee('Approve')
            ->assertSee('Send')
            ->assertDontSee('Open withdrawals (how Due to pay now is built)')
            ->getContent();

        $this->assertStringContainsString('staff-sites-strip', $html);
        $this->assertStringContainsString('id="finance-due"', $html);
        $this->assertStringContainsString('id="finance-wallets"', $html);
        $this->assertStringContainsString('adminFinanceMinWallet', $html);
        $this->assertStringNotContainsString('Clear filters', $html);
        $this->assertStringContainsString('Completed GMV', $html);
        $this->assertStringContainsString('Paid GMV', $html);
        $this->assertStringContainsString('Dashboard month GMV is Paid GMV.', $html);
        $this->assertStringContainsString('finance=1', $html);
        $this->assertStringContainsString('date_field=completed_at', $html);
        $this->assertStringContainsString('date_field=paid_at', $html);
        $this->assertStringContainsString('DEP-, WD-, ORD-', $html);
    }

    public function test_overview_focus_shows_deposit_and_unpaid_preview_rows(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advertiser->update(['name' => 'Deposit Ann', 'email' => 'deposit-ann@example.test']);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-PREVIEW-9',
            'amount' => 88.5,
            'payment_method' => 'wise',
            'status' => 'pending',
            'user_marked_paid_at' => now(),
        ]);

        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-UNPAID-9',
            'subtotal' => 60,
            'tax' => 0,
            'total_amount' => 60,
            'payment_method' => 'wallet',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['focus' => 'deposits']))
            ->assertOk()
            ->assertSee('DEP-PREVIEW-9')
            ->assertSee('Deposit Ann')
            ->assertSee('€88.50')
            ->assertSee('User-reported paid')
            ->assertSee('id="finance-deposits"', false);

        $this->actingAs($admin)
            ->get(route('admin.finance', ['focus' => 'unpaid']))
            ->assertOk()
            ->assertSee('ORD-UNPAID-9')
            ->assertSee('€60.00')
            ->assertSee('id="finance-unpaid"', false);
    }

    public function test_overview_money_alerts_list_open_disputes(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dispute Site',
            'site_url' => 'https://dispute-site.test',
            'domain' => 'dispute-site-'.uniqid().'.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Finance overview dispute alert site description.',
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-DISPUTE-1',
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        $item = OrderItem::create([
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

        OrderItemDispute::ensureTable();
        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Listing went down after completion.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.finance'))
            ->assertOk()
            ->assertSee('Needs a look')
            ->assertSee('Open disputes');
    }

    public function test_overview_search_jumps_to_unique_deposit_withdrawal_and_order(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-JUMP-1',
            'amount' => 30,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 12,
            'fee' => 0,
            'net_amount' => 12,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@b.com'],
            'status' => 'pending',
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-JUMP-1',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $deposit = $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'DEP-JUMP-1', 'period' => 'week']));
        $deposit->assertRedirect();
        $depositLocation = (string) $deposit->headers->get('Location');
        $this->assertStringContainsString('DEP-JUMP-1', $depositLocation);
        $this->assertStringContainsString('finance=1', $depositLocation);
        $this->assertStringContainsString('period=week', $depositLocation);

        $defaultDeposit = $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'DEP-JUMP-1']));
        $defaultDeposit->assertRedirect();
        $this->assertStringContainsString('finance=1', (string) $defaultDeposit->headers->get('Location'));

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'WD-'.$withdrawal->id]))
            ->assertRedirect(route('admin.withdrawals', [
                'search' => (string) $withdrawal->id,
                'queue' => 'open',
            ]));

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => 'ORD-JUMP-1']))
            ->assertRedirect(route('admin.orders.show', $order->id));

        $this->actingAs($admin)
            ->get(route('admin.finance', ['q' => (string) $advertiser->id]))
            ->assertRedirect(route('admin.finance.user', $advertiser));
    }

    public function test_overview_sticky_chips_wallet_cap_and_reserved_link(): void
    {
        $admin = $this->makeUser('admin');
        $advRole = Role::firstOrCreate(['name' => 'advertiser']);
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);
        $advertiser = $this->makeUser('advertiser');

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $advRole->id,
            'balance' => 50,
            'bonus_balance' => 0,
            'reserved_balance' => 18,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $preview = FinanceOverviewService::PREVIEW_LIMIT;
        for ($i = 1; $i <= $preview + 1; $i++) {
            $publisher = $this->makeUser('publisher');
            Wallet::create([
                'user_id' => $publisher->id,
                'role_id' => $pubRole->id,
                'balance' => 10 + $i,
                'bonus_balance' => 0,
                'reserved_balance' => 0,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]);
            Withdrawal::create([
                'user_id' => $publisher->id,
                'amount' => 5,
                'fee' => 0,
                'net_amount' => 5,
                'payment_method' => 'paypal',
                'payment_details' => ['email' => 'a@b.com'],
                'status' => 'pending',
            ]);
        }

        $html = $this->actingAs($admin)
            ->get(route('admin.finance', [
                'period' => 'all',
                'min_wallet' => '10',
                'focus' => 'due',
            ]))
            ->assertOk()
            ->assertSee('Wallets ≥ €10.00')
            ->assertSee('Period: All time')
            ->assertSee('Clear filters')
            ->assertSee('Reserved in flight')
            ->assertSee('€18.00')
            ->getContent();

        $this->assertStringContainsString($preview.' of '.($preview + 1), $html);
        $this->assertStringContainsString('id="finance-wallets"', $html);
        $this->assertStringContainsString('adminFinanceMinWallet', $html);

        $expanded = $this->actingAs($admin)
            ->get(route('admin.finance', [
                'withdrawals' => 'all',
                'wallets' => 'all',
                'focus' => 'due',
            ]))
            ->assertOk()
            ->assertSee('All open payouts')
            ->assertSee('All wallets')
            ->getContent();
        $this->assertStringNotContainsString($preview.' of '.($preview + 1), $expanded);

        $css = (string) file_get_contents(public_path('assets/css/admin-components.css'));
        $this->assertStringContainsString('.admin-finance-overview .admin-finance-toolbar__form', $css);
        $this->assertStringContainsString('.admin-finance-overview .admin-finance-toolbar__search', $css);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $css);
    }

    private function seedPublisherWallet(User $publisher, float $balance): Wallet
    {
        $pubRole = Role::firstOrCreate(['name' => 'publisher']);

        return Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $pubRole->id,
            'balance' => $balance,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }
}
