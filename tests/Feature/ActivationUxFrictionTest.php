<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivationUxFrictionTest extends TestCase
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

    private function verifiedSite(): Site
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $publisher = User::factory()->create(['email_verified_at' => now()]);
        $publisher->roles()->attach($publisherRole->id);

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Activation Teaser Site',
            'site_url' => 'https://teaser-example.com',
            'domain' => 'teaser-example.com',
            'da' => 45,
            'dr' => 52,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'en',
            'countries' => ['de'],
            'languages' => ['en'],
            'category' => 'Marketing, PR & Advertising',
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Teaser inventory',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_guest_marketplace_shows_verified_site_teasers(): void
    {
        $this->verifiedSite();

        $this->get(route('marketplace'))
            ->assertOk()
            ->assertSee('Sample verified inventory', false)
            ->assertSee('Activation Teaser Site', false)
            ->assertSee('t********.com', false)
            ->assertSee('/register', false);
    }

    public function test_advertiser_campaigns_redirects_to_dashboard(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.campaigns'))
            ->assertRedirect(route('advertiser.dashboard'));
    }

    public function test_register_page_clarifies_dual_role_starting_workspace(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('both Advertiser and Publisher are included', false)
            ->assertSee('Starting workspace', false)
            ->assertSee('€20 welcome credit', false);
    }

    public function test_new_advertiser_dashboard_leads_with_catalog(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.dashboard'))
            ->assertOk()
            ->assertSee('Browse catalog', false)
            ->assertSee(route('advertiser.catalog'), false)
            ->assertSee('Prefer a guided flow?', false)
            ->assertSee(route('advertiser.wizard.start'), false)
            ->assertDontSee('Guided path to your first guest post', false);
    }
}
