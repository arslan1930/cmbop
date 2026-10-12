<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogPromotedSiteMatch;
use Tests\TestCase;

class AdvertiserCatalogApiSurfaceTest extends TestCase
{
    public function test_profile_route_is_shared_at_root_not_under_a_role(): void
    {
        $web = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("->name('profile')", $web);
        $this->assertStringContainsString("Route::get('/profile'", $web);
        $this->assertDoesNotMatchRegularExpression(
            '/prefix\(\'(advertiser|publisher)\'\).*name\(\'profile\'\)/s',
            $web
        );
    }

    public function test_catalog_api_routes_are_wired_without_touching_html_catalog_path(): void
    {
        $web = file_get_contents(base_path('routes/web.php'));
        $api = file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString("->name('api.index')", $web);
        $this->assertStringContainsString('ApiSettingsController', $web);
        $this->assertStringContainsString("Route::get('/catalog'", $api);
        $this->assertStringContainsString('AuthenticateAdvertiserCatalogApi', $api);
        $this->assertStringContainsString("->name('catalog')", $web);
    }

    public function test_website_hint_guesses_norway_from_tld_without_database(): void
    {
        $guess = app(CatalogPromotedSiteMatch::class)->fromUrl('https://my-rentacar.no');

        $this->assertSame('my-rentacar.no', $guess['host']);
        $this->assertSame('no', $guess['country']);
        $this->assertSame('no', $guess['language']);
        $this->assertNotSame('', $guess['category']);
    }

    public function test_openapi_describes_catalog_search_only(): void
    {
        $spec = file_get_contents(base_path('docs/openapi/advertiser-catalog-v1.yaml'));

        $this->assertStringContainsString('/catalog:', $spec);
        $this->assertStringContainsString('bearerAuth', $spec);
        $this->assertStringNotContainsString('/orders', $spec);
        $this->assertStringContainsString('/profile', $spec);
    }

    public function test_phase_zero_lock_is_written_and_matches_v1_surface(): void
    {
        $lock = file_get_contents(base_path('docs/advertiser-catalog-api.md'));
        $middleware = file_get_contents(base_path('app/Http/Middleware/AuthenticateAdvertiserCatalogApi.php'));
        $matcher = file_get_contents(base_path('app/Services/Catalog/CatalogPromotedSiteMatch.php'));
        $catalogApi = file_get_contents(base_path('app/Http/Controllers/Api/V1/CatalogSearchController.php'));
        $settings = file_get_contents(base_path('app/Http/Controllers/Advertiser/ApiSettingsController.php'));

        $this->assertStringContainsString('Phase 0 — locked product (v1)', $lock);
        $this->assertStringContainsString('Search only', $lock);
        $this->assertStringContainsString('Advertiser → API', $lock);
        $this->assertStringContainsString('hasRole(\'advertiser\')', $middleware);
        $this->assertStringContainsString('email_verified_at', $middleware);
        $this->assertStringContainsString('isSuspended', $middleware);
        $this->assertStringContainsString('TLD + host keywords only — no crawl', $matcher);
        $this->assertStringNotContainsString('Order', $catalogApi);
        $this->assertStringContainsString('advertiser.api.index', $settings);
    }
}
