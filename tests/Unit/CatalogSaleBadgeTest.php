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

    public function test_offer_text_uses_the_translated_off_suffix(): void
    {
        $this->assertSame('11.5% off', CatalogSaleBadge::offerText(113.0, 100.0));
        $this->assertNull(CatalogSaleBadge::offerText(113.0, 113.0));
    }

    public function test_bulk_note_only_when_pack_savings_beat_the_sale(): void
    {
        $this->assertNull(CatalogSaleBadge::bulkNote(13.0, 13.0, 3));
        $this->assertNull(CatalogSaleBadge::bulkNote(11.5, 11.5, 3));
        $this->assertNull(CatalogSaleBadge::bulkNote(11.5, 10.0, 3));
        $this->assertSame(
            '−11.5% when buying 3+',
            CatalogSaleBadge::bulkNote(10.0, 11.5, 3)
        );
        $this->assertSame(
            '−11.5% when buying 3+',
            CatalogSaleBadge::bulkNote(null, 11.5, 3)
        );
    }

    public function test_paid_addons_drive_the_from_prefix(): void
    {
        $this->assertFalse(CatalogSaleBadge::hasPaidAddons([], []));
        $this->assertFalse(CatalogSaleBadge::hasPaidAddons(['casino' => 0], [7 => 0]));
        $this->assertTrue(CatalogSaleBadge::hasPaidAddons(['crypto' => 5], []));
        $this->assertTrue(CatalogSaleBadge::hasPaidAddons([], [7 => 15]));
    }
}
