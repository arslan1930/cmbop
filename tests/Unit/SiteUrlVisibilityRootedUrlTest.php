<?php

namespace Tests\Unit;

use App\Services\Catalog\SiteUrlVisibility;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteUrlVisibilityRootedUrlTest extends TestCase
{
    private SiteUrlVisibility $visibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->visibility = new SiteUrlVisibility;
    }

    public static function rootedUrlProvider(): array
    {
        return [
            'strips blog path' => ['https://site.com/blog', 'https://site.com'],
            'keeps www' => ['https://www.site.com/blog/a', 'https://www.site.com'],
            'keeps subdomain' => ['https://news.site.com/category/x', 'https://news.site.com'],
            'keeps shop subdomain' => ['https://shop.brand.co.uk/products/1', 'https://shop.brand.co.uk'],
            'strips query' => ['http://blog.example.org/2024/post?id=9', 'http://blog.example.org'],
            'bare host defaults https' => ['example.com/path', 'https://example.com'],
            'leftover javascript scheme' => ['javascript:alert(1)', ''],
            'leftover data scheme' => ['data:text/html,hi', ''],
            'leftover vbscript scheme' => ['vbscript:msgbox(1)', ''],
        ];
    }

    public function test_host_refuses_leftover_non_http_schemes(): void
    {
        $this->assertSame('', $this->visibility->host('javascript:alert(1)'));
        $this->assertSame('', $this->visibility->host('data:text/html,hi'));
        $this->assertSame('example.com', $this->visibility->host('https://www.example.com/path'));
    }

    #[DataProvider('rootedUrlProvider')]
    public function test_rooted_url_keeps_host_and_subdomain_only(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->visibility->rootedUrl($input));
    }
}
