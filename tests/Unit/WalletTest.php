<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function makeWallet(float $balance = 100, float $reserved = 0): Wallet
    {
        $role = Role::create(['name' => 'advertiser']);
        $user = User::factory()->create();

        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'reserved_balance' => $reserved,
            'currency' => 'EUR',
        ]);
    }

    public function test_add_balance_increases_available_funds(): void
    {
        $wallet = $this->makeWallet(50);

        $wallet->addBalance(25.5);

        $this->assertEquals(75.5, (float) $wallet->fresh()->balance);
    }

    public function test_deduct_balance_decreases_available_funds(): void
    {
        $wallet = $this->makeWallet(50);

        $wallet->deductBalance(20);

        $this->assertEquals(30.0, (float) $wallet->fresh()->balance);
    }

    public function test_deduct_balance_throws_when_insufficient(): void
    {
        $wallet = $this->makeWallet(10);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient balance');

        $wallet->deductBalance(11);
    }

    public function test_reserve_amount_moves_funds_to_reserved(): void
    {
        $wallet = $this->makeWallet(100);

        $wallet->reserveAmount(40);

        $wallet->refresh();
        $this->assertEquals(60.0, (float) $wallet->balance);
        $this->assertEquals(40.0, (float) $wallet->reserved_balance);
    }

    public function test_reserve_amount_throws_when_insufficient(): void
    {
        $wallet = $this->makeWallet(10);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient balance to reserve');

        $wallet->reserveAmount(11);
    }

    public function test_release_reserved_returns_funds_to_balance(): void
    {
        $wallet = $this->makeWallet(60, 40);

        $wallet->releaseReserved(40);

        $wallet->refresh();
        $this->assertEquals(100.0, (float) $wallet->balance);
        $this->assertEquals(0.0, (float) $wallet->reserved_balance);
    }

    public function test_release_reserved_throws_when_reserved_too_low(): void
    {
        $wallet = $this->makeWallet(60, 10);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Reserved balance too low');

        $wallet->releaseReserved(11);
    }
}
