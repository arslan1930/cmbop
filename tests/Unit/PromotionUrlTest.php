<?php

namespace Tests\Unit;

use App\Support\PromotionUrl;
use Tests\TestCase;

class PromotionUrlTest extends TestCase
{
    public function test_accepts_relative_and_absolute_http_urls(): void
    {
        $this->assertTrue(PromotionUrl::isSafe('/advertiser/catalog'));
        $this->assertTrue(PromotionUrl::isSafe('https://example.com/x'));
        $this->assertTrue(PromotionUrl::isSafe('http://example.com/x'));
        $this->assertTrue(PromotionUrl::isSafe(''));
        $this->assertTrue(PromotionUrl::isSafe(null));
    }

    public function test_rejects_javascript_and_protocol_relative(): void
    {
        $this->assertFalse(PromotionUrl::isSafe('javascript:alert(1)'));
        $this->assertFalse(PromotionUrl::isSafe('//evil.example/phish'));
        $this->assertFalse(PromotionUrl::isSafe('data:text/html,hi'));
    }

    public function test_rejects_userinfo_open_redirect(): void
    {
        $this->assertFalse(PromotionUrl::isSafe('https://google.com@evil.example/path'));
        $this->assertFalse(PromotionUrl::isSafe('https://user:pass@evil.example/path'));
        $this->assertNull(PromotionUrl::href('https://google.com@evil.example/path'));
        $this->assertNull(PromotionUrl::normalizeForStorage('https://user:pass@phish.test'));
    }

    public function test_href_resolves_relative_against_app_url(): void
    {
        $href = PromotionUrl::href('/advertiser/catalog');
        $this->assertStringEndsWith('/advertiser/catalog', (string) $href);
        $this->assertStringStartsWith('http', (string) $href);
    }

    public function test_normalize_returns_null_for_unsafe(): void
    {
        $this->assertNull(PromotionUrl::normalizeForStorage('javascript:alert(1)'));
        $this->assertSame('/advertiser/catalog', PromotionUrl::normalizeForStorage('/advertiser/catalog'));
    }
}
