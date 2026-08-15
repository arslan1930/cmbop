<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\ContentSubmission;
use App\Models\OrderItem;
use App\Services\ContentUpload\ArticleDetectedLinks;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ArticlePreviewHtml;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\ContentUpload\ScheduledOrderService;
use App\Services\Orders\OrderRefundService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContentSubmissionController extends Controller
{
    public function __construct(
        private ContentUploadService $uploads,
        private ScheduledOrderService $scheduler,
        private OrderRefundService $refunds,
    ) {}

    public function config()
    {
        $cfg = $this->uploads->effectiveConfig();

        return response()->json([
            'success' => true,
            'config' => [
                'enabled' => $this->uploads->uploadsEnabled(),
                'require_same_language' => $this->uploads->requireSameLanguagePlacement(),
                'preferred_extension' => $cfg['preferred_extension'] ?? 'docx',
                'allowed_extensions' => $cfg['allowed_extensions'] ?? ['docx'],
                'max_kilobytes' => $this->uploads->effectiveMaxKilobytes($cfg),
                'php_max_kilobytes' => $this->uploads->phpUploadMaxKilobytes(),
                'scheduling_enabled' => (bool) ($cfg['scheduling']['enabled'] ?? true),
                'max_schedule_months' => (int) ($cfg['scheduling']['max_months'] ?? 3),
                'max_schedule_at' => $this->scheduler->maxScheduleAt()->toIso8601String(),
                'anchor_max' => (int) ($cfg['anchor_text']['max_length'] ?? 120),
                'help' => $cfg['help'] ?? [],
                'feature_image_extensions' => $cfg['feature_image']['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ],
        ]);
    }

    /**
     * Legacy dual-upload endpoint (site_id / cart_key / copy_index).
     * Prefer advertiser.content-library.upload — this path remains for API compatibility.
     */
    public function upload(Request $request)
    {
        if (! $this->uploads->uploadsEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Content uploads are temporarily turned off. Browse approved articles in Content Library instead.',
            ], 403);
        }

        $cfg = $this->uploads->effectiveConfig();
        $maxKb = $this->uploads->effectiveMaxKilobytes($cfg);
        $ext = implode(',', $cfg['allowed_extensions'] ?? ['docx']);

        $allowedCountries = array_map('strtolower', config('markets.allowed_country_codes', []));
        $allowedLanguages = array_map('strtolower', config('markets.allowed_language_codes', []));

        [$contentLength, $clientBytes] = $this->uploads->uploadByteHints($request);
        if ($message = $this->uploads->rejectedUploadMessage(
            $request->file('file'),
            $cfg,
            $contentLength,
            $clientBytes,
        )) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'extensions:'.($ext ?: 'docx')],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'copy_index' => ['nullable', 'integer', 'min:0', 'max:50'],
            'cart_key' => ['nullable', 'string', 'max:64'],
            'replace_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:200'],
            'country' => ['required', 'string', 'max:10', Rule::in($allowedCountries)],
            'language' => ['required', 'string', 'max:10', Rule::in($allowedLanguages)],
            'image_rights' => ['required', Rule::in(ContentSubmission::imageRightsOptions())],
            'image_rights_source' => [
                'nullable', 'string', 'max:2000',
                'required_if:image_rights,'.ContentSubmission::IMAGE_RIGHTS_LICENSED,
            ],
        ], array_merge($this->uploads->uploadValidationMessages($cfg), [
            'image_rights.required' => 'Tell us where the images in this article came from.',
            'image_rights_source.required_if' => 'Add the source URL or copyright/licence details for the images.',
        ]));

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
                file: $request->file('file'),
                user: auth()->user(),
                siteId: isset($data['site_id']) ? (int) $data['site_id'] : null,
                copyIndex: (int) ($data['copy_index'] ?? 0),
                cartKey: $data['cart_key'] ?? null,
                replace: $replace,
                title: $data['title'] ?? null,
                country: $data['country'],
                language: $data['language'],
                imageRights: $data['image_rights'],
                imageRightsSource: $data['image_rights_source'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::error('Content submission upload failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The article could not be uploaded. Please try again.',
            ], 500);
        }

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

    public function updateContent(Request $request, ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);

        if ($submission->isLockedByPaidOrder()) {
            return response()->json(['success' => false, 'message' => 'This article is already linked to an order.'], 422);
        }

        if ($submission->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Restore this article before editing.'], 422);
        }

        if ($submission->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Expired articles are preview only. The original file cannot be edited.'], 422);
        }

        $data = $request->validate([
            'preview_html' => ['required', 'string', 'max:'.ContentUploadService::PREVIEW_HTML_MAX_CHARS],
            'title' => ['nullable', 'string', 'max:200'],
            'image_rights' => ['nullable', Rule::in(ContentSubmission::imageRightsOptions())],
            'image_rights_source' => [
                'nullable', 'string', 'max:2000',
                'required_if:image_rights,'.ContentSubmission::IMAGE_RIGHTS_LICENSED,
            ],
        ], [
            'preview_html.max' => 'This article is too large to save in the editor. Shorten it and try again.',
            'image_rights_source.required_if' => 'Add the source URL or copyright/licence details for the images.',
        ]);

        if ($blocked = ContentUploadService::articleHtmlBlockedMessage($data['preview_html'])) {
            return response()->json([
                'success' => false,
                'message' => $blocked,
            ], 422);
        }

        // Apply a posted declaration on a replica first. Persisting before the
        // cover check let "this article has no images" overwrite a real claim
        // when the HTML still contained <img>.
        $incoming = $submission->replicate();
        $incoming->preview_html = $data['preview_html'];
        if (! empty($data['image_rights'])) {
            $incoming->image_rights = $data['image_rights'];
            $incoming->image_rights_source = ContentSubmission::imageRightsNeedsSource($data['image_rights'])
                ? ($data['image_rights_source'] ?? null)
                : null;
        }

        if (! $incoming->imageRightsCoverContent()) {
            return response()->json([
                'success' => false,
                'message' => ContentUploadService::imageRightsRequiredMessage(),
                'needs_image_rights' => true,
            ], 422);
        }

        $previousRights = [
            'image_rights' => $submission->image_rights,
            'image_rights_source' => $submission->image_rights_source,
            'image_rights_declared_at' => $submission->image_rights_declared_at,
        ];
        $rightsApplied = false;
        if (! empty($data['image_rights'])) {
            $submission->update([
                'image_rights' => $incoming->image_rights,
                'image_rights_source' => $incoming->image_rights_source,
                'image_rights_declared_at' => now(),
            ]);
            $rightsApplied = true;
        }

        try {
            $result = $this->uploads->updateArticleContent(
                $submission,
                $data['preview_html'],
                $data['title'] ?? null,
            );
        } catch (\Throwable $e) {
            if ($rightsApplied) {
                $submission->update($previousRights);
            }
            Log::error('Content article save failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not save article. Please try again.',
            ], 500);
        }

        if (! $result['ok']) {
            if ($rightsApplied) {
                $submission->update($previousRights);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Could not save article.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'approved' => (bool) ($result['approved'] ?? false),
            'title' => $result['title'] ?? null,
            'message' => $result['message'] ?? 'Article saved.',
            'report' => $result['report'] ?? null,
            'has_link' => (bool) ($result['has_link'] ?? false),
            'links' => $result['links'] ?? [],
            'submission' => $this->serializeSubmission($result['submission']),
        ]);
    }

    public function uploadEditorImage(Request $request)
    {
        if (! $this->uploads->uploadsEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Content uploads are temporarily turned off.',
            ], 403);
        }

        $image = $request->file('image');
        [$contentLength, $clientBytes] = $this->uploads->uploadByteHints($request);
        if ($message = $this->uploads->rejectedImageUploadMessage(
            $image instanceof UploadedFile ? $image : null,
            $contentLength,
            $clientBytes,
        )) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:'.ContentUploadService::IMAGE_MAX_KILOBYTES],
            'content_submission_id' => ['required', 'integer', 'exists:content_submissions,id'],
            'current_image_count' => ['required', 'integer', 'min:0', 'max:500'],
        ]);

        $submission = ContentSubmission::query()->findOrFail((int) $request->input('content_submission_id'));
        $this->authorizeSubmission($submission);

        if ($submission->isLockedByPaidOrder()) {
            return response()->json([
                'success' => false,
                'message' => 'This article is already linked to an order and cannot be edited.',
            ], 422);
        }

        if ($submission->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Restore this article before editing.'], 422);
        }

        if ($submission->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Expired articles are preview only. The original file cannot be edited.',
            ], 422);
        }

        if ((int) $request->input('current_image_count') >= ContentUploadService::IMAGE_MAX_PER_ARTICLE) {
            return response()->json([
                'success' => false,
                'message' => ContentUploadService::tooManyImagesMessage(),
            ], 422);
        }

        $file = $request->file('image');
        $binary = file_get_contents($file->getRealPath());
        if ($binary === false) {
            return response()->json(['success' => false, 'message' => 'Unable to read image.'], 422);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        try {
            $url = $this->uploads->storeArticleImage($binary, $ext, $file->getClientOriginalName(), auth()->user());
        } catch (\Throwable $e) {
            Log::error('Editor image store failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to store image.'], 500);
        }

        if (! $url) {
            return response()->json(['success' => false, 'message' => 'Unable to store image.'], 500);
        }

        return response()->json([
            'success' => true,
            'url' => $url,
        ]);
    }

    public function updateDraft(Request $request, ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);

        if ($submission->isLockedByPaidOrder()) {
            return response()->json(['success' => false, 'message' => 'This submission is already linked to an order.'], 422);
        }

        if ($submission->isArchived()) {
            return response()->json(['success' => false, 'message' => 'Restore this article before editing.'], 422);
        }

        if ($submission->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Expired articles are preview only. The original file cannot be edited.'], 422);
        }

        $cfg = $this->uploads->effectiveConfig();
        $anchorMax = (int) ($cfg['anchor_text']['max_length'] ?? 120);
        $imageExt = $cfg['feature_image']['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'anchor_text' => ['nullable', 'string', 'max:'.$anchorMax],
            'target_url' => ['nullable', 'string', 'max:1000'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*.anchor' => ['nullable', 'string', 'max:'.$anchorMax],
            'links.*.url' => ['nullable', 'string', 'max:1000'],
            'preview_html' => ['nullable', 'string', 'max:'.ContentUploadService::PREVIEW_HTML_MAX_CHARS],
            'feature_image_url' => ['nullable', 'string', 'max:1000'],
            'publication_mode' => ['nullable', 'in:immediate,scheduled'],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'timezone'],
            'wizard_step' => ['nullable', 'integer', 'min:1', 'max:5'],
            'draft_payload' => ['nullable', 'array'],
        ], [
            'preview_html.max' => 'This article is too large to save in the editor. Shorten it and try again.',
        ]);

        $contentChanged = array_key_exists('links', $data)
            || array_key_exists('preview_html', $data)
            || array_key_exists('target_url', $data)
            || array_key_exists('anchor_text', $data);

        $pendingHtml = null;
        if (array_key_exists('preview_html', $data) && is_string($data['preview_html'])) {
            $pendingHtml = $data['preview_html'];
        } elseif (array_key_exists('links', $data)) {
            $pendingHtml = (string) ($submission->preview_html ?? '');
        }

        if (is_string($pendingHtml) && $pendingHtml !== '') {
            if ($blocked = ContentUploadService::articleHtmlBlockedMessage($pendingHtml)) {
                return response()->json([
                    'success' => false,
                    'message' => $blocked,
                ], 422);
            }

            $incoming = $submission->replicate();
            $incoming->preview_html = (new ArticleHtmlSanitizer)->sanitize($pendingHtml);
            if (! $incoming->imageRightsCoverContent()) {
                return response()->json([
                    'success' => false,
                    'message' => ContentUploadService::imageRightsRequiredMessage(),
                    'needs_image_rights' => true,
                ], 422);
            }
        }

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            $data['title'] = $title !== '' ? $title : null;
        }

        if (array_key_exists('anchor_text', $data)) {
            $data['anchor_text'] = trim(preg_replace('/\s+/', ' ', (string) $data['anchor_text']) ?? '');
        }

        if (array_key_exists('target_url', $data)) {
            $url = trim((string) $data['target_url']);
            if ($url === '') {
                $data['target_url'] = null;
            } elseif (! ContentSubmission::isCheckoutReadyTarget($url)) {
                return response()->json([
                    'success' => false,
                    'message' => ContentSubmission::CHECKOUT_LINK_MESSAGE,
                ], 422);
            } else {
                $data['target_url'] = $url;
            }
        }

        if (array_key_exists('links', $data) && is_array($data['links'])) {
            foreach ($data['links'] as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $url = trim((string) ($link['url'] ?? ''));
                $anchor = trim(preg_replace('/\s+/u', ' ', (string) ($link['anchor'] ?? '')) ?? '');
                if ($url === '' && $anchor === '') {
                    continue;
                }
                if ($anchor === '' || ! ContentSubmission::isCheckoutReadyTarget($url)) {
                    return response()->json([
                        'success' => false,
                        'message' => ContentSubmission::CHECKOUT_LINK_MESSAGE,
                    ], 422);
                }
            }
        }

        if (array_key_exists('anchor_text', $data) || array_key_exists('target_url', $data)) {
            $probe = $submission->replicate();
            if (array_key_exists('anchor_text', $data)) {
                $probe->anchor_text = $data['anchor_text'];
            }
            if (array_key_exists('target_url', $data)) {
                $probe->target_url = $data['target_url'];
            }
            if (! $probe->hasCheckoutReadyLinks()) {
                return response()->json([
                    'success' => false,
                    'message' => ContentSubmission::CHECKOUT_LINK_MESSAGE,
                ], 422);
            }
        }

        if (! empty($data['feature_image_url'])) {
            $img = trim($data['feature_image_url']);
            $path = parse_url($img, PHP_URL_PATH) ?: '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! filter_var($img, FILTER_VALIDATE_URL) || ! in_array($ext, $imageExt, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Feature image must be a direct image URL (jpg, png, gif, or webp).',
                ], 422);
            }
            $data['feature_image_url'] = $img;
        } elseif (array_key_exists('feature_image_url', $data)) {
            $data['feature_image_url'] = null;
        }

        if (($data['publication_mode'] ?? null) === 'scheduled' || ! empty($data['scheduled_date'])) {
            $schedule = $this->scheduler->normalizeSchedule(
                $data['publication_mode'] ?? 'scheduled',
                $data['scheduled_date'] ?? null,
                $data['scheduled_time'] ?? null,
                $data['timezone'] ?? $submission->timezone,
            );
            if (! $schedule['ok']) {
                return response()->json(['success' => false, 'message' => $schedule['message']], 422);
            }
            $data['publication_mode'] = $schedule['mode'];
            $data['scheduled_publish_at'] = $schedule['at'];
            $data['timezone'] = $schedule['timezone'];
        } elseif (($data['publication_mode'] ?? null) === 'immediate') {
            $data['scheduled_publish_at'] = null;
        }

        unset($data['scheduled_date'], $data['scheduled_time']);

        // Reject empty bodies before flipping approved → processing. Otherwise a
        // 422 leaves the row stuck in Evaluating and not orderable.
        if (array_key_exists('preview_html', $data) && is_string($data['preview_html'])) {
            $pendingClean = ArticlePreviewHtml::normalize(
                (new ArticleHtmlSanitizer)->sanitize($data['preview_html'])
            );
            if ($pendingClean === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Article content cannot be empty.',
                ], 422);
            }
        }

        if ($contentChanged && $submission->isApproved()) {
            $submission->forceFill([
                'moderation_status' => ContentSubmission::STATUS_PROCESSING,
                'evaluation_status' => 'processing',
            ])->save();
        }

        if (array_key_exists('links', $data)) {
            $links = ArticleDetectedLinks::normalizeList($data['links'] ?? [], $anchorMax);
            if (array_key_exists('preview_html', $data)) {
                $clean = ArticlePreviewHtml::normalize(
                    (new ArticleHtmlSanitizer)->sanitize((string) $data['preview_html'])
                );
                if ($clean === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Article content cannot be empty.',
                    ], 422);
                }
                $html = $clean;
            } else {
                $html = (string) ($submission->preview_html ?? '');
            }
            $submission->syncDetectedLinks($links, $html !== '' ? $html : null);
            unset($data['links'], $data['preview_html']);
            // Primary pair already synced; avoid overwriting with stale single fields if absent.
            unset($data['anchor_text'], $data['target_url']);
        } elseif (array_key_exists('preview_html', $data) && is_string($data['preview_html'])) {
            $sanitizer = new ArticleHtmlSanitizer;
            $clean = ArticlePreviewHtml::normalize($sanitizer->sanitize($data['preview_html']));
            if ($clean === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Article content cannot be empty.',
                ], 422);
            }
            $submission->syncDetectedLinks($sanitizer->extractLinksFromHtml($clean), $clean);
            unset($data['preview_html'], $data['anchor_text'], $data['target_url']);
        }

        $submission->fill($data)->save();

        $eval = null;
        if ($contentChanged) {
            // Keep extracted text in sync so uniqueness / policy scans stay accurate.
            $html = (string) ($submission->fresh()->preview_html ?? '');
            if ($html !== '') {
                $sanitizer = new ArticleHtmlSanitizer;
                $text = $sanitizer->htmlToPlainText($html);
                $submission->forceFill([
                    'extracted_text' => $text,
                    'word_count' => $sanitizer->countWords($text),
                ])->save();
            }

            $eval = $this->uploads->reEvaluateSubmission($submission->fresh());
            $submission = $eval['submission'];
        }

        $payload = [
            'success' => true,
            'submission' => $this->serializeSubmission($submission->fresh()),
        ];

        if ($eval !== null) {
            $payload['approved'] = (bool) ($eval['approved'] ?? false);
            $payload['message'] = $eval['message'] ?? null;
            $payload['report'] = $eval['report'] ?? null;
            $payload['moderation_status'] = $eval['moderation_status'] ?? $submission->moderation_status;
        }

        return response()->json($payload);
    }

    public function drafts(Request $request)
    {
        $cartKey = trim(scalar_text($request->query('cart_key')));
        $query = ContentSubmission::query()
            ->forLibraryList()
            ->where('user_id', auth()->id())
            ->whereNull('order_id')
            ->latest('id');

        if ($cartKey !== '') {
            $query->where('cart_key', $cartKey);
        }

        $items = $query->limit(50)->get()->map(fn (ContentSubmission $s) => $this->serializeSubmission($s));

        return response()->json(['success' => true, 'drafts' => $items]);
    }

    public function preview(ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);

        $html = ArticlePreviewHtml::normalize((string) ($submission->preview_html ?? ''));

        return response()->json([
            'success' => true,
            'id' => (int) $submission->id,
            'title' => $submission->title ?: $submission->original_filename,
            'preview_html' => $html,
            'html' => $html,
            'links' => $submission->detectedLinks(),
            'detected_links' => $submission->detectedLinks(),
            'editable' => $submission->canEditArticle(),
            'word_count' => $submission->word_count,
            'original_filename' => $submission->original_filename,
            'moderation_status' => $submission->moderation_status,
            'country' => $submission->country,
            'language' => $submission->language,
            'can_order' => $submission->canBeOrdered(),
            'ready' => $submission->isReadyForCheckout(),
            'availability' => $submission->libraryAvailability(),
            'anchor_text' => $submission->anchor_text,
            'target_url' => $submission->target_url,
            'feature_image_url' => $submission->feature_image_url
                ? ArticlePreviewHtml::normalizeSrc((string) $submission->feature_image_url)
                : null,
            'uniqueness_score' => $submission->uniqueness_score,
            'quality_score' => $submission->quality_score,
            'has_images' => $submission->hasImages(),
            'needs_image_rights' => $submission->hasImages() && ! $submission->imageRightsCoverContent(),
            'image_rights_covers' => $submission->imageRightsCoverContent(),
            'has_file' => $submission->hasStoredFile(),
            'editor_notice' => $submission->editorNotice(),
            'editor_notice_ok' => false,
        ]);
    }

    public function download(ContentSubmission $submission): StreamedResponse
    {
        $this->authorizeDownload($submission);

        if (! $submission->canDownloadOriginal()) {
            $user = auth()->user();
            $staff = $user && ($user->hasRole('admin') || $user->hasRole('marketing'));
            if (! $staff) {
                abort(404, 'File not found');
            }
        }

        $disk = Storage::disk($submission->disk ?: 'local');
        if (! $submission->path || ! $disk->exists($submission->path)) {
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
        if ($submission->isInUse()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete a submission linked to an order.'], 422);
        }

        $inFlight = OrderItem::query()
            ->where('content_submission_id', $submission->id)
            ->whereHas('order', function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->where('payment_status', '!=', 'refunded');
            })
            ->exists();
        if ($inFlight) {
            return response()->json(['success' => false, 'message' => 'Cannot delete a submission linked to an order.'], 422);
        }

        $submission->deleteStoredFile();
        $submission->delete();

        return response()->json(['success' => true]);
    }

    public function archive(ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);

        if (($submission->isInUse() || $submission->isClaimedByAnotherOrder()) && ! $submission->isPublished()) {
            return response()->json([
                'success' => false,
                'message' => $submission->isClaimedByAnotherOrder() && ! $submission->isInUse()
                    ? ContentSubmission::ACTIVE_ORDER_CLAIM_MESSAGE
                    : 'Articles in progress cannot be archived until the order is completed or cancelled.',
            ], 422);
        }

        $submission->archive();

        return response()->json([
            'success' => true,
            'submission' => $this->serializeSubmission($submission->fresh()),
        ]);
    }

    public function restore(ContentSubmission $submission)
    {
        $this->authorizeSubmission($submission);
        $submission->restoreFromArchive();

        return response()->json([
            'success' => true,
            'submission' => $this->serializeSubmission($submission->fresh()),
        ]);
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

        // Registration attaches both portal roles, so a publisher can hit this
        // advertiser route. Site ownership alone used to skip the paid check
        // that publisher.content.download already enforces.
        if ($this->publisherHasPaidDownloadAccess($submission, (int) $user->id)) {
            return;
        }

        abort(403);
    }

    private function publisherHasPaidDownloadAccess(ContentSubmission $submission, int $publisherId): bool
    {
        return OrderItem::query()
            ->where(function ($q) use ($submission) {
                $q->where('content_submission_id', $submission->id);
                if ($submission->order_item_id) {
                    $q->orWhere('id', $submission->order_item_id);
                }
            })
            ->whereHas('site', fn ($q) => $q->where('publisher_id', $publisherId))
            ->whereHas('order', fn ($q) => $q->where('payment_status', 'paid'))
            ->exists();
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
            'anchor_text' => $s->anchor_text,
            'target_url' => $s->target_url,
            'detected_links' => $s->detectedLinks(),
            'feature_image_url' => $s->feature_image_url
                ? ArticlePreviewHtml::normalizeSrc((string) $s->feature_image_url)
                : null,
            'evaluation_report' => $s->evaluation_report,
            'publication_mode' => $s->publication_mode,
            'scheduled_publish_at' => optional($s->scheduled_publish_at)?->toIso8601String(),
            'timezone' => $s->timezone,
            'wizard_step' => $s->wizard_step,
            'ready' => $s->isReadyForCheckout(),
            'needs_correction' => $s->needsCorrection(),
            'has_images' => $s->hasImages(),
            'needs_image_rights' => $s->hasImages() && ! $s->imageRightsCoverContent(),
            'image_rights_covers' => $s->imageRightsCoverContent(),
            'editor_notice' => $s->editorNotice(),
            'editor_notice_ok' => false,
            'archived' => $s->isArchived(),
            'availability' => $s->libraryAvailability(),
            'live_url' => $s->liveUrl(),
            'can_order' => $s->canBeOrdered(),
            'editable' => $s->canEditArticle(),
            'has_file' => $s->hasStoredFile(),
            'history' => $s->articleHistory(),
            'download_url' => $s->canDownloadOriginal()
                ? route('advertiser.content-submissions.download', $s)
                : null,
            'created_at' => optional($s->created_at)?->toIso8601String(),
            'evaluated_at' => optional($s->evaluated_at)?->toIso8601String(),
            'updated_at' => optional($s->updated_at)?->toIso8601String(),
        ];
    }
}
