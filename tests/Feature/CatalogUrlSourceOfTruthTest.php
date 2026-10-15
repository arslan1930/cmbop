<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogUrlSourceOfTruthTest extends TestCase
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
            'description' => 'URL source fixture.',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    public function test_refreshable_query_string_drives_form_and_results(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Url Truth Match',
            'da' => 75,
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->site($publisher, [
            'site_name' => 'Url Truth Miss',
            'da' => 20,
            'country' => 'us',
            'countries' => ['us'],
        ]);

        $query = [
            'search' => 'Url Truth',
            'da_min' => '50',
            'country' => 'de',
            'sort' => 'da_desc',
        ];

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', $query))
            ->assertOk()
            ->assertSee('Url Truth Match')
            ->assertDontSee('Url Truth Miss')
            ->getContent();

        $this->assertMatchesRegularExpression('/id="catalogSearchInput"[^>]*value="Url Truth"/', $html);
        $this->assertMatchesRegularExpression('/name="da_min"[^>]*value="50"/', $html);
        $this->assertMatchesRegularExpression('/id="selectedCountry"[^>]*value="de"/', $html);
        $this->assertMatchesRegularExpression('/id="catalogSort"[^>]*>[\s\S]*value="da_desc"[^>]*selected/', $html);
    }

    public function test_catalog_config_exposes_url_allowlist_for_js(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('queryKeys:', $html);
        $this->assertStringContainsString('catalogPath:', $html);
        $this->assertStringContainsString('defaultSort:', $html);
        foreach (CatalogUrlQuery::KEYS as $key) {
            $this->assertStringContainsString('"'.$key.'"', $html);
        }
    }

    public function test_partial_results_honor_the_same_query_string(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $this->site($publisher, [
            'site_name' => 'Partial Url Hit',
            'dr' => 88,
        ]);
        $this->site($publisher, [
            'site_name' => 'Partial Url Miss',
            'dr' => 10,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', [
                'search' => 'Partial Url',
                'dr_min' => 50,
            ]))
            ->assertOk()
            ->assertSee('Partial Url Hit')
            ->assertDontSee('Partial Url Miss');
    }

    public function test_chip_remove_url_uses_allowlisted_query(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, ['site_name' => 'Chip Url Site']);

        $html = $this->actingAs($advertiser)
            ->followingRedirects()
            ->get(route('advertiser.catalog', [
                'search' => 'Chip',
                'da_min' => 30,
                'da_max' => 60,
                'page' => 2,
                'utm_source' => 'should-not-leak',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('filter-chip__remove', $html);
        // Chip hrefs (and pagination) only carry the allowlisted listing query.
        preg_match_all('/href="([^"]+)"[^>]*class="filter-chip__remove"/', $html, $chipHrefs);
        $this->assertNotEmpty($chipHrefs[1]);
        foreach ($chipHrefs[1] as $href) {
            $this->assertStringNotContainsString('utm_source', urldecode($href));
            $this->assertStringNotContainsString('page=', urldecode($href));
        }
        // Removing the DA chip keeps search.
        $this->assertTrue(
            collect($chipHrefs[1])->contains(
                fn (string $href) => str_contains(urldecode($href), 'search=Chip')
            ),
            'Expected a chip-remove link that preserves search=Chip'
        );
    }
}
