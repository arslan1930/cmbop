<?php

namespace Tests\Unit;

use App\Support\CommunityInbox;
use Tests\TestCase;

class CommunityInboxFiltersTest extends TestCase
{
    public function test_inbox_filter_helpers_reject_junk_query_values(): void
    {
        $this->assertSame('', CommunityInbox::normalizeKind(['catalog']));
        $this->assertSame('catalog', CommunityInbox::normalizeKind('catalog'));
        $this->assertSame('other', CommunityInbox::normalizeKind('other'));
        $this->assertSame('newest', CommunityInbox::normalizeSort(['oldest']));
        $this->assertSame('oldest', CommunityInbox::normalizeSort('oldest'));
        $this->assertSame('', CommunityInbox::normalizeOccupying('maybe'));
        $this->assertSame('yes', CommunityInbox::normalizeOccupying('yes'));
        $this->assertSame('mismatch', CommunityInbox::normalizeClaimView('mismatch'));
        $this->assertSame('', CommunityInbox::normalizeClaimView(['blocked']));
        $this->assertTrue(CommunityInbox::normalizeStale('1'));
        $this->assertTrue(CommunityInbox::normalizeStale(true));
        $this->assertFalse(CommunityInbox::normalizeStale(['1']));
        $this->assertFalse(CommunityInbox::normalizeStale('0'));
    }
}
