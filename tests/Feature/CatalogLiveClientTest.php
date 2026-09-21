<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogLiveClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function catalogJs(): string
    {
        return (string) file_get_contents(public_path('assets/js/catalog.js'));
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

    private function site(User $publisher, array $attrs = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Live Client '.uniqid(),
            'site_url' => 'https://live-client-'.uniqid().'.test',
            'domain' => 'live-client-'.uniqid().'.test',
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
            'description' => 'Live client fixture.',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    public function test_catalog_js_wires_live_fetch_instead_of_full_reload(): void
    {
        $js = $this->catalogJs();

        $this->assertStringContainsString('window.CatalogLive', $js);
        $this->assertStringContainsString('CatalogLive.apply', $js);
        $this->assertStringContainsString('AbortController', $js);
        $this->assertStringContainsString('CatalogConfig.routes.results', $js);
        $this->assertStringContainsString('CatalogConfig.liveSearch === false', $js);
        $this->assertStringContainsString('applyResultsHtml', $js);
        $this->assertStringContainsString('syncHideModeFromCard', $js);
        $this->assertStringContainsString('data-catalog-hide-mode', $js);
        $this->assertStringContainsString('popstate', $js);
        // Row actions must be delegated so swapped markup stays clickable.
        $this->assertStringContainsString("e.target.closest('.buy-now')", $js);
        $this->assertStringContainsString("e.target.closest('.favorite-btn')", $js);
        $this->assertStringContainsString("e.target.closest('.blacklist-btn')", $js);
        $this->assertStringContainsString("e.target.closest('.expand-arrow')", $js);
        $this->assertStringContainsString('function hydrateExpandScreenshots', $js);
        // Details opens only from the dedicated control — not whole-row / card-body clicks.
        $this->assertStringNotContainsString('Whole-row click toggles Details', $js);
        $this->assertStringNotContainsString('Mobile cards: same body-click toggle', $js);
        $this->assertStringNotContainsString('function catalogActionClick', $js);
        $this->assertStringNotContainsString("closest('tr.site-row')", $js);
        // Filter submit goes through live apply (full navigate is fallback only).
        $this->assertMatchesRegularExpression(
            '/function submitCatalogFilters\(options\) \{[\s\S]*?CatalogLive\.apply\(/s',
            $js
        );
    }

    public function test_filter_controls_share_the_live_apply_path(): void
    {
        $js = $this->catalogJs();
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        // Presets, multi-select, ranges, sponsored/new badge, and Reset all
        // feed CatalogLive rather than a full form GET.
        $this->assertStringContainsString('function scheduleCatalogFilterLive', $js);
        $this->assertStringContainsString('CATALOG_FILTER_LIVE_MS', $js);
        $this->assertStringContainsString('scheduleCatalogFilterLive({ replace: true })', $js);
        $this->assertStringContainsString("getElementById('new_badge')", $js);
        $this->assertStringContainsString("'price_min', 'price_max'", $js);
        $this->assertStringContainsString("'traffic_min', 'traffic_max'", $js);
        $this->assertStringContainsString('catalog-reset-filters', $js);
        $this->assertStringContainsString('catalog-reset-filters', $blade);
        $this->assertStringContainsString('id="catalogResetFilters"', $blade);
        $this->assertStringContainsString('syncMoreFiltersBadge', $js);
        $this->assertStringContainsString("'tag', 'favorites_filter', 'blacklist_filter'", $js);
        $this->assertStringContainsString("getElementById('bulk_deals')", $js);
        $this->assertStringContainsString("getElementById('on_sale')", $js);
        // Unchecked form checkboxes must not be revived from the URL in fromForm.
        $this->assertMatchesRegularExpression(
            '/el\.type === [\'"]checkbox[\'"][\s\S]{0,80}return;/s',
            $js
        );
        // Preset chips apply immediately after setting min/max.
        $this->assertMatchesRegularExpression(
            '/filter-preset[\s\S]*?submitCatalogFilters\(\)/s',
            $js
        );
    }

    public function test_live_ux_polish_guards_busy_state_and_announcements(): void
    {
        $js = $this->catalogJs();
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));

        $this->assertStringContainsString('Searching…', $js);
        $this->assertStringContainsString('Loading page…', $js);
        $this->assertStringContainsString('busyMinHeight', $js);
        $this->assertStringContainsString('maybeScrollResults', $js);
        $this->assertStringContainsString('syncSuggestButtons', $js);
        $this->assertStringContainsString('catalogLiveStatus', $js);
        $this->assertStringContainsString('id="catalogLiveStatus"', $blade);
        $this->assertStringContainsString('aria-live="polite"', $blade);
        $this->assertStringContainsString('lastAppliedQuery', $js);
        // Stale rows under the veil must not stay clickable.
        $this->assertStringContainsString('.catalog-results-card.is-busy > .card-body', $css);
        $this->assertStringContainsString('pointer-events: none', $css);
        $this->assertStringContainsString('intent: \'search\'', $js);
        $this->assertStringContainsString('intent: \'page\'', $js);

        // Busy overlay: declare the veil node (no ReferenceError) + timeout fallback.
        $this->assertMatchesRegularExpression(
            '/function markCatalogResultsBusy\([\s\S]*?const busy = card\.querySelector\(\'\.catalog-results-busy\'\)/s',
            $js
        );
        $this->assertStringContainsString('LIVE_FETCH_TIMEOUT_MS', $js);
        $this->assertStringContainsString('timedOut', $js);
        $this->assertStringContainsString('thisController', $js);
        $this->assertStringContainsString('fallbackNavigated', $js);
        $this->assertStringContainsString('.finally(function ()', $js);
        // Late timeout must abort only this request's controller, not a newer apply().
        $this->assertMatchesRegularExpression(
            '/const thisController =[\s\S]*?setTimeout\(function \(\) \{[\s\S]*?thisController\.abort\(\)/s',
            $js
        );
        // Fallback navigate keeps its busy veil — finally must not clear after handoff.
        $this->assertMatchesRegularExpression(
            '/fallbackNavigated = true;[\s\S]*?CatalogUrl\.navigate\(/s',
            $js
        );
        $this->assertStringContainsString('if (fallbackNavigated) return;', $js);
        // No-op same-query Apply must not push/replace history before bailing.
        $this->assertMatchesRegularExpression(
            '/lastAppliedQuery !== null && queryKey === lastAppliedQuery[\s\S]*?return Promise\.resolve\(\);[\s\S]*?CatalogUrl\.pushState\(params\)/s',
            $js
        );
        // Bulk rail follows Catalog country= via live fragment (Option 1).
        $this->assertStringContainsString('bulkDeals:', $blade);
        $this->assertStringContainsString('refreshBulkDeals', $js);
        $this->assertStringContainsString('catalogBulkHost', $blade);
        $this->assertStringContainsString('window.initBulkDealRail', $js);
        $this->assertStringContainsString('window.destroyBulkDealRail', $js);
    }

    public function test_results_fragment_exposes_count_meta_for_live_bar(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher, ['site_name' => 'Meta Count Site']);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', ['search' => 'Meta Count']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="catalogResults"[^>]*data-result-total="1"/', $html);
        $this->assertMatchesRegularExpression('/data-first-item="1"/', $html);
        $this->assertMatchesRegularExpression('/data-last-item="1"/', $html);
    }

    public function test_full_catalog_exposes_live_hooks_in_shell(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->site($publisher);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="catalogResultsCount"', $html);
        $this->assertStringContainsString('id="catalogActiveFiltersHost"', $html);
        $this->assertStringContainsString('id="catalogResetFilters"', $html);
        $this->assertStringContainsString('data-result-total=', $html);
        $this->assertStringContainsString('routes: {', $html);
        $this->assertStringContainsString('results:', $html);
    }
}
