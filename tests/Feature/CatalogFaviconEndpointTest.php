<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogFaviconResolver;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogFaviconEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

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

        Storage::fake('public');
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Favicon Site',
            'site_url' => 'https://good-site.de',
            'domain' => 'good-site.de',
            'example_url' => 'https://good-site.de/sample',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Favicon catalog listing.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_missing_icon_returns_fallback_without_a_remote_fetch(): void
    {
        Http::fake();

        $site = $this->makeSite();

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.favicon', $site))
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml');

        Http::assertNothingSent();
        $this->assertFalse(Storage::disk('public')->exists(CatalogFaviconResolver::STORAGE_DIR.'/'.$site->id.'.png'));
        $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('assets/img/catalog-site-fallback.svg')));
    }

    public function test_stored_icon_is_served_from_disk(): void
    {
        Http::fake();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertNotFalse($png);

        $site = $this->makeSite();
        Storage::disk('public')->put(CatalogFaviconResolver::STORAGE_DIR.'/'.$site->id.'.png', $png);

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.favicon', $site))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        Http::assertNothingSent();
    }

    public function test_demo_hosts_return_the_theme_fallback_without_a_remote_fetch(): void
    {
        Http::fake();

        $site = $this->makeSite([
            'site_url' => 'https://demo16.com',
            'domain' => 'demo16.com',
            'example_url' => 'https://demo16.com/sample',
        ]);

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.favicon', $site))
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml');

        Http::assertNothingSent();
        $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('assets/img/catalog-site-fallback.svg')));
    }
}
