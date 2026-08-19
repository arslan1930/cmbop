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

    public function test_homepage_payment_trust_shows_paypal_and_links_refund_policy(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->assertSee('See refund policy', false)
            ->assertSee('refund-policy', false)
            ->assertSee('Wallet refund if a publisher cannot deliver', false)
            ->getContent();

        $this->assertStringContainsString('alt="PayPal"', $html);
        $this->assertStringContainsString('paypal.svg', $html);
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
            ->assertSee('alt="PayPal"', false);
    }

    public function test_checkout_includes_buy_confidence_and_paypal_tile(): void
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
            ->assertSee('data-method="paypal"', false)
            ->assertSee('assets/img/payments/paypal.svg', false)
            ->assertSee('assets/img/payments/visa.svg', false)
            ->assertSee('assets/img/payments/mastercard.svg', false)
            ->assertDontSee('fab fa-stripe', false)
            ->assertSee('alt="PayPal"', false);
    }

    public function test_checkout_paypal_new_badge_shows_only_when_paypal_is_online(): void
    {
        config([
            'content_moderation.enabled' => false,
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'feature_badges.checkout.paypal' => [
                'label' => 'New',
                'until' => now()->addWeek()->toDateString(),
            ],
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'badge-on', 40);
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
            ->assertSee('>New</span>', false);
    }

    public function test_checkout_paypal_new_badge_is_hidden_when_paypal_is_offline(): void
    {
        config([
            'content_moderation.enabled' => false,
            'services.paypal.client_id' => '',
            'services.paypal.secret' => '',
            'feature_badges.checkout.paypal' => [
                'label' => 'New',
                'until' => now()->addWeek()->toDateString(),
            ],
        ]);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'badge-off', 40);
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
            ->assertSee('data-method="paypal"', false)
            ->assertDontSee('>New</span>', false);
    }

    public function test_trust_strip_shows_paypal_when_configured(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('assets/img/payments/paypal.svg', false)
            ->assertSee('alt="PayPal"', false);
    }
}
