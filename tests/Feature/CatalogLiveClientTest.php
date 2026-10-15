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
        $this->assertStringContainsString('.expand-arrow, .catalog-card-details-toggle', $js);
        $this->assertStringContainsString('function hydrateExpandScreenshots', $js);
        $this->assertStringContainsString('.site-row[data-id]', $js);
        $this->assertStringContainsString('.catalog-mobile-card[data-id]', $js);
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

    public function test_page_one_live_pagination_does_not_leave_overlay_stuck(): void
    {
        $js = $this->catalogJs();

        // page=1 is stripped so share URLs stay clean — the page-1 link is
        // often ?page=1 or no page param, which canonicalize to the same key.
        $this->assertMatchesRegularExpression(
            '/if \(key === [\'"]page[\'"] && value === [\'"]1[\'"]\) return;/',
            $js
        );

        // fromForm must restore page= from the URL when keepPage is true.
        // An unconditional `if (key === 'page') return` made lastAppliedQuery
        // look like page 1 after a page-2 fetch.
        $this->assertDoesNotMatchRegularExpression(
            '/KEYS\.forEach\(function \(key\) \{[\s\S]{0,220}?if \(key === [\'"]page[\'"]\) return;/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/if \(key === [\'"]page[\'"] && !options\.keepPage\) return;/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/if \(!options\.keepPage\) \{\s*raw\.delete\([\'"]page[\'"]\);/s',
            $js
        );

        // Same-query no-op also requires the browser URL to match, then hides
        // the overlay (a sibling click handler used to raise it first).
        $this->assertMatchesRegularExpression(
            '/queryKey === lastAppliedQuery[\s\S]{0,180}?queryKey === locationKey[\s\S]{0,400}?clearCatalogResultsBusy\(\)[\s\S]{0,80}?return Promise\.resolve\(\)/s',
            $js
        );

        // Live pagination / chip / clear-all clicks must not pre-mark busy.
        $this->assertMatchesRegularExpression(
            '/#catalogResults \.pagination a\.page-link[\s\S]{0,220}?filter-chip__remove[\s\S]{0,160}?catalog-clear-all[\s\S]{0,80}?return;/s',
            $js
        );

        // Overlay always has a timeout fallback; apply() still clears in finally.
        $this->assertStringContainsString('CATALOG_BUSY_FALLBACK_MS', $js);
        $this->assertStringContainsString('armCatalogBusyFallback', $js);
        $this->assertMatchesRegularExpression(
            '/function markCatalogResultsBusy\([\s\S]*?armCatalogBusyFallback\(\)/s',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/function clearCatalogResultsBusy\(\) \{[\s\S]*?catalogBusyFallbackTimer/s',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/\.finally\(function \(\) \{[\s\S]*?if \(fallbackNavigated\) return;[\s\S]*?clearCatalogResultsBusy\(\)/s',
            $js
        );

        // Back/forward always fetches (force), per-page restarts at page 1.
        $this->assertMatchesRegularExpression(
            '/addEventListener\([\'"]popstate[\'"][\s\S]{0,180}?applyFromLocation\([\'"]none[\'"]\)/s',
            $js
        );
        $this->assertStringContainsString('force: true', $js);
        $this->assertMatchesRegularExpression(
            '/getElementById\([\'"]catalogPerPage[\'"]\)[\s\S]{0,280}?submitCatalogFilters\(\{ replace: true/s',
            $js
        );
        $this->assertStringContainsString("keepPage: true, intent: 'page'", $js);
    }

    public function test_page_one_live_pagination_node_contract(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for the catalog live page contract');
        }

        $script = base_path('tests/js/catalog-live-page.test.mjs');
        $this->assertFileExists($script);

        $command = escapeshellarg($node).' --test '.escapeshellarg($script).' 2>&1';
        exec($command, $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
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
