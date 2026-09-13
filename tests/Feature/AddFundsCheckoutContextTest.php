<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AddFundsCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsCheckoutContextTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    public function test_checkout_query_shows_banner_cover_chip_and_boot(): void
    {
        $query = AddFundsCheckout::query(40.5, 'wise');

        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds', $query))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('checkoutShortfallBanner', $html);
        $this->assertStringContainsString('Add at least €40.50 to finish checkout', $html);
        $this->assertStringContainsString('coverCheckoutBtn', $html);
        $this->assertStringContainsString('Cover checkout €40.50', $html);
        $this->assertStringContainsString('data-needed="40.50"', $html);
        $this->assertStringContainsString('advertiser/checkout', $html);
        $this->assertMatchesRegularExpression('/checkoutNeeded:\s*40\.5/', $html);
    }

    public function test_plain_add_funds_visit_has_no_checkout_banner(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('checkoutShortfallBanner', $html);
        $this->assertStringNotContainsString('coverCheckoutBtn', $html);
        $this->assertStringNotContainsString('Add at least €', $html);
        $this->assertMatchesRegularExpression('/checkoutNeeded:\s*null/', $html);
    }

    public function test_from_checkout_without_needed_does_not_show_banner(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds', ['from' => 'checkout', 'amount' => 40]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('checkoutShortfallBanner', $html);
        $this->assertStringNotContainsString('coverCheckoutBtn', $html);
    }

    public function test_needed_below_minimum_does_not_show_banner(): void
    {
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds', ['from' => 'checkout', 'needed' => 9.99]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('checkoutShortfallBanner', $html);
    }
}
