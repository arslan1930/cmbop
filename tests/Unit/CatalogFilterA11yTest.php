<?php

namespace Tests\Unit;

use Tests\TestCase;

class CatalogFilterA11yTest extends TestCase
{
    public function test_catalog_filter_labels_and_preview_dimensions(): void
    {
        $blade = (string) file_get_contents(resource_path('views/advertiser/catalog.blade.php'));
        $results = (string) file_get_contents(resource_path('views/advertiser/partials/catalog-results.blade.php'));
        $tile = (string) file_get_contents(resource_path('views/advertiser/partials/catalog-site-tile.blade.php'));

        $this->assertStringContainsString('for="categoryMultiTrigger"', $blade);
        $this->assertStringContainsString('id="categoryMultiTrigger"', $blade);
        $this->assertStringContainsString('for="countryMultiTrigger"', $blade);
        $this->assertStringContainsString('id="countryMultiTrigger"', $blade);
        $this->assertStringContainsString('for="languageMultiTrigger"', $blade);
        $this->assertStringContainsString('id="languageMultiTrigger"', $blade);
        $this->assertStringContainsString('for="priceMinInput"', $blade);
        $this->assertStringContainsString('for="daMinInput"', $blade);
        $this->assertStringContainsString('for="drMinInput"', $blade);
        $this->assertStringContainsString('for="trafficMinInput"', $blade);
        $this->assertStringContainsString('aria-label="Maximum price in euros"', $blade);
        $this->assertStringContainsString('aria-label="Maximum Domain Authority"', $blade);
        $this->assertStringContainsString('aria-label="Maximum Domain Rating"', $blade);
        $this->assertStringContainsString('aria-label="Maximum monthly traffic"', $blade);
        $this->assertStringContainsString('for="bulk_deals"', $blade);
        $this->assertStringContainsString('for="featured"', $blade);
        $this->assertStringContainsString('for="on_sale"', $blade);
        $this->assertStringContainsString('for="new_badge"', $blade);
        $this->assertStringContainsString('for="catalogQualityGate"', $blade);
        $this->assertStringContainsString('for="catalogHasCompletions"', $blade);
        $this->assertStringContainsString('aria-label="More filters"', $blade);
        $this->assertStringContainsString('data-type="category"', $blade);
        $this->assertStringContainsString('data-type="country"', $blade);
        $this->assertStringContainsString('data-type="language"', $blade);
        $this->assertMatchesRegularExpression(
            '/data-type="category"[^>]*aria-label="\{\{ \$category \}\}"/',
            $blade
        );
        $this->assertMatchesRegularExpression(
            '/data-type="language"[^>]*aria-label="\{\{ \$name \}\}"/',
            $blade
        );
        $this->assertSame(2, preg_match_all('/data-type="country"[\s\S]*?aria-label="/', $blade));
        $this->assertStringContainsString('<span class="form-label fw-semibold small text-muted mb-1 d-none d-md-block" aria-hidden="true">&nbsp;</span>', $blade);
        $this->assertStringNotContainsString('d-none d-md-block">&nbsp;</label>', $blade);
        $this->assertStringNotContainsString('aria-labelledby="catalogPriceFilter"', $blade);
        $this->assertStringNotContainsString('aria-labelledby="catalogDaFilter"', $blade);
        $this->assertStringNotContainsString('aria-labelledby="catalogDrFilter"', $blade);
        $this->assertStringNotContainsString('aria-labelledby="catalogTrafficFilter"', $blade);

        $this->assertStringContainsString('width="300"', $results);
        $this->assertStringContainsString('height="188"', $results);
        $this->assertStringContainsString('width="220"', $results);
        $this->assertStringContainsString('height="138"', $results);
        $this->assertStringContainsString('for="catalogPerPage-trigger"', $results);
        $this->assertStringContainsString('catalog-pagination__perpage', $results);

        $this->assertStringContainsString('width="32"', $tile);
        $this->assertStringContainsString('height="32"', $tile);
        $this->assertStringContainsString('loading="lazy"', $tile);
        $this->assertSame(4, substr_count($tile, 'loading="lazy"'));

        $css = (string) file_get_contents(public_path('assets/css/catalog.css'));
        $promo = (string) file_get_contents(public_path('assets/css/promotions.css'));
        $this->assertStringContainsString('aspect-ratio: 1 / 1', $css);
        $this->assertStringContainsString('.catalog-tile__img', $css);
        $this->assertStringContainsString('aspect-ratio: var(--ad-w, 300) / var(--ad-h, 250)', $promo);
        $this->assertSame(3, substr_count($promo, 'aspect-ratio: var(--ad-w, 300) / var(--ad-h, 250)'));
        $this->assertStringNotContainsString('unsafe-eval', (string) file_get_contents(app_path('Http/Middleware/SecurityHeaders.php')));

        $trust = (string) file_get_contents(resource_path('views/partials/payment-trust.blade.php'));
        $this->assertStringContainsString('payment-trust__logo--visa', $trust);
        $this->assertStringContainsString('aspect-ratio: 48 / 30', $trust);
        $this->assertStringContainsString('aspect-ratio: 40 / 30', $trust);
        $this->assertStringContainsString('aspect-ratio: 72 / 36', $trust);
        $this->assertStringContainsString('aspect-ratio: 48 / 47', $trust);
        $this->assertStringContainsString('aspect-ratio: 72 / 16', $trust);
        $this->assertStringNotContainsString('width: auto;', $trust);

        $js = (string) file_get_contents(public_path('assets/js/catalog.js'));
        $this->assertStringContainsString('img.width = 72', $js);
        $this->assertStringContainsString('img.height = 52', $js);
        $this->assertMatchesRegularExpression('/^function catalogMoneyLabel\(/m', $js);
    }
}
