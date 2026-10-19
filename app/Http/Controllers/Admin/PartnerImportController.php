<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerImportJob;
use App\Services\Partners\PartnerImportPipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerImportController extends Controller
{
    public function __construct(private PartnerImportPipeline $pipeline) {}

    public function index(Request $request): View
    {
        $jobs = PartnerImportJob::query()
            ->with('partner.user')
            ->orderByDesc('id')
            ->paginate(30);
        $partners = Partner::query()->with('user')->orderByDesc('id')->get();

        return view('admin.partners.imports', compact('jobs', 'partners'));
    }

    public function show(PartnerImportJob $import): View
    {
        $import->load(['partner.user', 'rows']);

        return view('admin.partners.import-show', ['job' => $import]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'partner_id' => 'required|integer|exists:partners,id',
            'file' => 'required|file|max:20480',
            'skip_reachability' => 'nullable|boolean',
        ]);
        $partner = Partner::query()->findOrFail((int) $data['partner_id']);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return back()->with('error', 'Choose a CSV, XLSX, JSON, or XML file.');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt', 'xlsx', 'json', 'xml'], true)) {
            return back()->with('error', 'Use CSV, XLSX, JSON, or XML.');
        }
        $contents = file_get_contents($file->getRealPath() ?: $file->getPathname());
        if (! is_string($contents) || $contents === '') {
            return back()->with('error', 'The file was empty.');
        }

        $job = $this->pipeline->importUpload($partner, $file->getClientOriginalName(), $contents, [
            'actor' => $request->user(),
            'skip_reachability' => $request->boolean('skip_reachability'),
        ]);

        return redirect()
            ->route('admin.partners.imports.show', $job)
            ->with('success', 'Import '.$job->status.'. Created '.((int) ($job->counts['created'] ?? 0)).', blocked '.((int) ($job->counts['blocked'] ?? 0)).'.');
    }

    public function syncFeed(Request $request, Partner $partner): RedirectResponse
    {
        $connection = $partner->feedConnection;
        if (! $connection || blank($connection->feed_url)) {
            return back()->with('error', 'This partner has no feed URL.');
        }
        $job = $this->pipeline->importFeed($partner, $connection, [
            'actor' => $request->user(),
            'skip_reachability' => $request->boolean('skip_reachability'),
        ]);

        return redirect()->route('admin.partners.imports.show', $job)
            ->with('success', 'Feed sync '.$job->status.'.');
    }

    public function confirmDrop(Request $request, PartnerImportJob $import): RedirectResponse
    {
        $this->pipeline->confirmPendingRemovals($import, $request->user());

        return back()->with('success', 'Missing feed rows were paused. Open orders are unchanged.');
    }

    public function template(): StreamedResponse
    {
        $headers = [
            'email', 'name', 'url', 'price', 'currency', 'country', 'language', 'da', 'dr', 'traffic',
            'site_name', 'example_url', 'turnaround', 'publication', 'link_type', 'tag', 'niches', 'description',
            'writing_fee', 'sensitive_extra_price',
        ];
        $example = [
            'partner@example.com', 'PlaCla s.r.o.', 'https://zahrada-dnes.cz', '40', 'EUR', 'CZ', 'cs', '30', '34', '18000',
            'Zahrada Dnes', 'https://zahrada-dnes.cz/sample', '5', 'permanent', 'dofollow', 'never', 'Home & Garden',
            'Garden tips and home articles.', '', '',
        ];

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            fputcsv($out, $example);
            fclose($out);
        }, 'SLB_partner_sites_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
