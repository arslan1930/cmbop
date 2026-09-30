<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\AudienceInventoryService;
use App\Support\AdminAudiences;
use Illuminate\Http\Request;

class AudienceController extends Controller
{
    public function index(Request $request, AudienceInventoryService $inventory)
    {
        $audienceKey = $this->resolvedAudienceKey($request->get('tab', 'advertisers'));
        $tab = AudienceInventoryService::tabForAudienceKey($audienceKey);
        $search = AdminAudiences::searchFromRequest($request);
        $filters = AdminAudiences::filtersFromRequest($request);
        $users = $inventory->paginate($audienceKey, $search !== '' ? $search : null, 25, $filters);
        try {
            $stats = $inventory->stats();
        } catch (\Throwable) {
            $stats = [];
        }
        try {
            $countries = $inventory->inventoryCountries();
        } catch (\Throwable) {
            $countries = [];
        }
        try {
            $exportMatchCount = $inventory->exportMatchCount($audienceKey, $search !== '' ? $search : null, $filters);
        } catch (\Throwable) {
            $exportMatchCount = (int) $users->total();
        }
        $campaignAudience = $audienceKey;
        $filterQuery = AdminAudiences::filterQuery($search, $filters);
        $sendQuery = AdminAudiences::filterQuery($search, $filters, includeSort: false);

        return view('admin.audiences.index', compact(
            'tab',
            'users',
            'stats',
            'search',
            'campaignAudience',
            'filters',
            'filterQuery',
            'sendQuery',
            'countries',
            'exportMatchCount'
        ));
    }

    public function export(Request $request, AudienceInventoryService $inventory)
    {
        $audienceKey = $this->resolvedAudienceKey($request->get('audience', 'advertisers'));
        $search = AdminAudiences::searchFromRequest($request);
        $filters = AdminAudiences::filtersFromRequest($request);

        $query = $inventory->prepareExportQuery($audienceKey, $search !== '' ? $search : null, $filters);
        if ($query === null) {
            return $inventory->exportCsv($audienceKey, $search !== '' ? $search : null, $filters);
        }

        try {
            $rowCount = $inventory->exportMatchCount($audienceKey, $search !== '' ? $search : null, $filters);
        } catch (\Throwable) {
            $rowCount = 0;
        }

        ActivityLogger::tryLog(
            'audience.exported',
            'Exported '.AudienceInventoryService::label($audienceKey).' audience CSV.',
            null,
            [
                'audience' => $audienceKey,
                'search' => $search,
                'filters' => $filters,
                'rows_exported' => min($rowCount, AudienceInventoryService::EXPORT_LIMIT),
                'truncated' => $rowCount > AudienceInventoryService::EXPORT_LIMIT,
            ]
        );

        return $inventory->exportCsv($audienceKey, $search !== '' ? $search : null, $filters, $query);
    }

    protected function resolvedAudienceKey(mixed $raw): string
    {
        $key = AudienceInventoryService::normalizeAudienceKey(search_text($raw));

        if (! AudienceInventoryService::isListableKey($key)) {
            return AudienceInventoryService::AUDIENCE_ADVERTISERS;
        }

        return $key;
    }
}
