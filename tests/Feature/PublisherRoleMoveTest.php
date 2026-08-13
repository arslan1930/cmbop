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
            ->postJson(route('publisher.balance.transfer'), ['amount' => 12])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('publisher.withdrawable', 0)
            ->assertJsonPath('advertiser.spendable', 12)
            ->assertJsonPath('advertiser.bonus', 0);
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
