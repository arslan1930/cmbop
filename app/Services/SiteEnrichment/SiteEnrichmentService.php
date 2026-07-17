<?php

namespace App\Services\SiteEnrichment;

use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use Illuminate\Support\Facades\Log;

class SiteEnrichmentService
{
    public function __construct(
        private readonly SiteMetricsAggregator $metrics,
        private readonly ScreenshotCaptureService $screenshots,
        private readonly CountryDetectionService $countries,
    ) {
    }

    public function refreshMetrics(Site $site, string $triggeredBy = 'system', ?string $provider = null): SiteEnrichmentRun
    {
        $this->countries->detectAndApply($site);
        $site->refresh();

        $run = SiteEnrichmentRun::create([
            'site_id' => $site->id,
            'type' => 'metrics',
            'provider' => $provider ?: (string) config('site_enrichment.default_provider', 'manual'),
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        try {
            $result = $this->metrics->fetch($site, $provider);
            $snapshot = $result['snapshot'];

            $updates = [
                'metrics_provider' => $snapshot->provider,
                'metrics_fetched_at' => now(),
                'enrichment_status' => $result['errors'] ? 'partial' : 'ready',
                'enrichment_error' => $result['errors'] ? implode('; ', $result['errors']) : null,
            ];

            // Only write values that were actually retrieved or already known — never invent.
            if ($snapshot->domainRating !== null) {
                $updates['dr'] = $snapshot->domainRating;
            }
            if ($snapshot->domainAuthority !== null) {
                $updates['da'] = $snapshot->domainAuthority;
            }
            if ($snapshot->monthlyOrganicTraffic !== null) {
                $updates['traffic'] = $snapshot->monthlyOrganicTraffic;
            }

            $site->forceFill($updates)->save();

            $run->update([
                'status' => $result['errors'] && ! $snapshot->hasAnyMetric() ? 'failed' : 'success',
                'payload' => [
                    'dr' => $snapshot->domainRating,
                    'da' => $snapshot->domainAuthority,
                    'traffic' => $snapshot->monthlyOrganicTraffic,
                    'providers_used' => $result['providers_used'],
                    'raw' => $snapshot->raw,
                ],
                'error' => $result['errors'] ? implode('; ', $result['errors']) : null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Site metrics refresh failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            $site->forceFill([
                'enrichment_status' => 'failed',
                'enrichment_error' => $e->getMessage(),
            ])->save();

            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    public function refreshScreenshot(Site $site, string $triggeredBy = 'system'): SiteEnrichmentRun
    {
        $run = SiteEnrichmentRun::create([
            'site_id' => $site->id,
            'type' => 'screenshot',
            'provider' => (string) config('site_enrichment.screenshots.provider', 'thum_io'),
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        try {
            $result = $this->screenshots->capture($site);

            $site->forceFill([
                'screenshot_path' => $result['path'],
                'screenshot_thumb_path' => $result['thumb_path'],
                'screenshot_fetched_at' => now(),
                'enrichment_status' => $result['success']
                    ? ($site->enrichment_status === 'failed' ? 'partial' : ($site->enrichment_status ?: 'ready'))
                    : ($result['path'] ? 'partial' : 'failed'),
                'enrichment_error' => $result['error'],
            ])->save();

            $run->update([
                'status' => $result['success'] ? 'success' : ($result['path'] ? 'partial' : 'failed'),
                'payload' => [
                    'path' => $result['path'],
                    'thumb_path' => $result['thumb_path'],
                    'used_placeholder' => $result['used_placeholder'],
                ],
                'error' => $result['error'],
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Site screenshot refresh failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    public function enrich(Site $site, string $triggeredBy = 'system', bool $metrics = true, bool $screenshot = true): void
    {
        if ($metrics) {
            $this->refreshMetrics($site, $triggeredBy);
            $site->refresh();
        }
        if ($screenshot) {
            $this->refreshScreenshot($site, $triggeredBy);
        }
    }

    public function applyManualMetrics(Site $site, ?int $dr, ?int $da, ?int $traffic, string $triggeredBy = 'admin'): SiteEnrichmentRun
    {
        $site->forceFill([
            'dr' => $dr,
            'da' => $da,
            'traffic' => $traffic,
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'enrichment_status' => 'ready',
            'enrichment_error' => null,
        ])->save();

        return SiteEnrichmentRun::create([
            'site_id' => $site->id,
            'type' => 'metrics',
            'provider' => 'manual',
            'status' => 'success',
            'payload' => compact('dr', 'da', 'traffic'),
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}
