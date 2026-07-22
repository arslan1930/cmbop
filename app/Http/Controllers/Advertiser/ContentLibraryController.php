<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\Country;
use App\Models\Language;
use App\Services\ContentUpload\ArticlePreviewHtml;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\Marketplace\LanguageCountryMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ContentLibraryController extends Controller
{
    public function __construct(
        private ContentUploadService $uploads,
        private LanguageCountryMap $languageCountryMap,
    ) {}

    public function index(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $status = strtolower(trim((string) $request->query('status', 'all')));
        $availability = strtolower(trim((string) $request->query('availability', 'all')));
        $languageFilter = strtolower(trim((string) $request->query('language', '')));
        $countryFilter = strtolower(trim((string) $request->query('country', '')));
        $search = trim((string) $request->query('q', ''));

        if (! in_array($status, ['all', 'approved', 'rejected', 'needs_improvement'], true)) {
            $status = 'all';
        }

        if (! in_array($availability, ['all', 'available', 'in_progress', 'published', 'completed', 'expired', 'archived', 'needs_fix', 'ordered'], true)) {
            $availability = 'all';
        }

        // Backward-compatible aliases from earlier UI.
        if ($availability === 'ordered') {
            $availability = 'in_progress';
        }
        // UI label is "Completed"; internal availability key remains "published".
        if ($availability === 'completed') {
            $availability = 'published';
        }

        $query = ContentSubmission::query()
            ->with(['orderItem.site', 'orderItems.site'])
            ->where('user_id', auth()->id())
            ->latest('id');

        if ($status && $status !== 'all') {
            $query->where('moderation_status', $status);
        }

        if ($languageFilter !== '' && $languageFilter !== 'all') {
            $query->where('language', $languageFilter);
        }

        if ($countryFilter !== '' && $countryFilter !== 'all') {
            $query->where('country', $countryFilter);
        }

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('original_filename', 'like', $like);
            });
        }

        $minUniqueness = (int) config('content_upload.evaluation.min_uniqueness', 50);

        if ($availability === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');

            if ($availability === 'available') {
                $query->whereNull('order_id')
                    ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
                    ->where('uniqueness_score', '>=', $minUniqueness)
                    ->whereNotNull('path')
                    ->whereNotNull('country')
                    ->where('country', '!=', '')
                    ->whereNotNull('language')
                    ->where('language', '!=', '')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            } elseif ($availability === 'in_progress') {
                $hasPublisherStatus = Schema::hasColumn('order_items', 'publisher_status');
                $query->whereNotNull('order_id')
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
                    ->where('expires_at', '<', now());
            } elseif ($availability === 'needs_fix') {
                $query->whereIn('moderation_status', [
                    ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                    ContentSubmission::STATUS_REJECTED,
                    ContentSubmission::STATUS_ERROR,
                ]);
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

        $submissions = $query->paginate(20)->withQueryString();

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
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $countScope->where(function ($q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('original_filename', 'like', $like);
            });
        }

        $statusTotals = (clone $countScope)
            ->selectRaw('moderation_status, COUNT(*) as total')
            ->groupBy('moderation_status')
            ->pluck('total', 'moderation_status');

        $moderationCounts = [
            'all' => (int) $statusTotals->sum(),
            'approved' => (int) ($statusTotals[ContentSubmission::STATUS_APPROVED] ?? 0),
            'rejected' => (int) ($statusTotals[ContentSubmission::STATUS_REJECTED] ?? 0),
            'needs_improvement' => (int) ($statusTotals[ContentSubmission::STATUS_NEEDS_IMPROVEMENT] ?? 0),
        ];

        $hasPublisherStatus = Schema::hasColumn('order_items', 'publisher_status');
        $availabilityCounts = [
            'all' => (int) (clone $countScope)->count(),
            'available' => (int) (clone $countScope)
                ->whereNull('order_id')
                ->where('moderation_status', ContentSubmission::STATUS_APPROVED)
                ->where('uniqueness_score', '>=', $minUniqueness)
                ->whereNotNull('path')
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->whereNotNull('language')
                ->where('language', '!=', '')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count(),
            'in_progress' => (int) (clone $countScope)
                ->whereNotNull('order_id')
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
        ];

        // UI filter key: "completed" covers internal "published".
        $availabilityUi = $availability === 'published' ? 'completed' : $availability;

        $countries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $languages = Language::marketplace()->orderBy('name')->get(['code', 'name']);
        $languageCountryMap = $this->languageCountryMap->map();

        return view('advertiser.content-library', [
            'submissions' => $submissions,
            'uploadCfg' => $cfg,
            'statusFilter' => $status,
            'availabilityFilter' => $availabilityUi,
            'languageFilter' => $languageFilter ?: 'all',
            'countryFilter' => $countryFilter ?: 'all',
            'searchQuery' => $search,
            'groupedByLanguage' => $groupedByLanguage,
            'groupedByCountry' => $groupedByCountry,
            'moderationCounts' => $moderationCounts,
            'availabilityCounts' => $availabilityCounts,
            'countries' => $countries,
            'languages' => $languages,
            'languageCountryMap' => $languageCountryMap,
            'openUpload' => $request->boolean('upload'),
            'editSubmission' => $this->resolveEditableSubmission($request->query('edit')),
            'libraryFilterBase' => [
                'status' => $status,
                'availability' => $availabilityUi,
                'language' => $languageFilter ?: 'all',
                'country' => $countryFilter ?: 'all',
                'q' => $search,
            ],
        ]);
    }

    public function upload(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $maxKb = (int) ($cfg['max_kilobytes'] ?? 5120);
        $allowedCountries = array_map('strtolower', config('markets.allowed_country_codes', []));
        $allowedLanguages = array_map('strtolower', config('markets.allowed_language_codes', []));

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:docx'],
            'title' => ['nullable', 'string', 'max:200'],
            'country' => ['required', 'string', 'max:10', Rule::in($allowedCountries)],
            'language' => ['required', 'string', 'max:10', Rule::in($allowedLanguages)],
            'replace_id' => ['nullable', 'integer'],
        ]);

        $replace = null;
        if (! empty($data['replace_id'])) {
            $replace = ContentSubmission::query()
                ->where('id', $data['replace_id'])
                ->where('user_id', auth()->id())
                ->whereNull('order_id')
                ->whereNull('archived_at')
                ->first();
        }

        $result = $this->uploads->uploadAndProcess(
            file: $request->file('file'),
            user: auth()->user(),
            siteId: null,
            copyIndex: 0,
            cartKey: null,
            replace: $replace,
            title: $data['title'] ?? null,
            country: $data['country'],
            language: $data['language'],
        );

        if (! $result['ok']) {
            return response()->json([
                'success' => false,
                'title' => $result['title'] ?? 'Upload failed',
                'message' => $result['message'] ?? 'Unable to upload document.',
            ], 422);
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
            'submission' => $this->serialize($result['submission']),
        ]);
    }

    /**
     * Start ordering an approved article via the Catalog (no language pre-filter).
     * Multiple websites are allowed; each website needs its own approved article.
     */
    public function orderInCatalog(Request $request, ?ContentSubmission $submission = null)
    {
        if (! $submission) {
            $id = (int) $request->input('content_submission_id', 0);
            $submission = ContentSubmission::query()
                ->where('id', $id)
                ->where('user_id', auth()->id())
                ->firstOrFail();
        }

        abort_unless((int) $submission->user_id === (int) auth()->id(), 403);

        if (! $submission->canBeOrdered()) {
            return redirect()
                ->route('advertiser.content-library')
                ->with('error', 'Only approved Content Library articles can be ordered. Please edit and resubmit if corrections are needed.');
        }

        // Keep existing cart sites; this article attaches when assigned in cart/checkout.
        session()->forget(['checkout_schedule']);
        session()->put('checkout_content_submission_id', $submission->id);
        session()->put('ordering_from_library', true);

        $title = $submission->title ?: $submission->original_filename;

        return redirect()->route('advertiser.catalog', [
            'content_submission_id' => $submission->id,
            'filters_open' => 1,
        ])->with(
            'success',
            'Ordering “'.$title.'”. Browse any publishers — this article can be assigned to any site. Each website still needs its own approved article.'
        );
    }

    protected function resolveEditableSubmission(mixed $id): ?ContentSubmission
    {
        if (! $id) {
            return null;
        }

        return ContentSubmission::query()
            ->where('id', (int) $id)
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereNull('archived_at')
            ->whereIn('moderation_status', [
                ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                ContentSubmission::STATUS_REJECTED,
                ContentSubmission::STATUS_ERROR,
            ])
            ->first();
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
            'preview_html' => ArticlePreviewHtml::normalize((string) ($s->preview_html ?? '')),
            'anchor_text' => $s->anchor_text,
            'target_url' => $s->target_url,
            'detected_links' => $s->detectedLinks(),
            'has_link' => $s->hasLink(),
            'can_order' => $s->canBeOrdered(),
            'needs_correction' => $s->needsCorrection(),
            'archived' => $s->isArchived(),
            'availability' => $s->libraryAvailability(),
            'live_url' => $s->liveUrl(),
            'download_url' => route('advertiser.content-submissions.download', $s),
            'created_at' => optional($s->created_at)?->toDateTimeString(),
        ];
    }
}
