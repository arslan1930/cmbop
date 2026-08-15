<?php

namespace Tests\Feature;

use App\Models\BalanceTransfer;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Admin\FinanceOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherRoleMoveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, float>  $overrides
     */
    private function publisherWithWallets(array $overrides = [], bool $dualRole = true): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'marketing']);
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        $publisher = Role::firstOrCreate(['name' => 'publisher']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisher->id,
        ]);
        $user->roles()->attach($dualRole ? [$publisher->id, $advertiser->id] : [$publisher->id]);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisher->id,
            'balance' => $overrides['publisher_balance'] ?? 25,
            'reserved_balance' => $overrides['publisher_reserved'] ?? 0,
            'bonus_balance' => $overrides['publisher_bonus'] ?? 0,
            'bonus_reserved' => 0,
            'debt_balance' => $overrides['publisher_debt'] ?? 0,
            'currency' => 'EUR',
        ]);

        if ($dualRole) {
            Wallet::create([
                'user_id' => $user->id,
                'role_id' => $advertiser->id,
                'balance' => $overrides['advertiser_balance'] ?? 20,
                'reserved_balance' => 0,
                'bonus_balance' => $overrides['advertiser_bonus'] ?? 20,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]);
        }

        return $user;
    }

    public function test_dual_role_publisher_can_move_withdrawable_to_advertiser_money(): void
    {
        $user = $this->publisherWithWallets();

        $response = $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 10]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('amount', 10)
            ->assertJsonPath('fee', 0)
            ->assertJsonPath('net_amount', 10)
            ->assertJsonPath('publisher.spendable', 15)
            ->assertJsonPath('publisher.withdrawable', 15)
            ->assertJsonPath('publisher.bonus', 0)
            ->assertJsonPath('advertiser.spendable', 30)
            ->assertJsonPath('advertiser.withdrawable', 10)
            ->assertJsonPath('advertiser.bonus', 20);

        $this->assertNotEmpty($response->json('reference'));

        $publisherWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::publisherRoleId())
            ->firstOrFail();
        $advertiserWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->firstOrFail();

        $this->assertSame(15.0, (float) $publisherWallet->balance);
        $this->assertSame(30.0, (float) $advertiserWallet->balance);
        $this->assertSame(20.0, (float) $advertiserWallet->bonus_balance);

        $this->assertDatabaseHas('balance_transfers', [
            'user_id' => $user->id,
            'from_role' => 'publisher',
            'to_role' => 'advertiser',
            'amount' => 10,
            'fee' => 0,
            'net_amount' => 10,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $publisherWallet->id,
            'type' => WalletTransaction::TYPE_ROLE_MOVE_OUT,
            'direction' => 'debit',
            'amount' => 10,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $advertiserWallet->id,
            'type' => WalletTransaction::TYPE_ROLE_MOVE_IN,
            'direction' => 'credit',
            'amount' => 10,
        ]);

        $this->assertSame(0, WalletTransaction::where('type', WalletTransaction::TYPE_TRANSFER_IN)->count());
        $this->assertSame(0, WalletTransaction::where('type', WalletTransaction::TYPE_TRANSFER_OUT)->count());

        $finance = app(FinanceOverviewService::class);
        $period = $finance->resolvePeriod('all');
        $overview = $finance->overview($period);
        $this->assertSame(0.0, (float) $overview['money_out']['earnings_credited']['ledger_transfer_in']);
    }

    public function test_move_is_blocked_when_publisher_has_debt(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 25,
            'publisher_debt' => 5,
        ]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 10])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'wallet_debt');

        $this->assertSame(
            25.0,
            (float) Wallet::where('user_id', $user->id)
                ->where('role_id', Wallet::publisherRoleId())
                ->value('balance')
        );
        $this->assertSame(0, BalanceTransfer::count());
    }

    public function test_publisher_only_account_cannot_move(): void
    {
        $user = $this->publisherWithWallets([], dualRole: false);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 10])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'advertiser_role_required');

        $this->assertSame(
            25.0,
            (float) Wallet::where('user_id', $user->id)
                ->where('role_id', Wallet::publisherRoleId())
                ->value('balance')
        );
        $this->assertSame(0, Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->count());
    }

    public function test_bonus_cannot_be_moved(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 20,
            'publisher_bonus' => 20,
        ]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 5])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'insufficient_withdrawable');

        $publisher = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::publisherRoleId())
            ->firstOrFail();
        $advertiser = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->firstOrFail();

        $this->assertSame(20.0, (float) $publisher->balance);
        $this->assertSame(20.0, (float) $publisher->bonus_balance);
        $this->assertSame(20.0, (float) $advertiser->balance);
        $this->assertSame(20.0, (float) $advertiser->bonus_balance);
    }

    public function test_amount_below_minimum_is_rejected(): void
    {
        $user = $this->publisherWithWallets();

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 0])
            ->assertStatus(422);

        $this->assertSame(0, BalanceTransfer::count());
    }

    public function test_creates_advertiser_wallet_when_dual_role_is_missing_one(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 12], dualRole: true);
        Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->delete();

        $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->assertSee('id="roleMoveForm"', false)
            ->assertSee('id="roleMoveBtn"', false);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 12])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('publisher.withdrawable', 0)
            ->assertJsonPath('advertiser.spendable', 12)
            ->assertJsonPath('advertiser.bonus', 0);
    }

    public function test_advertiser_activity_labels_role_move_in_and_hides_debit_twin(): void
    {
        $user = $this->publisherWithWallets();

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 10])
            ->assertOk();

        $response = $this->actingAs($user->fresh())
            ->getJson(route('advertiser.balance.transactions'));

        $response->assertOk()->assertJsonPath('success', true);
        $rows = collect($response->json('transactions'));
        $types = $rows->pluck('type')->all();

        $this->assertContains(WalletTransaction::TYPE_ROLE_MOVE_IN, $types);
        $this->assertNotContains(WalletTransaction::TYPE_ROLE_MOVE_OUT, $types);

        $move = $rows->firstWhere('type', WalletTransaction::TYPE_ROLE_MOVE_IN);
        $this->assertNotNull($move);
        $this->assertSame('Earnings Moved for Spending', $move['type_label']);
        $this->assertSame('credit', $move['direction']);
        $this->assertSame(10.0, (float) $move['amount']);
    }

    public function test_add_funds_filter_offers_role_move_in_label(): void
    {
        $user = $this->publisherWithWallets();
        $user->update(['active_role_id' => Role::where('name', 'advertiser')->value('id')]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="role_move_in"', $html);
        $this->assertStringContainsString('Earnings Moved for Spending', $html);
        $this->assertStringContainsString('Open Balance to move earnings here for catalog spend', $html);
    }

    public function test_role_move_type_labels(): void
    {
        $out = new WalletTransaction(['type' => WalletTransaction::TYPE_ROLE_MOVE_OUT]);
        $in = new WalletTransaction(['type' => WalletTransaction::TYPE_ROLE_MOVE_IN]);

        $this->assertSame('Moved to Advertiser Wallet', $out->typeLabel());
        $this->assertSame('Earnings Moved for Spending', $in->typeLabel());
        $this->assertSame('Moved to Advertiser Wallet', WalletTransaction::typeLabelFor(WalletTransaction::TYPE_ROLE_MOVE_OUT));
        $this->assertSame('Earnings Moved for Spending', WalletTransaction::typeLabelFor(WalletTransaction::TYPE_ROLE_MOVE_IN));
    }

    public function test_only_withdrawable_cash_moves_and_publisher_bonus_stays(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 25,
            'publisher_bonus' => 10,
        ]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 15])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('publisher.spendable', 10)
            ->assertJsonPath('publisher.withdrawable', 0)
            ->assertJsonPath('publisher.bonus', 10)
            ->assertJsonPath('advertiser.spendable', 35)
            ->assertJsonPath('advertiser.withdrawable', 15)
            ->assertJsonPath('advertiser.bonus', 20);

        $publisher = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::publisherRoleId())
            ->firstOrFail();
        $this->assertSame(10.0, (float) $publisher->balance);
        $this->assertSame(10.0, (float) $publisher->bonus_balance);
    }

    public function test_amount_above_withdrawable_is_rejected_when_cash_remains(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 25,
            'publisher_bonus' => 10,
        ]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 15.01])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_withdrawable');

        $this->assertSame(
            25.0,
            (float) Wallet::where('user_id', $user->id)
                ->where('role_id', Wallet::publisherRoleId())
                ->value('balance')
        );
        $this->assertSame(0, BalanceTransfer::count());
    }

    public function test_minimum_one_cent_move_succeeds(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 25]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 0.01])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('amount', 0.01)
            ->assertJsonPath('fee', 0)
            ->assertJsonPath('publisher.withdrawable', 24.99);
    }

    public function test_sequential_moves_debit_publisher_twice(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 25]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 10])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 5])
            ->assertOk()
            ->assertJsonPath('publisher.withdrawable', 10)
            ->assertJsonPath('advertiser.spendable', 35)
            ->assertJsonPath('advertiser.bonus', 20);

        $this->assertSame(2, BalanceTransfer::where('user_id', $user->id)->count());
        $this->assertSame(2, WalletTransaction::where('type', WalletTransaction::TYPE_ROLE_MOVE_IN)->count());
    }

    public function test_guests_cannot_move(): void
    {
        $response = $this->postJson(route('publisher.balance.transfer'), ['amount' => 5]);

        $this->assertContains($response->status(), [401, 302]);
        $this->assertSame(0, BalanceTransfer::count());
    }

    public function test_advertiser_only_cannot_post_publisher_move(): void
    {
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
        ]);
        $user->roles()->attach($advertiser->id);
        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiser->id,
            'balance' => 50,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 5])
            ->assertForbidden();

        $this->assertSame(0, BalanceTransfer::count());
    }

    public function test_activity_can_filter_to_role_move_in_with_icon(): void
    {
        $user = $this->publisherWithWallets();

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 10])
            ->assertOk();

        $response = $this->actingAs($user->fresh())
            ->getJson(route('advertiser.balance.transactions', ['type' => WalletTransaction::TYPE_ROLE_MOVE_IN]));

        $response->assertOk();
        $rows = collect($response->json('transactions'));
        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn ($row) => $row['type'] === WalletTransaction::TYPE_ROLE_MOVE_IN));
        $this->assertSame('Earnings Moved for Spending', $rows->first()['type_label']);
        $this->assertSame('fa-exchange-alt', $rows->first()['icon']);
    }

    public function test_legacy_publisher_transfer_without_ledger_uses_role_move_in_label(): void
    {
        $user = $this->publisherWithWallets();

        BalanceTransfer::create([
            'user_id' => $user->id,
            'from_role' => 'publisher',
            'to_role' => 'advertiser',
            'amount' => 8,
            'fee' => 0,
            'net_amount' => 8,
            'reference_code' => 'INT-TRF-LEGACY-1',
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('advertiser.balance.transactions'));

        $response->assertOk();
        $row = collect($response->json('transactions'))
            ->firstWhere('reference', 'INT-TRF-LEGACY-1');

        $this->assertNotNull($row);
        $this->assertSame(WalletTransaction::TYPE_ROLE_MOVE_IN, $row['type']);
        $this->assertSame('Earnings Moved for Spending', $row['type_label']);
        $this->assertSame('credit', $row['direction']);
        $this->assertSame(8.0, (float) $row['amount']);
    }

    public function test_balance_page_shows_updated_totals_after_move(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 25]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 10])
            ->assertOk();

        $html = $this->actingAs($user->fresh())
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="publisherBalance">€15\.00/', $html);
        $this->assertMatchesRegularExpression('/id="advertiserBalance">€30\.00/', $html);
        $this->assertStringContainsString('data-max="15.00"', $html);
        $this->assertStringContainsString('data-can-move="1"', $html);
    }

    public function test_role_move_config_is_fee_free_with_cent_minimum(): void
    {
        $this->assertSame(0.01, round((float) config('billing.role_move.min_amount'), 2));
        $this->assertSame(0.0, (float) config('billing.role_move.fee_percent'));
        $this->assertGreaterThan(0.01, (float) config('billing.role_move.max_amount'));
    }

    public function test_advertiser_to_publisher_transfer_stays_gone(): void
    {
        $user = $this->publisherWithWallets();
        $user->update(['active_role_id' => Role::where('name', 'advertiser')->value('id')]);

        $this->actingAs($user)
            ->postJson(route('advertiser.balance.transfer'), ['amount' => 5])
            ->assertStatus(410)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'transfers_disabled');
    }
}
