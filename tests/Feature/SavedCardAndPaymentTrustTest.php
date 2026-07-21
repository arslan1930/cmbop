<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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
}
