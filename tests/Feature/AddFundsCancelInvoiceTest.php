<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\StripePaymentService;
use App\Support\UserMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsCancelInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        Wallet::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id],
            [
                'balance' => 25,
                'reserved_balance' => 0,
                'bonus_balance' => 0,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]
        );

        return $user->fresh();
    }

    private function pendingInvoice(User $user, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $user->id,
            'reference_code' => '555001',
            'amount' => 40,
            'payment_method' => 'wise',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_owner_can_cancel_unused_pending_invoice(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingInvoice($user);
        $wallet = Wallet::where('user_id', $user->id)->first();
        $before = (float) $wallet->balance;

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.cancel', $deposit))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonMissingPath('exception');

        $this->assertSame('cancelled', $deposit->fresh()->status);
        $this->assertSame($before, (float) $wallet->fresh()->balance);
        $this->assertNull($deposit->fresh()->user_marked_paid_at);
    }

    public function test_other_user_cannot_cancel_invoice(): void
    {
        $owner = $this->advertiser(['email' => 'owner-cancel@example.com']);
        $other = $this->advertiser(['email' => 'other-cancel@example.com']);
        $deposit = $this->pendingInvoice($owner);

        $this->actingAs($other)
            ->postJson(route('advertiser.add-funds.cancel', $deposit))
            ->assertForbidden()
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('App\\Models');

        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_cannot_cancel_after_mark_paid(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingInvoice($user, [
            'user_marked_paid_at' => now(),
            'user_payment_note' => 'WISE-1',
        ]);

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.cancel', $deposit))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertTrue($deposit->fresh()->userHasMarkedPaid());
    }

    public function test_cannot_cancel_card_or_paypal_deposits(): void
    {
        $user = $this->advertiser();

        foreach (['card', 'paypal'] as $method) {
            $deposit = $this->pendingInvoice($user, [
                'reference_code' => $method === 'card' ? '555010' : '555011',
                'payment_method' => $method,
            ]);

            $this->actingAs($user)
                ->postJson(route('advertiser.add-funds.cancel', $deposit))
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('exception');

            $this->assertSame('pending', $deposit->fresh()->status);
        }
    }

    public function test_store_returns_cancel_url(): void
    {
        $user = $this->advertiser();

        $response = $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'bank',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('cancel_url'));
        $this->assertStringContainsString('/cancel', (string) $response->json('cancel_url'));
    }

    public function test_stripe_wallet_cancel_url_includes_cancelled_flag(): void
    {
        $this->assertStringContainsString('cancelled=1', StripePaymentService::walletDepositCancelUrl());
    }

    public function test_add_funds_cancelled_query_flashes_stripe_message(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds', ['cancelled' => 1]))
            ->assertOk()
            ->assertSee(UserMessages::get('payment.stripe_cancelled'), false)
            ->getContent();

        $this->assertStringContainsString('session-flash', (string) file_get_contents(resource_path('views/advertiser/layouts/app.blade.php')));
        $this->assertStringContainsString(UserMessages::get('payment.stripe_cancelled'), $html);
    }

    public function test_add_funds_canceled_spelling_also_flashes(): void
    {
        $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds', ['canceled' => 1]))
            ->assertOk()
            ->assertSee(UserMessages::get('payment.stripe_cancelled'), false);
    }

    public function test_activity_feed_includes_cancel_url_for_pending_invoice(): void
    {
        $user = $this->advertiser();
        $deposit = $this->pendingInvoice($user);

        $this->actingAs($user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'reference' => $deposit->reference_code,
                'status' => 'pending',
                'can_cancel' => true,
                'can_mark_paid' => true,
                'cancel_url' => route('advertiser.add-funds.cancel', $deposit),
            ]);
    }

    public function test_pending_banner_includes_cancel_button(): void
    {
        $user = $this->advertiser();
        $this->pendingInvoice($user);

        $html = $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('cancel-deposit-btn', $html);
        $this->assertStringContainsString('add-funds/'.$this->pendingInvoiceId($user).'/cancel', $html);
        $this->assertStringContainsString('invoiceReadyCancel', $html);
    }

    private function pendingInvoiceId(User $user): int
    {
        return (int) DepositRequest::query()->where('user_id', $user->id)->value('id');
    }
}
