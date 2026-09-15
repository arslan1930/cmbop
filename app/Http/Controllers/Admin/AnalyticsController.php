<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Admin\FinanceOverviewService;
use App\Services\Admin\MarketplaceAnalyticsService;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        private FinanceOverviewService $finance,
        private MarketplaceAnalyticsService $analytics,
    ) {}

    public function index(Request $request): View
    {
        $input = $this->validatedPeriodInput($request);
        $period = $this->finance->resolvePeriod(
            $input['period'] ?? null,
            $input['date_from'] ?? null,
            $input['date_to'] ?? null
        );
        $data = $this->analytics->snapshot($period);

        return view('admin.analytics', [
            'data' => $data,
            'periodKey' => $period['key'],
            'dateFrom' => $input['date_from'] ?? null,
            'dateTo' => $input['date_to'] ?? null,
            'exportQuery' => array_filter([
                'date_from' => $input['date_from'] ?? null,
                'date_to' => $input['date_to'] ?? null,
                'period' => (! ($input['date_from'] ?? null) && ! ($input['date_to'] ?? null) && in_array($period['key'], ['week', 'month', 'all'], true))
                    ? $period['key']
                    : null,
            ]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $input = $this->validatedPeriodInput($request);
        $period = $this->finance->resolvePeriod(
            $input['period'] ?? null,
            $input['date_from'] ?? null,
            $input['date_to'] ?? null
        );
        $rows = $this->analytics->exportRows($period);
        $filename = 'marketplace-analytics-'.$period['key'].'-'.now()->format('Y-m-d-His').'.csv';

        ActivityLogger::tryLog(
            'analytics.exported',
            ($request->user()?->name ?? 'Admin').' exported marketplace analytics ('.$period['label'].').',
            null,
            [
                'period' => $period['key'] ?? null,
                'date_from' => $input['date_from'] ?? null,
                'date_to' => $input['date_to'] ?? null,
                'rows_exported' => count($rows),
            ]
        );

        return response()->streamDownload(function () use ($rows, $period) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['period', 'section', 'metric', 'value']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    Csv::cell($period['label'] ?? ''),
                    Csv::cell($row['section'] ?? ''),
                    Csv::cell($row['metric'] ?? ''),
                    Csv::cell($row['value'] ?? ''),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{period: ?string, date_from: ?string, date_to: ?string}
     */
    private function validatedPeriodInput(Request $request): array
    {
        return validator(
            [
                'period' => search_text($request->input('period')) ?: null,
                'date_from' => search_text($request->input('date_from')) ?: null,
                'date_to' => search_text($request->input('date_to')) ?: null,
            ],
            [
                'period' => 'nullable|in:week,month,all',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]
        )->validate();
    }
}
