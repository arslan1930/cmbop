<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogLiveResultsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $u = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $u->roles()->attach($roleModel->id);

        return $u->fresh();
    }

    private function site(User $publisher, array $attrs): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Listing '.uniqid(),
            'site_url' => 'https://example-'.uniqid().'.test',
            'domain' => 'example-'.uniqid().'.test',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Live results fixture.',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    public function test_results_endpoint_returns_matching_fragment_without_shell(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Alpha Live Journal',
            'dr' => 80,
        ]);
        $this->site($publisher, [
            'site_name' => 'Beta Quiet Notes',
            'dr' => 20,
        ]);

        $response = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', ['search' => 'Alpha Live']));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="catalogResults"', $html);
        $this->assertStringContainsString('Alpha Live Journal', $html);
        $this->assertStringNotContainsString('Beta Quiet Notes', $html);

        // Fragment only — no full catalog chrome / filter form shell.
        $this->assertStringNotContainsString('id="filterForm"', $html);
        $this->assertStringNotContainsString('window.CatalogConfig', $html);
        $this->assertStringNotContainsString('<html', strtolower($html));
    }

    public function test_results_endpoint_respects_metric_filters_and_sort(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'High DA Filter Hit',
            'da' => 70,
            'dr' => 10,
        ]);
        $this->site($publisher, [
            'site_name' => 'Low DA Filter Miss',
            'da' => 15,
            'dr' => 90,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', [
                'da_min' => 50,
                'sort' => 'da_desc',
            ]))
            ->assertOk()
            ->assertSee('High DA Filter Hit')
            ->assertDontSee('Low DA Filter Miss');
    }

    public function test_results_pagination_links_point_at_full_catalog(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        for ($i = 0; $i < 21; $i++) {
            $this->site($publisher, [
                'site_name' => 'Paged Site '.$i,
                'dr' => 100 - $i,
            ]);
        }

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', ['search' => 'Paged Site']))
            ->assertOk()
            ->getContent();

        $catalogPath = parse_url(route('advertiser.catalog'), PHP_URL_PATH);
        $resultsPath = parse_url(route('advertiser.catalog.results'), PHP_URL_PATH);

        $this->assertStringContainsString($catalogPath, $html);
        $this->assertStringNotContainsString($resultsPath.'?', $html);
        $this->assertStringNotContainsString($resultsPath.'"', $html);
    }

    public function test_full_catalog_includes_same_results_partial(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Shell Include Target',
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'Shell Include']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="catalogResults"', $html);
        $this->assertStringContainsString('Shell Include Target', $html);
        $this->assertStringContainsString('id="filterForm"', $html);
    }

    public function test_results_and_bulk_deals_are_throttled(): void
    {
        foreach (['advertiser.catalog.results', 'advertiser.catalog.bulk-deals'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertTrue(
                collect($route->gatherMiddleware())->contains(
                    fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:60,1')
                ),
                $name
            );
        }
    }

    public function test_guest_cannot_fetch_results(): void
    {
        $this->get(route('advertiser.catalog.results'))
            ->assertRedirect();
    }

    public function test_results_endpoint_sets_no_store_cache_header(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
