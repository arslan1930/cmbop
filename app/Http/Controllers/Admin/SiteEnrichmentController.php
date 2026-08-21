<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Jobs\EnrichSiteJob;
use App\Jobs\RefreshSiteMetricsJob;
use App\Models\Site;
use App\Models\SiteEnrichmentRun;
use App\Services\ActivityLogger;
use App\Services\SiteEnrichment\SiteEnrichmentService;
use App\Services\SiteEnrichment\SiteMetricsAggregator;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SiteEnrichmentController extends Controller
{
    public function index(Request $request)
    {
        $status = $this->attentionStatusFilter($request->query('status'));
        $type = $this->attentionTypeFilter($request->query('type'));
        $attention = new LengthAwarePaginator([], 0, 40);
        $attention->withPath($request->url())->appends($request->only(['status', 'type']));

        try {
            if (Schema::hasTable('site_enrichment_runs')) {
                $siteSelect = $this->siteRelationSelectColumns();
                $attention = SiteEnrichmentRun::query()
                    ->with(['site:'.implode(',', $siteSelect)])
                    ->needsAttention($status, $type)
                    ->latest('id')
                    ->paginate(40)
                    ->withQueryString();
            }
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment attention list failed', [
                'error' => $e->getMessage(),
            ]);
            $attention = new LengthAwarePaginator([], 0, 40);
            $attention->withPath($request->url())->appends($request->only(['status', 'type']));
        }

        $aggregator = app(SiteMetricsAggregator::class);
        $config = [
            'enabled' => (bool) config('site_enrichment.enabled'),
            'default_provider' => (string) config('site_enrichment.default_provider'),
            'fallback_providers' => config('site_enrichment.fallback_providers'),
            'has_api_keys' => $aggregator->anyApiProviderConfigured(),
            'refresh_frequency' => (string) config('site_enrichment.refresh_frequency'),
            'max_age_days' => (int) config('site_enrichment.max_age_days'),
            'screenshot_provider' => $this->screenshotProviderLabel(),
        ];

        $staleSites = new LengthAwarePaginator([], 0, 40);
        $staleSites->withPath($request->url())->appends($request->query());
        $staleCount = 0;
        $placeholderSiteIds = [];
        $batchLimit = max(1, (int) config('site_enrichment.batch_limit', 40));
        $marketingEditor = $this->isMarketingEditor($request);

        try {
            $staleQuery = Site::query()
                ->where('active', 1)
                ->staleForEnrichment()
                ->orderForStaleEnrichment();
            $this->restrictToMarketingEditable($staleQuery, $request);

            if (Schema::hasTable('site_enrichment_runs')) {
                $staleQuery->with('latestEnrichmentRun');
                $placeholderSiteIds = array_fill_keys(
                    SiteEnrichmentRun::placeholderScreenshotSiteIds(),
                    true
                );
            }

            $staleSites = $staleQuery
                ->paginate(40, ['*'], 'stale_page')
                ->withQueryString();
            $staleCount = $staleSites->total();
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment stale list failed', [
                'error' => $e->getMessage(),
            ]);
            $staleSites = new LengthAwarePaginator([], 0, 40);
            $staleSites->withPath($request->url())->appends($request->query());
        }

        return view('admin.site-enrichment', compact(
            'attention',
            'config',
            'staleCount',
            'staleSites',
            'placeholderSiteIds',
            'batchLimit',
            'status',
            'type',
            'marketingEditor'
        ));
    }

    public function refreshMetrics(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }
        $sync = $request->boolean('sync', false);

        if ($sync) {
            try {
                $run = $enrichment->refreshMetrics($site, 'admin', $request->input('provider'));
            } catch (\Throwable $e) {
                Log::warning('Admin enrichment refreshMetrics failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Could not refresh metrics. Please try again after migrations are applied.',
                    'run' => null,
                    'site' => $site->fresh(),
                ], 422);
            }
        } else {
            RefreshSiteMetricsJob::dispatch($site->id, 'admin', $request->input('provider'));
            $run = null;
        }

        $runStatus = (string) data_get($run, 'status', '');
        $ok = $sync
            ? in_array($runStatus, ['success', 'partial'], true)
            : true;

        if ($ok) {
            $this->logRefreshOutcome(
                $site,
                $sync,
                'site.metrics_refreshed',
                'site.metrics_refresh_queued',
                'metrics',
                ['provider' => $request->input('provider')]
            );
        }

        $providerError = trim((string) (data_get($run, 'error') ?? $site->fresh()?->enrichment_error ?? ''));

        return response()->json([
            'success' => $ok,
            'message' => $sync
                ? ($ok ? 'Metrics refreshed' : UserFacingError::safeText($providerError, 'Metrics refresh failed.'))
                : 'Metrics refresh queued',
            'run' => $run,
            'site' => $site->fresh(),
        ], $ok ? 200 : 422);
    }

    public function refreshScreenshot(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }
        // Default async — Sites Management must not block on remote capture.
        $sync = $request->boolean('sync', false);

        if ($sync) {
            try {
                $run = $enrichment->refreshScreenshot($site, 'admin');
            } catch (\Throwable $e) {
                Log::warning('Admin enrichment refreshScreenshot failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Could not refresh the screenshot. Please try again after migrations are applied.',
                    'run' => null,
                    'site' => $site->fresh(),
                ], 422);
            }
        } else {
            CaptureSiteScreenshotJob::dispatch($site->id, 'admin');
            $run = null;
        }

        $fresh = $site->fresh();
        $usedPlaceholder = (bool) data_get($run, 'payload.used_placeholder', false);
        $runStatus = (string) data_get($run, 'status', '');
        $providerError = trim((string) (
            data_get($run, 'error')
            ?? $fresh?->enrichment_error
            ?? ''
        ));
        // Placeholder / partial captures look like success in the UI but leave a
        // broken preview — treat them as failures so staff upload a site image.
        $ok = $sync
            ? (! $usedPlaceholder && $runStatus === 'success')
            : true;

        if ($ok) {
            $this->logRefreshOutcome(
                $site,
                $sync,
                'site.screenshot_refreshed',
                'site.screenshot_refresh_queued',
                'screenshot'
            );
        }

        $message = $sync
            ? ($ok
                ? 'Screenshot refreshed'
                : UserFacingError::safeText($providerError, 'Screenshot capture failed. Upload a site image instead.'))
            : 'Screenshot refresh queued';

        return response()->json([
            'success' => $ok,
            'message' => $message,
            'run' => $run,
            'site' => $fresh,
        ], $ok ? 200 : 422);
    }

    public function enrich(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }
        // Default async — Manage → Enrich should return immediately.
        $sync = $request->boolean('sync', false);

        if ($sync) {
            try {
                $enrichment->enrich($site, 'admin', true, true);
            } catch (\Throwable $e) {
                Log::warning('Admin enrichment enrich failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Could not enrich this site. Please try again.',
                    'site' => $site->fresh(),
                ], 422);
            }
        } else {
            EnrichSiteJob::dispatch($site->id, 'admin', true, true);
        }

        $actor = auth()->user()?->name ?? 'Staff';
        ActivityLogger::tryLog(
            $sync ? 'site.enrichment_refreshed' : 'site.enrichment_queued',
            $sync
                ? $actor.' enriched "'.$site->site_name.'"'
                : $actor.' queued enrichment for "'.$site->site_name.'"',
            $site,
            ['sync' => $sync],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => $sync ? 'Site enriched' : 'Enrichment queued',
            'site' => $site->fresh(),
        ]);
    }

    public function manualMetrics(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            return $denied;
        }

        $data = $request->validate([
            'dr' => 'nullable|integer|min:0|max:100',
            'da' => 'nullable|integer|min:0|max:100',
            'traffic' => 'nullable|integer|min:0|max:4294967295',
        ]);

        $newDr = array_key_exists('dr', $data) ? $data['dr'] : $site->dr;
        $newDa = array_key_exists('da', $data) ? $data['da'] : $site->da;
        $newTraffic = array_key_exists('traffic', $data) ? $data['traffic'] : $site->traffic;
        if ((bool) $site->metrics_manual
            && (int) ($site->dr ?? 0) === (int) ($newDr ?? 0)
            && (int) ($site->da ?? 0) === (int) ($newDa ?? 0)
            && (int) ($site->traffic ?? 0) === (int) ($newTraffic ?? 0)) {
            return response()->json([
                'success' => true,
                'message' => 'Manual metrics saved',
                'run' => null,
                'site' => $site->fresh(),
            ]);
        }

        try {
            $run = $enrichment->applyManualMetrics(
                $site,
                isset($data['dr']) ? (int) $data['dr'] : null,
                isset($data['da']) ? (int) $data['da'] : null,
                isset($data['traffic']) ? (int) $data['traffic'] : null,
                'admin'
            );
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment manualMetrics failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not save manual metrics. Please try again after migrations are applied.',
                'run' => null,
                'site' => $site->fresh(),
            ], 422);
        }

        ActivityLogger::tryLog(
            'site.metrics_manual',
            auth()->user()->name.' set manual metrics for "'.$site->site_name.'"',
            $site,
            $data,
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => 'Manual metrics saved',
            'run' => $run,
            'site' => $site->fresh(),
        ]);
    }

    public function allowApiOverwrite(Request $request, int $id)
    {
        if (! Site::hasSitesColumn('metrics_manual')) {
            return $request->wantsJson()
                ? response()->json([
                    'success' => false,
                    'message' => 'Manual metrics lock is unavailable until the database migration has been run.',
                ], 422)
                : back()->withErrors(['metrics_manual' => 'Manual metrics lock is unavailable until the database migration has been run.']);
        }

        $site = Site::findOrFail($id);
        if ($denied = $this->denyMarketingLockedListing($request, $site)) {
            if ($request->wantsJson()) {
                return $denied;
            }

            return back()->withErrors(['metrics_manual' => (string) data_get($denied->getData(true), 'message', 'Not allowed.')]);
        }

        $alreadyUnlocked = ! (bool) $site->metrics_manual;
        $site->forceFill(['metrics_manual' => false])->save();

        if (! $alreadyUnlocked) {
            ActivityLogger::tryLog(
                'site.metrics_api_unlocked',
                auth()->user()->name.' allowed API overwrite for "'.$site->site_name.'"',
                $site,
                [],
                $site->site_name
            );
        }

        $message = 'API overwrite allowed. Queue Enrich to fetch live metrics.';

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'site' => $site->fresh(),
            ]);
        }

        return back()->with('success', $message);
    }

    public function queueStale(Request $request)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }

        $configured = max(1, (int) config('site_enrichment.batch_limit', 40));
        $limit = min($configured, max(1, (int) $request->input('limit', $configured)));

        try {
            $ids = Site::query()
                ->where('active', 1)
                ->staleForEnrichment()
                ->orderForStaleEnrichment();
            $this->restrictToMarketingEditable($ids, $request);
            $ids = $ids->limit($limit)->pluck('id');

            foreach ($ids as $siteId) {
                EnrichSiteJob::dispatch((int) $siteId, 'admin', true, true);
            }

            if ($ids->count() > 0) {
                ActivityLogger::tryLog(
                    'site.enrichment_batch_queued',
                    (auth()->user()?->name ?? 'Staff').' queued '.$ids->count().' stale site(s) for enrichment',
                    null,
                    [
                        'count' => $ids->count(),
                        'limit' => $limit,
                        'site_ids' => $ids->values()->take(40)->all(),
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Queued '.$ids->count().' stale site(s)',
                'count' => $ids->count(),
                'site_ids' => $ids->values()->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment queueStale failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not queue stale sites. Please try again after migrations are applied.',
                'count' => 0,
            ], 422);
        }
    }

    public function rerunFailed(Request $request)
    {
        if ($denied = $this->denyIfEnrichmentDisabled()) {
            return $denied;
        }

        if (! Schema::hasTable('site_enrichment_runs')) {
            return response()->json([
                'success' => false,
                'message' => 'Enrichment history is unavailable until the database migration has been run.',
                'count' => 0,
            ], 422);
        }

        try {
            $limit = min(100, max(1, (int) $request->input('limit', 20)));
            $ids = SiteEnrichmentRun::query()
                ->needsAttention()
                ->latest('id')
                ->limit($limit)
                ->pluck('site_id')
                ->unique()
                ->filter();

            if ($this->isMarketingEditor($request) && $ids->isNotEmpty()) {
                $ids = Site::query()
                    ->whereIn('id', $ids)
                    ->editableByMarketing()
                    ->pluck('id');
            }

            foreach ($ids as $siteId) {
                EnrichSiteJob::dispatch((int) $siteId, 'admin', true, true);
            }

            if ($ids->count() > 0) {
                ActivityLogger::tryLog(
                    'site.enrichment_rerun_queued',
                    (auth()->user()?->name ?? 'Staff').' re-queued '.$ids->count().' failed site(s) for enrichment',
                    null,
                    [
                        'count' => $ids->count(),
                        'limit' => $limit,
                        'site_ids' => $ids->values()->take(40)->all(),
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Queued '.$ids->count().' site(s) for re-scan',
                'count' => $ids->count(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Admin enrichment rerunFailed failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not queue failed scans. Please try again after migrations are applied.',
                'count' => 0,
            ], 422);
        }
    }

    /**
     * Columns for SiteEnrichmentRun→site eager load (skip Hostinger-missing cols).
     *
     * @return list<string>
     */
    private function siteRelationSelectColumns(): array
    {
        $columns = ['id', 'site_name', 'domain', 'site_url'];
        foreach (['enrichment_status', 'metrics_fetched_at', 'screenshot_fetched_at'] as $optional) {
            if (Site::hasSitesColumn($optional)) {
                $columns[] = $optional;
            }
        }

        return $columns;
    }

    private function screenshotProviderLabel(): string
    {
        $provider = (string) config('site_enrichment.screenshots.provider', 'thum_io');

        if ($provider === 'thum_io') {
            return 'thum_io (unauthenticated)';
        }

        if ($provider === 'screenshotone') {
            return filled(config('site_enrichment.screenshots.screenshotone_access_key'))
                ? 'screenshotone'
                : 'screenshotone (no key)';
        }

        if ($provider === 'url_api') {
            return filled(config('site_enrichment.screenshots.api_url'))
                ? 'url_api'
                : 'url_api (no url)';
        }

        return $provider;
    }

    private function isMarketingEditor(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->isMarketing() && ! $user?->isAdmin());
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function restrictToMarketingEditable($query, Request $request): void
    {
        if ($this->isMarketingEditor($request)) {
            $query->editableByMarketing();
        }
    }

    private function denyIfEnrichmentDisabled()
    {
        if (SiteEnrichmentService::enabled()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Site enrichment is disabled (SITE_ENRICHMENT_ENABLED=false).',
        ], 422);
    }

    private function attentionStatusFilter(mixed $status): ?string
    {
        $status = is_string($status) ? strtolower(trim($status)) : '';

        return in_array($status, SiteEnrichmentRun::ATTENTION_STATUSES, true) ? $status : null;
    }

    private function attentionTypeFilter(mixed $type): ?string
    {
        $type = is_string($type) ? strtolower(trim($type)) : '';

        return in_array($type, ['metrics', 'screenshot'], true) ? $type : null;
    }

    private function denyMarketingLockedListing(Request $request, Site $site)
    {
        $user = $request->user();
        if ($user?->isMarketing() && ! $user?->isAdmin() && $site->isLockedForMarketingEdits()) {
            return response()->json([
                'success' => false,
                'message' => 'Marketing can only enrich pending sites that are not live.',
            ], 403);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logRefreshOutcome(
        Site $site,
        bool $sync,
        string $refreshedAction,
        string $queuedAction,
        string $noun,
        array $properties = []
    ): void {
        $actor = auth()->user()?->name ?? 'Staff';

        ActivityLogger::tryLog(
            $sync ? $refreshedAction : $queuedAction,
            $sync
                ? $actor.' refreshed '.$noun.' for "'.$site->site_name.'"'
                : $actor.' queued a '.$noun.' refresh for "'.$site->site_name.'"',
            $site,
            $properties + ['sync' => $sync],
            $site->site_name
        );
    }
}
