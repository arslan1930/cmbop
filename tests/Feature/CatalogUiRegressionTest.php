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

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $u = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $u->roles()->attach($role->id);

        return $u->fresh();
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

        $this->assertStringContainsString('class="pagination"', $html);
        $this->assertStringContainsString('page-link', $html);
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
        // Capture-phase listener so reveal runs before any leftover expand handlers.
        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*[\'"]click[\'"]\s*,\s*function\s*\([^)]*\)\s*\{[\s\S]*?reveal-url[\s\S]*?\}\s*,\s*true\s*\)/',
            $js
        );
        // Whole-row click must not expand Details — that stole eye clicks.
        $this->assertStringContainsString('Details only', $js);
        $this->assertDoesNotMatchRegularExpression(
            '/querySelectorAll\(\s*[\'"]\.site-row[\'"]\s*\)\.forEach\([^)]*toggleExpandRow/s',
            $js
        );

        $this->seedSites(1);
        $html = $this->actingAs($this->advertiser())
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('reveal-url', $html);
        $this->assertStringContainsString('catalog-url-eye', $html);
        $this->assertStringContainsString('catalog-site-controls', $html);
        $this->assertStringContainsString('expand-arrow', $html);
        $this->assertStringContainsString('fa-eye', $html);
        // Verified / NEW / eye / open share one centerline cluster on the domain row.
        $css = file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-site-controls', $css);
        $this->assertMatchesRegularExpression(
            '/\.catalog-site-actions \.catalog-url-eye \{[\s\S]*?height:\s*22px;/',
            $css
        );
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

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('.catalog-pagination__links .page-link', $css);
        $this->assertStringContainsString('min-width: 2.25rem', $css);
    }
}
