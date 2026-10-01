<?php

namespace Tests\Unit;

use App\Support\CampaignHtml;
use PHPUnit\Framework\TestCase;

class CampaignHtmlTest extends TestCase
{
    public function test_order_status_mail_uses_html_strong_not_markdown_asterisks(): void
    {
        $path = dirname(__DIR__, 2).'/resources/views/emails/orders/status-changed.blade.php';
        $statusMail = (string) file_get_contents($path);

        $this->assertStringContainsString('<strong>Ready to place another order?</strong>', $statusMail);
        $this->assertStringNotContainsString('**Ready to place another order?**', $statusMail);
    }

    public function test_leftover_markdown_asterisks_become_emphasis(): void
    {
        $clean = CampaignHtml::sanitize('**Limited offer** and **Ready to place another order?**');

        $this->assertStringContainsString('<strong>Limited offer</strong>', $clean);
        $this->assertStringContainsString('<strong>Ready to place another order?</strong>', $clean);
        $this->assertStringNotContainsString('**Limited', $clean);
        $this->assertStringNotContainsString('**Ready', $clean);
    }

    public function test_email_masks_are_not_treated_as_markdown(): void
    {
        $clean = CampaignHtml::sanitize('PayPal · jo***@example.com and exam***.com');

        $this->assertStringContainsString('jo***@example.com', $clean);
        $this->assertStringContainsString('exam***.com', $clean);
    }

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

    public function test_nbsp_only_body_is_blank(): void
    {
        $this->assertTrue(CampaignHtml::isBlank('   '));
        $this->assertTrue(CampaignHtml::isBlank('<p>&nbsp;</p>'));
        $this->assertTrue(CampaignHtml::isBlank('<p>'.html_entity_decode('&nbsp;').'</p>'));
        $this->assertFalse(CampaignHtml::isBlank('<p>Hello</p>'));
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
