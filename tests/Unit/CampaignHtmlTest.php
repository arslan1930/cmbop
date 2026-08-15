<?php

namespace Tests\Unit;

use App\Support\CampaignHtml;
use PHPUnit\Framework\TestCase;

class CampaignHtmlTest extends TestCase
{
    public function test_plain_text_is_escaped_and_wrapped(): void
    {
        $clean = CampaignHtml::sanitize("Price < €50\nNext line");

        $this->assertStringContainsString('Price &lt; €50', $clean);
        $this->assertStringContainsString('<br>', $clean);
        $this->assertStringStartsWith('<p>', $clean);
    }

    public function test_javascript_href_is_removed(): void
    {
        $clean = CampaignHtml::sanitize('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('Click', $clean);
    }

    public function test_event_handlers_are_stripped(): void
    {
        $clean = CampaignHtml::sanitize('<p onclick="alert(1)" onmouseover="bad()">Hello</p>');

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onmouseover', $clean);
        $this->assertStringContainsString('Hello', $clean);
        $this->assertStringContainsString('<p>', $clean);
    }

    public function test_https_links_are_kept(): void
    {
        $clean = CampaignHtml::sanitize('<p>See <a href="https://example.com/offer">the offer</a>.</p>');

        $this->assertStringContainsString('href="https://example.com/offer"', $clean);
        $this->assertStringContainsString('the offer', $clean);
    }

    public function test_mailto_links_are_kept(): void
    {
        $clean = CampaignHtml::sanitize('<a href="mailto:hello@example.com">Email us</a>');

        $this->assertStringContainsString('href="mailto:hello@example.com"', $clean);
    }

    public function test_script_tags_are_dropped(): void
    {
        $clean = CampaignHtml::sanitize('<p>Hi</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
        $this->assertStringContainsString('Hi', $clean);
    }

    public function test_data_and_javascript_urls_are_rejected(): void
    {
        $this->assertFalse(CampaignHtml::isSafeHttpUrl('javascript:alert(1)'));
        $this->assertFalse(CampaignHtml::isSafeHttpUrl('data:text/html,hi'));
        $this->assertFalse(CampaignHtml::isSafeHttpUrl('mailto:hello@example.com'));
        $this->assertTrue(CampaignHtml::isSafeHttpUrl('https://seolinkbuildings.com/offer'));
        $this->assertTrue(CampaignHtml::isSafeHttpUrl('http://example.com'));
        $this->assertFalse(CampaignHtml::isSafeHttpUrl('https://google.com@evil.example/path'));
        $this->assertFalse(CampaignHtml::isSafeHttpUrl('https://user:pass@evil.example/path'));
    }
}
