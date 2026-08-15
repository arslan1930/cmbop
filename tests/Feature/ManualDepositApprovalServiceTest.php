<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Models\ActivityLog;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\DepositSettlementNotifier;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Services\Wallet\ManualDepositNotManualException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManualDepositApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function walletFor(User $user): Wallet
    {
        $roleId = Wallet::advertiserRoleId();

        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function pendingDeposit(User $user, float $amount = 75, string $method = 'bank'): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'DEP-SVC-'.uniqid(),
            'amount' => $amount,
            'payment_method' => $method,
            'status' => 'pending',
        ]);
    }

    public function test_approve_credits_wallet_once_and_notifies(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 75, 'wise');

        $result = app(ManualDepositApprovalService::class)->approve(
            $deposit,
            $admin,
            'Wire received'
        );

        $this->assertTrue($result['email_sent']);
        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame('Wire received', $deposit->fresh()->admin_notes);
        $this->assertNotNull($deposit->fresh()->approved_at);
        $this->assertSame(75.0, (float) $wallet->fresh()->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'amount' => 75,
        ]);

        Mail::assertQueued(DepositApproved::class, function (DepositApproved $mail) use ($deposit) {
            return (int) $mail->deposit->id === (int) $deposit->id;
        });
    }

    public function test_approve_rejects_already_processed_deposit(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 40);

        app(ManualDepositApprovalService::class)->approve($deposit, $admin);

        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        Mail::assertQueued(DepositApproved::class, 1);

        try {
            app(ManualDepositApprovalService::class)->approve($deposit->fresh(), $admin);
            $this->fail('Expected ManualDepositAlreadyProcessedException');
        } catch (ManualDepositAlreadyProcessedException $e) {
            $this->assertSame('This deposit request has already been processed.', $e->getMessage());
        }

        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        Mail::assertQueued(DepositApproved::class, 1);
    }

    public function test_admin_http_approve_uses_service_path(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 55, 'crypto');

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id), [
                'admin_notes' => 'On-chain confirmed',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', true);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame(55.0, (float) $wallet->fresh()->balance);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This deposit request has already been processed.');

        $this->assertSame(55.0, (float) $wallet->fresh()->balance);
        Mail::assertQueued(DepositApproved::class, 1);
    }

    public function test_approve_refuses_card_deposits(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 40, 'card');

        try {
            app(ManualDepositApprovalService::class)->approve($deposit, $admin);
            $this->fail('Expected ManualDepositNotManualException');
        } catch (ManualDepositNotManualException $e) {
            $this->assertStringContainsString('Stripe', $e->getMessage());
        }

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        Mail::assertNothingQueued();
    }

    public function test_approve_refuses_manual_method_tied_to_stripe(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 40, 'bank');
        $deposit->update(['stripe_payment_intent_id' => 'pi_mixed_svc']);

        try {
            app(ManualDepositApprovalService::class)->approve($deposit->fresh(), $admin);
            $this->fail('Expected ManualDepositNotManualException');
        } catch (ManualDepositNotManualException $e) {
            $this->assertStringContainsString('Stripe', $e->getMessage());
        }

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        Mail::assertNothingQueued();
    }

    public function test_approve_refuses_zero_amount(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 0, 'bank');

        try {
            app(ManualDepositApprovalService::class)->approve($deposit, $admin);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        Mail::assertNothingQueued();
    }

    public function test_approve_still_succeeds_when_activity_log_throws(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 45, 'bank');

        ActivityLog::creating(function () {
            throw new \RuntimeException('activity log down');
        });

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame(45.0, (float) $wallet->fresh()->balance);
        Mail::assertQueued(DepositApproved::class, 1);
    }

    public function test_approve_still_succeeds_when_notifier_throws(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 30, 'wise');

        $this->mock(DepositSettlementNotifier::class, function ($mock) {
            $mock->shouldReceive('notifyApproved')
                ->once()
                ->andThrow(new \RuntimeException('notifier down'));
        });

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('email_sent', false);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame(30.0, (float) $wallet->fresh()->balance);
    }
}
