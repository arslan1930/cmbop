<?php

namespace Tests\Feature;

use App\Mail\WithdrawalStatusUpdated;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Billing\WithdrawalPayoutStatementService;
use App\Services\Wallet\ManualWithdrawalInvalidTransitionException;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use App\Services\Wallet\ManualWithdrawalUnknownWalletException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManualWithdrawalSettlementServiceTest extends TestCase
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

    private function pendingWithdrawal(User $user, float $amount = 100, ?Wallet $wallet = null): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => $amount,
            'fee' => 0,
            'net_amount' => $amount,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes($wallet)));
    }

    private function advertiserWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function dualRoleUser(): User
    {
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        $publisher = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
        ]);
        $user->roles()->attach([$advertiser->id, $publisher->id]);

        return $user->fresh();
    }

    public function test_mark_paid_completes_pending_withdrawal(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 75);

        $result = app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin, 'Paid via Wise');

        $this->assertFalse($result['unchanged']);
        $this->assertSame('completed', $withdrawal->fresh()->status);
        $this->assertSame('Paid via Wise', $withdrawal->fresh()->admin_notes);
        $this->assertNotNull($withdrawal->fresh()->processed_at);
        Mail::assertQueued(WithdrawalStatusUpdated::class, 1);
    }

    public function test_reject_refunds_publisher_wallet(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 40);

        app(ManualWithdrawalSettlementService::class)->reject($withdrawal, $admin, 'Bad IBAN');

        $this->assertSame('cancelled', $withdrawal->fresh()->status);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
    }

    public function test_double_mark_paid_is_unchanged(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 50);

        $service = app(ManualWithdrawalSettlementService::class);
        $service->markPaid($withdrawal, $admin);
        $second = $service->markPaid($withdrawal->fresh(), $admin);

        $this->assertTrue($second['unchanged']);
        $this->assertSame('completed', $withdrawal->fresh()->status);
        Mail::assertQueued(WithdrawalStatusUpdated::class, 1);
        $this->assertSame(1, Invoice::query()
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('reference_code', 'WD-'.$withdrawal->id)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->count());
    }

    public function test_unchanged_mark_paid_retries_missing_payout_statement_without_renotify(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 50);
        $withdrawal->forceFill([
            'status' => 'completed',
            'processed_at' => now(),
        ])->save();

        $this->assertNull(app(WithdrawalPayoutStatementService::class)->find($withdrawal));

        $result = app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);

        $this->assertTrue($result['unchanged']);
        $this->assertNotNull(app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh()));
        Mail::assertNothingOutgoing();
    }

    public function test_cannot_reject_completed_withdrawal(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 30);

        $service = app(ManualWithdrawalSettlementService::class);
        $service->markPaid($withdrawal, $admin);

        $this->expectException(ManualWithdrawalInvalidTransitionException::class);
        $service->reject($withdrawal->fresh(), $admin);

        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
    }

    public function test_admin_http_mark_paid_uses_service(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 60);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.paid', $withdrawal->id), [
                'notes' => 'Sent',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $withdrawal->fresh()->status);
    }

    public function test_reject_refunds_advertiser_wallet_when_wallet_id_is_set(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->dualRoleUser();
        $publisherWallet = $this->publisherWallet($user, 5);
        $advertiserWallet = $this->advertiserWallet($user, 0);
        $withdrawal = $this->pendingWithdrawal($user, 40, $advertiserWallet);

        app(ManualWithdrawalSettlementService::class)->reject($withdrawal, $admin, 'Bad IBAN');

        $this->assertSame('cancelled', $withdrawal->fresh()->status);
        $this->assertSame(40.0, (float) $advertiserWallet->fresh()->balance);
        $this->assertSame(5.0, (float) $publisherWallet->fresh()->balance);
    }

    public function test_reject_uses_ledger_wallet_when_wallet_id_is_missing(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->dualRoleUser();
        $publisherWallet = $this->publisherWallet($user, 5);
        $advertiserWallet = $this->advertiserWallet($user, 0);
        $withdrawal = $this->pendingWithdrawal($user, 25);

        WalletTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $advertiserWallet->id,
            'type' => WalletTransaction::TYPE_WITHDRAWAL,
            'direction' => 'debit',
            'amount' => 25,
            'currency' => 'EUR',
            'status' => 'pending',
            'reference' => 'WD-'.$withdrawal->id,
            'related_type' => $withdrawal->getMorphClass(),
            'related_id' => $withdrawal->id,
        ]);

        app(ManualWithdrawalSettlementService::class)->reject($withdrawal, $admin, 'Ledger fallback');

        $this->assertSame(25.0, (float) $advertiserWallet->fresh()->balance);
        $this->assertSame(5.0, (float) $publisherWallet->fresh()->balance);
    }

    public function test_reject_does_not_guess_when_wallet_is_ambiguous(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->dualRoleUser();
        $publisherWallet = $this->publisherWallet($user, 5);
        $advertiserWallet = $this->advertiserWallet($user, 10);
        $withdrawal = $this->pendingWithdrawal($user, 40);

        try {
            app(ManualWithdrawalSettlementService::class)->reject($withdrawal, $admin, 'Ambiguous');
            $this->fail('Expected reject to fail when the source wallet is unknown.');
        } catch (ManualWithdrawalUnknownWalletException $e) {
            $this->assertStringContainsString('source wallet is unknown', $e->getMessage());
        }

        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertSame(5.0, (float) $publisherWallet->fresh()->balance);
        $this->assertSame(10.0, (float) $advertiserWallet->fresh()->balance);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.reject', $withdrawal->id), [
                'notes' => 'Ambiguous',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertSame(5.0, (float) $publisherWallet->fresh()->balance);
        $this->assertSame(10.0, (float) $advertiserWallet->fresh()->balance);
    }

    public function test_cannot_reopen_completed_or_cancelled_to_pending(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $service = app(ManualWithdrawalSettlementService::class);

        $completed = $this->pendingWithdrawal($publisher, 20);
        $service->markPaid($completed, $admin);

        try {
            $service->transition($completed->fresh(), 'pending', $admin);
            $this->fail('Expected completed withdrawals to stay closed.');
        } catch (ManualWithdrawalInvalidTransitionException $e) {
            $this->assertStringContainsString('Cannot reopen', $e->getMessage());
        }
        $this->assertSame('completed', $completed->fresh()->status);

        $cancelled = $this->pendingWithdrawal($publisher, 15);
        $service->reject($cancelled, $admin);
        try {
            $service->transition($cancelled->fresh(), 'pending', $admin);
            $this->fail('Expected cancelled withdrawals to stay closed.');
        } catch (ManualWithdrawalInvalidTransitionException $e) {
            $this->assertStringContainsString('Cannot reopen', $e->getMessage());
        }
        $this->assertSame('cancelled', $cancelled->fresh()->status);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.update-status', $completed->id), [
                'status' => 'pending',
            ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_processing_can_return_to_pending(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 30);

        $service = app(ManualWithdrawalSettlementService::class);
        $service->markProcessing($withdrawal, $admin);
        $result = $service->transition($withdrawal->fresh(), 'pending', $admin);

        $this->assertFalse($result['unchanged']);
        $this->assertSame('pending', $withdrawal->fresh()->status);
    }

    public function test_advertiser_http_withdraw_then_admin_reject_restores_advertiser_wallet(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->dualRoleUser();
        $publisherWallet = $this->publisherWallet($user, 80);
        $advertiserWallet = $this->advertiserWallet($user, 50);

        $this->actingAs($user)
            ->postJson(route('advertiser.balance.withdraw'), [
                'amount' => 20,
                'payment_method' => 'paypal',
                'business_name' => 'Acme Media',
                'paypal_email' => 'user@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $withdrawal = Withdrawal::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($advertiserWallet->id, (int) $withdrawal->wallet_id);
        $this->assertSame(30.0, (float) $advertiserWallet->fresh()->balance);
        $this->assertSame(80.0, (float) $publisherWallet->fresh()->balance);

        $this->actingAs($admin)
            ->postJson(route('admin.withdrawals.reject', $withdrawal->id), [
                'notes' => 'Rejected',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('cancelled', $withdrawal->fresh()->status);
        $this->assertSame(50.0, (float) $advertiserWallet->fresh()->balance);
        $this->assertSame(80.0, (float) $publisherWallet->fresh()->balance);
    }
}
