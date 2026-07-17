<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\Country;
use App\Models\Language;
use App\Models\Site;
use App\Services\CartPricingService;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\ContentUpload\ScheduledOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ContentLibraryController extends Controller
{
    public function __construct(
        private ContentUploadService $uploads,
        private CartPricingService $pricing,
        private ScheduledOrderService $scheduler,
    ) {}

    public function index(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $status = $request->query('status');
        $availability = strtolower(trim((string) $request->query('availability', 'all')));
        $languageFilter = strtolower(trim((string) $request->query('language', '')));
        $countryFilter = strtolower(trim((string) $request->query('country', '')));
        $search = trim((string) $request->query('q', ''));

        if (! in_array($availability, ['all', 'available', 'in_progress', 'published', 'expired', 'archived', 'needs_fix', 'ordered'], true)) {
            $availability = 'all';
        }

        // Backward-compatible alias from earlier UI.
        if ($availability === 'ordered') {
            $availability = 'in_progress';
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

        $sites = Site::query()
            ->where('active', 1)
            ->orderBy('site_name')
            ->limit(500)
            ->get();

        $pricedSites = $sites->map(function (Site $site) {
            $pricing = $this->pricing->priceForAdvertiser($site, null);

            return [
                'id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'link_type' => $site->link_type ?? 'dofollow',
                'countries' => $site->countryCodes(),
                'languages' => $site->languageCodes(),
                'advertiser_price' => $pricing['total'],
                'list_total' => $pricing['list_total'],
                'discount_percent' => $pricing['discount_percent'],
                'discount_labels' => $pricing['discount_labels'],
            ];
        });

        $countries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $languages = Language::marketplace()->orderBy('name')->get(['code', 'name']);

        return view('advertiser.content-library', [
            'submissions' => $submissions,
            'sites' => $pricedSites,
            'uploadCfg' => $cfg,
            'statusFilter' => $status ?: 'all',
            'availabilityFilter' => $availability,
            'languageFilter' => $languageFilter ?: 'all',
            'countryFilter' => $countryFilter ?: 'all',
            'searchQuery' => $search,
            'groupedByLanguage' => $groupedByLanguage,
            'groupedByCountry' => $groupedByCountry,
            'countries' => $countries,
            'languages' => $languages,
            'openUpload' => $request->boolean('upload'),
            'editSubmission' => $this->resolveEditableSubmission($request->query('edit')),
            'libraryFilterBase' => [
                'status' => $status ?: 'all',
                'availability' => $availability,
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
     * Build a cart from an approved article + exactly one website, then go to checkout.
     */
    public function startOrder(Request $request)
    {
        $data = $request->validate([
            'content_submission_id' => ['required', 'integer'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['integer', 'exists:sites,id'],
            'anchor_text' => ['nullable', 'string', 'max:120'],
            'target_url' => ['nullable', 'url', 'max:1000'],
            'feature_image_url' => ['nullable', 'url', 'max:1000'],
            'allow_no_link' => ['nullable', 'boolean'],
            'acknowledge_nofollow' => ['nullable', 'boolean'],
            'publication_mode' => ['nullable', 'in:immediate,scheduled'],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $siteId = $data['site_id'] ?? null;
        if (! $siteId && ! empty($data['site_ids'])) {
            $unique = array_values(array_unique(array_map('intval', $data['site_ids'])));
            if (count($unique) !== 1) {
                return back()->withInput()->with('error', 'Each article can only be ordered on one website.');
            }
            $siteId = $unique[0];
        }

        if (! $siteId) {
            return back()->withInput()->with('error', 'Please select one website for this article.');
        }

        $submission = ContentSubmission::query()
            ->where('id', $data['content_submission_id'])
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereNull('archived_at')
            ->firstOrFail();

        if (! $submission->canBeOrdered()) {
            return back()->with('error', 'Only approved Content Library articles can be ordered. Please edit and resubmit if corrections are needed.');
        }

        $anchor = trim(preg_replace('/\s+/', ' ', (string) ($data['anchor_text'] ?? '')) ?? '');
        $target = trim((string) ($data['target_url'] ?? ''));
        $hasLink = $anchor !== '' || $target !== '';

        if ($hasLink) {
            if ($anchor === '' || $target === '' || ! str_starts_with(strtolower($target), 'https://')) {
                return back()->withInput()->with('error', 'Please provide both anchor text and a valid HTTPS target URL, or leave both empty to continue without a link.');
            }
        } elseif (! $request->boolean('allow_no_link')) {
            return back()->withInput()->with('error', 'No link was provided. Confirm that you want to continue without a link, or add anchor text and URL.');
        }

        $site = Site::query()
            ->where('id', $siteId)
            ->where('active', 1)
            ->first();

        if (! $site) {
            return back()->withInput()->with('error', 'Please select one active website.');
        }

        if (! $submission->matchesSite($site)) {
            return back()->withInput()->with(
                'error',
                'This article is for '
                .strtoupper((string) $submission->country).' / '.strtoupper((string) $submission->language)
                .'. It does not match '.$site->site_name.'. Choose a matching website or upload an article for that market.'
            );
        }

        if (($site->link_type ?? '') === 'nofollow' && $hasLink && ! $request->boolean('acknowledge_nofollow')) {
            return back()->withInput()->with(
                'error',
                'This website publishes nofollow links only. Please acknowledge this to continue.'
            );
        }

        $schedule = $this->scheduler->normalizeSchedule(
            $data['publication_mode'] ?? 'immediate',
            $data['scheduled_date'] ?? null,
            $data['scheduled_time'] ?? null,
            $data['timezone'] ?? null,
        );

        if (! $schedule['ok']) {
            return back()->withInput()->with('error', $schedule['message'] ?? 'Invalid publication schedule.');
        }

        $submission->update([
            'anchor_text' => $hasLink ? $anchor : null,
            'target_url' => $hasLink ? $target : null,
            'feature_image_url' => $data['feature_image_url'] ?? null,
            'publication_mode' => $schedule['mode'],
            'scheduled_publish_at' => $schedule['at'],
            'timezone' => $schedule['timezone'],
        ]);

        $pricing = $this->pricing->priceForAdvertiser($site, null);
        $cart = [[
            'id' => $site->id,
            'name' => $site->site_name,
            'url' => $site->site_url,
            'price' => $pricing['total'],
            'base_price' => $pricing['base'],
            'additional_price' => $pricing['additional'],
            'sensitive_type' => null,
            'quantity' => 1,
            'content_submission_id' => $submission->id,
            'link_type' => $site->link_type,
            'country' => $site->country,
            'language' => $site->language,
        ]];

        session()->put('cart', $cart);
        session()->put('checkout_content_submission_id', $submission->id);
        session()->put('checkout_schedule', [
            'mode' => $schedule['mode'],
            'date' => $data['scheduled_date'] ?? null,
            'time' => $data['scheduled_time'] ?? '09:00',
            'timezone' => $schedule['timezone'],
        ]);

        return redirect()->route('advertiser.checkout')
            ->with('success', 'Approved article selected. Complete payment to place your order.');
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
            'preview_html' => $s->preview_html,
            'anchor_text' => $s->anchor_text,
            'target_url' => $s->target_url,
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
