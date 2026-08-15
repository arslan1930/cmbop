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

    public function test_href_keeps_relative_paths_relative(): void
    {
        $this->assertSame('/advertiser/catalog', PromotionUrl::href('/advertiser/catalog'));
        $this->assertSame('https://example.com/x', PromotionUrl::href('https://example.com/x'));
        $this->assertFalse(PromotionUrl::isSafe('/../.env'));
        $this->assertNull(PromotionUrl::href('/../admin'));
        $this->assertFalse(PromotionUrl::isSafe('/%2e%2e/admin'));
        $this->assertFalse(PromotionUrl::isSafe('/%5c%5cevil.example'));
        $this->assertFalse(PromotionUrl::isSafe('/%2f%2fevil.example/phish'));
        $this->assertTrue(PromotionUrl::isSafe('/advertiser/catalog?v=1..2'));
    }

    public function test_normalize_returns_null_for_unsafe(): void
    {
        $this->assertNull(PromotionUrl::normalizeForStorage('javascript:alert(1)'));
        $this->assertSame('/advertiser/catalog', PromotionUrl::normalizeForStorage('/advertiser/catalog'));
    }

    public function test_safe_public_storage_path_rejects_encoded_traversal(): void
    {
        $this->assertSame('banners/offer.png', PromotionUrl::safePublicStoragePath('banners/offer.png'));
        $this->assertNull(PromotionUrl::safePublicStoragePath('banners/%2e%2e/etc/passwd'));
        $this->assertNull(PromotionUrl::safePublicStoragePath('banners/%252e%252e/etc/passwd'));
        $this->assertNull(PromotionUrl::safePublicStoragePath('banners/%25252e%25252e/etc/passwd'));
        $this->assertNull(PromotionUrl::safePublicStoragePath("banners/foo.png\0.jpg"));
        $this->assertNull(PromotionUrl::safePublicStoragePath('/etc/passwd'));
    }

    public function test_rejects_crlf_in_absolute_urls(): void
    {
        $this->assertFalse(PromotionUrl::isSafe("https://example.com/\r\nLocation: https://evil.example"));
        $this->assertFalse(PromotionUrl::isSafe('https://example.com/%0d%0aLocation:%20https://evil.example'));
        $this->assertNull(PromotionUrl::href('https://example.com/%0aSet-Cookie:x=1'));
    }
}
