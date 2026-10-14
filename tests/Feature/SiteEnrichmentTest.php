<?php

namespace Tests\Feature;

use App\Jobs\EnrichSiteJob;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteEnrichment\CountryDetectionService;
use App\Services\SiteEnrichment\ImageOptimizationService;
use App\Services\SiteEnrichment\Providers\SemrushMetricsProvider;
use App\Services\SiteEnrichment\ScreenshotCaptureService;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use App\Services\SiteEnrichment\SiteMetricsAggregator;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesBlogUploads;
use Tests\TestCase;

class SiteEnrichmentTest extends TestCase
{
    use CreatesBlogUploads;
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

    public function test_screenshot_failure_stores_placeholder(): void
    {

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

    public function test_none_provider_uses_placeholder_without_warning_log(): void
    {
        Storage::fake('public');
        config([
            'site_enrichment.enabled' => true,
            'site_enrichment.screenshots.provider' => 'none',
        ]);

        $warnings = [];
        Event::listen(
            MessageLogged::class,
            function (MessageLogged $event) use (&$warnings) {
                if ($event->level === 'warning' && str_contains((string) $event->message, 'Screenshot capture failed')) {
                    $warnings[] = $event->message;
                }
            }
        );

        $site = $this->makeSite(['site_url' => 'https://draft.example', 'domain' => 'draft.example']);
        app(ScreenshotCaptureService::class)->capture($site);

        $this->assertSame([], $warnings, 'Intentional none provider must not warn');
    }

    public function test_real_provider_failure_still_logs_warning(): void
    {
        Storage::fake('public');
        Http::fake([
            'image.thum.io/*' => Http::response('nope', 500),
        ]);
        config([
            'site_enrichment.enabled' => true,
            'site_enrichment.screenshots.provider' => 'thum_io',
            'site_enrichment.screenshots.thum_io_auth' => 'test-auth',
        ]);

        $warnings = [];
        Event::listen(
            MessageLogged::class,
            function (MessageLogged $event) use (&$warnings) {
                if ($event->level === 'warning' && (
                    str_contains((string) $event->message, 'Screenshot capture failed')
                    || str_contains((string) $event->message, 'thum.io screenshot HTTP failure')
                )) {
                    $warnings[] = [
                        'message' => $event->message,
                        'context' => $event->context,
                    ];
                }
            }
        );

        $site = $this->makeSite(['site_url' => 'https://draft.example', 'domain' => 'draft.example']);
        app(ScreenshotCaptureService::class)->capture($site);

        $this->assertNotEmpty($warnings);
        $withProvider = collect($warnings)->first(fn ($row) => ($row['context']['provider'] ?? null) === 'thum_io');
        $this->assertNotNull($withProvider, 'Capture failure should name the thum.io provider.');
    }

    public function test_failed_refresh_keeps_previous_screenshot_files(): void
    {
        Storage::fake('public');
        Http::fake([
            'image.thum.io/*' => Http::response('nope', 500),
        ]);
        config([
            'site_enrichment.enabled' => true,
            'site_enrichment.screenshots.provider' => 'thum_io',
            'site_enrichment.screenshots.thum_io_auth' => 'test-auth',
        ]);

        $oldPath = 'site-screenshots/site-keep-full.webp';
        $oldThumb = 'site-screenshots/site-keep-thumb.webp';
        Storage::disk('public')->put($oldPath, 'good-full');
        Storage::disk('public')->put($oldThumb, 'good-thumb');

        $site = $this->makeSite([
            'screenshot_path' => $oldPath,
            'screenshot_thumb_path' => $oldThumb,
        ]);

        $result = app(ScreenshotCaptureService::class)->capture($site);
        $run = app(SiteEnrichmentService::class)->refreshScreenshot($site->fresh(), 'test');
        $site->refresh();

        $this->assertFalse($result['success']);
        $this->assertFalse($result['used_placeholder']);
        $this->assertSame($oldPath, $result['path']);
        $this->assertSame($oldThumb, $result['thumb_path']);
        $this->assertTrue(Storage::disk('public')->exists($oldPath));
        $this->assertTrue(Storage::disk('public')->exists($oldThumb));
        $this->assertSame($oldPath, $site->screenshot_path);
        $this->assertSame($oldThumb, $site->screenshot_thumb_path);
        $this->assertNotSame('success', $run->status);
        $this->assertStringContainsString('previous preview kept', (string) $site->enrichment_error);
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
        if (! ImageOptimizationService::canEncodeWebp()) {
            $this->markTestSkipped('No WebP encoder (GD, Imagick, or cwebp)');
        }

        Storage::fake('public');
        $stored = app(ImageOptimizationService::class)->storeOptimizedWebp(
            $this->tinyPngBytes(),
            'site-screenshots',
            'test-site'
        );
        $this->assertNotNull($stored);
        $this->assertTrue(Storage::disk('public')->exists($stored['path']));
        $this->assertStringEndsWith('.webp', $stored['path']);
        $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($stored['path']));
    }

    public function test_ahrefs_provider_does_not_invent_values_when_unconfigured(): void
    {
        config([
            'site_enrichment.default_provider' => 'ahrefs',
            'site_enrichment.fallback_providers' => ['manual'],
            'site_enrichment.providers.ahrefs.api_token' => '',
        ]);

        $site = $this->makeSite(['dr' => 55, 'da' => 50, 'traffic' => 9000]);
        $result = app(SiteMetricsAggregator::class)->fetch($site);
        $snapshot = $result['snapshot'];

        $this->assertSame(55, $snapshot->domainRating);
        $this->assertSame(50, $snapshot->domainAuthority);
        $this->assertSame(9000, $snapshot->monthlyOrganicTraffic);
        $this->assertSame(['manual'], $result['providers_used']);
        $this->assertSame('manual', $snapshot->provider);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($msg) => str_contains((string) $msg, 'not configured')));

        $run = app(SiteEnrichmentService::class)->refreshMetrics($site, 'test');
        $this->assertSame('manual', $run->provider);
        $this->assertSame('manual', $site->fresh()->metrics_provider);
    }

    public function test_metrics_manual_lock_skips_configured_api_providers(): void
    {
        Http::fake();
        config([
            'site_enrichment.default_provider' => 'ahrefs',
            'site_enrichment.fallback_providers' => ['manual'],
            'site_enrichment.providers.ahrefs.api_token' => 'tok',
        ]);

        $site = $this->makeSite(['metrics_manual' => true, 'dr' => 33, 'da' => 31, 'traffic' => 800]);
        $result = app(SiteMetricsAggregator::class)->fetch($site);

        Http::assertNothingSent();
        $this->assertSame(['manual'], $result['providers_used']);
        $this->assertSame('manual', $result['snapshot']->provider);
        $this->assertSame(33, $result['snapshot']->domainRating);
    }

    public function test_aggregator_provider_is_who_filled_the_fields(): void
    {
        config([
            'site_enrichment.default_provider' => 'ahrefs',
            'site_enrichment.fallback_providers' => ['moz', 'manual'],
            'site_enrichment.providers.ahrefs.api_token' => 'ahrefs-tok',
            'site_enrichment.providers.moz.access_token' => 'moz-tok',
        ]);
        Http::fake([
            'api.ahrefs.com/*' => Http::response([
                'domain_rating' => 61,
                'org_traffic' => 4400,
            ], 200),
            'lsapi.seomoz.com/*' => Http::response([
                'results' => [['domain_authority' => 48]],
            ], 200),
        ]);

        $site = $this->makeSite(['dr' => 10, 'da' => 11, 'traffic' => 12, 'metrics_manual' => false]);
        $result = app(SiteMetricsAggregator::class)->fetch($site);

        $this->assertSame('ahrefs,moz', $result['snapshot']->provider);
        $this->assertSame(61, $result['snapshot']->domainRating);
        $this->assertSame(48, $result['snapshot']->domainAuthority);
        $this->assertSame(4400, $result['snapshot']->monthlyOrganicTraffic);
        $this->assertSame(['ahrefs', 'moz'], $result['providers_used']);
    }

    public function test_failed_api_refresh_is_partial_when_existing_metrics_remain(): void
    {
        config([
            'site_enrichment.default_provider' => 'ahrefs',
            'site_enrichment.fallback_providers' => ['manual'],
            'site_enrichment.providers.ahrefs.api_token' => 'tok',
        ]);
        Http::fake([
            'api.ahrefs.com/*' => Http::response('nope', 500),
        ]);

        $site = $this->makeSite(['dr' => 55, 'da' => 50, 'traffic' => 9000, 'metrics_manual' => false]);
        $run = app(SiteEnrichmentService::class)->refreshMetrics($site, 'test');
        $site->refresh();

        $this->assertSame('partial', $run->status);
        $this->assertSame('partial', $site->enrichment_status);
        $this->assertSame(55, $site->dr);
        $this->assertNotEmpty($run->error);
    }

    public function test_semrush_uses_country_database_and_defaults_unknown_to_us(): void
    {
        $this->assertSame('de', SemrushMetricsProvider::databaseForCountry('de'));
        $this->assertSame('uk', SemrushMetricsProvider::databaseForCountry('gb'));
        $this->assertSame('us', SemrushMetricsProvider::databaseForCountry(null));
        $this->assertSame('us', SemrushMetricsProvider::databaseForCountry('zz'));
        $fromCountries = $this->makeSite([
            'country' => '',
            'countries' => ['de'],
            'domain' => 'from-countries.example',
            'site_url' => 'https://from-countries.example',
        ]);
        $this->assertSame('de', SemrushMetricsProvider::databaseForSite($fromCountries));
        $blankCountries = $this->makeSite([
            'country' => '',
            'countries' => ['', '  '],
            'domain' => 'blank-countries.example',
            'site_url' => 'https://blank-countries.example',
        ]);
        $this->assertSame('us', SemrushMetricsProvider::databaseForSite($blankCountries));

        config([
            'site_enrichment.providers.semrush.api_key' => 'semrush-key',
            'site_enrichment.providers.semrush.base_url' => 'https://api.semrush.com',
        ]);
        Http::fake([
            'api.semrush.com/*' => Http::response("Dn;Rk;Or;Ot\nnews.de;1;2;8800", 200),
        ]);

        $site = $this->makeSite([
            'domain' => 'news.de',
            'site_url' => 'https://news.de',
            'country' => 'de',
        ]);
        $snapshot = app(SemrushMetricsProvider::class)->fetch($site);

        $this->assertTrue($snapshot->success);
        $this->assertSame(8800, $snapshot->monthlyOrganicTraffic);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.semrush.com')
                && $request['database'] === 'de'
                && $request['domain'] === 'news.de';
        });
    }

    public function test_com_gtld_does_not_guess_united_states(): void
    {
        $detector = app(CountryDetectionService::class);

        $this->assertNull($detector->fromTld('example.com'));
        $this->assertNull($detector->fromTld('https://www.example.net/path'));
        $this->assertSame('de', $detector->fromTld('news.de'));
        $this->assertSame('gb', $detector->fromTld('news.co.uk'));

        // Callback by host: sequential Http::fake() merges and the first '*' wins.
        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_HOST)) {
                'example.com' => Http::response('<html><body>no lang</body></html>', 200),
                'english-com.example.com' => Http::response('<html lang="en"><body>English .com</body></html>', 200),
                'british-com.example.com' => Http::response('<html lang="en-GB"><body>UK English</body></html>', 200),
                'portugal-com.example.com' => Http::response('<html lang="pt-PT"><body>Portugal</body></html>', 200),
                default => Http::response('not stubbed', 404),
            };
        });

        $site = $this->makeSite([
            'domain' => 'example.com',
            'site_url' => 'https://example.com',
            'country' => '',
            'countries' => [],
        ]);
        $detector->detectAndApply($site);
        $site->refresh();

        $this->assertTrue(blank($site->country));
        $this->assertTrue(empty($site->countries));

        $english = $this->makeSite([
            'site_name' => 'English Com',
            'domain' => 'english-com.example.com',
            'site_url' => 'https://english-com.example.com',
            'country' => '',
            'countries' => [],
        ]);
        $detector->detectAndApply($english);
        $english->refresh();
        $this->assertTrue(blank($english->country), 'lang=en must not stamp United States');

        $british = $this->makeSite([
            'site_name' => 'British Com',
            'domain' => 'british-com.example.com',
            'site_url' => 'https://british-com.example.com',
            'country' => '',
            'countries' => [],
        ]);
        $detector->detectAndApply($british);
        $british->refresh();
        $this->assertSame('uk', $british->country);

        $portugal = $this->makeSite([
            'site_name' => 'Portugal Com',
            'domain' => 'portugal-com.example.com',
            'site_url' => 'https://portugal-com.example.com',
            'country' => '',
            'countries' => [],
        ]);
        $detector->detectAndApply($portugal);
        $portugal->refresh();
        $this->assertSame('pt', $portugal->country);
    }

    public function test_country_detection_does_not_overwrite_existing_country(): void
    {
        $site = $this->makeSite([
            'domain' => 'example.com',
            'country' => 'de',
            'countries' => ['de'],
        ]);

        app(CountryDetectionService::class)->detectAndApply($site);
        $site->refresh();

        $this->assertSame('de', $site->country);
    }

    public function test_blank_countries_values_do_not_skip_tld_detection(): void
    {
        $site = $this->makeSite([
            'domain' => 'news.de',
            'site_url' => 'https://news.de',
            'country' => '',
            'countries' => ['', '  '],
        ]);

        app(CountryDetectionService::class)->detectAndApply($site);
        $site->refresh();

        $this->assertSame('de', $site->country);
        $this->assertSame(['de'], $site->countries);
    }

    public function test_allow_api_overwrite_clears_manual_lock_without_queueing(): void
    {
        Queue::fake();
        $this->seed(RolesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $site = $this->makeSite(['metrics_manual' => true, 'dr' => 40, 'da' => 41, 'traffic' => 900]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.allow-api-metrics', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $site->refresh();
        $this->assertFalse((bool) $site->metrics_manual);
        $this->assertSame(40, $site->dr);
        $this->assertSame(1, ActivityLog::query()->where('action', 'site.metrics_api_unlocked')->count());
        Queue::assertNothingPushed();

        $this->actingAs($admin)
            ->postJson(route('admin.sites.allow-api-metrics', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.metrics_api_unlocked')->count());
    }

    public function test_site_edit_unlock_is_not_nested_inside_the_update_form(): void
    {
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $site = $this->makeSite(['metrics_manual' => true, 'dr' => 40, 'da' => 41, 'traffic' => 900]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('id="allow-api-overwrite-form"', false)
            ->assertSee('form="allow-api-overwrite-form"', false)
            ->assertSee('Allow API overwrite', false)
            ->getContent();

        $unlockPos = strpos($html, 'id="allow-api-overwrite-form"');
        $updatePos = strpos($html, 'enctype="multipart/form-data"');
        $this->assertNotFalse($unlockPos);
        $this->assertNotFalse($updatePos);
        $this->assertLessThan($updatePos, $unlockPos, 'Unlock form must sit outside the update form');

        $this->actingAs($admin)
            ->from(route('admin.sites.edit', $site->id))
            ->post(route('admin.sites.allow-api-metrics', $site->id))
            ->assertRedirect();

        $site->refresh();
        $this->assertFalse((bool) $site->metrics_manual);
        $this->assertSame(40, $site->dr);
    }

    public function test_refresh_screenshot_endpoint_reports_placeholder_as_failure(): void
    {
        Storage::fake('public');
        config([
            'site_enrichment.enabled' => true,
            'site_enrichment.screenshots.provider' => 'none',
        ]);

        $this->seed(RolesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $site = $this->makeSite();

        $this->actingAs($admin)
            ->postJson(route('admin.sites.refresh-screenshot', $site->id), ['sync' => true])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $site->refresh();
        $this->assertNotNull($site->screenshot_path);
        $this->assertNotEmpty($site->enrichment_error);
        $this->assertSame(0, ActivityLog::query()->where('action', 'site.screenshot_refreshed')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'site.screenshot_refresh_queued')->count());
    }

    public function test_async_metrics_refresh_logs_queued_not_refreshed(): void
    {
        Queue::fake();
        config(['site_enrichment.enabled' => true]);

        $this->seed(RolesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $site = $this->makeSite();

        $this->actingAs($admin)
            ->postJson(route('admin.sites.refresh-metrics', $site->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Metrics refresh queued');

        $this->assertSame(1, ActivityLog::query()->where('action', 'site.metrics_refresh_queued')->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'site.metrics_refreshed')->count());
    }

    public function test_thum_io_capture_requests_a_desktop_viewport(): void
    {
        Storage::fake('public');
        config([
            'site_enrichment.screenshots.provider' => 'thum_io',
            'site_enrichment.screenshots.thum_io_auth' => 'test-auth',
            'site_enrichment.screenshots.width' => 1280,
            'site_enrichment.screenshots.height' => 800,
        ]);

        $png = $this->screenshotRasterBytes();
        $this->assertGreaterThan(500, strlen($png));

        Http::fake([
            'image.thum.io/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $site = $this->makeSite([
            'site_url' => 'https://desktop-shot.example:8080/home',
            'domain' => 'desktop-shot.example',
        ]);
        $this->assertSame(
            'https://desktop-shot.example:8080/home',
            app(ScreenshotCaptureService::class)->homepageUrl($site)
        );
        $result = app(ScreenshotCaptureService::class)->capture($site);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['path']);
        if (ImageOptimizationService::canEncodeWebp()) {
            $this->assertStringEndsWith('.webp', (string) $result['path']);
            $this->assertStringStartsWith('RIFF', Storage::disk('public')->get((string) $result['path']));
        } else {
            $this->assertStringEndsWith('.png', (string) $result['path']);
        }
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'image.thum.io/get/auth/test-auth/width/1280/crop/800/viewportWidth/1280/')
                && str_contains($request->url(), rawurlencode('https://desktop-shot.example:8080/home'));
        });
    }

    public function test_refresh_screenshot_discards_files_when_site_is_deleted_mid_capture(): void
    {
        Storage::fake('public');

        $site = $this->makeSite([
            'site_url' => 'https://gone-during-shot.example',
            'domain' => 'gone-during-shot.example',
        ]);
        $path = 'site-screenshots/site-'.$site->id.'-orphan.webp';
        $thumb = 'site-screenshots/site-'.$site->id.'-orphan-thumb.webp';
        Storage::disk('public')->put($path, 'full');
        Storage::disk('public')->put($thumb, 'thumb');

        $this->mock(ScreenshotCaptureService::class, function ($mock) use ($site, $path, $thumb) {
            $mock->shouldReceive('capture')->once()->andReturnUsing(function () use ($site, $path, $thumb) {
                $site->delete();

                return [
                    'path' => $path,
                    'thumb_path' => $thumb,
                    'success' => true,
                    'error' => null,
                    'used_placeholder' => false,
                ];
            });
        });

        $run = app(SiteEnrichmentService::class)->refreshScreenshot($site->fresh(), 'test');

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertFalse(Storage::disk('public')->exists($thumb));
        $this->assertContains($run->status, ['failed', 'running']);
    }

    public function test_refresh_screenshot_skips_missing_site_without_writing_files(): void
    {
        Storage::fake('public');
        config(['site_enrichment.screenshots.provider' => 'none']);

        $site = $this->makeSite([
            'site_url' => 'https://already-gone.example',
            'domain' => 'already-gone.example',
        ]);
        $stale = $site->fresh();
        $site->delete();

        $run = app(SiteEnrichmentService::class)->refreshScreenshot($stale, 'test');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('removed before screenshot', (string) $run->error);
        $this->assertSame([], Storage::disk('public')->allFiles('site-screenshots'));
    }
}
