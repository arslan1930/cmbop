<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\Site;
use App\Services\CartPricingService;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Http\Request;

class ContentLibraryController extends Controller
{
    public function __construct(
        private ContentUploadService $uploads,
        private CartPricingService $pricing,
    ) {
    }

    public function index(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $status = $request->query('status');

        $query = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->latest('id');

        if ($status && $status !== 'all') {
            $query->where('moderation_status', $status);
        }

        $submissions = $query->paginate(12)->withQueryString();

        $sites = Site::query()
            ->where('active', 1)
            ->orderBy('site_name')
            ->limit(500)
            ->get(['id', 'site_name', 'site_url', 'price', 'sensitive_prices']);

        return view('advertiser.content-library', [
            'submissions' => $submissions,
            'sites' => $sites,
            'uploadCfg' => $cfg,
            'statusFilter' => $status ?: 'all',
        ]);
    }

    public function upload(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $maxKb = (int) ($cfg['max_kilobytes'] ?? 5120);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimes:docx'],
            'title' => ['nullable', 'string', 'max:200'],
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
            'anchor_text' => ['required', 'string', 'max:120'],
            'target_url' => ['required', 'url', 'max:1000'],
            'feature_image_url' => ['nullable', 'url', 'max:1000'],
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
            return back()->with('error', 'This article is not approved for publication yet. Uniqueness must be at least 50% and compliance checks must pass.');
        }

        $anchor = trim(preg_replace('/\s+/', ' ', $data['anchor_text']) ?? '');
        $target = trim($data['target_url']);
        if ($anchor === '' || !str_starts_with(strtolower($target), 'https://')) {
            return back()->with('error', 'Anchor text and a valid HTTPS target URL are required.');
        }

        $submission->update([
            'anchor_text' => $anchor,
            'target_url' => $target,
            'feature_image_url' => $data['feature_image_url'] ?? null,
            'publication_mode' => $data['publication_mode'] ?? 'immediate',
        ]);

        $cart = [];
        foreach ($data['site_ids'] as $siteId) {
            $site = Site::where('id', $siteId)->where('active', 1)->first();
            if (!$site) {
                continue;
            }
            $qty = max(1, (int) ($data['quantities'][$siteId] ?? 1));
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
            ];
        }

        if ($cart === []) {
            return back()->with('error', 'Please select at least one active website.');
        }

        // One approved article can be used across multiple selected websites.
        session()->put('cart', $cart);
        session()->put('checkout_content_submission_id', $submission->id);
        session()->put('checkout_schedule', [
            'mode' => $data['publication_mode'] ?? 'immediate',
            'date' => $data['scheduled_date'] ?? null,
            'time' => $data['scheduled_time'] ?? '09:00',
            'timezone' => $data['timezone'] ?? 'UTC',
        ]);

        return redirect()->route('advertiser.checkout')
            ->with('success', 'Article ready. Review payment details to place your order.');
    }

    protected function serialize(?ContentSubmission $s): ?array
    {
        if (!$s) {
            return null;
        }

        return [
            'id' => $s->id,
            'title' => $s->title,
            'original_filename' => $s->original_filename,
            'word_count' => $s->word_count,
            'uniqueness_score' => $s->uniqueness_score,
            'quality_score' => $s->quality_score,
            'moderation_status' => $s->moderation_status,
            'evaluation_status' => $s->evaluation_status,
            'evaluation_report' => $s->evaluation_report,
            'preview_html' => $s->preview_html,
            'can_order' => $s->canBeOrdered(),
            'download_url' => route('advertiser.content-submissions.download', $s),
            'created_at' => optional($s->created_at)?->toDateTimeString(),
        ];
    }
}
