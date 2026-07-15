<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletBonusTest extends TestCase
{
    use RefreshDatabase;

    private function makeWallet(float $balance = 20, float $bonus = 20): Wallet
    {
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create();

        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_welcome_bonus_is_not_withdrawable(): void
    {
        $wallet = $this->makeWallet(20, 20);

        $this->assertSame(0.0, $wallet->withdrawableBalance());
        $this->assertSame(20.0, $wallet->lockedBonusBalance());
    }

    public function test_deposited_funds_are_withdrawable_while_bonus_stays_locked(): void
    {
        $wallet = $this->makeWallet(20, 20);
        $wallet->addBalance(50);

        $this->assertEquals(70.0, (float) $wallet->fresh()->balance);
        $this->assertSame(50.0, $wallet->fresh()->withdrawableBalance());
        $this->assertSame(20.0, $wallet->fresh()->lockedBonusBalance());
    }

    public function test_spending_consumes_bonus_first(): void
    {
        $wallet = $this->makeWallet(70, 20);

        $wallet->reserveForOrder(30);

        $wallet->refresh();
        $this->assertEquals(40.0, (float) $wallet->balance);
        $this->assertEquals(30.0, (float) $wallet->reserved_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_balance);
        $this->assertEquals(20.0, (float) $wallet->bonus_reserved);
        $this->assertSame(40.0, $wallet->withdrawableBalance());
    }

    public function test_refund_restores_spend_only_bonus(): void
    {
        $wallet = $this->makeWallet(20, 20);
        $wallet->reserveForOrder(20);
        $wallet->refundReserved(20);

        $wallet->refresh();
        $this->assertEquals(20.0, (float) $wallet->balance);
        $this->assertEquals(20.0, (float) $wallet->bonus_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_completed_order_permanently_consumes_bonus(): void
    {
        $wallet = $this->makeWallet(20, 20);
        $wallet->reserveForOrder(20);
        $wallet->consumeReserved(20);

        $wallet->refresh();
        $this->assertEquals(0.0, (float) $wallet->balance);
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_balance);
        $this->assertEquals(0.0, (float) $wallet->bonus_reserved);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_cannot_deduct_bonus_via_withdrawable_helper(): void
    {
        $wallet = $this->makeWallet(20, 20);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient withdrawable balance');

        $wallet->deductWithdrawable(1);
    }
}
