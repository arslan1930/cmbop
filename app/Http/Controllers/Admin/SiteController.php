<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Jobs\EnrichSiteJob;
use App\Mail\AdminAssignedSiteNotification;
use App\Mail\SiteStatusNotification;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\SiteDescriptionSanitizer;
use App\Support\PublicStorageLink;
use App\Support\SiteImageUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        $needsReviewFilter = $request->boolean('needs_review')
            || $request->query('verified') === '0'
            || $request->query('verified') === 0;

        // Counts only — do not eager-load every site row for the publisher list.
        $query = User::withCount('sites')
            ->withCount(['sites as needs_review_sites_count' => function ($q) {
                $q->needsAdminReview();
            }]);

        // Ops queue: publishers with sites ready for admin decision (not unfinished drafts)
        if ($needsReviewFilter) {
            $query->whereHas('sites', function ($q) {
                $q->needsAdminReview();
            })->withCount(['sites as unverified_sites_count' => function ($q) {
                $q->needsAdminReview();
            }]);
        }

        $users = $query->latest()->paginate(20)->appends($request->query());
        $unverifiedFilter = $needsReviewFilter;
        $needsReviewFilterActive = $needsReviewFilter;
        $openReviewCount = Site::query()->needsAdminReview()->count();
        $missingMarketCount = Site::query()->activeMissingMarketplaceCountry()->count();

        return view('admin.sites', compact(
            'users',
            'unverifiedFilter',
            'needsReviewFilterActive',
            'openReviewCount',
            'missingMarketCount'
        ));
    }

    /**
     * Admin records sheet: all websites with URL, countries, categories only.
     * Always reads live from the sites table.
     * Optional ?country=de (or other ISO code) filters to that market.
     * ?partial=1 or Accept: application/json returns table HTML for live filter swaps.
     */
    public function records(Request $request)
    {
        $countryFilter = strtolower(trim((string) $request->query('country', '')));
        if ($countryFilter === 'all') {
            $countryFilter = '';
        }
        $missingMarket = $request->boolean('missing_market');
        if ($missingMarket) {
            // Missing-market queue is mutually exclusive with a specific country.
            $countryFilter = '';
        }

        $query = Site::query()->orderBy('domain')->orderBy('id');
        if ($missingMarket) {
            $query->activeMissingMarketplaceCountry();
        } else {
            $this->applyRecordsCountryFilter($query, $countryFilter);
        }

        $sites = $query
            ->paginate(100)
            ->appends(array_filter([
                'country' => $countryFilter !== '' ? $countryFilter : null,
                'missing_market' => $missingMarket ? 1 : null,
            ]))
            ->through(fn (Site $site) => $this->siteRecordRow($site));

        $countryCounts = $this->recordsCountryCounts();
        $totalSites = (int) Site::query()->count();
        $missingMarketCount = (int) Site::query()->activeMissingMarketplaceCountry()->count();
        $countries = Country::marketplace()
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(function (Country $country) use ($countryCounts) {
                $code = strtolower(trim((string) $country->code));

                return [
                    'code' => $code,
                    'name' => (string) $country->name,
                    'count' => (int) ($countryCounts[$code] ?? 0),
                ];
            })
            ->values();

        $selectedCountry = $countryFilter;
        $exportUrl = route('admin.sites.records.export', array_filter([
            'country' => $selectedCountry !== '' ? $selectedCountry : null,
            'missing_market' => $missingMarket ? 1 : null,
        ]));

        $wantsPartial = $request->boolean('partial')
            || $request->expectsJson()
            || str_contains(strtolower((string) $request->header('Accept', '')), 'application/json');

        if ($wantsPartial) {
            $tableHtml = view('admin.sites.partials.records-table', [
                'sites' => $sites,
                'selectedCountry' => $selectedCountry,
                'missingMarket' => $missingMarket,
            ])->render();

            return response()->json([
                'success' => true,
                'selected_country' => $selectedCountry,
                'missing_market' => $missingMarket,
                'missing_market_count' => $missingMarketCount,
                'total' => $sites->total(),
                'export_url' => $exportUrl,
                'table_html' => $tableHtml,
            ]);
        }

        return view('admin.sites.records', compact(
            'sites',
            'countries',
            'selectedCountry',
            'totalSites',
            'exportUrl',
            'missingMarket',
            'missingMarketCount'
        ));
    }

    /**
     * CSV download of the same live records sheet (honours country / missing-market filter).
     */
    public function exportRecords(Request $request): StreamedResponse
    {
        $countryFilter = strtolower(trim((string) $request->query('country', '')));
        if ($countryFilter === 'all') {
            $countryFilter = '';
        }
        $missingMarket = $request->boolean('missing_market');
        if ($missingMarket) {
            $countryFilter = '';
        }

        $suffix = $missingMarket
            ? '-missing-market'
            : ($countryFilter !== '' ? '-'.$countryFilter : '');
        $filename = 'websites-records'.$suffix.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($countryFilter, $missingMarket) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['url', 'countries', 'categories', 'active']);

            $query = Site::query()->orderBy('domain')->orderBy('id');
            if ($missingMarket) {
                $query->activeMissingMarketplaceCountry();
            } else {
                $this->applyRecordsCountryFilter($query, $countryFilter);
            }

            foreach ($query->cursor() as $site) {
                $row = $this->siteRecordRow($site);
                fputcsv($out, [
                    $row['url'],
                    $row['countries'],
                    $row['categories'],
                    $site->active ? '1' : '0',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Tally sites per country code from legacy `country` + JSON `countries`.
     * Multi-market sites increment each matched code.
     *
     * @return array<string, int>
     */
    private function recordsCountryCounts(): array
    {
        $counts = [];
        $select = ['id', 'country'];
        if (Site::hasSitesColumn('countries')) {
            $select[] = 'countries';
        }

        try {
            foreach (Site::query()->select($select)->cursor() as $site) {
                foreach ($site->countryCodes() as $code) {
                    $code = strtolower(trim((string) $code));
                    if ($code === '') {
                        continue;
                    }
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Admin sites records country counts failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $counts;
    }

    /**
     * @param  Builder<Site>  $query
     */
    private function applyRecordsCountryFilter($query, string $countryCode): void
    {
        $code = strtolower(trim($countryCode));
        if ($code === '') {
            return;
        }

        $hasCountriesJson = Site::hasSitesColumn('countries');
        $query->where(function ($q) use ($code, $hasCountriesJson) {
            $q->whereRaw('LOWER(country) = ?', [$code]);
            if ($hasCountriesJson) {
                $q->orWhereJsonContains('countries', $code);
            }
        });
    }

    /**
     * Build desktop preview URLs for staff Sites Management rows.
     *
     * @return array{thumb: ?string, full: ?string, fallbacks: list<string>}
     */
    /**
     * Staff preview URL for a public-disk path.
     * Prefer /{admin|marketing}/sites/media/... (Laravel disk stream) so Hostinger
     * broken public/storage symlinks do not blank row/detail previews.
     */
    private function staffPublicStorageUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        if ($normalized === '') {
            return null;
        }

        return rtrim(staff_base_path(), '/').'/sites/media/'.$normalized;
    }

    /**
     * Client onerror chain: staff media → /storage → public /media.
     *
     * @return list<string>
     */
    private function staffPublicStorageUrlFallbacks(?string $path): array
    {
        if (! is_string($path) || trim($path) === '') {
            return [];
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '') {
            return [];
        }
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }
        if ($normalized === '') {
            return [];
        }

        $staff = $this->staffPublicStorageUrl($normalized);

        return array_values(array_unique(array_filter([
            $staff,
            '/storage/'.$normalized,
            '/media/'.$normalized,
        ])));
    }

    /**
     * Fast preview URLs for Sites Management rows (no per-path disk I/O).
     * Client onerror walks fallbacks when a path 404s.
     *
     * @return array{thumb: ?string, full: ?string, fallbacks: list<string>}
     */
    private function staffSitePreviewPayload(Site $site): array
    {
        $firstPath = static function (array $candidates): ?string {
            foreach ($candidates as $path) {
                if (is_string($path) && trim($path) !== '') {
                    return $path;
                }
            }

            return null;
        };

        // List: prefer uploaded cover, then screenshot thumb, then full capture.
        // Admin "Images" uploads must win over stale auto-screenshots or rows look blank.
        $thumbPath = $firstPath([
            $site->site_image,
            $site->screenshot_thumb_path,
            $site->screenshot_path,
        ]);

        // Hover/detail: prefer full desktop capture, then upload, then thumb.
        $fullPath = $firstPath([
            $site->screenshot_path,
            $site->site_image,
            $site->screenshot_thumb_path,
        ]);

        // onerror chain: upload → thumb → full
        $ordered = [];
        foreach ([
            $site->site_image,
            $site->screenshot_thumb_path,
            $site->screenshot_path,
        ] as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            if (! in_array($path, $ordered, true)) {
                $ordered[] = $path;
            }
        }

        $fallbacks = [];
        foreach ($ordered as $path) {
            foreach ($this->staffPublicStorageUrlFallbacks($path) as $url) {
                if (! in_array($url, $fallbacks, true)) {
                    $fallbacks[] = $url;
                }
            }
        }

        return [
            'thumb' => $this->staffPublicStorageUrl($thumbPath),
            'full' => $this->staffPublicStorageUrl($fullPath) ?: $this->staffPublicStorageUrl($thumbPath),
            'fallbacks' => $fallbacks,
        ];
    }

    /**
     * Slim JSON row for Sites Management (avoid full model dumps + disk I/O).
     *
     * @return array<string, mixed>
     */
    private function staffSiteListRow(Site $site): array
    {
        $preview = $this->staffSitePreviewPayload($site);
        $imageUrl = $this->staffPublicStorageUrl(
            is_string($site->site_image) ? $site->site_image : null
        );

        return [
            'id' => (int) $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'domain' => $site->domain,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $site->price,
            'active' => (bool) $site->active,
            'verified' => (bool) $site->verified,
            'country' => $site->country,
            'countries' => $site->countries,
            'language' => $site->language,
            'languages' => $site->languages,
            'category' => $site->category,
            'categories' => $site->categories,
            'link_type' => $site->link_type,
            'sponsored' => (bool) $site->sponsored,
            'description' => $site->description,
            'enrichment_status' => $site->enrichment_status,
            'enrichment_error' => $site->enrichment_error,
            'metrics_fetched_at' => optional($site->metrics_fetched_at)?->toIso8601String(),
            'site_image' => $site->site_image,
            'screenshot_path' => $site->screenshot_path,
            'screenshot_thumb_path' => $site->screenshot_thumb_path,
            'needs_review' => $site->needsAdminReview(),
            'missing_market' => ! $site->hasMarketplaceCountry(),
            'awaits_publisher_details' => $site->awaitsPublisherDetails(),
            'pending_publisher_acceptance' => $site->isPendingPublisherAcceptance(),
            'agency_site_import_id' => Site::hasSitesColumn('agency_site_import_id')
                ? ($site->agency_site_import_id ? (int) $site->agency_site_import_id : null)
                : null,
            'csv_metrics_spot_check' => $site->isFromAgencyCsvImport() && (bool) $site->metrics_manual,
            'archived' => $site->isArchived(),
            'orders_count' => $site->orderItemsCount(),
            'preview_thumb_url' => $preview['thumb'],
            'preview_full_url' => $preview['full'],
            'preview_fallback_urls' => $preview['fallbacks'],
            'screenshot_url' => $preview['full'],
            'screenshot_thumb_url' => $preview['thumb'],
            'image_url' => $imageUrl,
        ];
    }

    /**
     * @return array{url: string, countries: string, categories: string}
     */
    private function siteRecordRow(Site $site): array
    {
        $url = trim((string) ($site->site_url ?: ''));
        if ($url === '') {
            $domain = trim((string) ($site->domain ?: ''));
            $url = $domain !== '' ? 'https://'.$domain : '';
        }

        $countries = collect($site->countryCodes())
            ->filter()
            ->map(fn ($code) => strtolower(trim((string) $code)))
            ->unique()
            ->values()
            ->implode('|');

        $categories = collect($site->categories_array)
            ->filter()
            ->map(fn ($cat) => trim((string) $cat))
            ->filter()
            ->unique()
            ->values()
            ->implode('|');

        return [
            'url' => $url,
            'countries' => $countries,
            'categories' => $categories,
            'missing_market' => ! $site->hasMarketplaceCountry(),
            'active' => (bool) $site->active,
        ];
    }

    // Get all sites of a user (AJAX)
    public function userSites($id)
    {
        $user = User::query()->find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Publisher not found',
                'publisher' => null,
                'sites' => [],
            ], 404);
        }

        $columns = [
            'id',
            'publisher_id',
            'publisher_accepted_at',
            'assigned_by_user_id',
            'site_name',
            'site_url',
            'domain',
            'da',
            'dr',
            'traffic',
            'price',
            'active',
            'verified',
            'country',
            'countries',
            'language',
            'languages',
            'category',
            'categories',
            'link_type',
            'sponsored',
            'description',
            'enrichment_status',
            'enrichment_error',
            'metrics_fetched_at',
            'onboarding_status',
            'example_url',
            'site_image',
            'screenshot_path',
            'screenshot_thumb_path',
            'agency_site_import_id',
            'metrics_manual',
            'created_at',
            'updated_at',
        ];

        $select = array_values(array_filter(
            $columns,
            static fn (string $column) => in_array($column, [
                'id', 'publisher_id', 'site_name', 'site_url', 'domain',
                'da', 'dr', 'traffic', 'price', 'active', 'verified',
                'country', 'language', 'category', 'link_type', 'sponsored',
                'description', 'example_url', 'created_at', 'updated_at',
                // Always try to select image cols — blank row previews if omitted.
                'site_image', 'screenshot_path', 'screenshot_thumb_path',
            ], true) || Site::hasSitesColumn($column)
        ));

        $select = array_values(array_filter(
            $select,
            static function (string $column) {
                if (! in_array($column, ['site_image', 'screenshot_path', 'screenshot_thumb_path'], true)) {
                    return true;
                }

                return Site::hasSitesColumn($column);
            }
        ));

        $sitesQuery = Site::query()
            ->where('publisher_id', $user->id)
            ->latest();

        if (Schema::hasTable('order_items')) {
            $sitesQuery->withCount('orderItems');
        }

        $sites = $sitesQuery
            ->get($select)
            ->map(fn (Site $site) => $this->staffSiteListRow($site))
            ->values();

        // Include publisher meta so the detail view still loads when the publisher
        // is absent from a filtered "needs review" users table (e.g. after activate).
        return response()->json([
            'publisher' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ],
            'sites' => $sites,
        ]);
    }

    /**
     * Staff form: add a complete listing for a publisher (pending their accept).
     */
    public function createForPublisher(Request $request): View
    {
        $publishers = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $languages = Language::marketplace()->orderBy('name')->get();
        $countries = Country::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();
        $selectedPublisherId = (int) $request->query('publisher', 0);

        return view('admin.site-create', compact(
            'publishers',
            'languages',
            'countries',
            'categories',
            'countryLanguageMap',
            'selectedPublisherId'
        ));
    }

    /**
     * Create a site for a publisher. Listing stays out of My Sites until they accept.
     */
    public function storeForPublisher(Request $request): RedirectResponse
    {
        if (! Site::hasSitesColumn('publisher_accepted_at') || ! Site::hasSitesColumn('assigned_by_user_id')) {
            return back()
                ->withErrors([
                    'site_url' => 'Database is missing the publisher-acceptance columns. Run migrations, then try again.',
                ])
                ->withInput();
        }

        $siteUrl = $this->normalizeHttpUrl((string) $request->input('site_url', $request->input('siteUrl', '')));
        $exampleUrl = $this->normalizeHttpUrl((string) $request->input('example_url', $request->input('exampleUrl', '')));

        // Coerce metric fields before validation (locale number inputs / "45.0" strings).
        $da = $this->normalizeMetricInt($request->input('da'));
        $dr = $this->normalizeMetricInt($request->input('dr'));
        $traffic = $this->normalizeMetricInt($request->input('traffic'));

        $request->merge([
            'site_url' => $siteUrl,
            'example_url' => $exampleUrl,
            'da' => $da,
            'dr' => $dr,
            'traffic' => $traffic,
        ]);

        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (! $host) {
            return back()->withErrors(['site_url' => 'Invalid URL'])->withInput();
        }

        $domain = preg_replace('/^www\./', '', strtolower($host));

        $categories = $this->parseCategoryList($request->input('categories', $request->input('category')));
        $primaryCategory = ! empty($categories) ? implode('|', $categories) : (string) $request->input('category', '');
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

        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        $validator = Validator::make($request->all(), [
            'publisher_id' => 'required|integer|exists:users,id',
            'site_name' => 'required|string|max:255',
            'site_url' => 'required|url|max:255',
            'example_url' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publication_time' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'description' => 'required|string|min:50',
            'site_image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:'.$this->siteImageMaxKilobytes(),
            'site_tag' => 'nullable|in:sponsored,partner_material,as_you_prefer',
        ], $this->siteImageValidationMessages());

        $validator->after(function ($validator) use ($request, $domain, $countryCodes, $languageCodes) {
            $publisherId = (int) $request->input('publisher_id');
            $publisher = User::query()
                ->whereKey($publisherId)
                ->whereHas('roles', fn ($q) => $q->where('name', 'publisher'))
                ->first();

            if (! $publisher) {
                $validator->errors()->add('publisher_id', 'Choose a valid publisher account.');
            }

            if (Site::where('domain', $domain)->exists()) {
                $validator->errors()->add('site_url', 'This website domain is already registered.');
            }

            $country = $countryCodes[0] ?? null;
            $language = $languageCodes[0] ?? null;
            if ($country && $language && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                $validator->errors()->add(
                    'language',
                    'That language is not allowed for the selected country. Pick country first, then a paired language.'
                );
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = app(SiteDescriptionSanitizer::class)
            ->sanitize((string) $request->input('description'));

        $site = null;
        $publisherId = (int) $request->input('publisher_id');

        try {
            DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, $publisherId, &$site) {
                $site = new Site;

                $sensitivePrices = [];
                foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
                    if ($request->input("sensitive.$topic")) {
                        $sensitivePrices[$topic] = $request->input("price_sensitive.$topic");
                    }
                }

                $imagePath = null;
                if ($request->hasFile('site_image')) {
                    $disk = Storage::disk('public');
                    $disk->makeDirectory('sites');
                    $stored = $request->file('site_image')->store('sites', 'public');
                    if (! is_string($stored) || $stored === '' || ! $disk->exists($stored)) {
                        throw ValidationException::withMessages([
                            'site_image' => ['Could not save the site image to storage. Check disk permissions and MEDIA_PATH.'],
                        ]);
                    }
                    PublicStorageLink::ensure();
                    $imagePath = $stored;
                }

                $da = (int) $request->input('da');
                $dr = (int) $request->input('dr');
                $traffic = (int) $request->input('traffic');

                $site->applyMarketplaceListing([
                    'publisher_id' => $publisherId,
                    'assigned_by_user_id' => auth()->id(),
                    'publisher_accepted_at' => null,
                    'site_name' => $request->input('site_name'),
                    'site_url' => $request->input('site_url'),
                    'domain' => $domain,
                    'example_url' => $request->input('example_url'),
                    'da' => $da,
                    'dr' => $dr,
                    'traffic' => $traffic,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                    'country' => $countryCodes[0],
                    'countries' => $countryCodes,
                    'language' => $languageCodes[0],
                    'languages' => $languageCodes,
                    'category' => $primaryCategory,
                    'categories' => $categoriesArray,
                    'price' => $request->input('price'),
                    'turnaround_time' => $request->input('turnaround_time'),
                    'publication_time' => $request->input('publication_time'),
                    'link_type' => $request->input('link_type'),
                    'description' => $cleanDescription,
                    'verified' => false,
                    'active' => false,
                    'enrichment_status' => 'pending',
                    'onboarding_status' => null,
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                    'site_image' => $imagePath,
                ]);

                // Hard-set invite + metrics so a missing column skip cannot silently drop them.
                $site->forceFill([
                    'assigned_by_user_id' => auth()->id(),
                    'publisher_accepted_at' => null,
                    'da' => $da,
                    'dr' => $dr,
                    'traffic' => $traffic,
                    'metrics_manual' => true,
                    'metrics_provider' => 'manual',
                    'metrics_fetched_at' => now(),
                ]);

                $tag = $request->input('site_tag', 'as_you_prefer');
                $site->sponsored = $tag === 'sponsored';
                $site->partner_material = $tag === 'partner_material';
                $site->as_you_prefer = $tag === 'as_you_prefer' || blank($tag);

                $site->save();

                if ((int) $site->da !== $da || (int) $site->dr !== $dr) {
                    throw new \RuntimeException('DA/DR did not persist after save.');
                }
                if (filled($site->publisher_accepted_at) || blank($site->assigned_by_user_id)) {
                    throw new \RuntimeException('Publisher invite state did not persist after save.');
                }
            });
        } catch (\Throwable $e) {
            Log::error('Staff site-for-publisher store failed', [
                'publisher_id' => $publisherId,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            $hint = 'We could not save this website. Please try again.';
            if (str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'publisher invite state')
                || str_contains($e->getMessage(), 'DA/DR did not persist')) {
                $hint = 'We could not save invite state or DA/DR. Run the latest migrations on the server, clear caches, and try again.';
            }

            return redirect()->back()
                ->withErrors(['site_url' => $hint])
                ->withInput();
        }

        if ($site && config('site_enrichment.enabled', true)) {
            try {
                CaptureSiteScreenshotJob::dispatch($site->id, 'staff_assign');
            } catch (\Throwable $e) {
                Log::warning('Failed to queue screenshot for staff-assigned site', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($site) {
            ActivityLogger::log(
                'site.assigned_for_acceptance',
                (auth()->user()->name ?? 'Staff').' added site "'.$site->site_name.'" for publisher acceptance',
                $site,
                [
                    'publisher_id' => $publisherId,
                    'assigned_by_user_id' => auth()->id(),
                    'domain' => $site->domain,
                ],
                $site->site_name
            );

            $publisher = $site->publisher;
            try {
                if ($publisher?->email) {
                    Mail::to($publisher->email)->send(new AdminAssignedSiteNotification($site, $publisher));
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to email publisher about staff-assigned site: '.$e->getMessage());
            }

            try {
                app(InAppNotificationService::class)->notifyPublisherSiteAssignedForAcceptance($site);
            } catch (\Throwable $e) {
                Log::warning('Failed to bell-notify publisher about staff-assigned site: '.$e->getMessage());
            }
        }

        return redirect()
            ->to(staff_route('sites.index', ['publisher' => $publisherId]))
            ->with('success', 'Site added (DA '.$site->da.' / DR '.$site->dr.'). Publisher was notified — they must open My Sites → Invites and Accept before it appears under Pending.');
    }

    // Edit page (optional)
    public function edit($id)
    {
        $site = Site::with('publisher:id,name,email')->findOrFail($id);
        $user = auth()->user();
        $isMarketingEditor = (bool) ($user?->isMarketing() && ! $user?->isAdmin());
        $languages = Language::marketplace()->orderBy('name')->get();
        $countries = Country::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter.
        $categories = Category::catalogPickerNames();
        $countryLanguageMap = app(CountryLanguagePairs::class)->mapWithNames();

        // Load by absolute path so a stale `view:cache` manifest cannot report
        // "View [admin.site-edit] not found" when the Blade file is on disk.
        $editViewPath = resource_path('views/admin/site-edit.blade.php');
        if (is_file($editViewPath)) {
            return view()->file($editViewPath, compact(
                'site',
                'isMarketingEditor',
                'languages',
                'countries',
                'categories',
                'countryLanguageMap'
            ));
        }

        // Fallback: open the existing Sites UI editor for this publisher/site.
        return redirect()->to(staff_route('sites.index', [
            'publisher' => $site->publisher_id,
            'edit_site' => $site->id,
        ]));
    }

    // Upload image for site
    public function uploadImage(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        $file = $request->file('site_image');
        if (! $file) {
            $message = 'Choose a site image to upload.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 422);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        if (! $file->isValid()) {
            $mb = $this->siteImageMaxMegabytesLabel();
            $message = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The site image is too large. Use a file under '.$mb.' MB.',
                UPLOAD_ERR_PARTIAL => 'The site image upload was interrupted. Try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a site image to upload.',
                default => 'The site image failed to upload. Use JPEG, PNG, GIF, or WebP under '.$mb.' MB.',
            };

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 422);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        $request->validate([
            // Avoid flaky finfo `image` rule on Hostinger — mimes is enough.
            'site_image' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:'.$this->siteImageMaxKilobytes(),
        ], $this->siteImageValidationMessages());

        $disk = Storage::disk('public');
        try {
            $disk->makeDirectory('sites');
        } catch (\Throwable $e) {
            Log::error('Could not create sites media directory', [
                'error' => $e->getMessage(),
                'root' => config('filesystems.disks.public.root'),
            ]);
            $message = 'Could not prepare image storage. Check disk permissions and MEDIA_PATH.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 500);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        $previous = is_string($site->site_image) ? $site->site_image : null;

        // Store new image first — only delete the previous file after success.
        $path = $file->store('sites', 'public');
        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            $message = 'Could not save the site image to storage. Check disk permissions and MEDIA_PATH.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['site_image' => [$message]],
                ], 500);
            }

            throw ValidationException::withMessages(['site_image' => $message]);
        }

        // Heal / verify public/storage. Hostinger open_basedir often makes is_file()
        // fail even when the web server can serve the file — never roll back a good save.
        $ensure = PublicStorageLink::ensure();
        $publicLinked = PublicStorageLink::pathIsPubliclyReachable($path);
        if (! $publicLinked) {
            Log::warning('Site image stored; public/storage probe failed (kept upload)', [
                'path' => $path,
                'disk_root' => config('filesystems.disks.public.root'),
                'public_storage' => public_path('storage'),
                'media_path' => config('filesystems.media_path'),
                'ensure' => $ensure,
            ]);
        }

        $site->update(['site_image' => $path]);

        if ($previous && $previous !== $path && $disk->exists($previous)) {
            $disk->delete($previous);
        }

        ActivityLogger::log(
            'site.image_uploaded',
            auth()->user()->name.' uploaded an image for site "'.$site->site_name.'"',
            $site,
            ['image_path' => $path],
            $site->site_name
        );

        $imageUrl = $this->staffPublicStorageUrl($path);
        // Cache-bust so browsers do not keep a prior broken/blank response.
        $imageUrlWithBust = $imageUrl ? ($imageUrl.'?v='.time()) : null;

        $message = 'Image uploaded successfully';
        if (! $publicLinked) {
            $message = 'Image saved. Preview uses a secure media URL; run php artisan media:ensure-link if /storage still 404s publicly.';
        }

        return response()->json([
            'success' => true,
            'image_path' => $path,
            'image_url' => $imageUrlWithBust,
            'storage_ok' => $publicLinked,
            'message' => $message,
        ]);
    }

    // UPDATE (supports partial + full updates safely)
    public function update(Request $request, $id)
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();
        } catch (\Throwable $e) {
            Log::warning('Admin site schema ensure failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $site = Site::findOrFail($id);
        $user = auth()->user();
        $isMarketingEditor = (bool) ($user?->isMarketing() && ! $user?->isAdmin());

        // Store old data for email comparison / activity log
        $oldData = [
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'price' => $site->price,
            'language' => $site->language,
            'country' => $site->country,
            'category' => $site->category,
            'active' => $site->active,
            'verified' => $site->verified,
        ];

        if ($isMarketingEditor) {
            $data = $this->marketingUpdatePayload($request, $site);

            if ($data instanceof JsonResponse) {
                return $data;
            }

            if ($data instanceof RedirectResponse) {
                return $data;
            }
        } else {
            $data = $request->only([
                'site_name',
                'site_url',
                'domain',
                'example_url',
                'da',
                'dr',
                'traffic',
                'country',
                'language',
                'category',
                'price',
                'publication_time',
                'link_type',
                'sponsored',
                'partner_material',
                'as_you_prefer',
                'sensitive_prices',
                'description',
                'active',
                'site_image',
            ]);

            // Derive domain from URL when the edit form omits it.
            if (empty($data['domain']) && ! empty($data['site_url'])) {
                try {
                    $data['domain'] = preg_replace('/^www\./i', '', parse_url($data['site_url'], PHP_URL_HOST) ?: '');
                } catch (\Throwable $e) {
                    $data['domain'] = null;
                }
                if ($data['domain'] === '') {
                    $data['domain'] = null;
                }
            }

            // Manual metric edits from admin — mark as manual so auto-refresh does not overwrite.
            if ($request->hasAny(['da', 'dr', 'traffic'])) {
                $data['metrics_manual'] = true;
                $data['metrics_provider'] = 'manual';
                $data['metrics_fetched_at'] = now();
                $data['enrichment_status'] = 'ready';
            }

            // Multipart form upload from the dedicated edit page.
            if ($request->hasFile('site_image')) {
                $upload = $request->file('site_image');
                if ($upload && ! $upload->isValid()) {
                    throw ValidationException::withMessages([
                        'site_image' => [$this->siteImageValidationMessages()['site_image.uploaded']],
                    ]);
                }

                // mimes only — Hostinger finfo often rejects valid WebP/JPEG via `image`.
                $request->validate([
                    'site_image' => 'file|mimes:jpeg,png,jpg,gif,webp|max:'.$this->siteImageMaxKilobytes(),
                ], $this->siteImageValidationMessages());

                $disk = Storage::disk('public');
                $disk->makeDirectory('sites');
                $previous = is_string($site->site_image) ? $site->site_image : null;

                $stored = $upload->store('sites', 'public');
                if (! is_string($stored) || $stored === '' || ! $disk->exists($stored)) {
                    throw ValidationException::withMessages([
                        'site_image' => ['Could not save the site image to storage. Check disk permissions and MEDIA_PATH.'],
                    ]);
                }

                PublicStorageLink::ensure();
                if (! PublicStorageLink::pathIsPubliclyReachable($stored)) {
                    Log::warning('Site image saved via update; public/storage probe failed (kept upload)', [
                        'path' => $stored,
                        'disk_root' => config('filesystems.disks.public.root'),
                    ]);
                }

                if ($previous && $previous !== $stored && $disk->exists($previous)) {
                    $disk->delete($previous);
                }

                $data['site_image'] = $stored;
            } elseif ($request->has('site_image') && $request->site_image !== null && $request->site_image !== '') {
                // JSON/AJAX path: image path already uploaded via upload-image.
                $data['site_image'] = $request->site_image;
            } else {
                unset($data['site_image']);
            }

            // Admin edit form: homepage/social offers shown to advertisers in Site Details.
            $placementPatch = null;
            if ($request->boolean('placement_offers_form')) {
                $homepagePrices = $this->collectHomepagePlacementPrices($request);
                $placementPatch = [
                    'homepage_placement_prices' => $homepagePrices !== [] ? $homepagePrices : null,
                    'social_promotion' => $this->collectSocialPromotion($request),
                ];
            }

            // Prevent overwriting NOT NULL fields with null
            $data = array_filter($data, function ($value) {
                return $value !== null;
            });

            if ($placementPatch !== null) {
                // Allow null to clear offers when admin unchecks everything.
                $data = array_merge($data, $placementPatch);
            }

            if (isset($data['description']) && is_string($data['description'])) {
                $data['description'] = app(SiteDescriptionSanitizer::class)
                    ->sanitize($data['description']);
            }

            $country = strtolower(trim((string) ($data['country'] ?? $site->country ?? '')));
            $language = strtolower(trim((string) ($data['language'] ?? $site->language ?? '')));
            if ($country !== '' && $language !== '' && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                throw ValidationException::withMessages([
                    'language' => ['That language is not allowed for the selected country. Pick country first, then a paired language.'],
                ]);
            }
        }

        $site->update($data);

        $changes = [];
        foreach ($oldData as $key => $oldValue) {
            $newValue = $site->{$key} ?? null;
            if ((string) $oldValue !== (string) $newValue) {
                $changes[$key] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        ActivityLogger::log(
            'site.updated',
            auth()->user()->name.' modified site "'.$site->site_name.'"',
            $site,
            ['changes' => $changes],
            $site->site_name
        );

        $emailSent = false;

        // Send email notification to publisher about the update
        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, 'update', $oldData));
                $emailSent = true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send update notification: '.$e->getMessage());
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site updated successfully',
                'email_sent' => $emailSent,
            ]);
        }

        $message = 'Site updated successfully.'.($emailSent ? ' Publisher notified.' : '');

        return redirect()
            ->to(staff_route('sites.edit', $site->id))
            ->with('success', $message);
    }

    /**
     * Marketing may only edit metrics, geo, and niches for the bulk handoff.
     *
     * @return array<string, mixed>|JsonResponse|RedirectResponse
     */
    private function marketingUpdatePayload(Request $request, Site $site)
    {
        $allowedCountries = Country::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();
        $allowedLanguages = Language::marketplace()->pluck('code')->map(fn ($c) => strtolower((string) $c))->all();

        // Resolve exact niche names and group aliases (e.g. Technology → Technology & Gadgets).
        // Also recovers from urlencoded truncation of "Technology & Gadgets" → "Technology".
        $resolved = Category::resolveNicheNames($request->input('categories', []));
        $categories = $resolved['resolved'];
        $unknownNiches = $resolved['unknown'];
        $request->merge(['categories' => $categories]);

        $validator = Validator::make($request->all(), [
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'language' => 'required|string|max:10',
            'country' => 'required|string|max:10',
            'categories' => 'required|array|min:1|max:7',
            'site_image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:'.$this->siteImageMaxKilobytes(),
        ], $this->siteImageValidationMessages());

        $validator->after(function ($validator) use ($request, $allowedCountries, $allowedLanguages, $unknownNiches) {
            $language = strtolower(trim((string) $request->input('language', '')));
            $country = strtolower(trim((string) $request->input('country', '')));

            if ($language !== '' && ! in_array($language, $allowedLanguages, true)) {
                $validator->errors()->add('language', 'Choose a valid marketplace language.');
            }
            if ($country !== '' && ! in_array($country, $allowedCountries, true)) {
                $validator->errors()->add('country', 'Choose a valid marketplace country.');
            }
            if ($country !== '' && $language !== '' && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                $validator->errors()->add(
                    'language',
                    'That language is not allowed for the selected country. Pick country first, then a paired language.'
                );
            }

            foreach ($unknownNiches as $cat) {
                $validator->errors()->add('categories', 'Unknown niche: '.$cat);
            }
        });

        if ($validator->fails()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return back()->withErrors($validator)->withInput();
        }

        $language = strtolower(trim((string) $request->input('language')));
        $country = strtolower(trim((string) $request->input('country')));

        $payload = [
            'da' => (int) $request->input('da'),
            'dr' => (int) $request->input('dr'),
            'traffic' => (int) $request->input('traffic'),
            'language' => $language,
            'languages' => [$language],
            'country' => $country,
            'countries' => [$country],
            'category' => Site::fitCategoryColumn(implode('|', $categories), $categories),
            'categories' => $categories,
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'enrichment_status' => 'ready',
        ];

        // Same image rules as admin — optional; leave empty to keep current.
        if ($request->hasFile('site_image')) {
            $upload = $request->file('site_image');
            if ($upload && ! $upload->isValid()) {
                $message = $this->siteImageValidationMessages()['site_image.uploaded'];
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'errors' => ['site_image' => [$message]],
                    ], 422);
                }

                return back()->withErrors(['site_image' => $message])->withInput();
            }

            $disk = Storage::disk('public');
            $disk->makeDirectory('sites');
            $previous = is_string($site->site_image) ? $site->site_image : null;

            $stored = $upload->store('sites', 'public');
            if (! is_string($stored) || $stored === '' || ! $disk->exists($stored)) {
                $message = 'Could not save the site image to storage. Check disk permissions and MEDIA_PATH.';
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'errors' => ['site_image' => [$message]],
                    ], 500);
                }

                return back()->withErrors(['site_image' => $message])->withInput();
            }

            PublicStorageLink::ensure();
            if (! PublicStorageLink::pathIsPubliclyReachable($stored)) {
                Log::warning('Site image saved via marketing update; public/storage probe failed (kept upload)', [
                    'path' => $stored,
                    'disk_root' => config('filesystems.disks.public.root'),
                ]);
            }

            if ($previous && $previous !== $stored && $disk->exists($previous)) {
                $disk->delete($previous);
            }

            $payload['site_image'] = $stored;
        } elseif ($request->filled('site_image') && ! $request->hasFile('site_image')) {
            // JSON/AJAX path: image already persisted via upload-image.
            $path = (string) $request->input('site_image');
            if ($path !== '' && ! str_contains($path, '..')) {
                $payload['site_image'] = ltrim(str_replace('\\', '/', $path), '/');
            }
        }

        return $payload;
    }

    /**
     * @return array<string, float>
     */
    private function collectHomepagePlacementPrices(Request $request): array
    {
        $out = [];
        foreach (config('site_placement.homepage_days', [1, 7, 30]) as $days) {
            if (! $request->boolean("homepage.$days")) {
                continue;
            }

            $raw = $request->input("price_homepage.$days");
            $price = ($raw === null || $raw === '') ? 0.0 : (float) $raw;
            if ($price < 0) {
                continue;
            }

            $out[(string) $days] = round($price, 2);
        }

        return $out;
    }

    /**
     * @return array<string, true>|null
     */
    private function collectSocialPromotion(Request $request): ?array
    {
        $channels = [];
        foreach (config('site_placement.social_channels', ['facebook', 'instagram', 'x']) as $channel) {
            if ($request->boolean("social.$channel")) {
                $channels[$channel] = true;
            }
        }

        return $channels === [] ? null : $channels;
    }

    /**
     * Max upload size for site cover images (kilobytes).
     * App cap 10 MB, also clamped to PHP upload_max_filesize / post_max_size.
     */
    private function siteImageMaxKilobytes(): int
    {
        return SiteImageUpload::maxKilobytes();
    }

    private function siteImageMaxMegabytesLabel(): int
    {
        return SiteImageUpload::maxMegabytesLabel();
    }

    /**
     * @return array<string, string>
     */
    private function siteImageValidationMessages(): array
    {
        $mb = $this->siteImageMaxMegabytesLabel();

        return [
            'site_image.uploaded' => 'The site image failed to upload. Use JPEG, PNG, GIF, or WebP under '.$mb.' MB (check the file is not corrupted).',
            'site_image.image' => 'The site image must be a JPEG, PNG, GIF, or WebP file.',
            'site_image.mimes' => 'The site image must be a JPEG, PNG, GIF, or WebP file.',
            'site_image.max' => 'The site image must be under '.$mb.' MB.',
            'site_image.required' => 'Choose a site image to upload.',
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function parseCategoryList($raw): array
    {
        return Category::normalizeNicheInputs($raw);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
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
     * Normalize DA/DR/traffic from number inputs (commas, decimals, blanks).
     */
    private function normalizeMetricInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $raw = trim((string) $value);
        $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
        if ($raw === '') {
            return null;
        }

        // US thousands: 15,000 or 1,200,000.5
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $raw)) {
            $raw = str_replace(',', '', $raw);
        }
        // EU thousands: 15.000 or 1.200.000,5
        elseif (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }
        // Decimal comma only: 48,5
        elseif (preg_match('/^\d+,\d+$/', $raw)) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw);
    }

    // VERIFY / UNVERIFY (approve / reject) — admin only
    public function verify(Request $request, $id)
    {
        if (! auth()->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can verify or unverify sites.',
            ], 403);
        }

        $approving = (bool) (int) $request->verified;
        $reason = $this->validatedStatusReason($request, ! $approving);

        $site = Site::findOrFail($id);

        if ($approving && $site->isPendingPublisherAcceptance()) {
            return response()->json([
                'success' => false,
                'message' => 'This site is waiting for the publisher to accept it into My Sites.',
            ], 422);
        }

        // Heal complete drafts; admin approve also clears incomplete awaiting_details.
        $site->promoteFromAwaitingDetailsIfComplete();
        $site->refresh();
        if ($approving && $site->awaitsPublisherDetails()) {
            $site->clearAwaitingDetailsForAdmin();
            $site->refresh();
        }

        $oldStatus = (int) $site->verified;
        $site->verified = $approving ? 1 : 0;
        if ($site->verified) {
            $site->verified_at = now();
            $site->verify_method = 'manual';
            $site->verify_token = null;
            $site->verify_token_created_at = null;
            // Leave the review/onboarding queue once approved.
            $site->onboarding_status = null;
        } else {
            $site->verified_at = null;
            $site->verify_method = null;
            Site::ensureStatusReasonColumns();
            $this->applyStatusReason($site, $reason);
        }
        $site->save();

        $action = $site->verified ? 'site.approved' : 'site.rejected';
        $label = $site->verified ? 'approved' : 'rejected';

        ActivityLogger::log(
            $action,
            auth()->user()->name.' '.$label.' site "'.$site->site_name.'"',
            $site,
            [
                'from' => $oldStatus,
                'to' => (int) $site->verified,
                'bulk_site_request_id' => $site->bulk_site_request_id,
                'reason' => $reason,
            ],
            $site->site_name
        );

        // After verification: always refresh homepage screenshot.
        // Skip automated metrics when the publisher entered DA/DR/traffic manually.
        if ($site->verified && config('site_enrichment.enabled', true)) {
            $runMetrics = ! (bool) $site->metrics_manual;
            EnrichSiteJob::dispatch($site->id, 'verify', $runMetrics, true);
        }

        // Verify / unverify is an admin decision — clear open review reminders for this site.
        try {
            app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
        } catch (\Throwable $e) {
            Log::warning('Could not complete site review notifications after verify: '.$e->getMessage());
        }

        $emailSent = false;
        $status = $site->verified ? 'verified' : 'unverified';
        $notifyReason = $approving ? null : $reason;

        try {
            $publisher = $site->publisher;
            if ($publisher && $publisher->email) {
                Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status, null, $notifyReason));
                $emailSent = true;
            }
            if ($publisher) {
                app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status, $notifyReason);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send verification notification: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification updated',
            'email_sent' => $emailSent,
        ]);
    }

    // TOGGLE ACTIVE STATUS — admin and marketing (shared Sites Management)
    public function toggleActive(Request $request, $id)
    {
        $actor = auth()->user();
        if (! $actor?->canActivateSites()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to activate or deactivate sites.',
            ], 403);
        }

        try {
            $site = Site::findOrFail($id);
            $activating = (bool) (int) $request->active;
            // Must not be swallowed by the catch below — UI expects 422 + errors.reason.
            $reason = $this->validatedStatusReason($request, ! $activating);

            if ($activating && $site->isPendingPublisherAcceptance()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This site is waiting for the publisher to accept it into My Sites.',
                ], 422);
            }

            // Heal complete drafts; staff activate also clears incomplete awaiting_details
            // so marketing can finish the same flow as admin from Sites Management.
            if ($activating) {
                $site->promoteFromAwaitingDetailsIfComplete();
                $site->refresh();
                if ($site->awaitsPublisherDetails()) {
                    $site->clearAwaitingDetailsForAdmin();
                    $site->refresh();
                }
            }

            $oldStatus = (int) $site->active;
            $site->active = $activating ? 1 : 0;
            if ($activating) {
                // Leave the review/onboarding queue once live.
                $site->onboarding_status = null;
            } else {
                Site::ensureStatusReasonColumns();
                $this->applyStatusReason($site, $reason);
            }
            $site->save();

            $action = $site->active ? 'site.activated' : 'site.deactivated';
            $label = $site->active ? 'activated' : 'deactivated';

            ActivityLogger::log(
                $action,
                ($actor->name ?? 'Staff').' '.$label.' site "'.$site->site_name.'"',
                $site,
                [
                    'from' => $oldStatus,
                    'to' => (int) $site->active,
                    'bulk_site_request_id' => $site->bulk_site_request_id,
                    'by_role' => $actor->activeRole(),
                    'reason' => $reason,
                ],
                $site->site_name
            );

            // Activate / deactivate counts as an admin decision for the open review task.
            try {
                app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
            } catch (\Throwable $e) {
                Log::warning('Could not complete site review notifications after active toggle: '.$e->getMessage());
            }

            $emailSent = false;
            $status = $site->active ? 'activated' : 'deactivated';
            $notifyReason = $activating ? null : $reason;
            $belowQualityBar = $activating && ! $site->hasGoodMetrics();
            $missingMarketWarning = null;
            if ($activating && ! $site->hasMarketplaceCountry()) {
                $missingMarketWarning = 'Activated without a marketplace country — this listing will not appear in country filters. Edit the site to set a country.';
            }
            $qualityWarning = $belowQualityBar
                ? 'Activated below the quality bar (DA ≥ 30, DR ≥ 30, traffic ≥ 10,000). Listing is live; consider updating metrics before promoting it.'
                : null;
            // Prefer the missing-market warning when both apply; quality is still flagged via below_quality_bar.
            $warning = $missingMarketWarning ?? $qualityWarning;

            try {
                $publisher = $site->publisher;
                if ($publisher && $publisher->email) {
                    Mail::to($publisher->email)->send(new SiteStatusNotification($site, $status, null, $notifyReason));
                    $emailSent = true;
                }
                if ($publisher) {
                    app(InAppNotificationService::class)->notifySiteStatusChanged($site->fresh(), $status, $notifyReason);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send status notification: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => $activating ? 'Site activated' : 'Site deactivated',
                'email_sent' => $emailSent,
                'active' => (bool) $site->active,
                'reason' => $notifyReason,
                'warning' => $warning,
                'missing_market' => $missingMarketWarning !== null,
                'below_quality_bar' => $belowQualityBar,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to toggle site active status', [
                'site_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $hint = '';
            if (str_contains($e->getMessage(), 'onboarding_status')) {
                $hint = ' Run database/sql/fix_sites_onboarding_status.sql on the database if this persists.';
            } elseif (str_contains($e->getMessage(), 'status_reason')) {
                $hint = ' Run database/sql/add_sites_status_reason.sql on the database if this persists.';
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not update active status.'.$hint,
            ], 500);
        }
    }

    /**
     * @return string|null Trimmed reason when provided; null when not required / empty optional.
     */
    private function validatedStatusReason(Request $request, bool $required): ?string
    {
        $rules = $required
            ? ['reason' => ['required', 'string', 'min:10', 'max:1000']]
            : ['reason' => ['nullable', 'string', 'max:1000']];

        $data = $request->validate($rules);
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

        return $reason !== '' ? $reason : null;
    }

    private function applyStatusReason(Site $site, ?string $reason): void
    {
        if ($reason === null) {
            return;
        }

        $site->status_reason = $reason;
        $site->status_reason_at = now();
        $site->status_reason_by = auth()->id();
    }

    // DELETE — pending never-ordered: hard delete. Live listings: archive.
    // Sites with order items cannot be removed (FK restrict + 422).
    public function destroy(Request $request, $id)
    {
        $user = auth()->user();
        $site = Site::findOrFail($id);

        $isAdmin = (bool) $user?->isAdmin();
        $isMarketingPendingDelete = (bool) $user?->isMarketing() && $site->canBeDeletedByMarketing();

        if (! $isAdmin && ! $isMarketingPendingDelete) {
            return response()->json([
                'success' => false,
                'message' => $user?->isMarketing()
                    ? 'Marketing can only delete pending sites that are not verified or active in the portal.'
                    : 'Only admins can delete sites.',
            ], 403);
        }

        $orderCount = $site->orderItemsCount();
        if ($orderCount > 0) {
            return response()->json([
                'success' => false,
                'message' => $orderCount === 1
                    ? 'This site has 1 order and cannot be deleted. Deactivate it to hide it from the catalog.'
                    : 'This site has '.$orderCount.' orders and cannot be deleted. Deactivate it to hide it from the catalog.',
                'order_count' => $orderCount,
            ], 422);
        }

        if ($site->isArchived()) {
            return response()->json([
                'success' => false,
                'message' => 'This site is already archived.',
            ], 422);
        }

        $siteName = $site->site_name;
        $siteId = $site->id;
        $domain = $site->domain;
        $bulkRequestId = $site->bulk_site_request_id;
        $onboarding = $site->onboarding_status;
        $rejectionReason = $this->validatedStatusReason($request, true);
        $publisher = $site->publisher;

        Site::ensureStatusReasonColumns();
        $this->applyStatusReason($site, $rejectionReason);

        $shouldArchive = (bool) $site->verified || (bool) $site->active;
        if ($shouldArchive) {
            if (! $site->archiveByStaff($rejectionReason)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Archive is not available yet.',
                ], 503);
            }

            try {
                app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
            } catch (\Throwable $e) {
                Log::warning('Could not complete site review notifications before archive: '.$e->getMessage());
            }

            $this->notifyPublisherSiteRemoved($site->fresh() ?? $site, $publisher, $rejectionReason, 'archived');

            ActivityLogger::log(
                'site.archived',
                ($user->name ?? 'Staff').' archived site "'.$siteName.'"'.($domain ? ' ('.$domain.')' : ''),
                $site,
                [
                    'site_id' => $siteId,
                    'site_name' => $siteName,
                    'domain' => $domain,
                    'bulk_site_request_id' => $bulkRequestId,
                    'onboarding_status' => $onboarding,
                    'archived_by_role' => $user?->activeRole(),
                    'reason' => $rejectionReason,
                ],
                $siteName
            );

            return response()->json([
                'success' => true,
                'archived' => true,
                'message' => 'Site archived and hidden from the catalog.',
            ]);
        }

        try {
            app(InAppNotificationService::class)->completeAdminSiteReviewNotifications($site);
        } catch (\Throwable $e) {
            Log::warning('Could not complete site review notifications before delete: '.$e->getMessage());
        }

        // Deleting is how staff reject a pending submission outright.
        $notifySnapshot = clone $site;

        if ($site->site_image && Storage::disk('public')->exists($site->site_image)) {
            Storage::disk('public')->delete($site->site_image);
        }

        // Do not rely on ON DELETE SET NULL alone — sqlite tests and some
        // hosts skip FKs, and the URL+price row must return to Done.
        // Lock bulk then items (same order as Done) so a concurrent Done
        // cannot attach a new draft while this one is half-unlinked.
        $deleted = false;
        DB::transaction(function () use ($siteId, $bulkRequestId, &$deleted) {
            if ($bulkRequestId) {
                BulkSiteRequest::query()->whereKey($bulkRequestId)->lockForUpdate()->first();
            }

            BulkSiteRequestItem::query()->where('site_id', $siteId)->lockForUpdate()->get();
            BulkSiteRequestItem::query()->where('site_id', $siteId)->update(['site_id' => null]);

            $lockedSite = Site::query()->whereKey($siteId)->lockForUpdate()->first();
            if ($lockedSite) {
                $lockedSite->delete();
                $deleted = true;
            }

            if ($bulkRequestId) {
                BulkSiteRequest::query()->find($bulkRequestId)?->refreshProgressStatus();
            }
        });

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'This site was already deleted.',
            ], 422);
        }

        $this->notifyPublisherSiteRemoved($notifySnapshot, $publisher, $rejectionReason, 'removed');

        ActivityLogger::log(
            $isMarketingPendingDelete && ! $isAdmin ? 'site.deleted_by_marketing' : 'site.deleted',
            ($user->name ?? 'Staff').' deleted site "'.$siteName.'"'.($domain ? ' ('.$domain.')' : ''),
            null,
            [
                'site_id' => $siteId,
                'site_name' => $siteName,
                'domain' => $domain,
                'bulk_site_request_id' => $bulkRequestId,
                'publisher_id' => $publisher?->id,
                'onboarding_status' => $onboarding,
                'deleted_by_role' => $user?->activeRole(),
                'reason' => $rejectionReason,
            ],
            $siteName
        );

        return response()->json([
            'success' => true,
            'archived' => false,
            'message' => 'Site deleted successfully',
        ]);
    }

    private function notifyPublisherSiteRemoved(
        Site $site,
        ?User $publisher,
        ?string $reason,
        string $action
    ): void {
        try {
            if ($publisher?->email) {
                Mail::to($publisher->email)->send(
                    new SiteStatusNotification($site, $action, null, $reason)
                );
            }
            if ($publisher) {
                app(InAppNotificationService::class)
                    ->notifySiteStatusChanged($site, $action, $reason);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to notify publisher after site '.$action.': '.$e->getMessage());
        }
    }
}
