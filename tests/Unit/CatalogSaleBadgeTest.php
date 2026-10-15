<?php

namespace Tests\Unit;

use App\Support\CatalogSaleBadge;
use Tests\TestCase;

class CatalogSaleBadgeTest extends TestCase
{
    public function test_percent_is_list_minus_displayed_over_list(): void
    {
        // andaluciainformacion.es-style fee floor: stored 20% paints ~10.7%.
        $this->assertSame(10.7, CatalogSaleBadge::percent(340.48, 304.0));
        $this->assertSame('10.7%', CatalogSaleBadge::label(340.48, 304.0));

        $this->assertSame(11.5, CatalogSaleBadge::percent(113.0, 100.0));
        $this->assertSame('11.5%', CatalogSaleBadge::label(113.0, 100.0));
    }

    public function test_badge_is_null_when_there_is_no_saving(): void
    {
        $this->assertNull(CatalogSaleBadge::percent(113.0, 113.0));
        $this->assertNull(CatalogSaleBadge::percent(0.0, 10.0));
        $this->assertNull(CatalogSaleBadge::label(113.0, 120.0));
    }
}
