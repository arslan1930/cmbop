<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkSitesSeededNotification;
use App\Models\BulkSiteRequest;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class BulkSiteRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->string('status')->toString();

        $query = BulkSiteRequest::query()
            ->with(['publisher', 'handler'])
            ->withCount([
                'sites',
                'sites as awaiting_details_count' => fn ($q) => $q->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS),
                'sites as ready_count' => fn ($q) => $q->where('onboarding_status', Site::ONBOARDING_READY_FOR_REVIEW),
            ])
            ->latest();

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(20)->withQueryString();

        return view('admin.bulk-site-requests.index', [
            'requests' => $requests,
            'status' => $status !== '' ? $status : 'all',
            'openCount' => BulkSiteRequest::query()
                ->whereNotIn('status', [BulkSiteRequest::STATUS_COMPLETED, BulkSiteRequest::STATUS_CANCELLED])
                ->count(),
        ]);
    }

    public function show(int $id)
    {
        $bulkRequest = BulkSiteRequest::with(['publisher', 'handler', 'sites' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);

        $countries = Country::marketplace()->orderBy('name')->get();
        $languages = Language::marketplace()->orderBy('name')->get();

        return view('admin.bulk-site-requests.show', compact('bulkRequest', 'countries', 'languages'));
    }

    public function markSheetSent(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'This request was cancelled.');
        }

        $bulkRequest->forceFill([
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'sheet_sent_at' => now(),
            'handled_by' => auth()->id(),
            'admin_notes' => $request->input('admin_notes', $bulkRequest->admin_notes),
        ])->save();

        return back()->with('success', 'Marked as sheet sent. Seed the sites when the publisher returns URLs + prices.');
    }

    public function updateNotes(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);
        $bulkRequest->forceFill([
            'admin_notes' => $request->input('admin_notes'),
            'handled_by' => auth()->id(),
        ])->save();

        return back()->with('success', 'Notes saved.');
    }

    public function cancel(int $id)
    {
        $bulkRequest = BulkSiteRequest::findOrFail($id);
        $bulkRequest->forceFill([
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'handled_by' => auth()->id(),
        ])->save();

        return redirect()
            ->route('admin.bulk-site-requests.index')
            ->with('success', 'Bulk request cancelled.');
    }

    /**
     * Seed draft sites from pasted rows:
     * url,price,da,dr,traffic,language,country[,site_name]
     */
    public function seed(Request $request, int $id)
    {
        $bulkRequest = BulkSiteRequest::with('publisher')->findOrFail($id);

        if ($bulkRequest->status === BulkSiteRequest::STATUS_CANCELLED) {
            return back()->with('error', 'Cannot seed a cancelled request.');
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|string|min:3',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        $parsed = $this->parseSeedRows((string) $request->input('rows'), $allowedCountries, $allowedLanguages);
        if ($parsed['rows'] === [] && $parsed['failures'] === []) {
            return back()->with('error', 'No rows found. Paste one site per line: url,price,da,dr,traffic,language,country')->withInput();
        }

        if ($parsed['rows'] === []) {
            return back()
                ->with('error', 'All rows failed validation.')
                ->with('seed_failures', $parsed['failures'])
                ->withInput();
        }

        $created = 0;
        $failures = $parsed['failures'];

        DB::transaction(function () use ($bulkRequest, $parsed, &$created, &$failures) {
            foreach ($parsed['rows'] as $index => $row) {
                $domain = $row['domain'];

                if (Site::where('domain', $domain)->exists()) {
                    $failures[] = [
                        'line' => $row['line'],
                        'url' => $row['site_url'],
                        'errors' => ['Domain already registered: '.$domain],
                    ];

                    continue;
                }

                $site = new Site;
                $site->applyMarketplaceListing([
                    'publisher_id' => $bulkRequest->publisher_id,
                    'bulk_site_request_id' => $bulkRequest->id,
                    'site_name' => $row['site_name'],
                    'site_url' => $row['site_url'],
                    'domain' => $domain,
                    'example_url' => $row['site_url'],
                    'da' => $row['da'],
                    'dr' => $row['dr'],
                    'traffic' => $row['traffic'],
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                    'country' => $row['country'],
                    'countries' => [$row['country']],
                    'language' => $row['language'],
                    'languages' => [$row['language']],
                    'category' => 'Pending',
                    'categories' => null,
                    'price' => $row['price'],
                    'turnaround_time' => '3days',
                    'publication_time' => 'permanent',
                    'link_type' => 'dofollow',
                    'description' => 'Please replace this placeholder with a real site description (at least 50 characters) before submitting for review.',
                    'sponsored' => false,
                    'partner_material' => false,
                    'as_you_prefer' => true,
                    'verified' => false,
                    'active' => false,
                    'enrichment_status' => 'pending',
                    'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
                ]);
                $site->save();
                $created++;
            }

            if ($created > 0) {
                $bulkRequest->forceFill([
                    'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
                    'seeded_at' => $bulkRequest->seeded_at ?? now(),
                    'handled_by' => auth()->id(),
                    'completed_at' => null,
                ])->save();
            }
        });

        if ($created > 0) {
            try {
                $publisher = $bulkRequest->publisher;
                if ($publisher?->email) {
                    Mail::to($publisher->email)->send(new BulkSitesSeededNotification($bulkRequest->fresh(), $created, $publisher));
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to email publisher after bulk seed: '.$e->getMessage());
            }
        }

        $message = "{$created} site(s) seeded as drafts (hidden from catalog).";
        if ($failures !== []) {
            $message .= ' '.count($failures).' row(s) failed.';
        }

        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('seed_failures', $failures);
    }

    /**
     * @param  list<string>  $allowedCountries
     * @param  list<string>  $allowedLanguages
     * @return array{rows: list<array<string, mixed>>, failures: list<array<string, mixed>>}
     */
    private function parseSeedRows(string $raw, array $allowedCountries, array $allowedLanguages): array
    {
        $rows = [];
        $failures = [];
        $lines = preg_split('/\R/', $raw) ?: [];

        foreach ($lines as $i => $line) {
            $lineNum = $i + 1;
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with(strtolower($trimmed), 'url')) {
                continue;
            }

            $parts = preg_split('/[\t,;]+/', $trimmed) ?: [];
            $parts = array_map('trim', $parts);

            if (count($parts) < 7) {
                $failures[] = [
                    'line' => $lineNum,
                    'url' => $parts[0] ?? '',
                    'errors' => ['Need 7 columns: url,price,da,dr,traffic,language,country'],
                ];

                continue;
            }

            [$urlRaw, $priceRaw, $daRaw, $drRaw, $trafficRaw, $langRaw, $countryRaw] = array_slice($parts, 0, 7);
            $siteName = isset($parts[7]) && $parts[7] !== '' ? $parts[7] : null;

            $siteUrl = $this->normalizeHttpUrl($urlRaw);
            $host = parse_url($siteUrl, PHP_URL_HOST);
            $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;

            $errors = [];
            if (! $domain) {
                $errors[] = 'Invalid URL';
            }

            $price = is_numeric($priceRaw) ? (float) $priceRaw : null;
            $da = is_numeric($daRaw) ? (int) $daRaw : null;
            $dr = is_numeric($drRaw) ? (int) $drRaw : null;
            $traffic = is_numeric($trafficRaw) ? (int) $trafficRaw : null;
            $language = strtolower($langRaw);
            $country = strtolower($countryRaw);

            if ($price === null || $price < 0) {
                $errors[] = 'Invalid price';
            }
            if ($da === null || $da < 0 || $da > 100) {
                $errors[] = 'Invalid DA';
            }
            if ($dr === null || $dr < 0 || $dr > 100) {
                $errors[] = 'Invalid DR';
            }
            if ($traffic === null || $traffic < 0) {
                $errors[] = 'Invalid traffic';
            }
            if (! in_array($language, $allowedLanguages, true)) {
                $errors[] = 'Unknown language code';
            }
            if (! in_array($country, $allowedCountries, true)) {
                $errors[] = 'Unknown country code';
            }

            if ($errors !== []) {
                $failures[] = ['line' => $lineNum, 'url' => $siteUrl, 'errors' => $errors];

                continue;
            }

            $rows[] = [
                'line' => $lineNum,
                'site_url' => $siteUrl,
                'domain' => $domain,
                'site_name' => $siteName ?: $domain,
                'price' => $price,
                'da' => $da,
                'dr' => $dr,
                'traffic' => $traffic,
                'language' => $language,
                'country' => $country,
            ];
        }

        return compact('rows', 'failures');
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
