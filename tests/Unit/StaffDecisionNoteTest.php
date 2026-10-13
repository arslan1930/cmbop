<?php

namespace Tests\Unit;

use App\Support\StaffDecisionNote;
use Tests\TestCase;

class StaffDecisionNoteTest extends TestCase
{
    public function test_plain_strips_markdown_and_soft_hyphens(): void
    {
        $raw = "Please keep **\"Verify\"** before sub\u{00AD}mitting the listing.";

        $this->assertSame(
            'Please keep "Verify" before submitting the listing.',
            StaffDecisionNote::plain($raw)
        );
    }

    public function test_summary_uses_quality_bar_shortcut_and_truncates(): void
    {
        $this->assertSame(
            'Rejected: below quality bar',
            StaffDecisionNote::summary('DA is below the quality bar. Please raise metrics before resubmitting.')
        );
        $this->assertSame(
            'Archived: below quality bar',
            StaffDecisionNote::summary('Below quality threshold for catalog.', true)
        );

        $long = str_repeat('Please fix the description. ', 8);
        $summary = StaffDecisionNote::summary($long);
        $this->assertTrue(mb_strlen($summary) <= 80);
        $this->assertStringEndsWith('…', $summary);
        $this->assertTrue(StaffDecisionNote::expandable($long));
    }
}
