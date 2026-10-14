<?php

namespace Tests\Unit;

use Tests\TestCase;

class ActivityActionLabelTest extends TestCase
{
    public function test_library_override_has_a_friendly_label(): void
    {
        $this->assertSame('Overrode library article', activity_action_label('content.overridden'));
        $this->assertSame('content.overridden', activity_action_canonical('content.overridden'));
    }

    public function test_featured_credit_has_a_friendly_label(): void
    {
        $this->assertSame('Featured site (credit)', activity_action_label('site.featured_credit'));
    }

    public function test_bulk_request_claim_has_a_friendly_label(): void
    {
        $this->assertSame('Claimed bulk request', activity_action_label('bulk_request.claimed'));
    }

    public function test_unknown_action_keys_are_humanized(): void
    {
        $this->assertSame('Something Custom Done', activity_action_label('something.custom_done'));
    }
}
