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
use Illuminate\Http\Request;

class SiteEnrichmentController extends Controller
{
    public function index(Request $request)
    {
        $failures = SiteEnrichmentRun::query()
            ->with('site:id,site_name,domain,site_url,enrichment_status,metrics_fetched_at,screenshot_fetched_at')
            ->where('status', 'failed')
            ->latest('id')
            ->paginate(40);

        $config = [
            'enabled' => (bool) config('site_enrichment.enabled'),
            'default_provider' => (string) config('site_enrichment.default_provider'),
            'fallback_providers' => config('site_enrichment.fallback_providers'),
            'refresh_frequency' => (string) config('site_enrichment.refresh_frequency'),
            'max_age_days' => (int) config('site_enrichment.max_age_days'),
            'screenshot_provider' => (string) config('site_enrichment.screenshots.provider'),
        ];

        $staleCount = Site::query()
            ->where('active', 1)
            ->where(function ($q) {
                $before = now()->subDays((int) config('site_enrichment.max_age_days', 90));
                $q->whereNull('metrics_fetched_at')
                    ->orWhere('metrics_fetched_at', '<', $before)
                    ->orWhereNull('screenshot_path');
            })
            ->count();

        return view('admin.site-enrichment', compact('failures', 'config', 'staleCount'));
    }

    public function refreshMetrics(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        $site = Site::findOrFail($id);
        $sync = $request->boolean('sync', true);

        if ($sync) {
            $run = $enrichment->refreshMetrics($site, 'admin', $request->input('provider'));
        } else {
            RefreshSiteMetricsJob::dispatch($site->id, 'admin', $request->input('provider'));
            $run = null;
        }

        ActivityLogger::log(
            'site.metrics_refreshed',
            auth()->user()->name.' refreshed metrics for "'.$site->site_name.'"',
            $site,
            ['provider' => $request->input('provider'), 'sync' => $sync],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => $sync ? 'Metrics refreshed' : 'Metrics refresh queued',
            'run' => $run,
            'site' => $site->fresh(),
        ]);
    }

    public function refreshScreenshot(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        $site = Site::findOrFail($id);
        $sync = $request->boolean('sync', true);

        if ($sync) {
            $run = $enrichment->refreshScreenshot($site, 'admin');
        } else {
            CaptureSiteScreenshotJob::dispatch($site->id, 'admin');
            $run = null;
        }

        ActivityLogger::log(
            'site.screenshot_refreshed',
            auth()->user()->name.' refreshed screenshot for "'.$site->site_name.'"',
            $site,
            ['sync' => $sync],
            $site->site_name
        );

        return response()->json([
            'success' => true,
            'message' => $sync ? 'Screenshot refreshed' : 'Screenshot refresh queued',
            'run' => $run,
            'site' => $site->fresh(),
        ]);
    }

    public function enrich(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        $site = Site::findOrFail($id);
        $sync = $request->boolean('sync', true);

        if ($sync) {
            $enrichment->enrich($site, 'admin', true, true);
        } else {
            EnrichSiteJob::dispatch($site->id, 'admin', true, true);
        }

        return response()->json([
            'success' => true,
            'message' => $sync ? 'Site enriched' : 'Enrichment queued',
            'site' => $site->fresh(),
        ]);
    }

    public function manualMetrics(Request $request, int $id, SiteEnrichmentService $enrichment)
    {
        $site = Site::findOrFail($id);

        $data = $request->validate([
            'dr' => 'nullable|integer|min:0|max:100',
            'da' => 'nullable|integer|min:0|max:100',
            'traffic' => 'nullable|integer|min:0',
        ]);

        $run = $enrichment->applyManualMetrics(
            $site,
            isset($data['dr']) ? (int) $data['dr'] : null,
            isset($data['da']) ? (int) $data['da'] : null,
            isset($data['traffic']) ? (int) $data['traffic'] : null,
            'admin'
        );

        ActivityLogger::log(
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

    public function rerunFailed(Request $request)
    {
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $ids = SiteEnrichmentRun::query()
            ->where('status', 'failed')
            ->latest('id')
            ->limit($limit * 3)
            ->pluck('site_id')
            ->unique()
            ->take($limit);

        foreach ($ids as $siteId) {
            EnrichSiteJob::dispatch((int) $siteId, 'admin', true, true);
        }

        return response()->json([
            'success' => true,
            'message' => 'Queued '.$ids->count().' failed site(s) for re-scan',
            'count' => $ids->count(),
        ]);
    }
}
