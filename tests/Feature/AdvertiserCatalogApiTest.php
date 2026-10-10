<?php

namespace Tests\Feature;

use App\Models\AdvertiserApiToken;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Oslo Rentals',
            'site_url' => 'https://oslo-rentals.no',
            'domain' => 'oslo-rentals.no',
            'example_url' => 'https://oslo-rentals.no/sample',
            'da' => 35,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'no',
            'language' => 'no',
            'price' => 90,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'API listing fixture.',
            'verified' => true,
            'active' => 1,
        ]);
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->getJson('/api/v1/catalog')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_advertiser_token_returns_catalog_rows(): void
    {
        $issued = AdvertiserApiToken::issue($this->advertiser, 'Claude');

        $this->getJson('/api/v1/catalog?country=no&price_max=150', [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('sites.0.id', $this->site->id)
            ->assertJsonPath('sites.0.host', 'oslo-rentals.no')
            ->assertJsonPath('sites.0.price.currency', 'EUR');
    }

    public function test_website_hint_fills_empty_country(): void
    {
        $issued = AdvertiserApiToken::issue($this->advertiser, 'Hint');

        $this->getJson('/api/v1/catalog?website=https://my-rentacar.no', [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])
            ->assertOk()
            ->assertJsonPath('website', 'my-rentacar.no')
            ->assertJsonPath('filters.country', 'no');
    }

    public function test_publisher_cannot_use_catalog_api_even_with_a_token_row(): void
    {
        $issued = AdvertiserApiToken::issue($this->publisher, 'Wrong role');

        $this->getJson('/api/v1/catalog', [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])
            ->assertForbidden()
            ->assertJsonPath('status', 'error');
    }

    public function test_unverified_advertiser_is_blocked(): void
    {
        $this->advertiser->forceFill(['email_verified_at' => null])->save();
        $issued = AdvertiserApiToken::issue($this->advertiser, 'Unverified');

        $this->getJson('/api/v1/catalog', [
            'Authorization' => 'Bearer '.$issued['plain'],
        ])->assertForbidden();
    }
}
