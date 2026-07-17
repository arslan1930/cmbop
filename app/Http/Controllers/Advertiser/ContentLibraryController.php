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
use Illuminate\Validation\Rule;

class ContentLibraryController extends Controller
{
    public function __construct(
        private ContentUploadService $uploads,
        private CartPricingService $pricing,
        private ScheduledOrderService $scheduler,
    ) {
    }

    public function index(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $status = $request->query('status');
        $languageFilter = strtolower(trim((string) $request->query('language', '')));

        $query = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->latest('id');

        if ($status && $status !== 'all') {
            $query->where('moderation_status', $status);
        }

        if ($languageFilter !== '' && $languageFilter !== 'all') {
            $query->where('language', $languageFilter);
        }

        $submissions = $query->paginate(12)->withQueryString();

        $groupedByLanguage = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNotNull('language')
            ->selectRaw('language, COUNT(*) as total')
            ->groupBy('language')
            ->pluck('total', 'language');

        $sites = Site::query()
            ->where('active', 1)
            ->orderBy('site_name')
            ->limit(500)
            ->get(['id', 'site_name', 'site_url', 'price', 'sensitive_prices', 'link_type', 'country', 'countries', 'language', 'languages']);

        $countries = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        $languages = Language::marketplace()->orderBy('name')->get(['code', 'name']);

        return view('advertiser.content-library', [
            'submissions' => $submissions,
            'sites' => $sites,
            'uploadCfg' => $cfg,
            'statusFilter' => $status ?: 'all',
            'languageFilter' => $languageFilter ?: 'all',
            'groupedByLanguage' => $groupedByLanguage,
            'countries' => $countries,
            'languages' => $languages,
            'openUpload' => $request->boolean('upload'),
            'editSubmission' => $this->resolveEditableSubmission($request->query('edit')),
        ]);
    }

    public function upload(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $maxKb = (int) ($cfg['max_kilobytes'] ?? 5120);
        $allowedCountries = array_map('strtolower', config('markets.allowed_country_codes', []));
        $allowedLanguages = array_map('strtolower', config('markets.allowed_language_codes', []));

        $data = $request->validate([
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimes:docx'],
            'title' => ['nullable', 'string', 'max:200'],
            'country' => ['required', 'string', 'max:10', Rule::in($allowedCountries)],
            'language' => ['required', 'string', 'max:10', Rule::in($allowedLanguages)],
            'replace_id' => ['nullable', 'integer'],
        ]);

        $replace = null;
        if (!empty($data['replace_id'])) {
            $replace = ContentSubmission::query()
                ->where('id', $data['replace_id'])
                ->where('user_id', auth()->id())
                ->whereNull('order_id')
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

        if (!$result['ok']) {
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
     * Build a cart from an approved article + selected websites, then go to checkout.
     */
    public function startOrder(Request $request)
    {
        $data = $request->validate([
            'content_submission_id' => ['required', 'integer'],
            'site_ids' => ['required', 'array', 'min:1'],
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
            'quantities' => ['nullable', 'array'],
        ]);

        $submission = ContentSubmission::query()
            ->where('id', $data['content_submission_id'])
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->firstOrFail();

        if (!$submission->canBeOrdered()) {
            return back()->with('error', 'Only approved Content Library articles can be ordered. Please edit and resubmit if corrections are needed.');
        }

        $anchor = trim(preg_replace('/\s+/', ' ', (string) ($data['anchor_text'] ?? '')) ?? '');
        $target = trim((string) ($data['target_url'] ?? ''));
        $hasLink = $anchor !== '' || $target !== '';

        if ($hasLink) {
            if ($anchor === '' || $target === '' || !str_starts_with(strtolower($target), 'https://')) {
                return back()->withInput()->with('error', 'Please provide both anchor text and a valid HTTPS target URL, or leave both empty to continue without a link.');
            }
        } elseif (!$request->boolean('allow_no_link')) {
            return back()->withInput()->with('error', 'No link was provided. Confirm that you want to continue without a link, or add anchor text and URL.');
        }

        $selectedSites = Site::query()
            ->whereIn('id', $data['site_ids'])
            ->where('active', 1)
            ->get();

        $mismatched = $selectedSites->reject(fn (Site $site) => $submission->matchesSite($site));
        if ($mismatched->isNotEmpty()) {
            $names = $mismatched->pluck('site_name')->take(3)->implode(', ');

            return back()->withInput()->with(
                'error',
                'This article is for '
                . strtoupper((string) $submission->country) . ' / ' . strtoupper((string) $submission->language)
                . '. It does not match: ' . $names . '. Choose matching websites or upload an article for that market.'
            );
        }

        $nofollowSites = $selectedSites->where('link_type', 'nofollow')->values();
        if ($nofollowSites->isNotEmpty() && $hasLink && !$request->boolean('acknowledge_nofollow')) {
            return back()->withInput()->with(
                'error',
                'One or more selected websites publish nofollow links only. Please acknowledge this to continue.'
            );
        }

        $schedule = $this->scheduler->normalizeSchedule(
            $data['publication_mode'] ?? 'immediate',
            $data['scheduled_date'] ?? null,
            $data['scheduled_time'] ?? null,
            $data['timezone'] ?? null,
        );

        if (!$schedule['ok']) {
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

        $cart = [];
        foreach ($selectedSites as $site) {
            $qty = max(1, (int) ($data['quantities'][$site->id] ?? 1));
            $pricing = $this->pricing->priceForAdvertiser($site, null);
            $cart[] = [
                'id' => $site->id,
                'name' => $site->site_name,
                'url' => $site->site_url,
                'price' => $pricing['total'],
                'base_price' => $pricing['base'],
                'additional_price' => $pricing['additional'],
                'sensitive_type' => null,
                'quantity' => $qty,
                'content_submission_id' => $submission->id,
                'link_type' => $site->link_type,
                'country' => $site->country,
                'language' => $site->language,
            ];
        }

        if ($cart === []) {
            return back()->with('error', 'Please select at least one active website.');
        }

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
        if (!$id) {
            return null;
        }

        return ContentSubmission::query()
            ->where('id', (int) $id)
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->whereIn('moderation_status', [
                ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                ContentSubmission::STATUS_REJECTED,
                ContentSubmission::STATUS_ERROR,
            ])
            ->first();
    }

    protected function serialize(?ContentSubmission $s): ?array
    {
        if (!$s) {
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
            'download_url' => route('advertiser.content-submissions.download', $s),
            'created_at' => optional($s->created_at)?->toDateTimeString(),
        ];
    }
}
