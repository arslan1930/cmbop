<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Jobs\CaptureSiteScreenshotJob;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\AgencySiteImportService;
use App\Services\CheckoutSchemaService;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\Marketplace\LanguageCountryMap;
use App\Support\NormalizesHttpUrls;
use App\Support\SiteDescriptionRules;
use App\Support\SiteImageUpload;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SiteController extends Controller
{
    use NormalizesHttpUrls;

    public function index()
    {
        $this->ensureListingSchema();

        // Europe + major North America markets
        $countries = Country::marketplace()->orderBy('name')->get();
        // Same A–Z niche list as Catalog main search filter (Category::catalogPickerNames).
        $categories = Category::catalogPickerNames();
        $languages = Language::marketplace()
            ->orderBy('name')
            ->get();

        $pairs = app(CountryLanguagePairs::class);
        // Country-first: country code → allowed languages.
        $countryLanguageMap = $pairs->mapWithNames();
        // Keep language→countries for any legacy UI that still reads it.
        $languageCountryMap = app(LanguageCountryMap::class)->map();

        $openBulkRequest = BulkSiteRequest::query()
            ->where('publisher_id', auth()->id())
            ->blockingPublisher()
            ->latest()
            ->first();

        $awaitingDetailsCount = Site::query()
            ->where('publisher_id', auth()->id())
            ->notFromCancelledBulk()
            ->where('onboarding_status', Site::ONBOARDING_AWAITING_DETAILS)
            ->count();

        $detailsCompleteCount = Site::query()
            ->where('publisher_id', auth()->id())
            ->notFromCancelledBulk()
            ->where('onboarding_status', Site::ONBOARDING_DETAILS_COMPLETE)
            ->count();

        return view('publisher.websites', compact(
            'countries',
            'categories',
            'languages',
            'countryLanguageMap',
            'languageCountryMap',
            'openBulkRequest',
            'awaitingDetailsCount',
            'detailsCompleteCount'
        ));
    }

    /**
     * @deprecated English expansion lives on LanguageCountryMap; kept for BC call sites.
     *
     * @return list<array{code: string, name: string}>
     */
    private function englishMarketplaceCountries(): array
    {
        return app(LanguageCountryMap::class)->englishMarketplaceCountries();
    }

    public function getCountryLanguages($countryCode)
    {
        $pairs = app(CountryLanguagePairs::class);
        $rows = $pairs->mapWithNames()[strtolower(trim(scalar_text($countryCode)))] ?? [];

        return response()->json(collect($rows)->map(fn ($r) => [
            'code' => $r['code'],
            'name' => $r['name'],
        ])->values());
    }

    public function store(Request $request)
    {
        // Normalize URLs before validation (publishers often omit https://)
        $siteUrl = $this->normalizeHttpUrl($request->input('siteUrl', ''));
        $exampleUrl = $this->normalizeHttpUrl($request->input('exampleUrl', ''));
        $request->merge([
            'siteUrl' => $siteUrl,
            'exampleUrl' => $exampleUrl,
        ]);

        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (! $host) {
            return back()->withErrors(['siteUrl' => 'Invalid URL'])->withInput();
        }

        $domain = Site::normalizeMarketplaceDomain($host);
        if ($domain === '') {
            return back()->withErrors(['siteUrl' => 'Invalid URL'])->withInput();
        }

        // Handle categories - get as array from multi-select
        $categories = $this->parseCategoryList($request->input('categories', $request->input('category')));
        // Pipe-join avoids breaking names that contain commas (e.g. "Marketing, PR & Advertising")
        $primaryCategory = ! empty($categories) ? implode('|', $categories) : scalar_text($request->category);
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

        if ($allowedCountries === [] || $allowedLanguages === []) {
            Log::error('Publisher site store blocked: empty marketplace country/language lists', [
                'user_id' => auth()->id(),
                'countries' => count($allowedCountries),
                'languages' => count($allowedLanguages),
            ]);

            return redirect()->back()
                ->withErrors([
                    'country' => 'Marketplace countries or languages are not configured. Please contact support — your listing was not saved.',
                ])
                ->withInput();
        }

        $this->normalizeSensitiveFlags($request);

        $validator = Validator::make($request->all(), [
            'siteName' => 'required|string|max:255',
            'siteUrl' => 'required|url|max:255',
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0|max:99999999.99',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string',
            'price_sensitive.*' => 'nullable|numeric|min:0|max:99999999.99',
            'sensitive.crypto' => 'nullable|boolean',
            'sensitive.trading' => 'nullable|boolean',
            'sensitive.CBD' => 'nullable|boolean',
            'sensitive.forex' => 'nullable|boolean',
            'price_sensitive.crypto' => 'nullable|required_with:sensitive.crypto|numeric|min:0|max:99999999.99',
            'price_sensitive.trading' => 'nullable|required_with:sensitive.trading|numeric|min:0|max:99999999.99',
            'price_sensitive.CBD' => 'nullable|required_with:sensitive.CBD|numeric|min:0|max:99999999.99',
            'price_sensitive.forex' => 'nullable|required_with:sensitive.forex|numeric|min:0|max:99999999.99',
            'homepage.1' => 'nullable|boolean',
            'homepage.7' => 'nullable|boolean',
            'homepage.30' => 'nullable|boolean',
            'price_homepage.1' => 'nullable|numeric|min:0|max:99999999.99',
            'price_homepage.7' => 'nullable|numeric|min:0|max:99999999.99',
            'price_homepage.30' => 'nullable|numeric|min:0|max:99999999.99',
            'social.facebook' => 'nullable|boolean',
            'social.instagram' => 'nullable|boolean',
            'social.x' => 'nullable|boolean',
        ]);

        $validator->after(function ($validator) use ($countryCodes, $languageCodes) {
            $country = $countryCodes[0] ?? null;
            $language = $languageCodes[0] ?? null;
            if ($country && $language && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                $validator->errors()->add(
                    'language',
                    'That language is not allowed for the selected country. Pick country first, then a paired language (e.g. Germany → German; UAE → Arabic or English).'
                );
            }
        });

        $validator->after(function ($validator) use ($domain) {
            $existing = Site::findOccupyingDomain($domain);
            if (! $existing) {
                return;
            }

            if ($existing->isArchived()) {
                $validator->errors()->add('siteUrl', $existing->occupyingDomainMessage());

                return;
            }

            if ((int) $existing->publisher_id === (int) auth()->id()) {
                $validator->errors()->add('siteUrl', 'You have already added this website.');

                return;
            }

            $validator->errors()->add('siteUrl', 'This website domain is already registered by another publisher. If you own it, use “Claim a website” on this page so we can verify the listing name and transfer ownership.');
        });

        $validator->after(function ($validator) use ($domain) {
            $pending = BulkSiteRequestItem::occupyingPendingDomainMessage($domain);
            if ($pending !== null) {
                $validator->errors()->add('siteUrl', $pending);
            }
        });

        $validator->after(function ($validator) use ($request) {
            foreach (SiteDescriptionRules::errors(scalar_text($request->input('siteDescription', ''))) as $message) {
                $validator->errors()->add('siteDescription', $message);
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        $site = null;

        $this->ensureListingSchema();

        try {
            DB::transaction(function () use ($request, $domain, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, &$site) {
                Site::releaseCancelledBulkDomain($domain, (int) auth()->id());
                $existing = Site::findOccupyingDomain($domain, lock: true);
                if ($existing) {
                    throw ValidationException::withMessages([
                        'siteUrl' => [$existing->occupyingDomainMessage()],
                    ]);
                }
                $pending = BulkSiteRequestItem::occupyingPendingDomainMessage($domain, lock: true);
                if ($pending !== null) {
                    throw ValidationException::withMessages([
                        'siteUrl' => [$pending],
                    ]);
                }

                $site = new Site;

                $sensitivePrices = $this->collectSensitivePrices($request);
                $homepagePrices = $this->collectHomepagePlacementPrices($request);
                $socialPromotion = $this->collectSocialPromotion($request);

                // Manual publisher metrics — never auto-fetched/overwritten.
                // applyMarketplaceListing skips columns missing on older Hostinger DBs
                // and fits legacy category VARCHAR(50) when multi-category strings are long.
                $site->applyMarketplaceListing([
                    'publisher_id' => auth()->id(),
                    'publisher_accepted_at' => now(),
                    'assigned_by_user_id' => null,
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
                    // Self-created listings are accepted immediately (not staff invites).
                    'publisher_accepted_at' => now(),
                    'enrichment_status' => 'pending',
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                    'homepage_placement_prices' => ! empty($homepagePrices) ? $homepagePrices : null,
                    'social_promotion' => $socialPromotion,
                ]);

                $this->applySiteTag($site, $request);

                $site->save();
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Publisher site store failed', [
                'user_id' => auth()->id(),
                'domain' => $domain,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            $hint = 'We could not save this website. Please check your details and try again.';
            if (str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'Data too long')
                || str_contains($e->getMessage(), 'onboarding_status')) {
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
                app(EmailNotificationService::class)->notifyAdminsNewSite($site, 'create');
            } catch (\Throwable $e) {
                Log::error('Failed to send new-site admin notification: '.$e->getMessage());
            }
        }

        return redirect()->back()->with('success', 'Site submitted successfully! Admin will review and activate it within 24-48 hours. A homepage screenshot is being generated automatically.');
    }

    public function ajax(Request $request)
    {
        try {
            $this->ensureListingSchema();
            $query = scalar_text($request->get('query'));
            $status = strtolower(scalar_text($request->get('status', 'active')));
            if (! in_array($status, ['pending', 'active', 'invites', 'archived', 'all'], true)) {
                $status = 'active';
            }
            $page = max(1, (int) scalar_text($request->get('page', 1)));

            $base = Site::where('publisher_id', auth()->id());
            $acceptedBase = (clone $base)->acceptedByPublisher();

            $openBulkRequest = BulkSiteRequest::query()
                ->where('publisher_id', auth()->id())
                ->blockingPublisher()
                ->latest()
                ->first();

            $waitingItemsQuery = BulkSiteRequestItem::query()
                ->whereNull('site_id')
                ->whereHas('bulkRequest', function ($q) {
                    $q->where('publisher_id', auth()->id())
                        ->where('status', '!=', BulkSiteRequest::STATUS_CANCELLED);
                });

            $waitingItemsCount = (clone $waitingItemsQuery)->count();
            // Match list filters: Active/Pending badges exclude archived sites.
            $sitePendingCount = (clone $acceptedBase)->notArchived()->notFromCancelledBulk()
                ->where('active', 0)->where('verified', 0)->count();
            $pendingCount = $sitePendingCount + $waitingItemsCount;
            $inviteCount = (clone $base)->pendingPublisherAcceptance()->count();

            $activeQuery = (clone $acceptedBase)->notArchived()->notFromCancelledBulk()->where(function ($q) {
                $q->where('active', 1)->orWhere('verified', 1);
            });
            $activeCount = (clone $activeQuery)->count();
            $activeIds = (clone $activeQuery)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            $bulkWaitingItems = collect();
            if ($status === 'pending' && $page === 1) {
                $bulkWaitingItems = (clone $waitingItemsQuery)
                    ->when($query, function ($q) use ($query) {
                        $q->where(function ($sub) use ($query) {
                            $sub->where('site_url', 'like', "%{$query}%")
                                ->orWhere('domain', 'like', "%{$query}%");
                        });
                    })
                    ->orderBy('id')
                    ->get();
            }

            if ($status === 'invites') {
                $sitesQuery = (clone $base)->pendingPublisherAcceptance();
            } elseif ($status === 'archived') {
                $sitesQuery = (clone $acceptedBase)->archived();
            } elseif ($status === 'all') {
                $sitesQuery = (clone $acceptedBase)->notArchived()->notFromCancelledBulk();
            } else {
                $sitesQuery = (clone $acceptedBase)->notArchived()
                    ->when($status === 'pending', function ($q) {
                        $q->notFromCancelledBulk()
                            ->where('active', 0)->where('verified', 0);
                    })
                    ->when($status === 'active', function ($q) {
                        $q->notFromCancelledBulk()
                            ->where(function ($inner) {
                                $inner->where('active', 1)->orWhere('verified', 1);
                            });
                    });
            }

            $sites = $sitesQuery
                ->when($query, function ($q) use ($query) {
                    $q->where(function ($sub) use ($query) {
                        $sub->where('site_name', 'like', "%{$query}%")
                            ->orWhere('site_url', 'like', "%{$query}%")
                            ->orWhere('domain', 'like', "%{$query}%");
                    });
                })
                ->latest()
                ->paginate(20)
                ->appends([
                    'status' => $status,
                    'query' => $query,
                ]);

            return view('publisher.sites.partials.table', compact(
                'sites',
                'pendingCount',
                'activeCount',
                'inviteCount',
                'activeIds',
                'status',
                'bulkWaitingItems',
                'openBulkRequest',
                'waitingItemsCount'
            ))->render();
        } catch (\Throwable $e) {
            Log::error('Publisher sites ajax failed: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'exception' => $e,
            ]);

            return response(
                '<div class="alert alert-danger text-center mb-0">Could not load your sites. Please refresh and try again.</div>',
                500
            );
        }
    }

    public function acceptAssignment(Request $request, $id)
    {
        $site = DB::transaction(function () use ($id) {
            $locked = Site::where('publisher_id', auth()->id())->lockForUpdate()->findOrFail($id);
            if (! $locked->isPendingPublisherAcceptance()) {
                return null;
            }

            $locked->publisher_accepted_at = now();
            $locked->save();

            return $locked;
        });

        if ($site === null) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This site is not waiting for acceptance.',
                ], 422);
            }

            return redirect()
                ->route('publisher.websites', ['status' => 'pending'])
                ->with('error', 'This site is not waiting for acceptance.');
        }

        try {
            app(InAppNotificationService::class)->completePublisherSiteAssignmentNotifications($site);
        } catch (\Throwable $e) {
            Log::warning('Failed to archive invite notification after publisher accepted site: '.$e->getMessage());
        }

        try {
            ActivityLogger::log(
                'site.assignment_accepted',
                (auth()->user()->name ?? 'Publisher').' accepted staff-assigned site "'.$site->site_name.'"',
                $site,
                [
                    'publisher_id' => auth()->id(),
                    'assigned_by_user_id' => $site->assigned_by_user_id,
                    'domain' => $site->domain,
                ],
                $site->site_name
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to log publisher site acceptance: '.$e->getMessage());
        }

        try {
            app(EmailNotificationService::class)->notifyAdminsNewSite($site, 'accept');
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins after publisher accepted staff-assigned site: '.$e->getMessage());
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site accepted. It now appears in My Sites.',
                'site_id' => $site->id,
            ]);
        }

        return redirect()
            ->route('publisher.websites', ['status' => 'pending'])
            ->with('success', 'Site accepted. It now appears in My Sites (Pending) until staff activate it.');
    }

    public function rejectAssignment(Request $request, $id)
    {
        $rejected = DB::transaction(function () use ($id) {
            $locked = Site::where('publisher_id', auth()->id())->lockForUpdate()->findOrFail($id);
            if (! $locked->isPendingPublisherAcceptance()) {
                return ['status' => 'not_pending'];
            }

            $orderCount = $locked->orderItemsCount();
            if ($orderCount > 0) {
                return ['status' => 'has_orders', 'order_count' => $orderCount];
            }

            try {
                app(InAppNotificationService::class)->completePublisherSiteAssignmentNotifications($locked);
            } catch (\Throwable $e) {
                Log::warning('Failed to archive invite notification after publisher declined site: '.$e->getMessage());
            }

            $snapshot = [
                'status' => 'deleted',
                'id' => $locked->id,
                'domain' => $locked->domain ?: $locked->site_name,
                'cover' => is_string($locked->site_image) ? $locked->site_image : null,
                'screenshot' => is_string($locked->screenshot_path) ? $locked->screenshot_path : null,
                'thumb' => is_string($locked->screenshot_thumb_path) ? $locked->screenshot_thumb_path : null,
            ];
            $locked->delete();

            return $snapshot;
        });

        if (($rejected['status'] ?? '') === 'not_pending') {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This site is not waiting for acceptance.',
                ], 422);
            }

            return redirect()
                ->route('publisher.websites', ['status' => 'invites'])
                ->with('error', 'This site is not waiting for acceptance.');
        }

        if (($rejected['status'] ?? '') === 'has_orders') {
            $orderCount = (int) ($rejected['order_count'] ?? 0);
            $message = $orderCount === 1
                ? 'This site has 1 order and cannot be removed. Contact support if the invitation was sent in error.'
                : 'This site has '.$orderCount.' orders and cannot be removed. Contact support if the invitation was sent in error.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            return redirect()
                ->route('publisher.websites', ['status' => 'invites'])
                ->with('error', $message);
        }

        SiteImageUpload::deleteListingPublicMedia(
            $rejected['cover'] ?? null,
            $rejected['screenshot'] ?? null,
            $rejected['thumb'] ?? null,
            (int) ($rejected['id'] ?? 0)
        );
        $siteId = $rejected['id'];
        $domain = $rejected['domain'];

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Site invitation declined.',
                'site_id' => $siteId,
            ]);
        }

        return redirect()
            ->route('publisher.websites', ['status' => 'invites'])
            ->with('success', 'Declined '.$domain.'. The listing was removed.');
    }

    public function editData(int $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isFromCancelledBulk()) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is from a cancelled bulk request and cannot be edited.',
            ], 422);
        }

        if ($site->isPendingPublisherAcceptance()) {
            return response()->json([
                'success' => false,
                'message' => 'Accept this invitation before editing the listing.',
            ], 422);
        }

        $categories = is_array($site->categories) && count($site->categories)
            ? array_values($site->categories)
            : array_values(array_filter(array_map('trim', preg_split('/[|,]/', (string) $site->category) ?: [])));

        return response()->json([
            'success' => true,
            'site' => [
                'id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'example_url' => $site->example_url,
                'da' => (int) $site->da,
                'dr' => (int) $site->dr,
                'traffic' => (int) $site->traffic,
                'country' => $site->country,
                'countries' => $site->countries,
                'language' => $site->language,
                'languages' => $site->languages,
                'category' => $site->category,
                'categories' => $categories,
                'price' => (float) $site->price,
                'turnaround_time' => $site->turnaround_time,
                'publication_time' => $site->publication_time,
                'link_type' => $site->link_type,
                'description' => $site->description,
                'sponsored' => (bool) $site->sponsored,
                'partner_material' => (bool) $site->partner_material,
                'as_you_prefer' => (bool) $site->as_you_prefer,
                'sensitive_prices' => $site->sensitive_prices ?: new \stdClass,
                'homepage_placement_prices' => $site->homepage_placement_prices ?: new \stdClass,
                'social_promotion' => $site->social_promotion ?: new \stdClass,
                'verified' => (bool) $site->verified,
                'active' => (bool) $site->active,
                'is_live' => (bool) ($site->verified || $site->active),
                'is_archived' => $site->isArchived(),
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isFromCancelledBulk()) {
            return redirect()->back()->with('error', 'This listing is from a cancelled bulk request and cannot be edited.');
        }

        if ($site->isPendingPublisherAcceptance()) {
            return redirect()
                ->route('publisher.websites', ['status' => 'invites'])
                ->with('error', 'Accept this invitation before editing the listing.');
        }

        if ($site->isArchived()) {
            return redirect()->back()->with('error', 'Archived sites cannot be edited. Restore the site first.');
        }

        if ($request->filled('exampleUrl')) {
            $request->merge([
                'exampleUrl' => $this->normalizeHttpUrl($request->input('exampleUrl')),
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

        if ($allowedCountries === [] || $allowedLanguages === []) {
            Log::error('Publisher site update blocked: empty marketplace country/language lists', [
                'user_id' => auth()->id(),
                'site_id' => $site->id,
                'countries' => count($allowedCountries),
                'languages' => count($allowedLanguages),
            ]);

            return redirect()->back()
                ->withErrors([
                    'country' => 'Marketplace countries or languages are not configured. Please contact support — your changes were not saved.',
                ])
                ->withInput();
        }

        $this->normalizeSensitiveFlags($request);

        $validator = Validator::make($request->all(), [
            'exampleUrl' => 'required|url|max:255',
            'da' => 'required|integer|min:0|max:100',
            'dr' => 'required|integer|min:0|max:100',
            'traffic' => 'required|integer|min:0|max:4294967295',
            'country' => 'required|string|size:2|in:'.implode(',', $allowedCountries),
            'language' => 'required|string|size:2|in:'.implode(',', $allowedLanguages),
            'categories' => 'required|array|min:1|max:7',
            'price' => 'required|numeric|min:0|max:99999999.99',
            'turnaround_time' => 'required|string|in:24h,48h,3days,5days,7days',
            'publicationTime' => 'required|string|max:20|in:6months,1year,permanent',
            'link_type' => 'required|in:dofollow,nofollow',
            'siteDescription' => 'required|string',
            'price_sensitive.*' => 'nullable|numeric|min:0|max:99999999.99',
            'sensitive.crypto' => 'nullable|boolean',
            'sensitive.trading' => 'nullable|boolean',
            'sensitive.CBD' => 'nullable|boolean',
            'sensitive.forex' => 'nullable|boolean',
            'price_sensitive.crypto' => 'nullable|required_with:sensitive.crypto|numeric|min:0|max:99999999.99',
            'price_sensitive.trading' => 'nullable|required_with:sensitive.trading|numeric|min:0|max:99999999.99',
            'price_sensitive.CBD' => 'nullable|required_with:sensitive.CBD|numeric|min:0|max:99999999.99',
            'price_sensitive.forex' => 'nullable|required_with:sensitive.forex|numeric|min:0|max:99999999.99',
            'homepage.1' => 'nullable|boolean',
            'homepage.7' => 'nullable|boolean',
            'homepage.30' => 'nullable|boolean',
            'price_homepage.1' => 'nullable|numeric|min:0|max:99999999.99',
            'price_homepage.7' => 'nullable|numeric|min:0|max:99999999.99',
            'price_homepage.30' => 'nullable|numeric|min:0|max:99999999.99',
            'social.facebook' => 'nullable|boolean',
            'social.instagram' => 'nullable|boolean',
            'social.x' => 'nullable|boolean',
        ]);

        $validator->after(function ($validator) use ($countryCodes, $languageCodes) {
            $country = $countryCodes[0] ?? null;
            $language = $languageCodes[0] ?? null;
            if ($country && $language && ! app(CountryLanguagePairs::class)->isAllowedPair($country, $language)) {
                $validator->errors()->add(
                    'language',
                    'That language is not allowed for the selected country. Pick country first, then a paired language (e.g. Germany → German; UAE → Arabic or English).'
                );
            }
        });

        $validator->after(function ($validator) use ($request) {
            $rawDescription = $request->input('siteDescription', '');
            if (! is_string($rawDescription)) {
                return;
            }
            foreach (SiteDescriptionRules::errors($rawDescription) as $message) {
                $validator->errors()->add('siteDescription', $message);
            }
        });

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput()
                ->with('editing_site_id', $site->id);
        }

        $cleanDescription = strip_tags($request->siteDescription, '<p><a><b><strong><i><ul><ol><li><br>');

        $needsRereview = $this->updateRequiresRereview($site, $countryCodes[0] ?? null, $languageCodes[0] ?? null, $categoriesArray ?? []);
        $wasLive = $site->verified || $site->active;
        $keepAsBulkDraft = $site->awaitsPublisherDetails() || $site->hasDetailsComplete();

        $this->ensureListingSchema();

        try {
            DB::transaction(function () use ($site, $request, $cleanDescription, $categoriesArray, $primaryCategory, $countryCodes, $languageCodes, $needsRereview, $keepAsBulkDraft) {
                $sensitivePrices = $this->collectSensitivePrices($request);
                $homepagePrices = $this->collectHomepagePlacementPrices($request);
                $socialPromotion = $this->collectSocialPromotion($request);

                $payload = [
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
                    'sensitive_prices' => ! empty($sensitivePrices) ? $sensitivePrices : null,
                    'homepage_placement_prices' => ! empty($homepagePrices) ? $homepagePrices : null,
                    'social_promotion' => $socialPromotion,
                ];

                if ($keepAsBulkDraft || $needsRereview) {
                    $payload['verified'] = false;
                    $payload['active'] = false;
                }

                // Bulk drafts stay with the publisher until Review & submit.
                $keepAsBulkDraft = $site->awaitsPublisherDetails() || $site->hasDetailsComplete();

                $site->applyMarketplaceListing($payload);
                $this->applySiteTag($site, $request);
                $site->save();

                // Move awaiting_details → details_complete (not admin queue yet).
                if ($keepAsBulkDraft && ! $site->markDetailsComplete()) {
                    throw new \RuntimeException('onboarding_status details_complete rejected by database');
                }
            });
        } catch (\Throwable $e) {
            Log::error('Publisher site update failed', [
                'site_id' => $site->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            $hint = 'We could not update this website. Please check your details and try again.';
            if (str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'Data too long')
                || str_contains($e->getMessage(), 'onboarding_status')) {
                $hint = 'We could not update this website because the database is missing a recent update. Please contact support (or run the sites column migration SQL).';
            }

            return redirect()->back()
                ->withErrors(['siteUrl' => $hint])
                ->withInput()
                ->with('editing_site_id', $site->id);
        }

        $site->refresh();

        // Pre-submit bulk drafts: no admin notify until Review & submit.
        if ($site->hasDetailsComplete() || $site->awaitsPublisherDetails()) {
            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('success', '“'.$site->site_name.'” saved. Review your sites, then submit for admin review.');
        }

        if ($needsRereview) {
            try {
                app(EmailNotificationService::class)->notifyAdminsNewSite($site, 'update');
            } catch (\Throwable $e) {
                Log::error('Failed to send site-update admin notification: '.$e->getMessage());
            }
        }

        if ($needsRereview && $wasLive) {
            return redirect()->back()->with('success', 'Site updated. Market/niche changes require re-review — it is offline until an admin approves it again.');
        }

        if ($needsRereview) {
            return redirect()->back()->with('success', 'Site updated and queued for review.');
        }

        // Bulk drafts return to the review sheet (PUT has no reliable referrer).
        if ($site->fresh()->hasDetailsComplete()) {
            return redirect()
                ->route('publisher.bulk-sites.review')
                ->with('success', 'Website details saved. Review your sites, then submit them for approval.');
        }

        return redirect()->back()->with('success', 'Site updated successfully.');
    }

    public function destroy($id)
    {
        $deleted = DB::transaction(function () use ($id) {
            $site = Site::where('publisher_id', auth()->id())->lockForUpdate()->findOrFail($id);

            if ($site->verified || $site->active) {
                return ['status' => 'live'];
            }

            if ($site->isArchived()) {
                return ['status' => 'archived'];
            }

            if ($site->orderItemsCount() > 0) {
                return ['status' => 'has_orders'];
            }

            try {
                app(InAppNotificationService::class)->completePublisherSiteAssignmentNotifications($site);
            } catch (\Throwable $e) {
                Log::warning('Failed to archive invite notification after publisher deleted site: '.$e->getMessage());
            }

            $snapshot = [
                'status' => 'deleted',
                'cover' => is_string($site->site_image) ? $site->site_image : null,
                'screenshot' => is_string($site->screenshot_path) ? $site->screenshot_path : null,
                'thumb' => is_string($site->screenshot_thumb_path) ? $site->screenshot_thumb_path : null,
                'id' => (int) $site->id,
                'bulk_id' => $site->bulk_site_request_id,
            ];
            $site->delete();

            return $snapshot;
        });

        if (($deleted['status'] ?? '') === 'live') {
            return redirect()->back()->with('error', 'You cannot delete an active or verified site. Archive it instead.');
        }

        if (($deleted['status'] ?? '') === 'archived') {
            return redirect()->back()->with('error', 'Archived sites cannot be deleted from here.');
        }

        if (($deleted['status'] ?? '') === 'has_orders') {
            return redirect()->back()->with('error', 'This site has orders and cannot be deleted.');
        }

        SiteImageUpload::deleteListingPublicMedia(
            $deleted['cover'] ?? null,
            $deleted['screenshot'] ?? null,
            $deleted['thumb'] ?? null,
            (int) ($deleted['id'] ?? 0)
        );

        if (! empty($deleted['bulk_id'])) {
            BulkSiteRequest::query()->find((int) $deleted['bulk_id'])
                ?->refreshProgressStatus(keepLegacySheetOpen: true);
        }

        return redirect()->back()->with('success', 'Site deleted successfully!');
    }

    public function archive(int $id)
    {
        if (! Schema::hasColumn('sites', 'archived_at')) {
            return response()->json(['success' => false, 'message' => 'Archive is not available yet.'], 503);
        }

        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if ($site->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Site is already archived.'], 422);
        }

        if (! $site->verified && ! $site->active) {
            return response()->json([
                'success' => false,
                'message' => 'Pending sites cannot be archived. Delete the listing instead.',
            ], 422);
        }

        // Hide via archived_at only — keep active/verified so restore does not force a site live.
        $site->archived_at = now();
        $site->save();

        return response()->json([
            'success' => true,
            'message' => 'Site archived and hidden from the catalog.',
        ]);
    }

    public function unarchive(int $id)
    {
        if (! Schema::hasColumn('sites', 'archived_at')) {
            return response()->json(['success' => false, 'message' => 'Archive is not available yet.'], 503);
        }

        $site = Site::where('publisher_id', auth()->id())->findOrFail($id);

        if (! $site->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Site is not archived.'], 422);
        }

        if ($site->isFromCancelledBulk()) {
            return response()->json([
                'success' => false,
                'message' => 'This listing is from a cancelled bulk request. Add the domain again via My Sites or a new bulk request — restoring it would be deleted on the next relist.',
            ], 422);
        }

        $site->archived_at = null;
        $site->save();
        $site->refresh();

        $message = 'Site restored. It remains inactive until it is active again.';
        if ($site->isCatalogVisible()) {
            $message = 'Site restored to the catalog.';
        } elseif ($site->isFromCancelledBulk()) {
            $message = 'Site restored. It stays off the catalog because its bulk request was cancelled.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Material market/niche edits require admin re-review.
     *
     * @param  list<string>  $newCategories
     */
    private function updateRequiresRereview(Site $site, ?string $newCountry, ?string $newLanguage, array $newCategories): bool
    {
        $oldCountry = strtolower((string) $site->country);
        $oldLanguage = strtolower((string) $site->language);
        if (strtolower((string) $newCountry) !== $oldCountry) {
            return true;
        }
        if (strtolower((string) $newLanguage) !== $oldLanguage) {
            return true;
        }

        $oldCategories = is_array($site->categories) && count($site->categories)
            ? array_values($site->categories)
            : array_values(array_filter(array_map('trim', preg_split('/[|,]/', (string) $site->category) ?: [])));

        $normalize = static function (array $cats): array {
            $out = array_map(static fn ($c) => mb_strtolower(trim((string) $c)), $cats);
            sort($out);

            return array_values($out);
        };

        return $normalize($oldCategories) !== $normalize($newCategories);
    }

    /**
     * HTML checkboxes without value="1" post "on"; Laravel's boolean rule rejects that.
     */
    private function normalizeSensitiveFlags(Request $request): void
    {
        $sensitive = $request->input('sensitive');
        if (! is_array($sensitive)) {
            return;
        }

        $normalized = [];
        foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
            if (! array_key_exists($topic, $sensitive)) {
                continue;
            }

            $value = $sensitive[$topic];
            $truthy = in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true)
                || filter_var($value, FILTER_VALIDATE_BOOLEAN);

            if ($truthy) {
                $normalized[$topic] = true;
            }
        }

        $request->merge(['sensitive' => $normalized]);
    }

    /**
     * @return array<string, float>
     */
    private function collectSensitivePrices(Request $request): array
    {
        $sensitivePrices = [];
        foreach (['crypto', 'trading', 'CBD', 'forex'] as $topic) {
            if (! $request->boolean("sensitive.$topic")) {
                continue;
            }

            $price = $request->input("price_sensitive.$topic");
            if ($price === null || $price === '') {
                continue;
            }

            $sensitivePrices[$topic] = (float) $price;
        }

        return $sensitivePrices;
    }

    /**
     * Only checked durations are stored. Blank price while checked = Free (€0).
     *
     * @return array<string, float> days (string keys) => fee
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
     * Bulk-import websites from CSV for agencies that manage many domains.
     */
    public function bulkImport(Request $request, AgencySiteImportService $imports)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
            'dry_run' => 'nullable|boolean',
        ], [
            'csv_file.required' => 'Please upload a CSV file.',
            'csv_file.mimes' => 'Upload a .csv file.',
        ]);

        $dryRun = $request->boolean('dry_run');

        try {
            $result = $imports->importFromUpload(
                $request->user(),
                $request->file('csv_file'),
                $dryRun
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', UserFacingError::message($e, 'We could not import that CSV. Please check the file and try again.'));
        }

        $created = (int) $result['created'];
        $wouldCreate = (int) $result['would_create'];
        $failed = $result['failed'];
        $processed = (int) $result['processed'];
        $import = $result['import'];

        if ($dryRun) {
            $message = "Dry run complete. Processed {$processed} row(s): {$wouldCreate} would be submitted, ".count($failed).' would fail. Nothing was saved.';

            return back()
                ->with($wouldCreate > 0 && count($failed) === 0 ? 'success' : 'error', $message)
                ->with('bulk_import_created', 0)
                ->with('bulk_import_would_create', $wouldCreate)
                ->with('bulk_import_failures', $failed)
                ->with('bulk_import_dry_run', true);
        }

        // Admin email/bell digest is wired in the ops-signal follow-up (PR2).

        $message = "{$created} site(s) submitted for review.";
        if (count($failed) > 0) {
            $message .= ' '.count($failed).' row(s) failed — see details below.';
        }
        if ($import) {
            $message .= ' Import #'.$import->id.'.';
        }

        return back()
            ->with($created > 0 ? 'success' : 'error', $message)
            ->with('bulk_import_created', $created)
            ->with('bulk_import_failures', $failed)
            ->with('bulk_import_id', $import?->id);
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
     * Parse category names from array, JSON, CSV, or pipe-separated string.
     * Prefer `|` as the delimiter so names containing commas stay intact.
     */
    private function parseCategoryList($value): array
    {
        $parts = [];
        if (is_array($value)) {
            array_walk_recursive($value, function ($item) use (&$parts) {
                if (is_scalar($item) && ! is_bool($item)) {
                    $parts[] = $item;
                }
            });
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
        }

        $categories = [];
        foreach ($parts as $part) {
            $name = trim(scalar_text($part));
            if ($name !== '') {
                $categories[] = $name;
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * Parse country/language codes from array, CSV, or pipe-separated string.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function parseCodeList($value): array
    {
        $parts = [];
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[|,]/', scalar_text($value)) ?: [];
        }

        $codes = [];
        foreach ($parts as $part) {
            $code = strtolower(trim(scalar_text($part)));
            if ($code !== '' && preg_match('/^[a-z]{2}$/', $code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Form/JSON text. Arrays/objects must not reach (string) — PHP 8 TypeError.
     */
    private function scalarString(mixed $value): string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Hostinger often skips migrations. Repair homepage/social columns before
     * listing reads/writes so WHERE/INSERT on them cannot 500.
     */
    private function ensureListingSchema(): void
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();
        } catch (\Throwable $e) {
            Log::warning('Publisher listing schema ensure failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
