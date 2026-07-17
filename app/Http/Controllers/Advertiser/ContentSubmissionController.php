<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\ContentUpload\ScheduledOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentSubmissionController extends Controller
{
    public function __construct(
        private ContentUploadService $uploads,
        private ScheduledOrderService $scheduler,
    ) {
    }

    public function config()
    {
        $cfg = $this->uploads->effectiveConfig();

        return response()->json([
            'success' => true,
            'config' => [
                'preferred_extension' => $cfg['preferred_extension'] ?? 'docx',
                'allowed_extensions' => $cfg['allowed_extensions'] ?? ['docx'],
                'max_kilobytes' => (int) ($cfg['max_kilobytes'] ?? 5120),
                'scheduling_enabled' => (bool) ($cfg['scheduling']['enabled'] ?? true),
                'max_schedule_months' => (int) ($cfg['scheduling']['max_months'] ?? 3),
                'max_schedule_at' => $this->scheduler->maxScheduleAt()->toIso8601String(),
                'anchor_max' => (int) ($cfg['anchor_text']['max_length'] ?? 120),
                'help' => $cfg['help'] ?? [],
                'feature_image_extensions' => $cfg['feature_image']['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ],
        ]);
    }

    public function upload(Request $request)
    {
        $cfg = $this->uploads->effectiveConfig();
        $maxKb = (int) ($cfg['max_kilobytes'] ?? 5120);
        $ext = implode(',', $cfg['allowed_extensions'] ?? ['docx']);

        $allowedCountries = array_map('strtolower', config('markets.allowed_country_codes', []));
        $allowedLanguages = array_map('strtolower', config('markets.allowed_language_codes', []));

        $data = $request->validate([
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimes:docx'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'copy_index' => ['nullable', 'integer', 'min:0', 'max:50'],
            'cart_key' => ['nullable', 'string', 'max:64'],
            'replace_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:200'],
            'country' => ['required', 'string', 'max:10', \Illuminate\Validation\Rule::in($allowedCountries)],
            'language' => ['required', 'string', 'max:10', \Illuminate\Validation\Rule::in($allowedLanguages)],
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
            siteId: isset($data['site_id']) ? (int) $data['site_id'] : null,
            copyIndex: (int) ($data['copy_index'] ?? 0),
            cartKey: $data['cart_key'] ?? null,
            replace: $replace,
            title: $data['title'] ?? null,
            country: $data['country'],
            language: $data['language'],
        );

        $submission = $result['submission'] ?? null;

        return response()->json([
            'success' => (bool) $result['ok'],
            'accepted' => (bool) ($result['accepted'] ?? $result['ok']),
            'approved' => (bool) ($result['approved'] ?? false),
            'title' => $result['title'] ?? null,
            'message' => $result['message'] ?? null,
            'report' => $result['report'] ?? null,
            'submission' => $submission ? $this->serializeSubmission($submission) : null,
        ], $result['ok'] ? 200 : 422);
    }

    public function updateDraft(Request $request, ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);

        if ($submission->order_id) {
            return response()->json(['success' => false, 'message' => 'This submission is already linked to an order.'], 422);
        }

        $cfg = $this->uploads->effectiveConfig();
        $anchorMax = (int) ($cfg['anchor_text']['max_length'] ?? 120);
        $imageExt = $cfg['feature_image']['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $data = $request->validate([
            'anchor_text' => ['nullable', 'string', 'max:' . $anchorMax],
            'target_url' => ['nullable', 'string', 'max:1000'],
            'feature_image_url' => ['nullable', 'string', 'max:1000'],
            'publication_mode' => ['nullable', 'in:immediate,scheduled'],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
            'wizard_step' => ['nullable', 'integer', 'min:1', 'max:5'],
            'draft_payload' => ['nullable', 'array'],
        ]);

        if (array_key_exists('anchor_text', $data)) {
            $data['anchor_text'] = trim(preg_replace('/\s+/', ' ', (string) $data['anchor_text']) ?? '');
        }

        if (!empty($data['target_url'])) {
            $url = trim($data['target_url']);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Target URL must be a valid HTTPS URL.',
                ], 422);
            }
            $data['target_url'] = $url;
        }

        if (!empty($data['feature_image_url'])) {
            $img = trim($data['feature_image_url']);
            $path = parse_url($img, PHP_URL_PATH) ?: '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!filter_var($img, FILTER_VALIDATE_URL) || !in_array($ext, $imageExt, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Feature image must be a direct image URL (jpg, png, gif, or webp).',
                ], 422);
            }
            $data['feature_image_url'] = $img;
        } elseif (array_key_exists('feature_image_url', $data)) {
            $data['feature_image_url'] = null;
        }

        if (($data['publication_mode'] ?? null) === 'scheduled' || !empty($data['scheduled_date'])) {
            $schedule = $this->scheduler->normalizeSchedule(
                $data['publication_mode'] ?? 'scheduled',
                $data['scheduled_date'] ?? null,
                $data['scheduled_time'] ?? null,
                $data['timezone'] ?? $submission->timezone,
            );
            if (!$schedule['ok']) {
                return response()->json(['success' => false, 'message' => $schedule['message']], 422);
            }
            $data['publication_mode'] = $schedule['mode'];
            $data['scheduled_publish_at'] = $schedule['at'];
            $data['timezone'] = $schedule['timezone'];
        } elseif (($data['publication_mode'] ?? null) === 'immediate') {
            $data['scheduled_publish_at'] = null;
        }

        unset($data['scheduled_date'], $data['scheduled_time']);
        $submission->fill($data)->save();

        return response()->json([
            'success' => true,
            'submission' => $this->serializeSubmission($submission->fresh()),
        ]);
    }

    public function drafts(Request $request)
    {
        $cartKey = $request->query('cart_key');
        $query = ContentSubmission::query()
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->latest('id');

        if ($cartKey) {
            $query->where('cart_key', $cartKey);
        }

        $items = $query->limit(50)->get()->map(fn (ContentSubmission $s) => $this->serializeSubmission($s));

        return response()->json(['success' => true, 'drafts' => $items]);
    }

    public function preview(ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);

        return response()->json([
            'success' => true,
            'preview_html' => $submission->preview_html,
            'word_count' => $submission->word_count,
            'original_filename' => $submission->original_filename,
            'moderation_status' => $submission->moderation_status,
        ]);
    }

    public function download(ContentSubmission $submission): StreamedResponse
    {
        $this->authorizeDownload($submission);

        $disk = Storage::disk($submission->disk ?: 'local');
        if (!$disk->exists($submission->path)) {
            abort(404, 'File not found');
        }

        return $disk->download(
            $submission->path,
            $submission->original_filename,
            ['Content-Type' => $submission->mime ?: 'application/octet-stream']
        );
    }

    public function destroy(ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);
        if ($submission->order_id) {
            return response()->json(['success' => false, 'message' => 'Cannot delete a submission linked to an order.'], 422);
        }

        $submission->deleteStoredFile();
        $submission->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Advertiser scheduled-order management.
     */
    public function scheduledOrders()
    {
        $orders = Order::query()
            ->with('items')
            ->where('user_id', auth()->id())
            ->where('publication_mode', 'scheduled')
            ->whereNotNull('scheduled_publish_at')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->orderBy('scheduled_publish_at')
            ->get();

        return view('advertiser.scheduled-orders', compact('orders'));
    }

    public function updateSchedule(Request $request, Order $order)
    {
        abort_unless((int) $order->user_id === (int) auth()->id(), 403);
        abort_unless(($order->publication_mode ?? '') === 'scheduled', 422, 'Only scheduled orders can be updated.');

        $action = $request->input('action');

        if ($action === 'publish_now') {
            $order->update([
                'publication_mode' => 'immediate',
                'scheduled_publish_at' => null,
                'schedule_released_at' => now(),
            ]);

            return back()->with('success', 'Order marked for immediate publication.');
        }

        if ($action === 'cancel') {
            $order->update(['status' => 'cancelled']);
            ContentSubmission::query()
                ->where('order_id', $order->id)
                ->get()
                ->each(fn (ContentSubmission $submission) => $submission->releaseFromOrder());

            return back()->with('success', 'Scheduled order cancelled. Your article is available in Content Library again.');
        }

        $data = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $schedule = $this->scheduler->normalizeSchedule(
            'scheduled',
            $data['scheduled_date'],
            $data['scheduled_time'] ?? '09:00',
            $data['timezone'] ?? $order->schedule_timezone,
        );

        if (!$schedule['ok']) {
            return back()->with('error', $schedule['message']);
        }

        $this->scheduler->reschedule($order, $schedule['at'], $schedule['timezone']);

        return back()->with('success', 'Publication schedule updated.');
    }

    protected function authorizeSubmission(ContentSubmission $submission): void
    {
        abort_unless((int) $submission->user_id === (int) auth()->id(), 403);
    }

    protected function authorizeDownload(ContentSubmission $submission): void
    {
        $user = auth()->user();
        if ((int) $submission->user_id === (int) $user->id) {
            return;
        }

        if ($user->hasRole('admin') || $user->hasRole('marketing')) {
            return;
        }

        // Publisher for the placement site (legacy site-bound submissions)
        if ($submission->site_id) {
            $site = Site::find($submission->site_id);
            if ($site && (int) $site->publisher_id === (int) $user->id) {
                return;
            }
        }

        if ($submission->order_item_id && $submission->orderItem?->site?->publisher_id == $user->id) {
            return;
        }

        // Library articles are linked via order_items.content_submission_id (site_id may be null)
        $viaOrderItem = OrderItem::query()
            ->where('content_submission_id', $submission->id)
            ->whereHas('site', fn ($q) => $q->where('publisher_id', $user->id))
            ->exists();

        if ($viaOrderItem) {
            return;
        }

        abort(403);
    }

    protected function serializeSubmission(ContentSubmission $s): array
    {
        return [
            'id' => $s->id,
            'site_id' => $s->site_id,
            'copy_index' => $s->copy_index,
            'cart_key' => $s->cart_key,
            'original_filename' => $s->original_filename,
            'title' => $s->title,
            'country' => $s->country,
            'language' => $s->language,
            'extension' => $s->extension,
            'size_bytes' => $s->size_bytes,
            'word_count' => $s->word_count,
            'uniqueness_score' => $s->uniqueness_score,
            'quality_score' => $s->quality_score,
            'evaluation_status' => $s->evaluation_status,
            'moderation_status' => $s->moderation_status,
            'scan_token' => $s->scan_token,
            'preview_html' => $s->preview_html,
            'anchor_text' => $s->anchor_text,
            'target_url' => $s->target_url,
            'feature_image_url' => $s->feature_image_url,
            'publication_mode' => $s->publication_mode,
            'scheduled_publish_at' => optional($s->scheduled_publish_at)?->toIso8601String(),
            'timezone' => $s->timezone,
            'wizard_step' => $s->wizard_step,
            'ready' => $s->isReadyForCheckout(),
            'needs_correction' => $s->needsCorrection(),
            'download_url' => route('advertiser.content-submissions.download', $s),
        ];
    }
}
