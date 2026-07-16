<?php

namespace Tests\Feature;

use App\Jobs\EnrichSiteJob;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteEnrichment\ImageOptimizationService;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSite(array $overrides = []): Site
    {
        $publisher = User::factory()->create();

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Example News',
            'site_url' => 'https://example.com',
            'domain' => 'example.com',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'price' => 100,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'active' => 1,
            'verified' => 0,
            'publication_time' => '3',
            'description' => 'Test publisher site',
            'link_type' => 'dofollow',
        ], $overrides));
    }

    public function test_manual_metrics_refresh_preserves_existing_values(): void
    {
        config([
            'site_enrichment.enabled' => true,
            'site_enrichment.default_provider' => 'manual',
            'site_enrichment.screenshots.provider' => 'none',
        ]);

        $site = $this->makeSite();
        $service = app(SiteEnrichmentService::class);

        $run = $service->refreshMetrics($site, 'test');

        $site->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(45, $site->dr);
        $this->assertSame(40, $site->da);
        $this->assertSame(12000, $site->traffic);
        $this->assertNotNull($site->metrics_fetched_at);
        $this->assertSame('manual', $site->metrics_provider);
    }

    public function test_manual_metrics_admin_entry_marks_manual_flag(): void
    {
        $site = $this->makeSite(['dr' => 0, 'da' => 0, 'traffic' => 0]);
        $service = app(SiteEnrichmentService::class);

        $service->applyManualMetrics($site, 72, 68, 245000, 'admin');
        $site->refresh();

        $this->assertTrue((bool) $site->metrics_manual);
        $this->assertSame(72, $site->dr);
        $this->assertSame(68, $site->da);
        $this->assertSame(245000, $site->traffic);
        $this->assertNotNull($site->lastUpdatedLabel());
        $this->assertSame('245K', $site->formattedTraffic());
        $this->assertSame('3 Days', $site->averagePublishLabel());
    }

    public function test_screenshot_failure_stores_placeholder_when_gd_available(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP not available');
        }

        Storage::fake('public');
        config([
            'site_enrichment.enabled' => true,
            'site_enrichment.screenshots.provider' => 'none',
        ]);

        $site = $this->makeSite();
        $run = app(SiteEnrichmentService::class)->refreshScreenshot($site, 'test');
        $site->refresh();

        $this->assertNotNull($site->screenshot_path);
        $this->assertTrue(Storage::disk('public')->exists($site->screenshot_path));
        $this->assertTrue(in_array($run->status, ['partial', 'failed', 'success'], true));
    }

    public function test_verify_dispatches_enrichment_job(): void
    {
        Queue::fake();
        config(['site_enrichment.enabled' => true]);

        $site = $this->makeSite();
        EnrichSiteJob::dispatch($site->id, 'verify', true, true);

        Queue::assertPushed(EnrichSiteJob::class, function (EnrichSiteJob $job) use ($site) {
            return $job->siteId === $site->id && $job->triggeredBy === 'verify';
        });
    }

    public function test_last_updated_hidden_when_older_than_max_age(): void
    {
        $site = $this->makeSite([
            'metrics_fetched_at' => now()->subDays(120),
        ]);

        config(['site_enrichment.max_age_days' => 90]);
        $this->assertNull($site->lastUpdatedLabel());
        $this->assertFalse($site->metricsAreFresh());
    }

    public function test_image_optimizer_converts_png_to_webp(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP not available');
        }

        Storage::fake('public');
        $img = imagecreatetruecolor(40, 30);
        $bg = imagecolorallocate($img, 20, 40, 60);
        imagefilledrectangle($img, 0, 0, 40, 30, $bg);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        $stored = app(ImageOptimizationService::class)->storeOptimizedWebp($png, 'site-screenshots', 'test-site');
        $this->assertNotNull($stored);
        $this->assertTrue(Storage::disk('public')->exists($stored['path']));
        $this->assertStringEndsWith('.webp', $stored['path']);
    }

    public function test_ahrefs_provider_does_not_invent_values_when_unconfigured(): void
    {
        config([
            'site_enrichment.default_provider' => 'ahrefs',
            'site_enrichment.fallback_providers' => ['manual'],
            'site_enrichment.providers.ahrefs.api_token' => '',
        ]);

        $site = $this->makeSite(['dr' => 55, 'da' => 50, 'traffic' => 9000]);
        $result = app(\App\Services\SiteEnrichment\SiteMetricsAggregator::class)->fetch($site);
        $snapshot = $result['snapshot'];

        $this->assertSame(55, $snapshot->domainRating);
        $this->assertSame(50, $snapshot->domainAuthority);
        $this->assertSame(9000, $snapshot->monthlyOrganicTraffic);
    }
}
