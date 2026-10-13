<?php

namespace Tests\Unit;

use App\Support\StaffArchiveReason;
use Tests\TestCase;

class StaffArchiveReasonTest extends TestCase
{
    public function test_normalize_and_label(): void
    {
        $this->assertSame('below_quality', StaffArchiveReason::normalize('below_quality'));
        $this->assertSame('Below quality bar', StaffArchiveReason::label('below_quality'));
        $this->assertNull(StaffArchiveReason::normalize('not-a-reason'));
        $this->assertNull(StaffArchiveReason::label(''));
        $this->assertContains('publisher_request', StaffArchiveReason::codes());
    }
}
