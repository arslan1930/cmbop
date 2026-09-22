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
