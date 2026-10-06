<?php

namespace Tests\Unit;

use App\Support\CatalogHealthQueue;
use App\Support\StaffSitesIndexCounts;
use Tests\TestCase;

class StaffSitesIndexCountsTest extends TestCase
{
    public function test_empty_bag_has_header_keys(): void
    {
        $empty = StaffSitesIndexCounts::empty();

        $this->assertSame(0, $empty['liveUnverifiedCount']);
        $this->assertSame(0, $empty['archivedListCount']);
        $this->assertSame(0, $empty['missingMarketCount']);
        $this->assertArrayHasKey(CatalogHealthQueue::MISSING_MARKET, $empty['healthCounts']);
        $this->assertSame(StaffSitesIndexCounts::CACHE_KEY, 'staff.sites.index_counts');
        $this->assertSame(45, StaffSitesIndexCounts::CACHE_TTL_SECONDS);
    }
}
