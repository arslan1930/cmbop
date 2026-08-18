<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Mail;
use ReflectionClass;
use Tests\TestCase;

class CatalogUiRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function advertiser(array $attrs = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $u = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $u->roles()->attach($role->id);

        return $u->fresh();
    }

    private function advertiserInHideMode(): User
    {
        return $this->advertiser([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ]);
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $u = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $u->roles()->attach($role->id);

        return $u->fresh();
    }

    private function seedSites(int $count): void
    {
        $publisher = $this->publisher();
        for ($i = 1; $i <= $count; $i++) {
            Site::create([
                'publisher_id' => $publisher->id,
                'site_name' => "Catalog UI {$i}",
                'site_url' => "https://catalog-ui-{$i}.example",
                'domain' => "catalog-ui-{$i}.example",
                'example_url' => "https://catalog-ui-{$i}.example/sample",
                'da' => 30,
                'dr' => 35,
                'traffic' => 5000 + $i,
                'country' => 'us',
                'language' => 'en',
                'countries' => ['us'],
                'languages' => ['en'],
                'category' => 'marketing',
                'price' => 100,
                'turnaround_time' => '3days',
                'publication_time' => 'permanent',
                'link_type' => 'dofollow',
                'description' => 'Catalog UI regression listing used for pagination and reveal controls.',
                'verified' => true,
                'active' => true,
            ]);
        }
    }

    public function test_app_uses_bootstrap_five_pagination_not_tailwind_default(): void
    {
        $boot = file_get_contents((new ReflectionClass(AppServiceProvider::class))->getFileName());
        $this->assertStringContainsString('Paginator::useBootstrapFive()', $boot);

        $viewProp = (new ReflectionClass(Paginator::class))->getProperty('defaultView');
        $viewProp->setAccessible(true);
        $this->assertSame('pagination::bootstrap-5', $viewProp->getValue());
    }

    public function test_catalog_pagination_renders_bootstrap_controls_not_giant_svgs(): void
    {
        $this->seedSites(25);
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('pagination', $html);
        $this->assertStringContainsString('page-link', $html);
        $this->assertStringContainsString('catalog-pagination__mobile', $html);
        // Tailwind-only pagination chrome must not leak through.
        $this->assertStringNotContainsString('rtl:flex-row-reverse', $html);
        $this->assertStringNotContainsString('dark:bg-gray-700', $html);
    }

    public function test_catalog_eye_reveal_does_not_share_row_expand_handler(): void
    {
        $js = file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString("closest('.reveal-url, .toggle-url')", $js);
        $this->assertStringContainsString('stopImmediatePropagation', $js);
        $this->assertStringContainsString('catalogActionClick', $js);
        // Eye listeners are gated to hide mode, then capture-phase so reveal
        // runs before any leftover expand handlers.
        $this->assertStringContainsString('if (CatalogConfig && CatalogConfig.inCatalogHideMode) {', $js);
        // Copy tracking must stay bound when the page loaded in hide mode so
        // an admin lift / expiry + live search still records the next wave.
        $this->assertDoesNotMatchRegularExpression(
            '/if \(!copyTrackEndpoint\) return;\s*(?:\/\/[^\n]*\n\s*)*if \(CatalogConfig && CatalogConfig\.inCatalogHideMode\) return;/',
            $js
        );
        $this->assertStringContainsString('syncHideModeFromPayload', $js);
        // A sticky client flag after hide_mode would silence /copy-track if the
        // reload never happens and an admin later lifts (or the window expires).
        $this->assertStringNotContainsString('trackingStopped', $js);
        $this->assertStringContainsString('hideToastShown = false', $js);
        $this->assertStringContainsString('window.CatalogCopyTrack', $js);
        $this->assertMatchesRegularExpression(
            '/copy-example-url[\s\S]*?isVisitCopy[\s\S]*?CatalogCopyTrack\.report\(/',
            $js
        );
        $this->assertStringContainsString('const isVisitCopy = /\\/go\\/\\d+/.test(String(url || \'\'));', $js);
        // Details expand is a sibling <tr>, not .site-row — still count copies.
        $this->assertStringContainsString('.catalog-site-details', $js);
        // Table-cell copies often include a trailing newline; multi-select dumps
        // include several hosts. Do not drop the whole clipboard.
        $this->assertStringContainsString('extractDomainish', $js);
        $this->assertStringContainsString('.split(/[\\s,;|]+/)', $js);
        $this->assertDoesNotMatchRegularExpression(
            '/if \(!t \|\| t\.length > 500 \|\| \/\\\\r\|\\\\n\/\.test\(t\)\) return false;/',
            $js
        );
        $this->assertMatchesRegularExpression(
            '/inCatalogHideMode\)\s*\{[\s\S]*?addEventListener\(\s*[\'"]click[\'"]\s*,\s*function\s*\([^)]*\)\s*\{[\s\S]*?reveal-url[\s\S]*?\}\s*,\s*true\s*\)/',
            $js
        );
        // Whole-row Details toggle is delegated + exclusion-guarded (not a
        // per-row forEach), so eye / ↗ / Buy near-misses do not steal clicks.
        $this->assertStringContainsString("closest('tr.site-row')", $js);
        $this->assertStringContainsString('catalogActionClick(e)', $js);
        $this->assertDoesNotMatchRegularExpression(
            '/querySelectorAll\(\s*[\'"]\.site-row[\'"]\s*\)\.forEach\([^)]*toggleExpandRow/s',
            $js
        );
        // Multi-open: opening one row must not close siblings.
        $this->assertStringNotContainsString(
            "querySelectorAll('[class^=\"expanded-row-\"]')",
            $js
        );
        $this->assertStringContainsString('function hydrateExpandScreenshots', $js);
        $this->assertStringContainsString('hydrateExpandScreenshots(expandedRow)', $js);

        $this->seedSites(1);

        $normalHtml = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="url-reveal-', $normalHtml);
        $this->assertStringNotContainsString('catalog-url-eye', $normalHtml);
        $this->assertStringContainsString('expand-arrow', $normalHtml);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*copy-example-url[^"]*"[^>]*data-site-id="\d+"/',
            $normalHtml
        );
        $this->assertMatchesRegularExpression(
            '/copy-example-url[^>]*data-url="[^"]*\/advertiser\/go\/\d+\?sample=1/',
            html_entity_decode($normalHtml)
        );
        $this->assertDoesNotMatchRegularExpression(
            '/copy-example-url[^>]*data-url="https:\/\/catalog-ui-1\.example/',
            html_entity_decode($normalHtml)
        );

        $hideHtml = $this->actingAs($this->advertiserInHideMode())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="url-reveal-', $hideHtml);
        $this->assertStringContainsString('catalog-url-eye', $hideHtml);
        $this->assertStringContainsString('catalog-site-controls', $hideHtml);
        $this->assertStringContainsString('expand-arrow', $hideHtml);
        $this->assertStringContainsString('fa-eye', $hideHtml);
        // NEW / Verified / eye / open pack against the name (no flex-grow gap).
        $css = file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-site-controls', $css);
        $this->assertMatchesRegularExpression(
            '/\.catalog-site-name \{[\s\S]*?flex:\s*0 1 auto;/',
            $css
        );
        $this->assertStringContainsString('.catalog-site-rooted-url', $css);
        $this->assertMatchesRegularExpression(
            '/\.catalog-site-actions \.catalog-url-eye,[\s\S]*?\.site-open-link \{[\s\S]*?height:\s*20px;/',
            $css
        );
        // Order: eye immediately after the domain, then NEW, then Verified chip.
        $this->assertMatchesRegularExpression(
            '/catalog-site-controls[\s\S]*?catalog-url-eye[\s\S]*?site-badge-new[\s\S]*?site-chip--verified/s',
            $hideHtml
        );
        $this->assertStringContainsString('Verified Publisher', $hideHtml);
    }

    public function test_shell_css_caps_pagination_svg_size(): void
    {
        $css = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('.pagination svg', $css);
        $this->assertStringContainsString('max-width: 1.25rem', $css);
        $this->assertStringContainsString('.pagination .page-link', $css);
        $this->assertStringContainsString('min-height: 2.25rem', $css);
    }

    public function test_catalog_pagination_has_sized_wrapper_separate_from_results_text(): void
    {
        $this->seedSites(25);
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('catalog-pagination', $html);
        $this->assertStringContainsString('catalog-pagination__meta', $html);
        $this->assertStringContainsString('catalog-pagination__links', $html);
        // Meta must be live markup — not left commented out.
        $this->assertMatchesRegularExpression(
            '/class="catalog-pagination__meta"[^>]*>\s*Showing/s',
            $html
        );
        $this->assertStringContainsString('Page 1 of 2', $html);
        $this->assertStringContainsString('catalog-pagination__mobile', $html);
        $this->assertStringContainsString('catalog-pagination__desktop', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-pagination__links .page-link', $css);
        $this->assertStringContainsString('min-width: 2.25rem', $css);
    }

    public function test_catalog_pagination_is_hidden_on_a_single_page(): void
    {
        $this->seedSites(5);
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('catalog-pagination__meta', $html);
        $this->assertStringNotContainsString('catalog-pagination__links', $html);
    }
}
