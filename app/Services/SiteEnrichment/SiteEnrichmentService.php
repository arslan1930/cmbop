<?php

namespace App\Services\SiteEnrichment;

use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use App\Support\SiteImageUpload;
use App\Support\UserFacingError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SiteEnrichmentService
{
    public function __construct(
        private readonly SiteMetricsAggregator $metrics,
        private readonly ScreenshotCaptureService $screenshots,
        private readonly CountryDetectionService $countries,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('site_enrichment.enabled', true);
    }

    public function refreshMetrics(Site $site, string $triggeredBy = 'system', ?string $provider = null): SiteEnrichmentRun
    {
        $this->countries->detectAndApply($site);
        $site->refresh();

        $run = $this->startRun([
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

            $runStatus = $result['errors']
                ? ($snapshot->hasAnyMetric() ? 'partial' : 'failed')
                : 'success';

            $updates = [
                'metrics_provider' => $snapshot->provider,
                'metrics_fetched_at' => now(),
                'enrichment_status' => $runStatus === 'success' ? 'ready' : $runStatus,
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

            $this->persistSiteColumns($site, $updates);

            $this->finishRun($run, [
                'status' => $runStatus,
                'provider' => $snapshot->provider ?: $run->provider,
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

            $safeError = UserFacingError::message($e, 'Metrics refresh failed.');
            $this->persistSiteColumns($site, [
                'enrichment_status' => 'failed',
                'enrichment_error' => $safeError,
            ]);

            $this->finishRun($run, [
                'status' => 'failed',
                'error' => $safeError,
                'finished_at' => now(),
            ]);
        }

        return $run->exists ? ($run->fresh() ?? $run) : $run;
    }

    public function refreshScreenshot(Site $site, string $triggeredBy = 'system'): SiteEnrichmentRun
    {
        $siteId = (int) $site->id;
        $provider = (string) config('site_enrichment.screenshots.provider', 'thum_io');

        if ($siteId < 1 || ! Site::query()->whereKey($siteId)->exists()) {
            return SiteEnrichmentRun::make([
                'site_id' => $siteId,
                'type' => 'screenshot',
                'provider' => $provider,
                'status' => 'failed',
                'triggered_by' => $triggeredBy,
                'error' => 'Site was removed before screenshot capture.',
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        }

        $run = $this->startRun([
            'site_id' => $siteId,
            'type' => 'screenshot',
            'provider' => $provider,
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        try {
            $result = $this->screenshots->capture($site);

            $persisted = Site::query()->find($siteId);
            if (! $persisted) {
                $this->discardCapturedScreenshotFiles($result, $siteId);
                $this->markScreenshotRunFailed($run, 'Site was removed during screenshot capture.');

                return $run->exists ? ($run->fresh() ?? $run) : $run;
            }

            $this->persistSiteColumns($persisted, [
                'screenshot_path' => $result['path'],
                'screenshot_thumb_path' => $result['thumb_path'],
                'screenshot_fetched_at' => now(),
                'enrichment_status' => $result['success']
                    ? ($persisted->enrichment_status === 'failed' ? 'partial' : ($persisted->enrichment_status ?: 'ready'))
                    : ($result['path'] ? 'partial' : 'failed'),
                'enrichment_error' => $result['error'],
            ]);

            $this->finishRun($run, [
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
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            $this->markScreenshotRunFailed(
                $run,
                UserFacingError::message($e, 'Screenshot capture failed.')
            );
        }

        return $run->exists ? ($run->fresh() ?? $run) : $run;
    }

    /**
     * @param  array{path: ?string, thumb_path: ?string}  $result
     */
    private function discardCapturedScreenshotFiles(array $result, int $siteId): void
    {
        SiteImageUpload::deleteListingPublicMedia(
            null,
            isset($result['path']) && is_string($result['path']) ? $result['path'] : null,
            isset($result['thumb_path']) && is_string($result['thumb_path']) ? $result['thumb_path'] : null,
            $siteId
        );
    }

    private function markScreenshotRunFailed(SiteEnrichmentRun $run, string $error): void
    {
        try {
            if (! $run->exists) {
                return;
            }
            $run->update([
                'status' => 'failed',
                'error' => $error,
                'finished_at' => now(),
            ]);
        } catch (\Throwable) {
            // The site delete may have cascade-removed this run.
        }
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
        $this->persistSiteColumns($site, [
            'dr' => $dr,
            'da' => $da,
            'traffic' => $traffic,
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'enrichment_status' => 'ready',
            'enrichment_error' => null,
        ]);

        return $this->startRun([
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

    /**
     * @param  array<string, mixed>  $updates
     */
    private function persistSiteColumns(Site $site, array $updates): void
    {
        $safe = [];
        foreach ($updates as $column => $value) {
            if (Site::hasSitesColumn((string) $column)) {
                $safe[$column] = $value;
            }
        }
        if ($safe === []) {
            return;
        }

        try {
            $site->forceFill($safe)->save();
        } catch (\Throwable $e) {
            if (! $this->isUnknownColumnError($e)) {
                throw $e;
            }

            Log::warning('Site enrichment skipped missing columns', [
                'site_id' => $site->id,
                'columns' => array_keys($safe),
                'error' => $e->getMessage(),
            ]);

            Site::forgetColumnCache();
            $retry = [];
            foreach ($safe as $column => $value) {
                if (Site::hasSitesColumn((string) $column) && ! str_contains($e->getMessage(), (string) $column)) {
                    $retry[$column] = $value;
                }
            }
            foreach (array_keys($site->getDirty()) as $dirty) {
                if (! array_key_exists($dirty, $retry)) {
                    $site->syncOriginalAttribute($dirty);
                }
            }
            if ($retry === []) {
                return;
            }

            $site->forceFill($retry)->save();
        }
    }

    private function isUnknownColumnError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '42S22')
            || str_contains($message, 'Unknown column');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function startRun(array $attributes): SiteEnrichmentRun
    {
        if (! $this->runsTableReady()) {
            return SiteEnrichmentRun::make($attributes);
        }

        return SiteEnrichmentRun::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finishRun(SiteEnrichmentRun $run, array $attributes): void
    {
        $run->fill($attributes);
        if (! $run->exists) {
            return;
        }

        try {
            $run->update($attributes);
        } catch (\Throwable $e) {
            Log::warning('Could not update enrichment run', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function runsTableReady(): bool
    {
        try {
            return Schema::hasTable('site_enrichment_runs');
        } catch (\Throwable) {
            return false;
        }
    }
}
