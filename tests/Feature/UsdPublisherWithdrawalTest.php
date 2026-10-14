<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Support\WithdrawalPayoutConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsdPublisherWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.withdrawal_fee_percent' => 0,
            'billing.withdrawal_usd_conversion_fee_percent' => 0.5,
            'billing.withdrawal_min_amounts.USD' => 20,
            'billing.withdrawal_min_amounts.EUR' => 20,
            'billing.withdrawal_min_amounts.GBP' => 20,
        ]);
    }

    public function test_usd_methods_are_paypal_wise_and_bank_only(): void
    {
        $this->assertSame(['bank', 'paypal', 'wise'], WithdrawalPayoutConfig::allowedMethods('USD'));
        $this->assertFalse(WithdrawalPayoutConfig::allowsMethod('crypto', 'USD'));
        $this->assertTrue(WithdrawalPayoutConfig::allowsMethod('bank', 'USD'));
        $this->assertTrue(WithdrawalPayoutConfig::allowsMethod('crypto', 'EUR'));
        $this->assertSame(0.5, WithdrawalPayoutConfig::conversionFeePercent('USD'));
        $this->assertSame(0.0, WithdrawalPayoutConfig::conversionFeePercent('EUR'));
        $this->assertSame(0.0, WithdrawalPayoutConfig::conversionFeePercent('GBP'));
        $this->assertEquals(0.50, WithdrawalPayoutConfig::conversionFee(100, 'USD'));
        $this->assertEquals(0.0, WithdrawalPayoutConfig::conversionFee(100, 'EUR'));
    }

    public function test_usd_withdraw_page_lists_paypal_wise_and_bank_not_crypto(): void
    {
        $publisher = $this->publisher('USD');

        $html = $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee('value="paypal"', false)
            ->assertSee('value="wise"', false)
            ->assertSee('value="bank"', false)
            ->assertSee('Conversion fee', false)
            ->assertSee('You will receive', false)
            ->getContent();

        $this->assertStringNotContainsString('value="crypto"', $html);
        $this->assertStringContainsString('0.5% conversion fee', $html);
    }

    public function test_usd_paypal_withdrawal_records_conversion_fee(): void
    {
        $publisher = $this->publisher('USD', 100);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 100,
                'currency' => 'USD',
                'payment_method' => 'paypal',
                'paypal_email' => 'usd@example.com',
                'paypal_email_confirm' => 'usd@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversion_fee', 0.5)
            ->assertJsonPath('conversion_fee_percent', 0.5)
            ->assertJsonPath('fee', 0)
            ->assertJsonPath('net_amount', 99.5)
            ->assertJsonPath('currency', 'USD');

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $publisher->id,
            'amount' => 100,
            'fee' => 0,
            'conversion_fee' => 0.5,
            'conversion_fee_percent' => 0.5,
            'net_amount' => 99.5,
            'currency' => 'USD',
            'payment_method' => 'paypal',
        ]);
    }

    public function test_usd_wise_withdrawal_applies_conversion_fee(): void
    {
        $publisher = $this->publisher('USD', 200);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 40,
                'currency' => 'USD',
                'payment_method' => 'wise',
                'wise_email' => 'wise@example.com',
                'wise_email_confirm' => 'wise@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('conversion_fee', 0.2)
            ->assertJsonPath('net_amount', 39.8);
    }

    public function test_usd_bank_withdrawal_applies_conversion_fee(): void
    {
        $publisher = $this->publisher('USD', 200);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'currency' => 'USD',
                'payment_method' => 'bank',
                'bank_name' => 'Chase',
                'account_holder' => 'USD Publisher',
                'account_number' => 'DE89370400440532013000',
                'account_number_confirm' => 'DE89370400440532013000',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('conversion_fee', 0.1)
            ->assertJsonPath('net_amount', 19.9)
            ->assertJsonPath('currency', 'USD');

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $publisher->id,
            'payment_method' => 'bank',
            'conversion_fee' => 0.1,
            'currency' => 'USD',
        ]);
    }

    public function test_usd_crypto_withdrawal_is_rejected(): void
    {
        $publisher = $this->publisher('USD');

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'currency' => 'USD',
                'payment_method' => 'crypto',
                'crypto_type' => 'USDT',
                'wallet_address' => 'TLsBTcjhpqLYKkA5nbha3bEe9CCmpCAeqR',
                'wallet_address_confirm' => 'TLsBTcjhpqLYKkA5nbha3bEe9CCmpCAeqR',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertFalse($publisher->fresh()->payoutProfileLocked());
    }

    public function test_eur_and_gbp_withdrawals_have_no_conversion_fee(): void
    {
        $eur = $this->publisher('EUR');
        $this->actingAs($eur)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'currency' => 'EUR',
                'payment_method' => 'crypto',
                'crypto_type' => 'USDT',
                'wallet_address' => 'TLsBTcjhpqLYKkA5nbha3bEe9CCmpCAeqR',
                'wallet_address_confirm' => 'TLsBTcjhpqLYKkA5nbha3bEe9CCmpCAeqR',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('conversion_fee', 0)
            ->assertJsonPath('net_amount', 25);

        $gbp = $this->publisher('GBP');
        $this->actingAs($gbp)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 25,
                'currency' => 'GBP',
                'payment_method' => 'paypal',
                'paypal_email' => 'gbp@example.com',
                'paypal_email_confirm' => 'gbp@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('conversion_fee', 0)
            ->assertJsonPath('net_amount', 25)
            ->assertJsonPath('currency', 'GBP');
    }

    public function test_usd_minimum_applies_to_the_requested_gross(): void
    {
        $publisher = $this->publisher('USD');

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 19.99,
                'currency' => 'USD',
                'payment_method' => 'paypal',
                'paypal_email' => 'usd@example.com',
                'paypal_email_confirm' => 'usd@example.com',
                'details_confirmed' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($publisher)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'currency' => 'USD',
                'payment_method' => 'paypal',
                'paypal_email' => 'usd@example.com',
                'paypal_email_confirm' => 'usd@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('conversion_fee', 0.1)
            ->assertJsonPath('net_amount', 19.9);
    }

    public function test_admin_payout_export_includes_conversion_fee(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $publisher = $this->publisher('USD');
        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 100,
            'fee' => 0,
            'conversion_fee' => 0.5,
            'conversion_fee_percent' => 0.5,
            'net_amount' => 99.5,
            'currency' => 'USD',
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'usd@example.com'],
            'status' => 'pending',
        ]);

        $csv = $this->actingAs($admin)
            ->get(route('admin.withdrawals.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('conversion_fee', $csv);
        $this->assertStringContainsString('conversion_fee_percent', $csv);
        $this->assertStringContainsString('0.50', $csv);
        $this->assertStringContainsString('USD', $csv);
        $this->assertStringContainsString('99.50', $csv);
    }

    private function publisher(string $currency = 'USD', float $balance = 100): User
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
            'currency' => $currency,
        ]);

        return $user->fresh();
    }
}
