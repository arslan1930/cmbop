<?php

namespace Tests\Feature;

use App\Mail\DepositRefunded;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Billing\DepositReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPaypalDepositRefundTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function enablePaypal(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.webhook_id' => 'WH-TEST-1',
            'services.paypal.base_url' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $refund
     */
    private function fakePaypalRefund(string $captureId, array $refund = []): void
    {
        Http::fake(function ($request) use ($captureId, $refund) {
            $url = $request->url();
            if (str_contains($url, '/v1/oauth2/token')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'expires_in' => 300,
                    'token_type' => 'Bearer',
                ], 200);
            }

            if (str_contains($url, '/v2/payments/captures/'.$captureId.'/refund')) {
                $status = (int) ($refund['http'] ?? 201);
                if ($status >= 400) {
                    return Http::response([
                        'name' => (string) ($refund['name'] ?? 'INTERNAL_SERVER_ERROR'),
                        'details' => $refund['details'] ?? [],
                    ], $status);
                }

                return Http::response([
                    'id' => (string) ($refund['id'] ?? 'RF-'.$captureId),
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'EUR', 'value' => (string) ($refund['amount'] ?? '25.00')],
                ], $status);
            }

            return Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404);
        });
    }

    private function completedPaypalDeposit(User $advertiser, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $advertiser->id,
            'reference_code' => '888801',
            'amount' => 25,
            'payment_method' => 'paypal',
            'status' => 'completed',
            'paypal_order_id' => 'PO-ADMIN-RF',
            'paypal_capture_id' => 'CAP-ADMIN-RF',
            'approved_at' => now(),
            'paid_at' => now(),
        ], $overrides));
    }

    private function advertiserWallet(User $advertiser, float $balance, float $debt = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => $debt,
            'currency' => 'EUR',
        ]);
    }

    public function test_admin_can_refund_paypal_deposit_and_debit_wallet(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->enablePaypal();
        $this->fakePaypalRefund('CAP-ADMIN-RF');

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 40);
        $deposit = $this->completedPaypalDeposit($advertiser);
        $this->assertNotNull(app(DepositReceiptService::class)->issue($deposit));

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id), [
                'admin_notes' => 'Buyer asked for the Add Funds refund.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('already_refunded', false);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/payments/captures/CAP-ADMIN-RF/refund'));

        $this->assertSame('refunded', $deposit->fresh()->status);
        $receipt = Invoice::query()
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->where('reference_code', $deposit->reference_code)
            ->first();
        $this->assertNotNull($receipt);
        $this->assertSame(Invoice::STATUS_REFUNDED, $receipt->status);
        $this->assertSame('refunded', $receipt->payment_status);
        $this->assertEqualsWithDelta(15.0, (float) $wallet->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->fresh()->debt_balance, 0.01);
        $this->assertStringContainsString('Buyer asked for the Add Funds refund.', (string) $deposit->fresh()->admin_notes);

        Mail::assertQueued(DepositRefunded::class, 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deposit.paypal_refunded',
            'user_id' => $admin->id,
        ]);
    }

    public function test_replayed_refund_is_a_noop(): void
    {
        Mail::fake();
        $this->enablePaypal();
        $this->fakePaypalRefund('CAP-ADMIN-RF');

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 40);
        $deposit = $this->completedPaypalDeposit($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertOk()
            ->assertJsonPath('already_refunded', false);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('already_refunded', true);

        $this->assertEqualsWithDelta(15.0, (float) $wallet->fresh()->balance, 0.01);
        Mail::assertQueued(DepositRefunded::class, 1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deposit.paypal_refund_replayed',
        ]);
    }

    public function test_already_refunded_capture_still_reverses_wallet(): void
    {
        Mail::fake();
        $this->enablePaypal();
        $this->fakePaypalRefund('CAP-ADMIN-RF', [
            'http' => 422,
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'CAPTURE_FULLY_REFUNDED']],
        ]);

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 25);
        $deposit = $this->completedPaypalDeposit($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('already_refunded', false);

        $this->assertSame('refunded', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->fresh()->balance, 0.01);
    }

    public function test_paypal_api_failure_does_not_debit_wallet(): void
    {
        Mail::fake();
        $this->enablePaypal();
        $this->fakePaypalRefund('CAP-ADMIN-RF', [
            'http' => 500,
            'name' => 'INTERNAL_SERVER_ERROR',
        ]);

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 40);
        $deposit = $this->completedPaypalDeposit($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(40.0, (float) $wallet->fresh()->balance, 0.01);
        Mail::assertNothingQueued();
    }

    public function test_missing_capture_does_not_debit_wallet(): void
    {
        Mail::fake();
        $this->enablePaypal();
        Http::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 40);
        $deposit = $this->completedPaypalDeposit($advertiser, [
            'paypal_capture_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(40.0, (float) $wallet->fresh()->balance, 0.01);
        Http::assertNothingSent();
    }

    public function test_spent_deposit_refund_creates_wallet_debt(): void
    {
        Mail::fake();
        $this->enablePaypal();
        $this->fakePaypalRefund('CAP-ADMIN-RF');

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 8);
        $deposit = $this->completedPaypalDeposit($advertiser);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(17.0, (float) $wallet->debt_balance, 0.01);
        $this->assertNotNull($wallet->advertiserSpendBlockedReason());
    }

    public function test_bank_deposit_cannot_use_paypal_refund_action(): void
    {
        $this->enablePaypal();
        Http::fake();

        $admin = $this->userWithRole('admin');
        $advertiser = $this->userWithRole('advertiser');
        $wallet = $this->advertiserWallet($advertiser, 40);
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => '888802',
            'amount' => 25,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(40.0, (float) $wallet->fresh()->balance, 0.01);
        Http::assertNothingSent();
    }

    public function test_advertiser_cannot_refund_paypal_deposit(): void
    {
        $this->enablePaypal();
        Http::fake();

        $advertiser = $this->userWithRole('advertiser');
        $deposit = $this->completedPaypalDeposit($advertiser);
        $this->advertiserWallet($advertiser, 40);

        $this->actingAs($advertiser)
            ->postJson(route('admin.deposits.paypal-refund', $deposit->id))
            ->assertForbidden();

        $this->assertSame('completed', $deposit->fresh()->status);
        Http::assertNothingSent();
    }
}
