<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WalletBalancePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $advertiser = Role::create(['name' => 'advertiser']);
        $publisher = Role::create(['name' => 'publisher']);

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
        ]);
        $this->user->roles()->attach([$advertiser->id, $publisher->id]);

        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'role_id' => $advertiser->id,
            'balance' => 20,
            'reserved_balance' => 0,
            'bonus_balance' => 20,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        Wallet::create([
            'user_id' => $this->user->id,
            'role_id' => $publisher->id,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_balance_redirects_to_merged_add_funds_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('advertiser.balance'))
            ->assertRedirect(route('advertiser.add-funds'));
    }

    public function test_add_funds_page_renders_wallet_and_deposit_ui(): void
    {
        $response = $this->actingAs($this->user)->get(route('advertiser.add-funds'));

        $response->assertOk();
        $response->assertSee('Add Funds', false);
        $response->assertSee('Available Balance', false);
        $response->assertSee('Bonus Balance', false);
        $response->assertSee('Pending Balance', false);
        $response->assertSee('Balance History', false);
        $response->assertSee('depositSection', false);
        $response->assertSee(Wallet::PROMOTIONAL_BONUS_MESSAGE, false);
        $response->assertDontSee('Transfer to Publisher Wallet', false);
    }

    public function test_cannot_withdraw_bonus_only_balance(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 10,
            'payment_method' => 'paypal',
            'paypal_email' => 'user@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'code' => 'bonus_not_withdrawable',
            'message' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
        ]);

        $this->wallet->refresh();
        $this->assertEquals(20.0, (float) $this->wallet->balance);
        $this->assertEquals(20.0, (float) $this->wallet->bonus_balance);
        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_can_withdraw_from_available_balance_only(): void
    {
        Mail::fake();
        $this->wallet->addBalance(50);
        $this->wallet->refresh();

        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 30,
            'payment_method' => 'paypal',
            'paypal_email' => 'user@example.com',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->wallet->refresh();
        $this->assertEquals(40.0, (float) $this->wallet->balance);
        $this->assertEquals(20.0, (float) $this->wallet->bonus_balance);
        $this->assertSame(20.0, $this->wallet->withdrawableBalance());
        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $this->user->id,
            'amount' => 30,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => 'withdrawal',
            'amount' => 30,
        ]);
    }

    public function test_role_transfers_are_disabled(): void
    {
        $this->wallet->addBalance(50);

        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.transfer'), [
            'amount' => 5,
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', 'transfers_disabled');
        $this->wallet->refresh();
        $this->assertEquals(70.0, (float) $this->wallet->balance);
    }

    public function test_transactions_endpoint_returns_bonus_activity(): void
    {
        app(\App\Services\Wallet\WalletLedgerService::class)->recordBonusCredit(
            $this->wallet,
            20,
            'Welcome promotional bonus'
        );

        $response = $this->actingAs($this->user)->getJson(route('advertiser.balance.transactions'));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $types = collect($response->json('transactions'))->pluck('type')->all();
        $this->assertContains('bonus_credit', $types);
    }

    public function test_analytics_endpoint_accepts_ranges(): void
    {
        $response = $this->actingAs($this->user)->getJson(route('advertiser.balance.analytics', [
            'range' => 'week',
        ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'analytics' => ['labels', 'deposits', 'orders', 'withdrawals', 'bonus_usage'],
        ]);
    }
}
