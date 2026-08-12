<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogHomepagePreviewTest extends TestCase
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
    }

    private function makeSite(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Preview Blog',
            'site_url' => 'https://preview-blog.example',
            'domain' => 'preview-blog.example',
            'example_url' => 'https://preview-blog.example/sample',
            'da' => 40,
            'dr' => 45,
            'traffic' => 9000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 120,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Catalog homepage preview listing.',
            'verified' => true,
            'active' => 1,
        ], $overrides));
    }

    public function test_homepage_preview_prefers_screenshot_over_site_image(): void
    {
        $site = $this->makeSite([
            'site_image' => 'sites/admin-upload.webp',
            'screenshot_path' => 'site-screenshots/home-full.webp',
            'screenshot_thumb_path' => 'site-screenshots/home-thumb.webp',
        ]);

        $this->assertStringContainsString(
            'site-screenshots/home-full.webp',
            (string) $site->screenshot_url
        );

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Homepage preview', $html);
        $this->assertStringContainsString('site-preview-zoom', $html);
        $this->assertStringContainsString('media/site-screenshots/home-full.webp', $html);
        $this->assertStringContainsString('/storage/site-screenshots/home-full.webp', $html);
        $this->assertStringContainsString('data-preview-chain', $html);
        // Cover is last in the onerror chain only — primary data-src must be the homepage capture.
        $this->assertMatchesRegularExpression(
            '/data-src="[^"]*\/media\/site-screenshots\/home-full\.webp"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-src="[^"]*sites\/admin-upload\.webp"/',
            $html
        );
        // Deferred data-src; hydrateExpandScreenshots promotes it on first open (Safari-safe).
        $this->assertMatchesRegularExpression(
            '/site-preview-zoom[\s\S]*?<img[^>]+data-src="[^"]*site-screenshots\/home-full\.webp"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/site-preview-zoom[\s\S]*?<img[^>]+class="[^"]*catalog-deferred-preview/',
            $html
        );
        // First open still hydrates any deferred data-src imgs (assets must exist).
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('function hydrateExpandScreenshots', $js);
        $this->assertStringContainsString('img.catalog-deferred-preview[data-src]', $js);
        $this->assertStringContainsString('window.catalogSitePreviewOnError', $js);
        $this->assertStringContainsString('hydrateExpandScreenshots(expandedRow)', $js);
        $this->assertStringContainsString('function syncDefaultHomepagePrices', $js);
        $this->assertStringContainsString('syncDefaultHomepagePrices()', $js);

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $this->assertStringContainsString('padding-top: 62.5%', $css);
        $this->assertStringContainsString('.site-preview-zoom img', $css);
        $this->assertStringContainsString('object-fit: contain', $css);
        // Hover zoom restored, gated for fine pointers + reduced-motion (Safari-safe).
        $this->assertStringContainsString('@media (hover: hover) and (pointer: fine)', $css);
        $this->assertStringContainsString('.site-preview-zoom:hover img', $css);
        $this->assertStringContainsString('transform: scale(1.08)', $css);
        $this->assertStringContainsString('transform-origin: center top', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function test_homepage_preview_falls_back_to_thumb_then_site_image(): void
    {
        $thumbOnly = $this->makeSite([
            'site_name' => 'Thumb Only',
            'site_url' => 'https://thumb-only.example',
            'domain' => 'thumb-only.example',
            'screenshot_thumb_path' => 'site-screenshots/thumb-only.webp',
        ]);

        $this->assertStringContainsString(
            'site-screenshots/thumb-only.webp',
            (string) $thumbOnly->screenshot_thumb_url
        );
        $this->assertStringStartsWith('/media/', (string) $thumbOnly->screenshot_thumb_url);

        $uploadOnly = $this->makeSite([
            'site_name' => 'Upload Only',
            'site_url' => 'https://upload-only.example',
            'domain' => 'upload-only.example',
            'site_image' => 'sites/cover-only.webp',
        ]);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('media/site-screenshots/thumb-only.webp', $html);
        $this->assertStringContainsString('/storage/site-screenshots/thumb-only.webp', $html);
        $this->assertStringContainsString('media/sites/cover-only.webp', $html);
        $this->assertStringContainsString('/storage/sites/cover-only.webp', $html);
    }

    public function test_broken_preview_fallback_beats_bootstrap_d_none(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $blade = (string) file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'));
        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));

        $this->assertStringContainsString(
            '.site-preview-zoom.is-broken + .site-preview-fallback { display: inline-flex !important; }',
            $css
        );
        $this->assertStringContainsString('catalogSitePreviewOnError', $blade);
        $this->assertStringContainsString('window.catalogSitePreviewOnError', $js);
        $this->assertStringContainsString("f.classList.remove('d-none')", $js);
        $this->assertStringContainsString('data-preview-chain', $blade);
    }
}
