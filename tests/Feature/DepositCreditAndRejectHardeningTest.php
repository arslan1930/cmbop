<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletStripeDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DepositCreditAndRejectHardeningTest extends TestCase
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

    public function test_checkout_session_credit_refuses_order_payment_metadata(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $session = (object) [
            'id' => 'cs_order_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 11500,
            'payment_intent' => 'pi_order_'.uniqid(),
            'metadata' => (object) [
                'type' => 'order_payment',
                'user_id' => (string) $advertiser->id,
                'amount' => '115.00',
                'reference_code' => 'ORD-REF-1',
            ],
        ];

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);

        $this->assertSame(0.0, $credited);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 0);
    }

    public function test_checkout_session_credit_accepts_wallet_deposit_metadata(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $sessionId = 'cs_wallet_'.uniqid();

        $session = (object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 5000,
            'payment_intent' => 'pi_wallet_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '50.00',
                'reference_code' => 'DEP-OK-50',
            ],
        ];

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);

        $this->assertSame(50.0, $credited);
        $this->assertSame(50.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('deposit_requests', [
            'stripe_session_id' => $sessionId,
            'status' => 'completed',
            'amount' => 50,
        ]);
    }

    public function test_checkout_session_credit_refuses_unpaid_status(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wallet_deposit session not paid');

        try {
            app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
                'id' => 'cs_unpaid_'.uniqid(),
                'payment_status' => 'unpaid',
                'amount_total' => 5000,
                'payment_intent' => 'pi_unpaid_'.uniqid(),
                'metadata' => (object) [
                    'type' => 'wallet_deposit',
                    'user_id' => (string) $advertiser->id,
                    'amount' => '50.00',
                    'reference_code' => 'DEP-UNPAID-50',
                ],
            ]);
        } finally {
            $this->assertSame(0.0, (float) $wallet->fresh()->balance);
            $this->assertSame(0, DepositRequest::count());
        }
    }

    public function test_payment_intent_object_credit_refuses_order_payment_metadata(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $intent = (object) [
            'id' => 'pi_order_'.uniqid(),
            'status' => 'succeeded',
            'amount' => 11500,
            'amount_received' => 11500,
            'metadata' => (object) [
                'type' => 'order_payment',
                'user_id' => (string) $advertiser->id,
                'amount' => '115.00',
                'reference_code' => 'ORD-PI-1',
            ],
        ];

        $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject($intent);

        $this->assertSame(0.0, $credited);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseCount('deposit_requests', 0);
    }

    public function test_payment_intent_object_credit_refuses_non_succeeded_status(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wallet_deposit PaymentIntent not succeeded');

        try {
            app(WalletStripeDepositService::class)->creditFromPaymentIntentObject((object) [
                'id' => 'pi_requires_'.uniqid(),
                'status' => 'requires_action',
                'amount' => 5000,
                'amount_received' => 0,
                'metadata' => (object) [
                    'type' => 'wallet_deposit',
                    'user_id' => (string) $advertiser->id,
                    'amount' => '50.00',
                    'reference_code' => 'DEP-PI-UNPAID',
                ],
            ]);
        } finally {
            $this->assertSame(0.0, (float) $wallet->fresh()->balance);
            $this->assertSame(0, DepositRequest::count());
        }
    }

    public function test_admin_reject_cannot_overwrite_approved_deposit(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-RACE-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $deposit->fresh()->status);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => 'Too late — already credited.',
            ])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This deposit request has already been processed.');

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertNull($deposit->fresh()->rejected_at);
    }

    public function test_admin_reject_still_works_for_pending_deposits(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-REJ-1',
            'amount' => 25,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.reject', $deposit->id), [
                'admin_notes' => 'No transfer found.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('rejected', $deposit->fresh()->status);
        $this->assertNotNull($deposit->fresh()->rejected_at);
    }

    public function test_same_payment_intent_cannot_credit_the_wallet_twice(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_once_'.uniqid();

        $first = app(WalletStripeDepositService::class)
            ->creditFromPaymentIntent($advertiser->id, $pi, 40.0, 'DEP-ONCE-1');
        $second = app(WalletStripeDepositService::class)
            ->creditFromPaymentIntent($advertiser->id, $pi, 40.0, 'DEP-ONCE-1');

        $this->assertSame(40.0, $first);
        $this->assertSame(40.0, $second);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_session_and_payment_intent_for_the_same_charge_credit_once(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_shared_'.uniqid();
        $sessionId = 'cs_shared_'.uniqid();

        $fromPi = app(WalletStripeDepositService::class)
            ->creditFromPaymentIntent($advertiser->id, $pi, 25.0, 'DEP-SHARED-1');

        $fromSession = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 2500,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '25.00',
                'reference_code' => 'DEP-SHARED-1',
            ],
        ]);

        $this->assertSame(25.0, $fromPi);
        $this->assertSame(25.0, $fromSession);
        $this->assertSame(25.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_checkout_session_then_payment_intent_credits_once(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_session_first_'.uniqid();
        $sessionId = 'cs_session_first_'.uniqid();

        $fromSession = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 3000,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '30.00',
                'reference_code' => 'DEP-SESSION-FIRST',
            ],
        ]);

        $fromPi = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject((object) [
            'id' => $pi,
            'status' => 'succeeded',
            'amount' => 3000,
            'amount_received' => 3000,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '30.00',
                'reference_code' => 'DEP-SESSION-FIRST',
            ],
        ]);

        $this->assertSame(30.0, $fromSession);
        $this->assertSame(30.0, $fromPi);
        $this->assertSame(30.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_payment_intent_with_deposit_id_completes_pending_row_once(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_dep_meta_'.uniqid();

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-META-1',
            'amount' => 80,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $first = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject((object) [
            'id' => $pi,
            'status' => 'succeeded',
            'amount' => 2000,
            'amount_received' => 2000,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '80.00',
                'reference_code' => 'DEP-META-1',
            ],
        ]);

        $second = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_dep_meta_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 2000,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '80.00',
            ],
        ]);

        $this->assertSame(20.0, $first);
        $this->assertSame(20.0, $second);
        $this->assertSame(20.0, (float) $wallet->fresh()->balance);
        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertEqualsWithDelta(20.0, (float) $deposit->fresh()->amount, 0.01);
        $this->assertSame(1, DepositRequest::where('user_id', $advertiser->id)->count());
    }

    public function test_orphan_pending_deposit_does_not_double_credit_after_pi_row(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_orphan_'.uniqid();

        $pending = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-ORPHAN-1',
            'amount' => 60,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $fromPi = app(WalletStripeDepositService::class)
            ->creditFromPaymentIntent($advertiser->id, $pi, 60.0, 'DEP-ORPHAN-PI');

        $fromPending = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_orphan_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 6000,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $pending->id,
                'amount' => '60.00',
            ],
        ]);

        $this->assertSame(60.0, $fromPi);
        $this->assertSame(60.0, $fromPending);
        $this->assertSame(60.0, (float) $wallet->fresh()->balance);
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertSame(1, DepositRequest::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_existing_deposit_is_completed_at_stripe_amount_not_request_amount(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-AMT-1',
            'amount' => 100,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_amt_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'payment_intent' => 'pi_amt_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '100.00',
            ],
        ]);

        $this->assertSame(10.0, $credited);
        $this->assertSame(10.0, (float) $wallet->fresh()->balance);
        $this->assertEqualsWithDelta(10.0, (float) $deposit->fresh()->amount, 0.01);
        $this->assertSame('completed', $deposit->fresh()->status);
    }

    public function test_complete_existing_deposit_refuses_other_users_row(): void
    {
        $owner = $this->advertiser();
        $other = $this->advertiser();
        $ownerWallet = $this->walletFor($owner);
        $otherWallet = $this->walletFor($other);

        $deposit = DepositRequest::create([
            'user_id' => $owner->id,
            'reference_code' => 'DEP-MISMATCH-1',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_mismatch_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_mismatch_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $other->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $ownerWallet->fresh()->balance);
        $this->assertSame(40.0, (float) $otherWallet->fresh()->balance);
        $this->assertSame('card', DepositRequest::query()
            ->where('user_id', $other->id)
            ->where('status', 'completed')
            ->value('payment_method'));
    }

    public function test_stripe_does_not_complete_a_rejected_bank_deposit(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-REJ-BANK-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_rej_bank_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_rej_bank_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        $this->assertSame('rejected', $deposit->fresh()->status);
        $this->assertNull($deposit->fresh()->stripe_payment_intent_id);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->count());
    }

    public function test_stripe_does_not_complete_a_pending_bank_deposit_via_deposit_id(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-BANK-ID-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_bank_id_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_bank_id_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertNull($deposit->fresh()->stripe_payment_intent_id);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->count());
    }

    public function test_stale_deposit_id_still_credits_a_new_card_row(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_stale_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 2500,
            'payment_intent' => 'pi_stale_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => '999999',
                'amount' => '25.00',
            ],
        ]);

        $this->assertSame(25.0, $credited);
        $this->assertSame(25.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->count());
    }

    public function test_completed_bank_deposit_id_does_not_swallow_a_new_card_charge(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $wallet->update(['balance' => 40]);

        $bank = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-DONE-BANK-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now()->subHour(),
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_done_bank_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 2500,
            'payment_intent' => 'pi_done_bank_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $bank->id,
                'amount' => '25.00',
            ],
        ]);

        $this->assertSame(25.0, $credited);
        $this->assertSame('completed', $bank->fresh()->status);
        $this->assertNull($bank->fresh()->stripe_payment_intent_id);
        $this->assertSame(65.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->count());
    }

    public function test_completed_card_with_same_payment_intent_does_not_credit_again(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_same_'.uniqid();
        $sessionId = 'cs_same_'.uniqid();

        $card = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-SAME-PI-1',
            'amount' => 30,
            'payment_method' => 'card',
            'status' => 'completed',
            'stripe_session_id' => $sessionId,
            'stripe_payment_intent_id' => $pi,
            'approved_at' => now()->subMinute(),
            'paid_at' => now()->subMinute(),
        ]);
        $wallet->update(['balance' => 30]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 3000,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $card->id,
                'amount' => '30.00',
            ],
        ]);

        $this->assertSame(30.0, $credited);
        $this->assertSame(30.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()->where('user_id', $advertiser->id)->count());
    }

    public function test_completed_card_with_a_new_payment_intent_credits_a_new_row(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $card = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-OLD-PI-1',
            'amount' => 30,
            'payment_method' => 'card',
            'status' => 'completed',
            'stripe_session_id' => 'cs_old_'.uniqid(),
            'stripe_payment_intent_id' => 'pi_old_'.uniqid(),
            'approved_at' => now()->subMinute(),
            'paid_at' => now()->subMinute(),
        ]);
        $wallet->update(['balance' => 30]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_new_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 1500,
            'payment_intent' => 'pi_new_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $card->id,
                'amount' => '15.00',
            ],
        ]);

        $this->assertSame(15.0, $credited);
        $this->assertSame(45.0, (float) $wallet->fresh()->balance);
        $this->assertSame(2, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->count());
    }

    public function test_untyped_session_with_bank_deposit_id_does_not_create_a_card_row(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-UNTYPED-BANK-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_untyped_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_untyped_'.uniqid(),
            'metadata' => (object) [
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(0.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, DepositRequest::query()->where('payment_method', 'card')->count());
    }

    public function test_order_payment_session_with_deposit_id_does_not_credit_wallet(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-ORDER-ID-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_order_dep_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_order_dep_'.uniqid(),
            'metadata' => (object) [
                'type' => 'order_payment',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(0.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, DepositRequest::query()->where('payment_method', 'card')->count());
    }

    public function test_untyped_payment_intent_with_stale_deposit_id_does_not_create_a_card_row(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject((object) [
            'id' => 'pi_untyped_stale_'.uniqid(),
            'status' => 'succeeded',
            'amount' => 4000,
            'amount_received' => 4000,
            'metadata' => (object) [
                'user_id' => (string) $advertiser->id,
                'deposit_id' => '999999',
                'amount' => '40.00',
                'reference_code' => 'DEP-UNTYPED-STALE',
            ],
        ]);

        $this->assertSame(0.0, $credited);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, DepositRequest::count());
    }

    public function test_untyped_payment_intent_with_bank_deposit_id_does_not_create_a_card_row(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-UNTYPED-PI-BANK',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject((object) [
            'id' => 'pi_untyped_bank_'.uniqid(),
            'status' => 'succeeded',
            'amount' => 4000,
            'amount_received' => 4000,
            'metadata' => (object) [
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
                'reference_code' => 'DEP-UNTYPED-PI-BANK',
            ],
        ]);

        $this->assertSame(0.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, DepositRequest::query()->where('payment_method', 'card')->count());
    }

    public function test_wallet_payment_intent_with_stale_deposit_id_still_credits_a_new_card_row(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject((object) [
            'id' => 'pi_wallet_stale_'.uniqid(),
            'status' => 'succeeded',
            'amount' => 2500,
            'amount_received' => 2500,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => '999999',
                'amount' => '25.00',
                'reference_code' => 'DEP-WALLET-STALE-PI',
            ],
        ]);

        $this->assertSame(25.0, $credited);
        $this->assertSame(25.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->count());
    }

    public function test_stripe_does_not_complete_a_bank_row_that_already_has_stripe_ids(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-BANK-LEFTOVER-PI',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
            'stripe_payment_intent_id' => 'pi_leftover_old_'.uniqid(),
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_bank_leftover_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_bank_leftover_'.uniqid(),
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('bank', $deposit->fresh()->payment_method);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->count());
    }

    public function test_pending_bank_with_matching_leftover_payment_intent_does_not_swallow_the_charge(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_bank_same_'.uniqid();
        $sessionId = 'cs_bank_same_'.uniqid();

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-BANK-SAME-PI',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'pending',
            'stripe_payment_intent_id' => $pi,
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertNull($deposit->fresh()->stripe_payment_intent_id);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertSame($pi, DepositRequest::query()
            ->where('user_id', $advertiser->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->value('stripe_payment_intent_id'));
    }

    public function test_pending_card_with_existing_session_id_is_credited_not_swallowed(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $sessionId = 'cs_pending_card_'.uniqid();
        $pi = 'pi_pending_card_'.uniqid();

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-PENDING-CS',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'pending',
            'stripe_session_id' => $sessionId,
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => $sessionId,
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame($pi, $deposit->fresh()->stripe_payment_intent_id);
        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()->where('user_id', $advertiser->id)->count());
    }

    public function test_rejected_card_with_payment_intent_is_credited(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $pi = 'pi_rej_card_'.uniqid();

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-REJ-CARD',
            'amount' => 25,
            'payment_method' => 'card',
            'status' => 'rejected',
            'rejected_at' => now(),
            'stripe_payment_intent_id' => $pi,
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntentObject((object) [
            'id' => $pi,
            'status' => 'succeeded',
            'amount' => 2500,
            'amount_received' => 2500,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $advertiser->id,
                'amount' => '25.00',
                'reference_code' => 'DEP-REJ-CARD',
            ],
        ]);

        $this->assertSame(25.0, $credited);
        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame(25.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, DepositRequest::query()->where('user_id', $advertiser->id)->count());
    }

    public function test_untyped_session_without_user_id_does_not_complete_a_pending_card(): void
    {
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);

        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-NO-USER',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_no_user_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => 'pi_no_user_'.uniqid(),
            'metadata' => (object) [
                'deposit_id' => (string) $deposit->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(0.0, $credited);
        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, DepositRequest::query()->where('status', 'completed')->count());
    }

    public function test_other_users_pending_card_with_same_payment_intent_still_credits_payer(): void
    {
        $owner = $this->advertiser();
        $payer = $this->advertiser();
        $ownerWallet = $this->walletFor($owner);
        $payerWallet = $this->walletFor($payer);
        $pi = 'pi_foreign_'.uniqid();

        $foreign = DepositRequest::create([
            'user_id' => $owner->id,
            'reference_code' => 'DEP-FOREIGN-PI',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'pending',
            'stripe_payment_intent_id' => $pi,
        ]);

        $credited = app(WalletStripeDepositService::class)->creditFromCheckoutSession((object) [
            'id' => 'cs_foreign_'.uniqid(),
            'payment_status' => 'paid',
            'amount_total' => 4000,
            'payment_intent' => $pi,
            'metadata' => (object) [
                'type' => 'wallet_deposit',
                'user_id' => (string) $payer->id,
                'deposit_id' => (string) $foreign->id,
                'amount' => '40.00',
            ],
        ]);

        $this->assertSame(40.0, $credited);
        $this->assertSame('pending', $foreign->fresh()->status);
        $this->assertNull($foreign->fresh()->stripe_payment_intent_id);
        $this->assertSame(0.0, (float) $ownerWallet->fresh()->balance);
        $this->assertSame(40.0, (float) $payerWallet->fresh()->balance);
        $this->assertSame($pi, DepositRequest::query()
            ->where('user_id', $payer->id)
            ->where('payment_method', 'card')
            ->where('status', 'completed')
            ->value('stripe_payment_intent_id'));
    }
}
