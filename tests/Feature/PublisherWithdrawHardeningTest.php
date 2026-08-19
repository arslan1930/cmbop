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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublisherWithdrawHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(float $balance = 100, float $reserved = 0, float $bonus = 0): User
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
            'bonus_balance' => $bonus,
            'reserved_balance' => $reserved,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
    }

    /**
     * @return array{0: User, 1: Wallet, 2: Wallet}
     */
    private function dualRoleWithSeparateWallets(float $publisherBalance = 80, float $advertiserBalance = 50): array
    {
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
            'balance' => $advertiserBalance,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $publisherWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => $publisherBalance,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return [$user->fresh(), $publisherWallet, $advertiserWallet];
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

        $this->actingAs($publisher)
            ->getJson(route('publisher.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_withdrawals', 70);
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

    public function test_history_ok_when_processed_at_is_unparseable(): void
    {
        $publisher = $this->publisher();
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now()->subDay(),
        ]);
        DB::table('withdrawals')->where('id', $withdrawal->id)->update([
            'processed_at' => 'not-a-date',
        ]);

        $this->assertNull($withdrawal->fresh()->processed_at);

        $this->actingAs($publisher)
            ->getJson(route('publisher.withdrawals.history'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.id', $withdrawal->id)
            ->assertJsonPath('data.data.0.processed_at', null)
            ->assertJsonPath('data.data.0.status_label', 'Paid');
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

    public function test_withdraw_page_shows_publisher_wallet_not_active_advertiser_wallet(): void
    {
        [$user] = $this->dualRoleWithSeparateWallets(80, 50);
        $this->assertSame('advertiser', $user->activeRole());

        $html = $this->actingAs($user)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/Withdrawable<\/span>\s*<h3[^>]*>€80\.00<\/h3>/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Withdrawable<\/span>\s*<h3[^>]*>€50\.00<\/h3>/',
            $html
        );
        $this->assertStringContainsString('Available: <strong>€80.00</strong>', $html);
    }

    public function test_withdraw_request_debits_publisher_wallet_not_active_advertiser_wallet(): void
    {
        [$user, $publisherWallet, $advertiserWallet] = $this->dualRoleWithSeparateWallets(80, 50);
        $this->assertSame('advertiser', $user->activeRole());

        $this->actingAs($user)
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
            'user_id' => $user->id,
            'wallet_id' => $publisherWallet->id,
            'amount' => 20,
            'status' => 'pending',
        ]);
        $this->assertSame(60.0, (float) $publisherWallet->fresh()->balance);
        $this->assertSame(50.0, (float) $advertiserWallet->fresh()->balance);
    }

    public function test_history_and_stats_exclude_advertiser_wallet_withdrawals(): void
    {
        [$user, $publisherWallet, $advertiserWallet] = $this->dualRoleWithSeparateWallets(80, 50);

        $advertiserPending = Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 33,
            'fee' => 0,
            'net_amount' => 33,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'adv@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes($advertiserWallet)));

        $publisherPending = Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes($publisherWallet)));

        Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 100,
            'fee' => 0,
            'net_amount' => 100,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'adv@example.com'],
            'status' => 'completed',
        ], Withdrawal::walletIdAttributes($advertiserWallet)));

        Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 25,
            'fee' => 0,
            'net_amount' => 25,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'completed',
        ], Withdrawal::walletIdAttributes($publisherWallet)));

        $history = $this->actingAs($user)
            ->getJson(route('publisher.withdrawals.history'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.data');

        $this->assertCount(2, $history);
        $this->assertSame(
            [$publisherPending->id],
            collect($history)->where('status', 'pending')->pluck('id')->all()
        );
        $this->assertFalse(collect($history)->contains('id', $advertiserPending->id));

        $this->actingAs($user)
            ->getJson(route('publisher.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_withdrawals', 40)
            ->assertJsonPath('data.total_withdrawn', 25)
            ->assertJsonPath('data.withdrawal_count', 2);

        $html = $this->actingAs($user)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('WD-'.$publisherPending->id, $html);
        $this->assertStringNotContainsString('WD-'.$advertiserPending->id, $html);
    }

    public function test_withdraw_page_hides_empty_bonus_and_on_hold_tiles(): void
    {
        $publisher = $this->publisher();

        $html = $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Withdrawable', $html);
        $this->assertStringContainsString('Pending payout', $html);
        $this->assertStringContainsString('Paid out', $html);
        $this->assertStringNotContainsString('is already reserved for open requests', $html);
        $this->assertStringNotContainsString('Locked for open orders', $html);
        $this->assertStringNotContainsString('Free Credit', $html);
        $this->assertStringNotContainsString('>On hold<', $html);
        $this->assertStringNotContainsString('>Bonus<', $html);
    }

    public function test_withdraw_page_shows_on_hold_without_open_orders_copy(): void
    {
        $publisher = $this->publisher(100, 12.5);

        $html = $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('On hold', $html);
        $this->assertStringContainsString('€12.50', $html);
        $this->assertStringContainsString('Already left withdrawable', $html);
        $this->assertStringNotContainsString('Locked for open orders', $html);
        $this->assertStringNotContainsString('Free Credit', $html);
    }

    public function test_withdraw_page_shows_bonus_only_when_present(): void
    {
        $publisher = $this->publisher(40, 0, 20);

        $html = $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Bonus', $html);
        $this->assertStringContainsString('€20.00', $html);
        $this->assertStringContainsString('Purchases only — cannot withdraw', $html);
        $this->assertStringContainsString(Wallet::PROMOTIONAL_BONUS_MESSAGE, $html);
        $this->assertStringNotContainsString('Free Credit', $html);
        $this->assertStringNotContainsString('On hold', $html);
    }

    public function test_withdraw_page_shows_pending_payout_and_lifetime_paid(): void
    {
        $publisher = $this->publisher(100);
        $wallet = Wallet::forPublisher((int) $publisher->id);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 40,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Withdrawal::create(array_merge([
            'user_id' => $publisher->id,
            'amount' => 50,
            'fee' => 5,
            'net_amount' => 45,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'paid@example.com'],
            'status' => 'completed',
        ], Withdrawal::walletIdAttributes($wallet)));

        $html = $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/Withdrawable<\/span>\s*<h3[^>]*>€60\.00<\/h3>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/Pending payout<\/span>\s*<h3[^>]*>€40\.00<\/h3>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/Paid out<\/span>\s*<h3[^>]*>€45\.00<\/h3>/',
            $html
        );
        $this->assertStringContainsString(
            '€40.00 is already reserved for open requests',
            $html
        );

        $this->actingAs($publisher)
            ->getJson(route('publisher.withdrawals.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_withdrawals', 40)
            ->assertJsonPath('data.total_withdrawn', 45)
            ->assertJsonPath('data.withdrawal_count', 2);
    }

    public function test_withdraw_page_uses_extracted_script_and_no_history_flicker(): void
    {
        $publisher = $this->publisher();
        $html = $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('assets/js/publisher-withdraw.js', $html);
        $this->assertStringContainsString('assets/css/publisher-withdraw.css', $html);
        $this->assertStringContainsString('id="publisherWithdrawApp"', $html);
        $this->assertStringContainsString('Back to Balance', $html);
        $this->assertStringNotContainsString('Withdraw Funds', $html);
        $this->assertStringNotContainsString('sweetalert2.min.css', $html);
        $this->assertStringNotContainsString('Swal.fire', $html);
        $this->assertStringNotContainsString('loadHistory(1)', $html);

        $js = file_get_contents(public_path('assets/js/publisher-withdraw.js'));
        $this->assertStringContainsString('window.slbConfirm', $js);
        $this->assertStringContainsString('function loadHistory', $js);
        $this->assertStringNotContainsString('loadHistory(1)', $js);
    }

    public function test_withdraw_request_without_wallet_is_unprocessable(): void
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
