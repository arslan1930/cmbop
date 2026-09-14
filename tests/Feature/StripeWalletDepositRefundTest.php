<?php

namespace Tests\Feature;

use App\Mail\DepositRefunded;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Billing\DepositReceiptService;
use App\Services\InAppNotificationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeWalletDepositRefundTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_stripe_deposit_refund';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
        Storage::fake('local');
        config(['services.stripe.webhook_secret' => $this->webhookSecret]);
    }

    private function advertiser(): User
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function signedWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
            ],
            $payload
        );
    }

    public function test_charge_refunded_reverses_wallet_and_marks_receipt_refunded(): void
    {
        $advertiser = $this->advertiser();
        $wallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $piId = 'pi_deposit_rf_'.uniqid();
        $this->signedWebhook([
            'id' => 'evt_credit_'.uniqid(),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $piId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 4000,
                    'amount_received' => 4000,
                    'currency' => 'eur',
                    'metadata' => [
                        'type' => 'wallet_deposit',
                        'user_id' => (string) $advertiser->id,
                        'amount' => '40.00',
                        'reference_code' => 'DEP-STRIPE-RF',
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertEqualsWithDelta(40.0, (float) $wallet->fresh()->balance, 0.01);
        $deposit = DepositRequest::where('stripe_payment_intent_id', $piId)->firstOrFail();
        $this->assertSame('completed', $deposit->status);
        $this->assertNotNull(app(DepositReceiptService::class)->issue($deposit));

        $this->signedWebhook([
            'id' => 'evt_refund_'.uniqid(),
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_deposit_rf',
                    'object' => 'charge',
                    'amount' => 4000,
                    'amount_refunded' => 4000,
                    'currency' => 'eur',
                    'payment_intent' => $piId,
                    'refunded' => true,
                    'refunds' => [
                        'object' => 'list',
                        'data' => [
                            ['id' => 're_deposit_rf', 'object' => 'refund', 'amount' => 4000],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('refunded', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->fresh()->balance, 0.01);

        $receipt = Invoice::query()
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->where('reference_code', $deposit->reference_code)
            ->first();
        $this->assertNotNull($receipt);
        $this->assertSame(Invoice::STATUS_REFUNDED, $receipt->status);
        $this->assertSame('refunded', $receipt->payment_status);

        Mail::assertQueued(DepositRefunded::class);

        $html = (new DepositRefunded($deposit->fresh(['user'])))->render();
        $this->assertStringContainsString('Card deposit refunded', $html);
        $this->assertStringContainsString('from your Card Add Funds deposit', $html);
        $this->assertStringNotContainsString('PayPal deposit refunded', $html);
    }

    public function test_card_deposit_refund_email_includes_stripe_wallet_debt(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-CARD-DEBT',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'refunded',
            'stripe_response' => [
                'refund' => [
                    'id' => 're_debt',
                    'amount' => 40,
                    'debited' => 12,
                    'debt_created' => 28,
                ],
            ],
        ]);

        $html = (new DepositRefunded($deposit->fresh(['user'])))->render();
        $this->assertStringContainsString('Card deposit refunded', $html);
        $this->assertStringContainsString('Outstanding wallet debt', $html);
        $this->assertStringContainsString('€28.00', $html);
        $this->assertStringNotContainsString('PayPal', $html);

        app(InAppNotificationService::class)->notifyDepositRefunded($deposit->fresh());

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $advertiser->id,
            'title' => 'Card deposit refunded — €40.00',
        ]);
    }

    public function test_charge_refunded_for_unknown_intent_is_a_noop(): void
    {
        $this->signedWebhook([
            'id' => 'evt_rf_unknown_'.uniqid(),
            'object' => 'event',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_unknown',
                    'object' => 'charge',
                    'amount' => 1000,
                    'amount_refunded' => 1000,
                    'currency' => 'eur',
                    'payment_intent' => 'pi_not_a_deposit',
                    'refunded' => true,
                    'refunds' => [
                        'object' => 'list',
                        'data' => [['id' => 're_unknown', 'object' => 'refund', 'amount' => 1000]],
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame(0, DepositRequest::query()->count());
    }
}
