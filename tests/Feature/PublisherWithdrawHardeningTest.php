<?php

namespace Tests\Feature;

use App\Mail\WithdrawalRequestedConfirmation;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublisherWithdrawHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(float $balance = 100): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
    }

    public function test_below_minimum_is_rejected(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 19.99,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertFalse($publisher->fresh()->payoutProfileLocked());
    }

    public function test_exact_minimum_succeeds(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $publisher->id,
            'wallet_id' => $publisher->activeWallet()?->id,
            'amount' => 20,
            'status' => 'pending',
        ]);
    }

    public function test_failed_withdraw_does_not_lock_payout_profile(): void
    {
        $publisher = $this->publisher(25);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 50,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422);

        $this->assertFalse($publisher->fresh()->payoutProfileLocked());
        $this->assertNull($publisher->fresh()->payout_paypal_email);
    }

    public function test_publisher_can_cancel_pending_with_ledger_credit(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 30,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk();

        $withdrawal = Withdrawal::where('user_id', $publisher->id)->firstOrFail();
        $wallet = $publisher->activeWallet();
        $this->assertEquals(70.0, (float) $wallet->fresh()->balance);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdrawals.cancel', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $withdrawal->refresh();
        $this->assertSame('cancelled', $withdrawal->status);
        $this->assertSame(Withdrawal::CANCELLED_BY_USER, $withdrawal->cancelled_by);
        $this->assertSame('Cancelled', $withdrawal->publisher_status_label);
        $this->assertEquals(100.0, (float) $wallet->fresh()->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_ADJUSTMENT,
            'direction' => 'credit',
            'reference' => 'WD-'.$withdrawal->id.'-cancel',
        ]);
    }

    public function test_cancel_credits_the_debited_wallet_not_the_active_role(): void
    {
        Mail::fake();

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $user->roles()->attach([$advertiserRole->id, $publisherRole->id]);

        $advertiserWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiserRole->id,
            'balance' => 50,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $publisherWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $this->actingAs($user)
            ->postJson(route('advertiser.balance.withdraw'), [
                'amount' => 20,
                'payment_method' => 'paypal',
                'business_name' => 'Acme Media',
                'paypal_email' => 'user@example.com',
            ])
            ->assertOk();

        $withdrawal = Withdrawal::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($advertiserWallet->id, (int) $withdrawal->wallet_id);
        $this->assertSame(30.0, (float) $advertiserWallet->fresh()->balance);

        $user->active_role_id = $publisherRole->id;
        $user->save();

        $this->actingAs($user->fresh())
            ->postJson(route('publisher.withdrawals.cancel', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(50.0, (float) $advertiserWallet->fresh()->balance);
        $this->assertSame(80.0, (float) $publisherWallet->fresh()->balance);
    }

    public function test_admin_reject_label_is_rejected(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk();

        $withdrawal = Withdrawal::where('user_id', $publisher->id)->firstOrFail();

        app(ManualWithdrawalSettlementService::class)->reject($withdrawal, null, 'Bad details', quiet: true);

        $withdrawal->refresh();
        $this->assertSame(Withdrawal::CANCELLED_BY_ADMIN, $withdrawal->cancelled_by);
        $this->assertSame('Rejected', $withdrawal->publisher_status_label);
    }

    public function test_fee_is_stored_and_history_includes_net(): void
    {
        config(['billing.withdrawal_fee_percent' => 10]);
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 50,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('fee', 5)
            ->assertJsonPath('net_amount', 45);

        $history = $this->actingAs($publisher)
            ->getJson(route('publisher.withdrawals.history'))
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame(50.0, (float) $history['amount']);
        $this->assertSame(5.0, (float) $history['fee']);
        $this->assertSame(45.0, (float) $history['net_amount']);
        $this->assertSame('WD-'.$history['id'], $history['reference']);
        $this->assertTrue($history['cancellable']);

        $historyCache = (string) $this->actingAs($publisher)
            ->getJson(route('publisher.withdrawals.history'))
            ->assertOk()
            ->headers
            ->get('Cache-Control');
        $this->assertStringContainsString('no-store', $historyCache);
    }

    public function test_statistics_include_processing_as_in_flight(): void
    {
        $publisher = $this->publisher();

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@example.com'],
            'status' => 'pending',
        ]);
        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 30,
            'fee' => 0,
            'net_amount' => 30,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'a@example.com'],
            'status' => 'processing',
        ]);

        $stats = $this->actingAs($publisher)
            ->getJson(route('publisher.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_withdrawals', 70);

        $this->assertStringContainsString('no-store', (string) $stats->headers->get('Cache-Control'));
    }

    public function test_invalid_iban_is_rejected(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'bank',
                'bank_name' => 'Demo Bank',
                'account_holder' => 'Jane Publisher',
                'account_number' => 'DE00INVALID',
                'account_number_confirm' => 'DE00INVALID',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_valid_iban_bank_withdraw_succeeds(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'bank',
                'bank_name' => 'Demo Bank',
                'account_holder' => 'Jane Publisher',
                'account_number' => 'DE89370400440532013000',
                'account_number_confirm' => 'DE89370400440532013000',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_invalid_crypto_address_is_rejected(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'crypto',
                'crypto_type' => 'ETH',
                'wallet_address' => 'not-an-eth-address',
                'wallet_address_confirm' => 'not-an-eth-address',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_publisher_receives_request_confirmation_email(): void
    {
        Mail::fake();
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk();

        Mail::assertQueued(WithdrawalRequestedConfirmation::class);
    }

    public function test_withdraw_page_shows_minimum_and_disables_when_below_min(): void
    {
        $publisher = $this->publisher(15);

        $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee('Minimum:', false)
            ->assertSee('Below minimum', false)
            ->assertSee('disabled', false);
    }
}
