<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Mail\NewSiteNotification;
use App\Models\BulkSiteRequest;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SiteController extends Controller
{
    public function index()
    {
        // Europe + major North America markets
        $countries = Country::marketplace()->orderBy('name')->get();
        $categories = Category::orderBy('group')->orderBy('name')->get();
        $languages = Language::marketplace()
            ->with(['countries' => fn ($q) => $q->marketplace()->select('countries.id', 'countries.code', 'countries.name')])
            ->orderBy('name')
            ->get();

        // Map language code → related countries (e.g. German → DE, AT, CH)
        $languageCountryMap = [];
        foreach ($languages as $language) {
            $languageCountryMap[$language->code] = $language->countries
                ->map(fn ($c) => ['code' => strtolower($c->code), 'name' => $c->name])
                ->values()
                ->all();
        }

        // English sites can target every English-speaking market we list:
        // English regions + Chinese markets + Gulf + any pivot EN countries.
        $languageCountryMap['en'] = $this->englishMarketplaceCountries();

        $openBulkRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->whereNotIn('status', [
                BulkSiteRequest::STATUS_COMPLETED,
                BulkSiteRequest::STATUS_CANCELLED,
            ])
            ->latest()
            ->first();

        $awaitingDetailsCount = Site::query()
            ->where('publisher_id', auth()->id())
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->count();

        return view('publisher.websites', compact(
            'countries',
            'categories',
            'languages',
            'languageCountryMap',
            'openBulkRequest',
            'awaitingDetailsCount'
        ));
    }

    /**
     * Countries where publishers may list English-language sites.
     *
     * @return list<array{code: string, name: string}>
     */
    private function englishMarketplaceCountries(): array
    {
        $codes = array_values(array_unique(array_merge(
            config('markets.english_region_country_codes', []),
            config('markets.chinese_country_codes', []),
            config('markets.gulf_country_codes', []),
            Language::where('code', 'en')
                ->first()
                ?->countries()
                ->marketplace()
                ->pluck('code')
                ->all() ?? []
        )));

        return Country::marketplace()
            ->whereIn('code', $codes)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($c) => ['code' => strtolower((string) $c->code), 'name' => $c->name])
            ->values()
            ->all();
    }

    public function getCountryLanguages($countryCode)
    {
        $country = Country::where('code', $countryCode)->first();

        if (! $country) {
            return response()->json([]);
        }

        $languages = DB::table('country_language')
            ->join('languages', 'country_language.language_id', '=', 'languages.id')
            ->where('country_language.country_id', $country->id)
            ->select('languages.code', 'languages.name')
            ->get();

        return response()->json($languages);
    }

    public function store(Request $request)
    {
        // Normalize URLs before validation (publishers often omit https://)
        $siteUrl = $this->normalizeHttpUrl((string) $request->input('siteUrl', ''));
        $exampleUrl = $this->normalizeHttpUrl((string) $request->input('exampleUrl', ''));
        $request->merge([
            'siteUrl' => $siteUrl,
            'exampleUrl' => $exampleUrl,
        ]);

        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (! $host) {
            return back()->withErrors(['siteUrl' => 'Invalid URL'])->withInput();
        }

        $domain = preg_replace('/^www\./', '', strtolower($host));

        // Handle categories - get as array from multi-select
        $categories = $this->parseCategoryList($request->input('categories', $request->input('category')));
        // Pipe-join avoids breaking names that contain commas (e.g. "Marketing, PR & Advertising")
        $primaryCategory = ! empty($categories) ? implode('|', $categories) : (string) $request->category;
        $categoriesArray = ! empty($categories) ? $categories : null;

        // Single country + single language per website (manual entry — never auto-overwritten)
        $countryCodes = array_slice($this->parseCodeList($request->input('country', $request->input('countries'))), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($request->input('language', $request->input('languages'))), 0, 1);

        $request->merge([
            'country' => $countryCodes[0] ?? null,
            'language' => $languageCodes[0] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
        ]);

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();

        $validator = Validator::make($request->all(), [
            'siteName' => 'required|string|max:255',
            'siteUrl' => 'required|url|max:255',
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        $validator->after(function ($validator) use ($domain) {
            if (Site::where('publisher_id', auth()->id())->where('domain', $domain)->exists()) {
                $validator->errors()->add('siteUrl', 'You have already added this website.');
            }
        });

        $validator->after(function ($validator) use ($domain) {
            if (Site::where('domain', $domain)->exists()) {
                $validator->errors()->add('siteUrl', 'This website domain is already registered by another publisher. If you own it, use “Claim a website” on this page so we can verify the listing name and transfer ownership.');
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        $site = null;

        try {
            DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, &$site) {
                $site = new Site;

                $sensitivePrices = [];
                foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                    if ($request->input("sensitive.$topic")) {
                        $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                    }
                }

                // Manual publisher metrics — never auto-fetched/overwritten.
                // applyMarketplaceListing skips columns missing on older Hostinger DBs
                // and fits legacy category VARCHAR(50) when multi-category strings are long.
                $site->applyMarketplaceListing([
                    'publisher_id' => auth()->id(),
                    'site_name' => $request->siteName,
                    'site_url' => $request->siteUrl,
                    'domain' => $domain,
                    'example_url' => $request->exampleUrl,
                    'da' => (int) $request->da,
                    'dr' => (int) $request->dr,
                    'traffic' => (int) $request->traffic,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                    'country' => $countryCodes[0],
                    'countries' => $countryCodes,
                    'language' => $languageCodes[0],
                    'languages' => $languageCodes,
                    'category' => $primaryCategory,
                    'categories' => $categoriesArray,
                    'price' => $request->price,
                    'turnaround_time' => $request->turnaround_time,
                    'publication_time' => $request->publicationTime,
                    'link_type' => $request->link_type,
                    'description' => $cleanDescription,
                    'verified' => false,
                    'active' => false,
                    'enrichment_status' => 'pending',
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                ]);

                $this->applySiteTag($site, $request);

                $site->save();
            });
        } catch (\Throwable $e) {
            Log::error('Publisher site store failed', [
                'user_id' => auth()->id(),
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            $hint = 'We could not save this website. Please check your details and try again.';
            if (str_contains($e->getMessage(), 'Unknown column') || str_contains($e->getMessage(), 'Data too long')) {
                $hint = 'We could not save this website because the database is missing a recent update. Please contact support (or run the sites column migration SQL).';
            }

            return redirect()->back()
                ->withErrors(['siteUrl' => $hint])
                ->withInput();
        }

        // Homepage screenshot only (compress + WebP). Metrics stay manual.
        if ($site && config('site_enrichment.enabled', true)) {
            try {
                CaptureSiteScreenshotJob::dispatch($site->id, 'publisher_create');
            } catch (\Throwable $e) {
                Log::warning('Failed to queue site screenshot job', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($site) {
            try {
                $admins = User::where('active_role_id', function ($query) {
                    $query->select('id')
                        ->from('roles')
                        ->where('name', 'admin')
                        ->limit(1);
                })->get();

                if ($admins->count() > 0) {
                    foreach ($admins as $admin) {
                        Mail::to($admin->email)->send(new NewSiteNotification($site));
                    }
                } else {
                    $defaultAdminEmail = config('mail.admin_email');
                    if ($defaultAdminEmail) {
                        Mail::to($defaultAdminEmail)->send(new NewSiteNotification($site));
                    }
                }

                try {
                    app(InAppNotificationService::class)->notifyAdminsNewSite($site, 'create');
                } catch (\Throwable $e) {
                    Log::warning('Failed to send admin new-site bell notification: '.$e->getMessage());
                }
            } catch (\Exception $e) {
                Log::error('Failed to send email notification: '.$e->getMessage());
            }
        }

        return redirect()->back()->with('success', 'Site submitted successfully! Admin will review and activate it within 24-48 hours. A homepage screenshot is being generated automatically.');
    }

    public function ajax(Request $request)
    {
        $query = $request->get('query');

        $sites = Site::where('publisher_id', auth()->id())
            ->when($query, function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('site_name', 'like', "%{$query}%")
                        ->orWhere('site_url', 'like', "%{$query}%")
                        ->orWhere('domain', 'like', "%{$query}%");
                });
            })
            ->latest()
            ->paginate(20);

        return view('publisher.sites.partials.table', compact('sites'))->render();
    }

    public function update(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($request->filled('exampleUrl')) {
            $request->merge([
                'exampleUrl' => $this->normalizeHttpUrl((string) $request->input('exampleUrl')),
            ]);
        }

        $categories = $this->parseCategoryList($request->input('categories', $request->input('category')));
        $primaryCategory = ! empty($categories) ? implode('|', $categories) : $site->category;
        $categoriesArray = ! empty($categories) ? $categories : null;

        $countryCodes = array_slice($this->parseCodeList($request->input('country', $request->input('countries'))), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($request->input('language', $request->input('languages'))), 0, 1);

        $request->merge([
            'country' => $countryCodes[0] ?? null,
            'language' => $languageCodes[0] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
        ]);

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();

        $validator = Validator::make($request->all(), [
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string|min:50',
            'price_sensitive.*' => 'nullable|numeric|min:0',
        ]);

        $validator->after(function ($validator) use ($request, $site) {
            $newDomain = null;
            if ($request->filled('siteUrl')) {
                $url = $this->normalizeHttpUrl((string) $request->siteUrl);
                $host = parse_url($url, PHP_URL_HOST);
                if ($host) {
                    $newDomain = preg_replace('/^www\./', '', strtolower($host));
                }
            }

            if ($newDomain && $newDomain !== $site->domain) {
                $existingSite = Site::where('domain', $newDomain)
                    ->where('id', '!=', $site->id)
                    ->exists();
                if ($existingSite) {
                    $validator->errors()->add('siteUrl', 'This website domain is already registered in our system by another publisher.');
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        try {
            DB::transaction(function () use ($site, $request, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes) {
                $sensitivePrices = [];
                foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                    if ($request->input("sensitive.$topic")) {
                        $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                    }
                }

                $site->applyMarketplaceListing([
                    'example_url' => $request->exampleUrl,
                    'da' => (int) $request->da,
                    'dr' => (int) $request->dr,
                    'traffic' => (int) $request->traffic,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'country' => $countryCodes[0],
                    'countries' => $countryCodes,
                    'language' => $languageCodes[0],
                    'languages' => $languageCodes,
                    'category' => $primaryCategory,
                    'categories' => $categoriesArray,
                    'price' => $request->price,
                    'turnaround_time' => $request->turnaround_time,
                    'publication_time' => $request->publicationTime,
                    'link_type' => $request->link_type,
                    'description' => $cleanDescription,
                    'verified' => false,
                    'active' => false,
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                ]);

                $this->applySiteTag($site, $request);

                $site->save();
            });
        } catch (\Throwable $e) {
            Log::error('Publisher site update failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withErrors(['siteUrl' => 'We could not update this website. Please check your details and try again.'])
                ->withInput();
        }

        // Send email notification for update
        try {
            $admins = User::where('active_role_id', function ($query) {
                $query->select('id')
                    ->from('roles')
                    ->where('name', 'admin')
                    ->limit(1);
            })->get();

            if ($admins->count() > 0) {
                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(new NewSiteNotification($site, 'update'));
                }
            } else {
                $defaultAdminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                Mail::to($defaultAdminEmail)->send(new NewSiteNotification($site, 'update'));
            }

            try {
                app(InAppNotificationService::class)->notifyAdminsNewSite($site, 'update');
            } catch (\Throwable $e) {
                Log::warning('Failed to send admin site-update bell notification: '.$e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('Failed to send email notification: '.$e->getMessage());
        }

        return redirect()->back()->with('success', 'Site updated successfully! It will be reviewed again.');
    }

    public function destroy($id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->verified || $site->active) {
            return redirect()->back()->with('error', 'You cannot delete an active or verified site.');
        }

        $site->delete();

        return redirect()->back()->with('success', 'Site deleted successfully!');
    }

    /**
     * Download a CSV template for agency bulk site import (150+ sites).
     */
    public function bulkTemplate()
    {
        $headers = [
            'site_name',
            'site_url',
            'example_url',
            'da',
            'dr',
            'traffic',
            'country',
            'language',
            'categories',
            'price',
            'turnaround_time',
            'publication_time',
            'link_type',
            'description',
            'sponsored',
            'partner_material',
            'as_you_prefer',
            'price_crypto',
            'price_trading',
            'price_CBD',
            'price_forex',
        ];

        $example = [
            'My Agency Blog',
            'https://example-agency-blog.com',
            'https://example-agency-blog.com/sample-post',
            '45',
            '40',
            '15000',
            'de',
            'de',
            'Business & Finance|Technology',
            '120',
            '3days',
            'permanent',
            'dofollow',
            'High-quality editorial site covering business and technology topics for professional audiences.',
            '0',
            '1',
            '0',
            '',
            '',
            '',
            '',
        ];

        $callback = function () use ($headers, $example) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
            fputcsv($out, $headers);
            fputcsv($out, $example);
            fclose($out);
        };

        return response()->streamDownload($callback, 'agency-sites-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Live multi-site submit for agencies (catalog niche pickers, max 25 per batch).
     */
    public function bulkStore(Request $request)
    {
        $maxSites = 25;

        $request->validate([
            'sites' => 'required|array|min:1|max:'.$maxSites,
        ], [
            'sites.required' => 'Add at least one website.',
            'sites.max' => "You can submit at most {$maxSites} websites at once. Submit this batch, then add more.",
        ]);

        $validCategoryNames = Category::pluck('name')->map(fn ($n) => strtolower((string) $n))->all();
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $publisherId = auth()->id();

        $created = 0;
        $failed = [];
        $seenDomains = [];

        foreach (array_values($request->input('sites', [])) as $index => $row) {
            $rowNumber = $index + 1;
            if (! is_array($row)) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => '',
                    'errors' => ['Invalid site row.'],
                ];

                continue;
            }

            $parsed = $this->normalizeLiveBulkSite($row, $validCategoryNames, $allowedCountries, $allowedLanguages);

            if (! empty($parsed['errors'])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $row['siteUrl'] ?? ($row['site_url'] ?? ($row['siteName'] ?? '')),
                    'errors' => $parsed['errors'],
                ];

                continue;
            }

            $domain = $parsed['domain'];

            if (isset($seenDomains[$domain])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $parsed['site_url'],
                    'errors' => ["Duplicate domain in this batch (also on site {$seenDomains[$domain]})."],
                ];

                continue;
            }
            $seenDomains[$domain] = $rowNumber;

            if (Site::where('domain', $domain)->exists()) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $parsed['site_url'],
                    'errors' => ['This domain is already registered in the system.'],
                ];

                continue;
            }

            try {
                $this->createPendingMarketplaceSite([
                    'publisher_id' => $publisherId,
                    'site_name' => $parsed['site_name'],
                    'site_url' => $parsed['site_url'],
                    'domain' => $parsed['domain'],
                    'example_url' => $parsed['example_url'],
                    'da' => $parsed['da'],
                    'dr' => $parsed['dr'],
                    'traffic' => $parsed['traffic'],
                    'country' => $parsed['country'],
                    'countries' => $parsed['countries'],
                    'language' => $parsed['language'],
                    'languages' => $parsed['languages'],
                    'category' => $parsed['primary_category'],
                    'categories' => $parsed['categories'],
                    'price' => $parsed['price'],
                    'turnaround_time' => $parsed['turnaround_time'],
                    'publication_time' => $parsed['publication_time'],
                    'link_type' => $parsed['link_type'],
                    'sponsored' => $parsed['sponsored'],
                    'partner_material' => $parsed['partner_material'],
                    'as_you_prefer' => $parsed['as_you_prefer'],
                    'description' => $parsed['description'],
                    'sensitive_prices' => $parsed['sensitive_prices'],
                ]);
                $created++;
            } catch (\Throwable $e) {
                Log::error('Live bulk site create failed: '.$e->getMessage(), [
                    'row' => $rowNumber,
                    'user_id' => $publisherId,
                ]);
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $parsed['site_url'] ?? '',
                    'errors' => ['Could not save this site. Please check the data.'],
                ];
            }
        }

        if ($created > 0) {
            $this->notifyAdminsOfBulkSites($created, count($failed), 'live form');
        }

        $message = "{$created} site(s) submitted for review.";
        if (count($failed) > 0) {
            $message .= ' '.count($failed).' site(s) failed — see details below.';
        }

        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('bulk_import_created', $created)
            ->with('bulk_import_failures', $failed);
    }

    /**
     * Bulk-import websites from CSV for agencies that manage many domains.
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ], [
            'csv_file.required' => 'Please upload a CSV file.',
            'csv_file.mimes' => 'Upload a .csv file.',
        ]);

        $maxRows = 200;
        $handle = fopen($request->file('csv_file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('error', 'Could not read the uploaded file.');
        }

        // Skip UTF-8 BOM if present
        $firstBytes = fread($handle, 3);
        if ($firstBytes !== chr(0xEF).chr(0xBB).chr(0xBF)) {
            rewind($handle);
        }

        $headerRow = fgetcsv($handle);
        if (! $headerRow) {
            fclose($handle);

            return back()->with('error', 'CSV is empty.');
        }

        $headers = array_map(function ($h) {
            return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
        }, $headerRow);

        $requiredHeaders = [
            'site_name', 'site_url', 'example_url', 'da', 'dr', 'traffic',
            'categories', 'price', 'turnaround_time',
            'publication_time', 'link_type', 'description',
        ];

        // Accept either countries/languages (new) or country/language (legacy single)
        $hasCountries = in_array('countries', $headers, true) || in_array('country', $headers, true);
        $hasLanguages = in_array('languages', $headers, true) || in_array('language', $headers, true);
        if (! $hasCountries || ! $hasLanguages) {
            fclose($handle);

            return back()->with('error', 'CSV must include countries (or country) and languages (or language) columns. Download the template and try again.');
        }

        foreach ($requiredHeaders as $required) {
            if (! in_array($required, $headers, true)) {
                fclose($handle);

                return back()->with('error', "CSV is missing required column: {$required}. Download the template and try again.");
            }
        }

        $validCategoryNames = Category::pluck('name')->map(fn ($n) => strtolower($n))->all();
        $publisherId = auth()->id();

        $created = 0;
        $failed = [];
        $seenDomainsInFile = [];
        $rowNumber = 1; // header is row 1

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip completely empty rows
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            if (($created + count($failed)) >= $maxRows) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => '',
                    'errors' => ["Maximum {$maxRows} rows per upload. Remaining rows were skipped."],
                ];
                break;
            }

            // Pad/truncate to header length
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            $data = array_combine($headers, array_slice($row, 0, count($headers)));
            if ($data === false) {
                $failed[] = ['row' => $rowNumber, 'site' => '', 'errors' => ['Could not parse row.']];

                continue;
            }

            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data);

            // Skip the sample template row if left unchanged
            if (($data['site_url'] ?? '') === 'https://example-agency-blog.com') {
                continue;
            }

            $parsed = $this->normalizeBulkRow($data, $validCategoryNames);

            if (! empty($parsed['errors'])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'] ?? ($data['site_name'] ?? ''),
                    'errors' => $parsed['errors'],
                ];

                continue;
            }

            $domain = $parsed['domain'];

            if (isset($seenDomainsInFile[$domain])) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'],
                    'errors' => ["Duplicate domain in this file (also on row {$seenDomainsInFile[$domain]})."],
                ];

                continue;
            }
            $seenDomainsInFile[$domain] = $rowNumber;

            if (Site::where('domain', $domain)->exists()) {
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'],
                    'errors' => ['This domain is already registered in the system.'],
                ];

                continue;
            }

            try {
                $this->createPendingMarketplaceSite([
                    'publisher_id' => $publisherId,
                    'site_name' => $parsed['site_name'],
                    'site_url' => $parsed['site_url'],
                    'domain' => $parsed['domain'],
                    'example_url' => $parsed['example_url'],
                    'da' => $parsed['da'],
                    'dr' => $parsed['dr'],
                    'traffic' => $parsed['traffic'],
                    'country' => $parsed['country'],
                    'countries' => $parsed['countries'],
                    'language' => $parsed['language'],
                    'languages' => $parsed['languages'],
                    'category' => $parsed['primary_category'],
                    'categories' => $parsed['categories'],
                    'price' => $parsed['price'],
                    'turnaround_time' => $parsed['turnaround_time'],
                    'publication_time' => $parsed['publication_time'],
                    'link_type' => $parsed['link_type'],
                    'sponsored' => $parsed['sponsored'],
                    'partner_material' => $parsed['partner_material'],
                    'as_you_prefer' => $parsed['as_you_prefer'],
                    'description' => $parsed['description'],
                    'sensitive_prices' => $parsed['sensitive_prices'],
                ]);
                $created++;
            } catch (\Exception $e) {
                Log::error('Bulk site import row failed: '.$e->getMessage(), [
                    'row' => $rowNumber,
                    'user_id' => $publisherId,
                ]);
                $failed[] = [
                    'row' => $rowNumber,
                    'site' => $data['site_url'] ?? '',
                    'errors' => ['Could not save this row. Please check the data.'],
                ];
            }
        }

        fclose($handle);

        if ($created > 0) {
            $this->notifyAdminsOfBulkSites($created, count($failed), 'CSV import');
        }

        $message = "{$created} site(s) submitted for review.";
        if (count($failed) > 0) {
            $message .= ' '.count($failed).' row(s) failed — see details below.';
        }

        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('bulk_import_created', $created)
            ->with('bulk_import_failures', $failed);
    }

    /**
     * Create a pending marketplace site (admin must verify/activate).
     *
     * @param  array<string, mixed>  $listing
     */
    private function createPendingMarketplaceSite(array $listing, ?callable $beforeSave = null): Site
    {
        return DB::transaction(function () use ($listing, $beforeSave) {
            $site = new Site;
            $site->applyMarketplaceListing(array_merge([
                'publisher_id' => auth()->id(),
                'metrics_manual' => true,
                'metrics_provider' => 'manual',
                'metrics_fetched_at' => now(),
                'verified' => false,
                'active' => false,
                'enrichment_status' => 'pending',
            ], $listing));

            if ($beforeSave) {
                $beforeSave($site);
            }

            $site->save();

            return $site;
        });
    }

    private function notifyAdminsOfBulkSites(int $created, int $failedCount, string $via): void
    {
        try {
            $user = auth()->user();
            $admins = User::where('active_role_id', function ($query) {
                $query->select('id')->from('roles')->where('name', 'admin')->limit(1);
            })->get();

            $subject = "Bulk site import: {$created} site(s) from {$user->name}";
            $body = "Publisher {$user->name} ({$user->email}) submitted {$created} website(s) via {$via}.\n"
                ."Failed rows: {$failedCount}\n"
                .'Please review them in the admin Sites panel.';

            $recipients = $admins->count() > 0
                ? $admins->pluck('email')->all()
                : [config('mail.admin_email', 'admin@yourdomain.com')];

            foreach ($recipients as $email) {
                Mail::raw($body, function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                });
            }
        } catch (\Exception $e) {
            Log::error('Bulk import admin notification failed: '.$e->getMessage());
        }
    }

    /**
     * Normalize + validate one live multi-site form row.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $validCategoryNamesLower
     * @param  list<string>  $allowedCountries
     * @param  list<string>  $allowedLanguages
     * @return array<string, mixed>
     */
    private function normalizeLiveBulkSite(
        array $data,
        array $validCategoryNamesLower,
        array $allowedCountries,
        array $allowedLanguages
    ): array {
        $errors = [];

        $siteUrl = $this->normalizeHttpUrl((string) ($data['siteUrl'] ?? $data['site_url'] ?? ''));
        $exampleUrl = $this->normalizeHttpUrl((string) ($data['exampleUrl'] ?? $data['example_url'] ?? ''));

        $host = parse_url($siteUrl, PHP_URL_HOST);
        $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
        if (! $domain) {
            $errors[] = 'Invalid site URL.';
        }

        $categories = $this->parseCategoryList($data['categories'] ?? ($data['category'] ?? []));
        if (count($categories) < 1) {
            $errors[] = 'Select at least one niche/category.';
        } elseif (count($categories) > 7) {
            $errors[] = 'Maximum 7 categories allowed.';
        } else {
            foreach ($categories as $cat) {
                if (! in_array(strtolower($cat), $validCategoryNamesLower, true)) {
                    $errors[] = "Unknown category: {$cat}";
                }
            }
        }

        $countryCodes = array_slice($this->parseCodeList($data['country'] ?? ($data['countries'] ?? '')), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($data['language'] ?? ($data['languages'] ?? '')), 0, 1);
        if (count($countryCodes) < 1) {
            $errors[] = 'A country is required.';
        }
        if (count($languageCodes) < 1) {
            $errors[] = 'A language is required.';
        }

        $description = strip_tags((string) ($data['siteDescription'] ?? $data['description'] ?? ''), '<p><a><b><strong><i><ul><ol><li><br>');

        $tag = $data['site_tag'] ?? null;
        $sponsored = false;
        $partnerMaterial = false;
        $asYouPrefer = false;
        if ($tag === 'sponsored') {
            $sponsored = true;
        } elseif ($tag === 'partner_material') {
            $partnerMaterial = true;
        } elseif ($tag === 'as_you_prefer') {
            $asYouPrefer = true;
        } else {
            $sponsored = $this->csvBool($data['sponsored'] ?? '0');
            $partnerMaterial = $this->csvBool($data['partner_material'] ?? '0');
            $asYouPrefer = $this->csvBool($data['as_you_prefer'] ?? '0');
        }

        $payload = [
            'site_name' => $data['siteName'] ?? ($data['site_name'] ?? ''),
            'site_url' => $siteUrl,
            'example_url' => $exampleUrl,
            'da' => $data['da'] ?? null,
            'dr' => $data['dr'] ?? null,
            'traffic' => $data['traffic'] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
            'price' => $data['price'] ?? null,
            'turnaround_time' => $data['turnaround_time'] ?? '',
            'publication_time' => $data['publicationTime'] ?? ($data['publication_time'] ?? ''),
            'link_type' => strtolower((string) ($data['link_type'] ?? '')),
            'description' => $description,
        ];

        $validator = Validator::make($payload, [
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:255',
            'example_url' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'countries' => 'required|array|size:1',
            'countries.*' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'languages' => 'required|array|size:1',
            'languages.*' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'description' => 'required|string|min:50',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $msg) {
                $errors[] = $msg;
            }
        }

        $sensitivePrices = [];
        $priceSensitive = $data['price_sensitive'] ?? [];
        if (is_array($priceSensitive)) {
            foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                $val = $priceSensitive[$topic] ?? null;
                $enabled = ! empty($data['sensitive'][$topic] ?? null) || ($val !== null && $val !== '');
                if ($enabled && $val !== null && $val !== '') {
                    if (! is_numeric($val) || $val < 0) {
                        $errors[] = "Sensitive price for {$topic} must be a number ≥ 0.";
                    } else {
                        $sensitivePrices[$topic] = (float) $val;
                    }
                }
            }
        }

        if (! empty($errors)) {
            return ['errors' => array_values(array_unique($errors))];
        }

        return [
            'errors' => [],
            'site_name' => $payload['site_name'],
            'site_url' => $payload['site_url'],
            'domain' => $domain,
            'example_url' => $payload['example_url'],
            'da' => (int) $payload['da'],
            'dr' => (int) $payload['dr'],
            'traffic' => (int) $payload['traffic'],
            'country' => $countryCodes[0],
            'countries' => $countryCodes,
            'language' => $languageCodes[0],
            'languages' => $languageCodes,
            'primary_category' => implode('|', $categories),
            'categories' => $categories,
            'price' => $payload['price'],
            'turnaround_time' => $payload['turnaround_time'],
            'publication_time' => $payload['publication_time'],
            'link_type' => $payload['link_type'],
            'sponsored' => $sponsored,
            'partner_material' => $partnerMaterial,
            'as_you_prefer' => $asYouPrefer,
            'description' => $description,
            'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
        ];
    }

    /**
     * Normalize + validate one CSV row into site attributes.
     */
    private function normalizeBulkRow(array $data, array $validCategoryNamesLower): array
    {
        $errors = [];

        $siteUrl = $data['site_url'] ?? '';
        if ($siteUrl !== '' && ! preg_match('~^(?:f|ht)tps?://~i', $siteUrl)) {
            $siteUrl = 'https://'.$siteUrl;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);
        $domain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
        if (! $domain) {
            $errors[] = 'Invalid site_url.';
        }

        $exampleUrl = $data['example_url'] ?? '';
        if ($exampleUrl !== '' && ! preg_match('~^(?:f|ht)tps?://~i', $exampleUrl)) {
            $exampleUrl = 'https://'.$exampleUrl;
        }

        $categoryRaw = $data['categories'] ?? '';
        $categories = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $categoryRaw) ?: [])));
        if (count($categories) < 1) {
            $errors[] = 'At least one category is required (use | or , between names).';
        } elseif (count($categories) > 7) {
            $errors[] = 'Maximum 7 categories allowed.';
        } else {
            foreach ($categories as $cat) {
                if (! in_array(strtolower($cat), $validCategoryNamesLower, true)) {
                    $errors[] = "Unknown category: {$cat}";
                }
            }
        }

        $countryCodes = array_slice($this->parseCodeList($data['country'] ?? ($data['countries'] ?? '')), 0, 1);
        $languageCodes = array_slice($this->parseCodeList($data['language'] ?? ($data['languages'] ?? '')), 0, 1);
        if (count($countryCodes) < 1) {
            $errors[] = 'A country code is required (e.g. de).';
        }
        if (count($languageCodes) < 1) {
            $errors[] = 'A language code is required (e.g. de).';
        }

        $description = strip_tags((string) ($data['description'] ?? ''), '<p><a><b><strong><i><ul><ol><li><br>');

        $payload = [
            'site_name' => $data['site_name'] ?? '',
            'site_url' => $siteUrl,
            'example_url' => $exampleUrl,
            'da' => $data['da'] ?? null,
            'dr' => $data['dr'] ?? null,
            'traffic' => $data['traffic'] ?? null,
            'countries' => $countryCodes,
            'languages' => $languageCodes,
            'categories' => $categories,
            'price' => $data['price'] ?? null,
            'turnaround_time' => $data['turnaround_time'] ?? '',
            'publication_time' => $data['publication_time'] ?? '',
            'link_type' => strtolower($data['link_type'] ?? ''),
            'description' => $description,
        ];

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower($c))->all();

        $validator = Validator::make($payload, [
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:255',
            'example_url' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0',
            'countries' => 'required|array|size:1',
            'countries.*' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'languages' => 'required|array|size:1',
            'languages.*' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'description' => 'required|string|min:50',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $msg) {
                $errors[] = $msg;
            }
        }

        $sensitivePrices = [];
        foreach (['crypto' => 'price_crypto', 'trading' => 'price_trading', 'CBD' => 'price_CBD', 'forex' => 'price_forex'] as $topic => $col) {
            $val = $data[$col] ?? '';
            if ($val !== '' && $val !== null) {
                if (! is_numeric($val) || $val < 0) {
                    $errors[] = "{$col} must be a number ≥ 0.";
                } else {
                    $sensitivePrices[$topic] = (float) $val;
                }
            }
        }

        if (! empty($errors)) {
            return ['errors' => array_values(array_unique($errors))];
        }

        return [
            'errors' => [],
            'site_name' => $payload['site_name'],
            'site_url' => $payload['site_url'],
            'domain' => $domain,
            'example_url' => $payload['example_url'],
            'da' => (int) $payload['da'],
            'dr' => (int) $payload['dr'],
            'traffic' => (int) $payload['traffic'],
            'country' => $countryCodes[0],
            'countries' => $countryCodes,
            'language' => $languageCodes[0],
            'languages' => $languageCodes,
            'primary_category' => implode(',', $categories),
            'categories' => $categories,
            'price' => $payload['price'],
            'turnaround_time' => $payload['turnaround_time'],
            'publication_time' => $payload['publication_time'],
            'link_type' => $payload['link_type'],
            'sponsored' => $this->csvBool($data['sponsored'] ?? '0'),
            'partner_material' => $this->csvBool($data['partner_material'] ?? '0'),
            'as_you_prefer' => $this->csvBool($data['as_you_prefer'] ?? '0'),
            'description' => $description,
            'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
        ];
    }

    /**
     * Parse country/language codes from array, CSV, or pipe-separated string.
     */
    private function parseCodeList($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[|,]/', (string) $value) ?: [];
        }

        $codes = [];
        foreach ($parts as $part) {
            $code = strtolower(trim((string) $part));
            if ($code !== '' && preg_match('/^[a-z]{2}$/', $code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Apply a single site tag from radio `site_tag`, with checkbox fallback.
     */
    private function applySiteTag(Site $site, Request $request): void
    {
        $tag = $request->input('site_tag');

        if ($tag === null) {
            // Legacy checkbox posts / bulk import paths
            $site->sponsored = $request->boolean('sponsored') || $request->has('sponsored');
            $site->partner_material = $request->boolean('partner_material') || $request->has('partner_material');
            $site->as_you_prefer = $request->boolean('as_you_prefer') || $request->has('as_you_prefer');

            return;
        }

        $site->sponsored = $tag === 'sponsored';
        $site->partner_material = $tag === 'partner_material';
        $site->as_you_prefer = $tag === 'as_you_prefer';
    }

    /**
     * Ensure URLs validate even when publishers omit the scheme.
     */
    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        if (! preg_match('~^(?:f|ht)tps?://~i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    /**
     * Parse category names from array, JSON, CSV, or pipe-separated string.
     * Prefer `|` as the delimiter so names containing commas stay intact.
     */
    private function parseCategoryList($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $parts = $decoded;
            } elseif (str_contains($value, '|')) {
                $parts = explode('|', $value);
            } else {
                // If the whole string is a known category (may contain commas), keep it intact.
                $known = Category::query()->where('name', $value)->exists();
                $parts = $known ? [$value] : (preg_split('/,/', $value) ?: []);
            }
        } else {
            $parts = [];
        }

        $categories = [];
        foreach ($parts as $part) {
            $name = trim((string) $part);
            if ($name !== '') {
                $categories[] = $name;
            }
        }

        return array_values(array_unique($categories));
    }

    private function csvBool($value): bool
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }
}
