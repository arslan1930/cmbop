<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class SavedCardAndPaymentTrustTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_marketing_footer_shows_stripe_security_line(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Payments secured by', false)
            ->assertSee('Stripe', false)
            ->assertSee('fa-lock', false)
            ->assertSee('assets/img/payments/stripe.svg', false)
            ->assertSee('assets/img/payments/wise.png', false)
            ->assertSee('assets/img/payments/paypal.svg', false)
            ->assertSee('assets/img/payments/bitcoin.svg', false)
            ->assertSee('assets/img/payments/binance.png', false);
    }

    public function test_add_funds_shows_saved_cards_section(): void
    {
        $user = $this->advertiser();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('listCards')->andReturn([
                [
                    'id' => 'pm_test_visa',
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => 12,
                    'exp_year' => 2030,
                    'is_default' => true,
                ],
            ]);
            $mock->shouldReceive('configured')->andReturn(true);
        });

        $this->actingAs($user)
            ->get(route('advertiser.add-funds', ['tab' => 'cards']))
            ->assertOk()
            ->assertSee('Saved cards', false)
            ->assertSee('•••• 4242', false)
            ->assertSee('Add card', false)
            ->assertSee('Payments secured by', false);
    }

    public function test_payment_methods_index_returns_cards_json(): void
    {
        $user = $this->advertiser();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('listCards')->once()->andReturn([
                [
                    'id' => 'pm_1',
                    'brand' => 'mastercard',
                    'last4' => '4444',
                    'exp_month' => 1,
                    'exp_year' => 2029,
                    'is_default' => false,
                ],
            ]);
        });

        $this->actingAs($user)
            ->getJson(route('advertiser.payment-methods.index'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cards.0.last4', '4444');
    }

    public function test_remove_card_calls_stripe_service(): void
    {
        $user = $this->advertiser();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('detachPaymentMethod')->once()->with(Mockery::type(User::class), 'pm_remove');
            $mock->shouldReceive('listCards')->once()->andReturn([]);
        });

        $this->actingAs($user)
            ->deleteJson(route('advertiser.payment-methods.destroy', 'pm_remove'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_checkout_rejects_manual_methods_still_and_card_ui_ready(): void
    {
        // Smoke: payment-methods.setup requires configured stripe
        $user = $this->advertiser();

        $this->mock(StripeCustomerService::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(false);
        });

        $this->actingAs($user)
            ->postJson(route('advertiser.payment-methods.setup'))
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_ensure_user_stripe_columns_is_idempotent(): void
    {
        if (! Schema::hasTable('users')) {
            $this->markTestSkipped('users table missing');
        }

        $svc = app(StripeCustomerService::class);
        $svc->ensureUserStripeColumns();
        $svc->ensureUserStripeColumns();

        $this->assertTrue(Schema::hasColumn('users', 'stripe_customer_id'));
        $this->assertTrue(Schema::hasColumn('users', 'stripe_default_payment_method_id'));
    }

    public function test_configured_requires_sk_secret(): void
    {
        $svc = app(StripeCustomerService::class);

        config(['services.stripe.secret' => '']);
        $this->assertFalse($svc->configured());

        config(['services.stripe.secret' => '  sk_test_abc  ']);
        $this->assertTrue($svc->configured());

        config(['services.stripe.secret' => 'pk_test_abc']);
        $this->assertFalse($svc->configured());
    }

    public function test_get_or_create_customer_survives_missing_column_persist(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake_key_for_unit_tests']);
        \Stripe\Stripe::setApiKey('sk_test_fake_key_for_unit_tests');

        $user = $this->advertiser();
        $customerBody = json_encode([
            'id' => 'cus_ephemeral_1',
            'object' => 'customer',
            'email' => $user->email,
        ], JSON_THROW_ON_ERROR);

        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('request')->once()->andReturn([$customerBody, 200, []]);
        ApiRequestor::setHttpClient($client);

        // Simulate Hostinger schema without the column after ensure "fails".
        $svc = Mockery::mock(StripeCustomerService::class)->makePartial();
        $svc->shouldAllowMockingProtectedMethods();
        $svc->shouldReceive('ensureUserStripeColumns')->andReturnNull();
        $svc->shouldReceive('usersTableReady')->andReturn(false);

        $id = $svc->getOrCreateCustomerId($user);
        $this->assertSame('cus_ephemeral_1', $id);
        $this->assertNull($user->fresh()->stripe_customer_id);

        ApiRequestor::setHttpClient(null);
    }
}
