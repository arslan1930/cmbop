<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogFilterChips;
use Tests\TestCase;

class CatalogFilterChipsTest extends TestCase
{
    public function test_price_chip_shows_the_filter_values(): void
    {
        $this->assertSame('Price ≤ €50', CatalogFilterChips::priceLabel(null, 50));
        $this->assertSame('Price ≥ €20', CatalogFilterChips::priceLabel(20, null));
        $this->assertSame('Price €10–€80', CatalogFilterChips::priceLabel(10, 80));
        $this->assertNull(CatalogFilterChips::priceLabel('', ''));
    }

    public function test_language_chip_uses_names_for_multi_select(): void
    {
        $names = ['en' => 'English', 'de' => 'German'];
        $this->assertSame('English', CatalogFilterChips::languageLabel('en', $names));
        $this->assertSame('English, German', CatalogFilterChips::languageLabel('en,de', $names));
        $this->assertSame('FR', CatalogFilterChips::languageLabel('fr', []));
    }
}
