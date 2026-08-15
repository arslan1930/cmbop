<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\Country;
use App\Models\Language;
use App\Models\User;
use App\Services\Advertiser\ContentLibrarySearchQuery;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\Marketplace\LanguageCountryMap;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ContentLibraryController extends Controller
{
    public function __construct(
        private ContentUploadService $uploads,
        private LanguageCountryMap $languageCountryMap,
        private CountryLanguagePairs $countryLanguagePairs,
        private ContentLibrarySearchQuery $librarySearch,
    ) {}

    public function index(Request $request)
    {
        return view('advertiser.content-library', $this->libraryPageData($request));
    }

    public function results(Request $request)
    {
        return response()
            ->view('advertiser.partials.content-library-results', $this->libraryPageData($request))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, mixed>
     */
    protected function libraryPageData(Request $request): array
    {
        $cfg = $this->uploads->effectiveConfig();
        $cfg['max_kilobytes'] = $this->uploads->effectiveMaxKilobytes($cfg);
        $cfg['php_max_kilobytes'] = $this->uploads->phpUploadMaxKilobytes();
        // Default to Approved (available) — the All chip was removed from the UI.
        $status = strtolower(trim(scalar_text($request->query('status', 'approved'))));
        $availability = strtolower(trim(scalar_text($request->query('availability', 'available'))));
        $languageFilter = strtolower(trim(scalar_text($request->query('language', ''))));
        $countryFilter = strtolower(trim(scalar_text($request->query('country', ''))));
        $search = trim(scalar_text($request->query('q', '')));

        if (! in_array($status, ['all', 'approved', 'rejected', 'needs_improvement'], true)) {
            $status = 'approved';
        }

        if (! in_array($availability, ['all', 'available', 'evaluating', 'in_progress', 'published', 'completed', 'expired', 'archived', 'needs_fix', 'ordered'], true)) {
            $availability = 'available';
        }

        // Backward-compatible aliases from earlier UI.
        if ($availability === 'ordered') {
            $availability = 'in_progress';
        }
        // UI label is "Completed"; internal availability key remains "published".
        if ($availability === 'completed') {
            $availability = 'published';
        }

        // Legacy status=needs_improvement is dead as an evaluator outcome — fold it
        // into the live “Needs corrections” availability (rejected / error / legacy).
        if ($status === 'needs_improvement') {
            $status = 'all';
            if (! $request->has('availability')) {
                $availability = 'needs_fix';
            }
        }

        // Deep-links like ?status=rejected must not keep the default "available"
        // availability (that forces moderation_status=approved and hides rejects).
        if ($status === 'rejected' && ! $request->has('availability')) {
            $availability = 'all';
        }

        // Approved chip = available for publication only (exclude in-progress + completed).
        if ($status === 'approved' && $availability === 'all') {
            $availability = 'available';
        }

        $query = ContentSubmission::query()
            ->forLibraryList()
            ->with(['order', 'orderItem.site', 'orderItems.site', 'orderItems.order'])
            ->where('user_id', auth()->id())
            ->latest('id');

        // Needs corrections / expired / archived chips must not keep the default
        // status=approved filter (that would hide rejected rows).
        if (in_array($availability, ['needs_fix', 'expired', 'archived', 'in_progress', 'published', 'evaluating'], true)
            && ! $request->has('status')) {
            $status = 'all';
        }

        // Available-for-publication already constrains moderation_status = approved.
        if ($status && $status !== 'all' && ! in_array($availability, ['available', 'evaluating'], true)) {
            $query->where('moderation_status', $status);
        }

        if ($languageFilter !== '' && $languageFilter !== 'all') {
            $query->where('language', $languageFilter);
        }

        if ($countryFilter !== '' && $countryFilter !== 'all') {
            $query->where('country', $countryFilter);
        }

        if ($search !== '') {
            $this->librarySearch->apply($query, $search);
        }

        if ($availability === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');

            if ($availability === 'available') {
                $query->checkoutReady();
            } elseif ($availability === 'evaluating') {
                $query->whereIn('moderation_status', [
                    ContentSubmission::STATUS_PENDING,
                    ContentSubmission::STATUS_PROCESSING,
                ])->whereNull('order_id')
                    ->where(function ($exp) {
                        $exp->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            } elseif ($availability === 'in_progress') {
                $hasPublisherStatus = Schema::hasColumn('order_items', 'publisher_status');
                $query->withOpenOwnerOrder()
                    ->hasCheckoutReadyLinks()
                    ->whereDoesntHave('orderItems', function ($item) use ($hasPublisherStatus) {
                        $item->where(function ($q) use ($hasPublisherStatus) {
                            $q->where(function ($live) {
                                $live->whereNotNull('live_url')->where('live_url', '!=', '');
                            });
                            if ($hasPublisherStatus) {
                                $q->orWhere('publisher_status', 'completed');
                            }
                        });
                    });
            } elseif ($availability === 'expired') {
                $query->whereNull('order_id')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now());
            } elseif ($availability === 'needs_fix') {
                $query->needsLibraryFix();
            } elseif ($availability === 'published') {
                $hasPublisherStatus = Schema::hasColumn('order_items', 'publisher_status');
                $query->whereNotNull('order_id')
                    ->whereHas('orderItems', function ($item) use ($hasPublisherStatus) {
                        $item->where(function ($q) use ($hasPublisherStatus) {
                            $q->where(function ($live) {
                                $live->whereNotNull('live_url')->where('live_url', '!=', '');
                            });
                            if ($hasPublisherStatus) {
                                $q->orWhere('publisher_status', 'completed');
                            }
                        });
                    });
            }
        }

        $page = (int) scalar_text($request->query('page', 1));
        if ($page < 1) {
            $page = 1;
        }
        $submissions = $query->paginate(20, ['*'], 'page', $page)->withQueryString();
        $submissions->setPath(route('advertiser.content-library', absolute: false));

        $baseScope = ContentSubmission::query()->where('user_id', auth()->id());

        $groupedByLanguage = (clone $baseScope)
            ->whereNull('archived_at')
            ->whereNotNull('language')
            ->selectRaw('language, COUNT(*) as total')
            ->groupBy('language')
            ->pluck('total', 'language');

        $groupedByCountry = (clone $baseScope)
            ->whereNull('archived_at')
            ->whereNotNull('country')
            ->selectRaw('country, COUNT(*) as total')
            ->groupBy('country')
            ->pluck('total', 'country');

        // Counts for moderation boxes: respect search / country / language, ignore status.
        $countScope = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNull('archived_at');

        if ($languageFilter !== '' && $languageFilter !== 'all') {
            $countScope->where('language', $languageFilter);
        }

        if ($countryFilter !== '' && $countryFilter !== 'all') {
            $countScope->where('country', $countryFilter);
        }

        if ($search !== '') {
            $this->librarySearch->apply($countScope, $search);
        }

        $statusTotals = (clone $countScope)
            ->selectRaw('moderation_status, COUNT(*) as total')
            ->groupBy('moderation_status')
            ->pluck('total', 'moderation_status');

        $moderationCounts = [
            'all' => (int) $statusTotals->sum(),
            'approved' => (int) ($statusTotals[ContentSubmission::STATUS_APPROVED] ?? 0),
            'rejected' => (int) ($statusTotals[ContentSubmission::STATUS_REJECTED] ?? 0),
            // Single UX bucket — includes rejected, scan errors, and legacy needs_improvement.
            'needs_fix' => (int) ($statusTotals[ContentSubmission::STATUS_NEEDS_IMPROVEMENT] ?? 0)
                + (int) ($statusTotals[ContentSubmission::STATUS_REJECTED] ?? 0)
                + (int) ($statusTotals[ContentSubmission::STATUS_ERROR] ?? 0),
        ];

        $hasPublisherStatus = Schema::hasColumn('order_items', 'publisher_status');
        $availabilityCounts = [
            'all' => (int) (clone $countScope)->count(),
            'available' => (int) (clone $countScope)->checkoutReady()->count(),
            'evaluating' => (int) (clone $countScope)
                ->whereIn('moderation_status', [
                    ContentSubmission::STATUS_PENDING,
                    ContentSubmission::STATUS_PROCESSING,
                ])
                ->whereNull('order_id')
                ->where(function ($exp) {
                    $exp->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count(),
            'in_progress' => (int) (clone $countScope)
                ->withOpenOwnerOrder()
                ->hasCheckoutReadyLinks()
                ->whereDoesntHave('orderItems', function ($item) use ($hasPublisherStatus) {
                    $item->where(function ($q) use ($hasPublisherStatus) {
                        $q->where(function ($live) {
                            $live->whereNotNull('live_url')->where('live_url', '!=', '');
                        });
                        if ($hasPublisherStatus) {
                            $q->orWhere('publisher_status', 'completed');
                        }
                    });
                })
                ->count(),
            'completed' => (int) (clone $countScope)
                ->whereNotNull('order_id')
                ->whereHas('orderItems', function ($item) use ($hasPublisherStatus) {
                    $item->where(function ($q) use ($hasPublisherStatus) {
                        $q->where(function ($live) {
                            $live->whereNotNull('live_url')->where('live_url', '!=', '');
                        });
                        if ($hasPublisherStatus) {
                            $q->orWhere('publisher_status', 'completed');
                        }
                    });
                })
                ->count(),
            'expired' => (int) (clone $countScope)
                ->whereNull('order_id')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->count(),
            'needs_fix' => (int) (clone $countScope)->needsLibraryFix()->count(),
        ];

        $archivedCountScope = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNotNull('archived_at');
        if ($languageFilter !== '' && $languageFilter !== 'all') {
            $archivedCountScope->where('language', $languageFilter);
        }
        if ($countryFilter !== '' && $countryFilter !== 'all') {
            $archivedCountScope->where('country', $countryFilter);
        }
        if ($search !== '') {
            $this->librarySearch->apply($archivedCountScope, $search);
        }
        $availabilityCounts['archived'] = (int) $archivedCountScope->count();

        // UI filter key: "completed" covers internal "published".
        $availabilityUi = $availability === 'published' ? 'completed' : $availability;

        $nearExpiryDays = 7;
        $nearExpiryCount = (int) (clone $countScope)
            ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
            ->whereNull('order_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($nearExpiryDays))
            ->count();

        $countries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $languages = Language::marketplace()->orderBy('name')->get(['code', 'name']);
        $languageCountryMap = $this->languageCountryMap->map();
        $countryLanguageMap = $this->countryLanguagePairs->mapWithNames();
        $editSubmission = $this->resolveEditableSubmission(scalar_text($request->query('edit')));

        return [
            'submissions' => $submissions,
            'uploadCfg' => $cfg,
            'uploadsEnabled' => $this->uploads->uploadsEnabled(),
            'statusFilter' => $status,
            'availabilityFilter' => $availabilityUi,
            'languageFilter' => $languageFilter ?: 'all',
            'countryFilter' => $countryFilter ?: 'all',
            'searchQuery' => $search,
            'groupedByLanguage' => $groupedByLanguage,
            'groupedByCountry' => $groupedByCountry,
            'moderationCounts' => $moderationCounts,
            'availabilityCounts' => $availabilityCounts,
            'nearExpiryCount' => $nearExpiryCount,
            'nearExpiryDays' => $nearExpiryDays,
            'retentionMonths' => (int) ($cfg['retention_months'] ?? 6),
            'countries' => $countries,
            'languages' => $languages,
            'languageCountryMap' => $languageCountryMap,
            'countryLanguageMap' => $countryLanguageMap,
            'openUpload' => filter_var(scalar_text($request->query('upload')), FILTER_VALIDATE_BOOLEAN)
                && $this->uploads->uploadsEnabled(),
            'editSubmission' => $editSubmission,
            'editSubmissionBoot' => $this->serializeEditBoot($editSubmission),
            'libraryFilterBase' => [
                'status' => $status,
                'availability' => $availabilityUi,
                'language' => $languageFilter ?: 'all',
                'country' => $countryFilter ?: 'all',
                'q' => $search,
            ],
        ];
    }

    public function upload(Request $request)
    {
        if (! $this->uploads->uploadsEnabled()) {
            return response()->json([
                'success' => false,
                'title' => 'Uploads disabled',
                'message' => 'Content uploads are temporarily turned off. You can still browse and order approved articles in your library.',
            ], 403);
        }

        $cfg = $this->uploads->effectiveConfig();
        $maxKb = $this->uploads->effectiveMaxKilobytes($cfg);
        $allowedCountries = array_map('strtolower', config('markets.allowed_country_codes', []));
        $allowedLanguages = array_map('strtolower', config('markets.allowed_language_codes', []));

        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }
        $uploadedFile = $request->file('file');
        $chunk = $this->uploads->receiveArticleChunk($request, $user);
        if (is_array($chunk)) {
            if (! ($chunk['ok'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'title' => 'Upload failed',
                    'message' => $chunk['message'] ?? 'The article could not be uploaded. Please try again.',
                ], 422);
            }
            if (! ($chunk['complete'] ?? false)) {
                return response()->json([
                    'success' => true,
                    'chunk_received' => true,
                    'received' => $chunk['received'] ?? 0,
                    'total' => $chunk['total'] ?? 0,
                ]);
            }
            $uploadedFile = $chunk['file'];
            $request->files->set('file', $uploadedFile);
        }

        $displayName = $this->uploads->safeDocxFilename(scalar_text($request->input('original_filename')));
        if (
            $uploadedFile instanceof UploadedFile
            && $uploadedFile->isValid()
            && $displayName !== ''
            && $displayName !== 'article.docx'
        ) {
            $realPath = $uploadedFile->getRealPath();
            if (is_string($realPath) && $realPath !== '') {
                $uploadedFile = new UploadedFile(
                    $realPath,
                    $displayName,
                    $uploadedFile->getMimeType() ?: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    $uploadedFile->getError(),
                    true
                );
                $request->files->set('file', $uploadedFile);
            }
        }

        $title = mb_substr(trim(scalar_text($request->input('title'))), 0, 200);
        $request->merge([
            'title' => $title !== '' ? $title : null,
            'country' => strtolower(trim(scalar_text($request->input('country')))),
            'language' => strtolower(trim(scalar_text($request->input('language')))),
            'replace_id' => scalar_text($request->input('replace_id')),
            'image_rights' => scalar_text($request->input('image_rights')) ?: null,
            'image_rights_source' => scalar_text($request->input('image_rights_source')) ?: null,
        ]);

        [$contentLength, $clientBytes] = $this->uploads->uploadByteHints($request);
        if ($message = $this->uploads->rejectedUploadMessage(
            $uploadedFile,
            $cfg,
            $contentLength,
            $clientBytes,
        )) {
            return response()->json([
                'success' => false,
                'title' => 'Upload failed',
                'message' => $message,
            ], 422);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'extensions:docx'],
            'title' => ['nullable', 'string', 'max:200'],
            'country' => ['required', 'string', 'max:10', Rule::in($allowedCountries)],
            'language' => ['required', 'string', 'max:10', Rule::in($allowedLanguages)],
            'replace_id' => ['nullable', 'integer'],
            'image_rights' => ['nullable', Rule::in(ContentSubmission::imageRightsOptions())],
            'image_rights_source' => [
                'nullable', 'string', 'max:2000',
                'required_if:image_rights,'.ContentSubmission::IMAGE_RIGHTS_LICENSED,
            ],
        ], array_merge($this->uploads->uploadValidationMessages($cfg), [
            'image_rights_source.required_if' => 'Add the source URL or copyright/licence details for the images.',
        ]));

        if (! $this->countryLanguagePairs->isAllowedPair($data['country'], $data['language'])) {
            return response()->json([
                'success' => false,
                'title' => 'Market required',
                'message' => 'That language is not allowed for the selected country. Pick country first, then a paired language.',
            ], 422);
        }

        $replace = null;
        if (! empty($data['replace_id'])) {
            $replace = ContentSubmission::query()
                ->where('id', $data['replace_id'])
                ->where('user_id', auth()->id())
                ->first();
            if ($replace?->isExpired()) {
                return response()->json([
                    'success' => false,
                    'title' => 'Expired',
                    'message' => 'Expired articles are preview only. Upload a new article instead of replacing this one.',
                ], 422);
            }
            if ($replace?->isArchived()) {
                return response()->json([
                    'success' => false,
                    'title' => 'Archived',
                    'message' => 'Restore this article before replacing it.',
                ], 422);
            }
            if ($replace && ! $replace->canEditArticle()) {
                return response()->json([
                    'success' => false,
                    'title' => 'In use',
                    'message' => 'This article is already linked to an order.',
                ], 422);
            }
        }

        try {
            $result = $this->uploads->uploadAndProcess(
                file: $uploadedFile instanceof UploadedFile
                    ? $uploadedFile
                    : $request->file('file'),
                user: auth()->user(),
                siteId: null,
                copyIndex: 0,
                cartKey: null,
                replace: $replace,
                title: $data['title'] ?? null,
                country: $data['country'],
                language: $data['language'],
                imageRights: $data['image_rights'] ?? null,
                imageRightsSource: $data['image_rights_source'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::error('Content library upload failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'title' => 'Upload failed',
                'message' => 'The article could not be uploaded. Please try again.',
            ], 500);
        }

        if (! $result['ok']) {
            return response()->json([
                'success' => false,
                'title' => $result['title'] ?? 'Upload failed',
                'message' => $result['message'] ?? 'Unable to upload document.',
            ], 422);
        }

        try {
            $submission = $this->serialize($result['submission']);
        } catch (\Throwable $e) {
            Log::error('Content library upload serialize failed', [
                'submission_id' => $result['submission']->id ?? null,
                'error' => $e->getMessage(),
            ]);
            $fallback = $result['submission'] ?? null;
            $submission = [
                'id' => $fallback->id ?? null,
                'title' => $fallback->title ?? $result['title'],
                'moderation_status' => $fallback->moderation_status ?? null,
                'can_order' => false,
                'ready' => false,
                'availability' => $fallback instanceof ContentSubmission
                    ? $fallback->libraryAvailability()
                    : 'needs_fix',
                'editable' => true,
                'needs_image_rights' => (bool) ($fallback?->hasImages() && ! $fallback->imageRightsCoverContent()),
                'editor_notice' => $result['message'] ?? null,
                'editor_notice_ok' => false,
            ];
        }

        return response()->json([
            'success' => true,
            'accepted' => true,
            'approved' => (bool) ($result['approved'] ?? false),
            'title' => $result['title'],
            'message' => $result['message'],
            'report' => $result['report'] ?? null,
            'has_link' => (bool) ($result['has_link'] ?? false),
            'links' => $result['links'] ?? [],
            'submission' => $submission,
        ]);
    }

    /**
     * Start ordering an approved article via the Catalog (no language pre-filter).
     * Multiple websites are allowed; each website needs its own approved article.
     */
    public function orderInCatalog(Request $request, ?ContentSubmission $submission = null)
    {
        if (! $submission) {
            $id = (int) scalar_text($request->input('content_submission_id', 0));
            $submission = ContentSubmission::query()
                ->where('id', $id)
                ->where('user_id', auth()->id())
                ->firstOrFail();
        }

        abort_unless((int) $submission->user_id === (int) auth()->id(), 403);

        if (! $submission->canBeOrdered()) {
            $message = $submission->isExpired()
                ? 'Expired articles are preview only and cannot be ordered.'
                : ($submission->hasImages() && ! $submission->imageRightsCoverContent()
                    ? ContentUploadService::imageRightsRequiredMessage()
                    : 'Only approved Content Library articles can be ordered. Please edit and resubmit if corrections are needed.');

            return redirect()
                ->route('advertiser.content-library')
                ->with('error', $message);
        }

        if (! $submission->isReadyForCheckout()) {
            return redirect()
                ->route('advertiser.content-library')
                ->with('error', $submission->libraryFixSummary() ?: ContentSubmission::CHECKOUT_LINK_MESSAGE);
        }

        // Keep existing cart sites and any publication date already chosen at checkout.
        session()->put('checkout_content_submission_id', $submission->id);
        session()->put('ordering_from_library', true);

        $title = $submission->title ?: $submission->original_filename;

        return redirect()->route('advertiser.catalog', [
            'content_submission_id' => $submission->id,
        ])->with(
            'success',
            'Ordering “'.$title.'”. Browse any publishers — this article can be assigned to any site. Each website still needs its own approved article.'
        );
    }

    protected function resolveEditableSubmission(mixed $id): ?ContentSubmission
    {
        $id = (int) scalar_text($id);
        if ($id < 1) {
            return null;
        }

        $submission = ContentSubmission::query()
            ->forLibraryList()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->whereNull('archived_at')
            ->needsLibraryFix()
            ->first();

        return $submission && $submission->canEditArticle() ? $submission : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeEditBoot(?ContentSubmission $s): ?array
    {
        if (! $s) {
            return null;
        }

        return [
            'id' => $s->id,
            'title' => $s->title,
            'country' => $s->country,
            'language' => $s->language,
            'word_count' => $s->word_count,
            'moderation_status' => $s->moderation_status,
            'can_order' => $s->canBeOrdered(),
            'ready' => $s->isReadyForCheckout(),
            'editable' => $s->canEditArticle(),
            'has_file' => $s->hasStoredFile(),
            'detected_links' => $s->detectedLinks(),
            'has_images' => $s->hasImages(),
            'needs_image_rights' => $s->hasImages() && ! $s->imageRightsCoverContent(),
            'image_rights_covers' => $s->imageRightsCoverContent(),
            'editor_notice' => $s->editorNotice(),
            'editor_notice_ok' => false,
        ];
    }

    protected function serialize(?ContentSubmission $s): ?array
    {
        if (! $s) {
            return null;
        }

        return [
            'id' => $s->id,
            'title' => $s->title,
            'country' => $s->country,
            'language' => $s->language,
            'original_filename' => $s->original_filename,
            'word_count' => $s->word_count,
            'uniqueness_score' => $s->uniqueness_score,
            'quality_score' => $s->quality_score,
            'moderation_status' => $s->moderation_status,
            'evaluation_status' => $s->evaluation_status,
            'evaluation_report' => $s->evaluation_report,
            'anchor_text' => $s->anchor_text,
            'target_url' => $s->target_url,
            'detected_links' => $s->detectedLinks(),
            'has_link' => $s->hasLink(),
            'ready' => $s->isReadyForCheckout(),
            'can_order' => $s->canBeOrdered(),
            'editable' => $s->canEditArticle(),
            'has_file' => $s->hasStoredFile(),
            'needs_correction' => $s->needsCorrection(),
            'has_images' => $s->hasImages(),
            'needs_image_rights' => $s->hasImages() && ! $s->imageRightsCoverContent(),
            'image_rights_covers' => $s->imageRightsCoverContent(),
            'editor_notice' => $s->editorNotice(),
            'editor_notice_ok' => false,
            'archived' => $s->isArchived(),
            'availability' => $s->libraryAvailability(),
            'live_url' => $s->liveUrl(),
            'download_url' => $s->canDownloadOriginal()
                ? route('advertiser.content-submissions.download', $s)
                : null,
            'created_at' => optional($s->created_at)?->toDateTimeString(),
        ];
    }
}
