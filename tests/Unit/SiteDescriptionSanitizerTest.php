<?php

namespace Tests\Unit;

use App\Services\SiteDescriptionSanitizer;
use PHPUnit\Framework\TestCase;

class SiteDescriptionSanitizerTest extends TestCase
{
    public function test_script_source_is_not_left_as_text(): void
    {
        $clean = (new SiteDescriptionSanitizer)->sanitize(
            '<p>Hello</p></body><script>alert(1)</script><p onclick=alert(2)>Safe</p>'
        );

        $this->assertStringContainsString('Hello', $clean);
        $this->assertStringContainsString('Safe', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }
}
