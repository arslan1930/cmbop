<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthAndMoneyHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_cron_auto_approve_is_disabled_without_a_strong_secret(): void
    {
        config(['app.cron_secret' => 'short']);

        $this->get('/cron/orders-auto-approve/short')->assertNotFound();
    }

    public function test_cron_auto_approve_rejects_a_wrong_secret(): void
    {
        config(['app.cron_secret' => str_repeat('a', 40)]);

        $this->get('/cron/orders-auto-approve/'.str_repeat('b', 40))->assertForbidden();
    }

    public function test_cron_accepts_header_secret_without_path_key(): void
    {
        $secret = str_repeat('c', 40);
        config(['app.cron_secret' => $secret]);

        $this->withHeaders(['X-Cron-Key' => $secret])
            ->post('/cron/run')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function throttledMoneyRoutes(): array
    {
        return [
            ['advertiser.add-funds.store'],
            ['advertiser.create-checkout-session'],
            ['advertiser.add-funds.pay-saved-card'],
            ['advertiser.create-order-payment'],
            ['advertiser.balance.withdraw'],
            ['publisher.withdraw.request'],
        ];
    }

    #[DataProvider('throttledMoneyRoutes')]
    public function test_money_endpoints_are_rate_limited(string $routeName): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} is missing");

        $hasThrottle = collect($route->gatherMiddleware())
            ->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        $this->assertTrue($hasThrottle, "Route {$routeName} must be throttled");
    }

    public function test_privileged_user_columns_are_not_mass_assignable(): void
    {
        $user = new User;

        foreach ([
            'email_verified_at',
            'can_activate_sites',
            'active_role_id',
            'google_token',
            'google_refresh_token',
            'stripe_customer_id',
            'stripe_default_payment_method_id',
            'payout_paypal_email',
            'payout_wise_email',
            'payout_bank_account',
            'payout_crypto_trx_wallet',
            'payout_profile_locked_at',
            'payout_preferred_method',
            'catalog_reveal_exempt',
            'catalog_reveal_exempt_until',
        ] as $column) {
            $this->assertFalse($user->isFillable($column), $column.' must not be mass-assignable');
        }

        $created = User::create([
            'name' => 'Mass Assign',
            'email' => 'mass-assign@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
            'can_activate_sites' => true,
            'active_role_id' => 99,
            'google_token' => 'stolen-token',
            'stripe_customer_id' => 'cus_stolen',
            'payout_paypal_email' => 'attacker@example.com',
            'catalog_reveal_exempt' => true,
        ]);

        $fresh = $created->fresh();
        $this->assertNull($fresh->email_verified_at);
        $this->assertFalse((bool) $fresh->can_activate_sites);
        $this->assertNull($fresh->active_role_id);
        $this->assertNull($fresh->google_token);
        $this->assertNull($fresh->stripe_customer_id);
        $this->assertNull($fresh->payout_paypal_email);
        $this->assertFalse((bool) $fresh->catalog_reveal_exempt);
    }
}
