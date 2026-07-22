<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class ConversionAndTrustTest extends TestCase
{
    use CreatesContentSubmissions;
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

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function activeSite(User $publisher, string $slug = 'trust', float $price = 40): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 500,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_homepage_payment_trust_omits_paypal_and_links_refund_policy(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertSee('See refund policy', false)
            ->assertSee('refund-policy', false)
            ->assertSee('Wallet refund if a publisher cannot deliver', false)
            ->getContent();

        $this->assertStringNotContainsString('alt="PayPal"', $html);
        $this->assertStringNotContainsString('paypal.svg', $html);
    }

    public function test_catalog_cart_includes_buy_confidence_strip(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('buy-confidence', false)
            ->assertSee('Price shown is what you pay', false)
            ->assertSee('wallet refund per our refund policy', false)
            ->assertDontSee('alt="PayPal"', false);
    }

    public function test_checkout_includes_buy_confidence_and_no_paypal(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'conv-trust', 40);
        $sub = $this->createApprovedSubmission($advertiser, null);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'price' => 46,
                    'language' => 'en',
                ]],
            ])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->assertSee('buy-confidence', false)
            ->assertSee('Price shown is what you pay', false)
            ->assertSee('See refund policy', false)
            ->assertDontSee('alt="PayPal"', false)
            ->assertDontSee('paypal.svg', false);
    }
}
