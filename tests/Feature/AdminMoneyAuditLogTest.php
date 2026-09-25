<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminMoneyAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

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

    private function advertiserWallet(User $user): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function publisherWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_deposit_approve_writes_activity_log(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->advertiserWallet($advertiser);
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-AUDIT-90',
            'amount' => 90,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deposit.approved',
            'subject_id' => $deposit->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_withdrawal_mark_paid_and_reject_write_activity_logs(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $paid = Withdrawal::create(array_merge([
            'user_id' => $publisher->id,
            'amount' => 75,
            'fee' => 0,
            'net_amount' => 75,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes(null)));
        $rejected = Withdrawal::create(array_merge([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes(null)));

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $paid->id), [
                'notes' => 'Paid via Wise',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'withdrawal.status_updated',
            'subject_id' => $paid->id,
            'user_id' => $admin->id,
        ]);
        $this->assertSame('completed', $paid->fresh()->status);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.reject', $rejected->id), [
                'notes' => 'Bad IBAN details',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'withdrawal.status_updated',
            'subject_id' => $rejected->id,
            'user_id' => $admin->id,
        ]);
        $this->assertSame('cancelled', $rejected->fresh()->status);
    }
}
