<?php

namespace Tests\Unit;

use App\Models\Site;
use App\Services\Catalog\CatalogFaviconResolver;
use PHPUnit\Framework\TestCase;

class CatalogFaviconUrlChainTest extends TestCase
{
    public function test_unsaved_site_has_no_tile_url(): void
    {
        $site = new Site([
            'domain' => 'News-Desk.Example',
            'site_url' => 'https://news-desk.example/path',
        ]);

        $this->assertSame('news-desk.example', $site->catalogFaviconHost('News-Desk.Example'));
        $this->assertNull($site->catalogTileFaviconUrl());
        $this->assertSame([], $site->catalogFaviconUrlChain('News-Desk.Example'));
    }

    public function test_saved_site_uses_static_file_not_php_route(): void
    {
        $site = new Site([
            'domain' => 'good-site.de',
            'site_url' => 'https://good-site.de',
        ]);
        $site->id = 42;

        $url = $site->catalogTileFaviconUrl();
        $this->assertIsString($url);
        $this->assertStringContainsString('catalog-site-fallback.svg', $url);
        $this->assertStringNotContainsString('catalog/favicon', $url);

        $stored = new Site([
            'domain' => 'good-site.de',
            'favicon_path' => 'site-favicons/42.png',
        ]);
        $stored->id = 42;
        $this->assertSame('/media/site-favicons/42.png', $stored->catalogTileFaviconUrl());
    }

    public function test_listing_response_does_not_live_fetch(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(CatalogFaviconResolver::class))->getFileName());
        $this->assertStringContainsString('return $this->fallbackResponse();', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function response\([^)]*\)[^{]*\{[^}]*capture\(/s',
            $source
        );
    }

    public function test_placeholder_hosts_are_not_fetched(): void
    {
        $resolver = new CatalogFaviconResolver;

        $this->assertFalse($resolver->shouldFetchHost('demo16.com'));
        $this->assertFalse($resolver->shouldFetchHost('preview-blog.example'));
        $this->assertTrue($resolver->shouldFetchHost('potsdamerplatz.de'));
    }

    public function test_rejects_non_host_values(): void
    {
        $site = new Site([
            'domain' => 'not a host',
            'site_url' => 'javascript:alert(1)',
        ]);

        $this->assertSame('', $site->catalogFaviconHost('https://evil.example/x'));
    }
}
