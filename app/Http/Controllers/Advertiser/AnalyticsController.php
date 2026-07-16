<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\AdvertiserAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(private AdvertiserAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        $filters = [
            'sites_sort' => $request->get('sites_sort', 'spend'),
            'calendar_year' => $request->integer('calendar_year') ?: now()->year,
            'timeline_from' => $request->get('timeline_from'),
            'timeline_to' => $request->get('timeline_to'),
            'timeline_event' => $request->get('timeline_event'),
            'timeline_order' => $request->get('timeline_order'),
        ];

        $analytics = $this->analytics->build($request->user(), $filters);

        return view('advertiser.analytics', compact('analytics', 'filters'));
    }

    public function export(Request $request)
    {
        $request->validate([
            'type' => 'required|in:spending,orders,monthly,websites,categories',
            'format' => 'required|in:csv,xlsx,pdf',
        ]);

        $user = $request->user();
        $type = $request->string('type')->toString();
        $format = $request->string('format')->toString();
        $filters = [
            'sites_sort' => $request->get('sites_sort', 'spend'),
            'calendar_year' => $request->integer('calendar_year') ?: now()->year,
            'timeline_from' => $request->get('timeline_from'),
            'timeline_to' => $request->get('timeline_to'),
            'timeline_event' => $request->get('timeline_event'),
            'timeline_order' => $request->get('timeline_order'),
        ];
        $rows = $this->analytics->exportRows($user, $type, $filters);
        $filename = 'advertiser-' . $type . '-' . now()->format('Ymd-His');

        if ($format === 'csv') {
            return $this->exportCsv($rows, $filename . '.csv');
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($rows, $filename . '.xlsx');
        }

        $pdf = Pdf::loadView('advertiser.exports.analytics-pdf', [
            'title' => ucfirst($type) . ' report',
            'rows' => $rows,
            'generatedAt' => now(),
            'user' => $user,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename . '.pdf');
    }

    protected function exportCsv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            if (!empty($rows)) {
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($out, array_values($row));
                }
            } else {
                fputcsv($out, ['No data']);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function exportXlsx(array $rows, string $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        if (empty($rows)) {
            $sheet->setCellValue('A1', 'No data');
        } else {
            $headers = array_keys($rows[0]);
            foreach ($headers as $i => $header) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
            }
            foreach ($rows as $r => $row) {
                foreach (array_values($row) as $c => $value) {
                    if ($value instanceof \DateTimeInterface) {
                        $value = $value->format('Y-m-d H:i:s');
                    }
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($c + 1) . ($r + 2), $value);
                }
            }
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
