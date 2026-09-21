<?php

namespace Tests\Feature;

use App\Http\Controllers\Advertiser\CatalogListingController;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogImprovementsTest extends TestCase
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
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function site(User $publisher, array $attrs = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Improve '.uniqid(),
            'site_url' => 'https://improve-'.uniqid().'.example',
            'domain' => 'improve-'.uniqid().'.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Catalog improvement fixture.',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    public function test_listing_routes_use_catalog_listing_controller(): void
    {
        foreach (['advertiser.catalog', 'advertiser.catalog.results', 'advertiser.catalog.bulk-deals', 'advertiser.catalog.suggest'] as $name) {
            $this->assertSame(
                CatalogListingController::class,
                app('router')->getRoutes()->getByName($name)?->getControllerClass()
            );
        }
    }

    public function test_metric_search_canonicalizes_and_chip_clears_range(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, ['site_name' => 'High Improve DA', 'da' => 60]);
        $this->site($publisher, ['site_name' => 'Low Improve DA', 'da' => 20]);

        $redirect = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'tech da>=50']))
            ->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString('da_min=50', $location);
        $this->assertStringContainsString('search=tech', $location);
        $this->assertStringNotContainsString('da>=50', $location);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['da_min' => '50']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/name="da_min"[^>]*value="50"/', $html);
        $this->assertStringContainsString('High Improve DA', $html);
        $this->assertStringNotContainsString('Low Improve DA', $html);

        $this->assertStringContainsString('Remove filter: DA (Domain Authority)', $html);
    }

    public function test_live_results_expose_effective_query_after_metric_tokens(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site($this->userWithRole('publisher'), ['da' => 55]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', ['search' => 'da>=50']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-effective-query', $html);
        $this->assertStringContainsString('da_min', $html);
        $this->assertStringContainsString('50', $html);
    }

    public function test_unset_link_type_does_not_default_to_dofollow_chip(): void
    {
        $html = view('advertiser.partials.catalog-meta-chips', [
            'site' => new Site(['link_type' => null]),
        ])->render();

        $this->assertStringNotContainsString('DoFollow', $html);
        $this->assertStringNotContainsString('catalog-meta-chips', $html);
    }

    public function test_catalog_shell_has_tag_quick_suggest_and_delegated_category_toggle(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->site($this->userWithRole('publisher'), [
            'link_type' => '',
            'categories' => ['Marketing', 'Technology', 'Finance'],
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-tag-quick', $html);
        $this->assertStringContainsString('data-catalog-tag="sponsored"', $html);
        $this->assertStringContainsString('id="catalogSuggestList"', $html);
        $this->assertStringContainsString('Pay with wallet, card, or PayPal at checkout', $html);
        $this->assertStringNotContainsString('catalog-site-trust--row', $html);
        $this->assertStringContainsString('catalog-expand-trust', $html);
        $results = (string) file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'));
        $this->assertStringContainsString('toggle-cats-btn', $results);
        $this->assertStringNotContainsString('onclick=', $results);
        $this->assertStringContainsString('DEFAULT_PER_PAGE', $results);
        $this->assertStringNotContainsString('(Base price)', $results);
        $this->assertStringContainsString('(base price)', $results);
    }

    public function test_catalog_js_wires_suggest_and_category_toggle(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString('function initCatalogSuggest', $js);
        $this->assertStringContainsString('function initCatalogCategoryToggle', $js);
        $this->assertStringContainsString('function initCatalogTagQuick', $js);
        $this->assertStringContainsString('function initCatalogFavoritesQuick', $js);
        $this->assertStringContainsString('data-effective-query', $js);
        $this->assertStringContainsString('typedSearch', $js);
        $this->assertStringContainsString('keepTypedSearch', $js);
        $this->assertStringContainsString("options.intent === 'search'", $js);
        $this->assertStringContainsString('lastAppliedQuery = CatalogUrl.fromForm({ keepPage: true }).toString()', $js);
        $this->assertStringContainsString("addEventListener('blur'", $js);
        $this->assertStringContainsString('if (lastAppliedQuery !== current) return;', $js);
    }
}
