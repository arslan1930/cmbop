<?php

namespace Tests\Unit;

use App\Models\Site;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitePublicDiskUrlTest extends TestCase
{
    #[Test]
    public function public_disk_url_prefers_media_stream_path(): void
    {
        $this->assertSame('/media/sites/cover.webp', Site::publicDiskUrl('sites/cover.webp'));
        $this->assertSame('/media/sites/cover.webp', Site::publicDiskUrl('/storage/sites/cover.webp'));
        $this->assertSame('/media/sites/cover.webp', Site::publicDiskUrl('storage/sites/cover.webp'));
        $this->assertNull(Site::publicDiskUrl(null));
        $this->assertNull(Site::publicDiskUrl(''));
    }

    #[Test]
    public function public_disk_url_fallbacks_include_media_then_storage(): void
    {
        $this->assertSame([
            '/media/sites/cover.webp',
            '/storage/sites/cover.webp',
        ], Site::publicDiskUrlFallbacks('sites/cover.webp'));
    }

    #[Test]
    public function homepage_preview_url_chain_orders_full_thumb_cover_with_fallbacks(): void
    {
        $site = new Site([
            'screenshot_path' => 'site-screenshots/full.webp',
            'screenshot_thumb_path' => 'site-screenshots/thumb.webp',
            'site_image' => 'sites/cover.webp',
        ]);

        $this->assertSame([
            '/media/site-screenshots/full.webp',
            '/storage/site-screenshots/full.webp',
            '/media/site-screenshots/thumb.webp',
            '/storage/site-screenshots/thumb.webp',
            '/media/sites/cover.webp',
            '/storage/sites/cover.webp',
        ], $site->homepagePreviewUrlChain());
    }
}
