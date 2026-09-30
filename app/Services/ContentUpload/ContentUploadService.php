<?php

namespace App\Services\ContentUpload;

use App\Mail\ContentEvaluationResult;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Support\PhpIniSize;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class ContentUploadService
{
    /** Hard article .docx cap (10 MB). Admin cannot raise this. */
    public const MAX_KILOBYTES = 10240;

    /** Per-image cap for the article editor. Not counted against the .docx cap. */
    public const IMAGE_MAX_KILOBYTES = 5120;

    /** Max img tags per article (Word extract + editor inserts). */
    public const IMAGE_MAX_PER_ARTICLE = 10;

    /**
     * Slice size the browser should send. Hostinger LiteSpeed often still
     * drops a 5 MB body at the default 2M pipe even when .user.ini says 64M.
     * 512 KB leaves room for multipart headers without approaching 2M.
     */
    public const CHUNK_KILOBYTES = 512;

    /** Accept older clients that still slice at 1.5 MB. */
    public const MAX_RECEIVE_CHUNK_KILOBYTES = 1536;

    /** 10 MB / 512 KB = 20 slices; keep headroom for retries and smaller slices. */
    public const MAX_CHUNKS = 32;

    /** Editor / draft HTML cap. Quill inflates markup; 500k rejected real 10 MB articles. */
    public const PREVIEW_HTML_MAX_CHARS = 8000000;

    public function __construct(
        private DocumentTextExtractor $extractor,
        private ArticleEvaluationService $evaluation,
    ) {}

    public function effectiveConfig(): array
    {
        $base = config('content_upload', []);
        $override = ContentModerationSetting::getValue('upload_config', []) ?: [];

        if (! is_array($override) || $override === []) {
            return $this->withClampedUploadLimit($base);
        }

        return $this->withClampedUploadLimit(array_replace_recursive($base, $override));
    }

    /**
     * Kill-switch for new uploads (library + legacy content-submissions upload).
     * Browse / download / archive of existing articles stay available when off.
     */
    public function uploadsEnabled(): bool
    {
        $cfg = $this->effectiveConfig();

        return (bool) ($cfg['enabled'] ?? true);
    }

    /**
     * When true, cart/checkout reject article↔site language mismatches.
     * When false (default), mismatches are soft-preferred with a cart warning.
     */
    public function requireSameLanguagePlacement(): bool
    {
        $cfg = $this->effectiveConfig();

        return (bool) ($cfg['placement']['require_same_language'] ?? false);
    }

    public function schedulingEnabled(): bool
    {
        $cfg = $this->effectiveConfig();

        return (bool) ($cfg['scheduling']['enabled'] ?? true);
    }

    /**
     * Accept a .docx upload, extract text, evaluate uniqueness/quality/compliance.
     * The file is always stored when valid; ordering requires approval.
     *
     * @return array{ok:bool, accepted:bool, approved:bool, submission?:ContentSubmission, message?:string, title?:string, report?:array}
     */
    public function uploadAndProcess(
        UploadedFile $file,
        User $user,
        ?int $siteId = null,
        int $copyIndex = 0,
        ?string $cartKey = null,
        ?ContentSubmission $replace = null,
        ?string $title = null,
        ?string $country = null,
        ?string $language = null,
        ?string $imageRights = null,
        ?string $imageRightsSource = null,
    ): array {
        $cfg = $this->effectiveConfig();
        $cfg['max_kilobytes'] = $this->effectiveMaxKilobytes($cfg);
        $validationError = $this->validateUpload($file, $cfg);
        if ($validationError !== null) {
            return ['ok' => false, 'accepted' => false, 'approved' => false, 'title' => 'Upload rejected', 'message' => $validationError];
        }

        $marketError = $this->validateMarket($country, $language, $replace);
        if ($marketError !== null) {
            return ['ok' => false, 'accepted' => false, 'approved' => false, 'title' => 'Market required', 'message' => $marketError];
        }

        if ($replace?->isUnusedExpired()) {
            return [
                'ok' => false,
                'accepted' => false,
                'approved' => false,
                'title' => 'Expired',
                'message' => 'Expired articles are preview only. Upload a new article instead of replacing this one.',
            ];
        }

        $country = strtolower(trim((string) ($country ?: $replace?->country)));
        $language = strtolower(trim((string) ($language ?: $replace?->language)));

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $disk = (string) ($cfg['disk'] ?? 'local');
        $dir = trim((string) ($cfg['directory'] ?? 'content-uploads'), '/');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs($dir.'/'.$user->id, $filename, $disk);

        if (! $path) {
            return ['ok' => false, 'accepted' => false, 'approved' => false, 'title' => 'Upload failed', 'message' => 'Unable to store the file. Please try again.'];
        }

        $absolute = Storage::disk($disk)->path($path);
        $extracted = $this->extractor->extract(
            $absolute,
            $extension,
            function (string $binary, string $ext, string $originalName) use ($user): ?string {
                return $this->storeArticleImage($binary, $ext, $originalName, $user);
            }
        );

        if (! $extracted['ok']) {
            Storage::disk($disk)->delete($path);

            return [
                'ok' => false,
                'accepted' => false,
                'approved' => false,
                'title' => 'Document processing failed',
                'message' => $extracted['error_message'] ?? 'Unable to process this document.',
                'report' => ['error_code' => $extracted['error_code']],
            ];
        }

        $retentionMonths = max(1, (int) ($cfg['retention_months'] ?? 6));
        $docTitle = $title
            ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)
            ?: 'Untitled article';

        $links = $extracted['links'] ?? [];
        $firstLink = $links[0] ?? null;

        $previewHtml = ArticlePreviewHtml::normalize((string) ($extracted['html'] ?? ''));
        [$storedText, $previewHtml] = $this->fitStoredArticleColumns(
            (string) ($extracted['text'] ?? ''),
            $previewHtml
        );

        $attrs = [
            'site_id' => $siteId,
            'copy_index' => $copyIndex,
            'cart_key' => $cartKey,
            'original_filename' => $file->getClientOriginalName(),
            'title' => $docTitle,
            'country' => $country,
            'language' => $language,
            'disk' => $disk,
            'path' => $path,
            'mime' => $file->getMimeType(),
            'extension' => $extension,
            'size_bytes' => (int) $file->getSize(),
            'extracted_text' => $storedText,
            'preview_html' => $previewHtml,
            'word_count' => $extracted['word_count'],
            'moderation_status' => ContentSubmission::STATUS_PROCESSING,
            'evaluation_status' => 'processing',
            'expires_at' => now()->addMonths($retentionMonths),
        ];

        // Image rights are declared per upload, so a resubmit re-attests rather
        // than inheriting whatever the previous version claimed.
        if ($imageRights !== null) {
            $attrs['image_rights'] = $imageRights;
            $attrs['image_rights_source'] = ContentSubmission::imageRightsNeedsSource($imageRights)
                ? $imageRightsSource
                : null;
            $attrs['image_rights_declared_at'] = now();
        }

        // Auto-fill anchor + URL from the article when the advertiser did not set them.
        if ($firstLink) {
            $attrs['anchor_text'] = $firstLink['anchor'];
            $attrs['target_url'] = $firstLink['url'];
        } elseif ($replace) {
            // Resubmit without a detected link clears previous autofill so the order form can warn.
            $attrs['anchor_text'] = null;
            $attrs['target_url'] = null;
        }

        $draftPayload = is_array($replace?->draft_payload) ? $replace->draft_payload : [];
        $draftPayload['detected_links'] = ArticleDetectedLinks::normalizeList($links);
        $attrs['draft_payload'] = $draftPayload;

        if ($replace) {
            $attrs['feature_image_url'] = null;
            $attrs['publication_mode'] = ContentSubmission::MODE_IMMEDIATE;
            $attrs['scheduled_publish_at'] = null;
            $attrs['timezone'] = $cfg['scheduling']['default_timezone'] ?? 'UTC';
            $replace->deleteStoredFile();
            $submission = $replace;
            $submission->fill($attrs)->save();
        } else {
            $submission = ContentSubmission::create(array_merge($attrs, [
                'user_id' => $user->id,
                'publication_mode' => ContentSubmission::MODE_IMMEDIATE,
                'timezone' => $cfg['scheduling']['default_timezone'] ?? 'UTC',
                'wizard_step' => 1,
            ]));
        }

        $result = $this->evaluation->evaluate($submission->fresh(), $user);

        $previewHtml = ArticlePreviewHtml::normalize((string) ($submission->preview_html ?? ''));
        if (! empty($result['highlighted_html'])) {
            $previewHtml = ArticlePreviewHtml::normalize((string) $result['highlighted_html']);
        }
        [, $previewHtml] = $this->fitStoredArticleColumns(
            (string) $submission->extracted_text,
            $previewHtml
        );

        $report = $this->evaluationReportWithNotifyStatus($submission, $result);

        $submission->update([
            'preview_html' => $previewHtml,
            'moderation_status' => $result['moderation_status'],
            'evaluation_status' => $result['evaluation_status'],
            'uniqueness_score' => $result['uniqueness_score'],
            'quality_score' => $result['quality_score'],
            'evaluation_report' => $report,
            'evaluated_at' => now(),
            'moderation_log_id' => $result['log']?->id,
            'scan_token' => $result['log']?->scan_token,
            'wizard_step' => $result['approved'] ? max(2, (int) $submission->wizard_step) : 1,
        ]);

        $fresh = $submission->fresh();
        $this->reconcileImageRightsAfterParse($fresh, $imageRights, $imageRightsSource);
        $fresh = $fresh->fresh();
        $result = $this->presentEvaluationResult($fresh, $result);
        $this->persistPresentedEvaluationReport($fresh, $result);
        $this->syncWizardStepWithPresentedResult($fresh, $result);
        $fresh = $fresh->fresh();
        $this->notifyAdvertiserOfEvaluation($fresh, $result);

        // Upload was accepted into the library; approval is separate.
        return [
            'ok' => true,
            'accepted' => true,
            'approved' => (bool) $result['approved'],
            'submission' => $fresh,
            'title' => $result['title'],
            'message' => $result['message'],
            'report' => $result['report'] ?? $report,
            'links' => $links,
            'has_link' => $firstLink !== null,
        ];
    }

    /**
     * Rights are optional on upload. After parse: no images → record "none";
     * images without own/licensed → clear so the editor asks.
     */
    protected function reconcileImageRightsAfterParse(
        ContentSubmission $submission,
        ?string $claimed,
        ?string $source,
    ): void {
        if ($submission->hasImages()) {
            if (in_array($claimed, [ContentSubmission::IMAGE_RIGHTS_OWN, ContentSubmission::IMAGE_RIGHTS_LICENSED], true)) {
                return;
            }

            $submission->update([
                'image_rights' => null,
                'image_rights_source' => null,
                'image_rights_declared_at' => null,
            ]);

            return;
        }

        $rights = $claimed ?: ContentSubmission::IMAGE_RIGHTS_NONE;
        $submission->update([
            'image_rights' => $rights,
            'image_rights_source' => ContentSubmission::imageRightsNeedsSource($rights) ? $source : null,
            'image_rights_declared_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function notifyAdvertiserOfEvaluation(ContentSubmission $submission, array $result): void
    {
        try {
            $user = $submission->user;
            if (! $user) {
                return;
            }

            $result = $this->presentEvaluationResult($submission, $result);
            $status = (string) ($result['notify_status'] ?? $result['moderation_status'] ?? $submission->moderation_status);

            // Same outcome (including "confirm rights") must not resend. A later
            // approval after rights are declared uses a different notify_status.
            if ($submission->approval_notified_at
                && ($submission->evaluation_report['notified_status'] ?? null) === $status) {
                return;
            }

            if ($user->email) {
                $mailable = new ContentEvaluationResult($submission, $result);
                $mailable->notificationType = 'content_evaluation_result';
                $mailable->dedupeKey = 'content_eval:'.$submission->id.':'.$status;
                Mail::to($user->email)->send($mailable);
            }

            $report = $submission->evaluation_report ?? [];
            if (! is_array($report)) {
                $report = [];
            }
            $report['notified_status'] = $status;
            $submission->update([
                'approval_notified_at' => now(),
                'evaluation_report' => $report,
            ]);

            try {
                $this->notifyInApp($user, $submission, $result);
            } catch (\Throwable $e) {
                Log::warning('Content evaluation in-app notification failed', [
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Content evaluation notification failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Policy can approve while image rights are still missing. Do not tell
     * the advertiser they can order until the article is actually orderable.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function presentEvaluationResult(ContentSubmission $submission, array $result): array
    {
        $moderationStatus = (string) ($result['moderation_status'] ?? $submission->moderation_status);
        $policyApproved = (bool) ($result['approved'] ?? false)
            || $moderationStatus === ContentSubmission::STATUS_APPROVED;

        if ($policyApproved
            && $submission->hasImages()
            && ! $submission->imageRightsCoverContent()) {
            $result['approved'] = false;
            $result['title'] = 'Confirm image rights';
            $result['message'] = $submission->editorNotice();
            $result['notify_status'] = 'needs_image_rights';
            if (! isset($result['report']) || ! is_array($result['report'])) {
                $result['report'] = [];
            }
            $result['report']['summary'] = $result['message'];

            return $result;
        }

        if ($policyApproved && ! $submission->hasCheckoutReadyLinks()) {
            $result['approved'] = false;
            $result['title'] = 'Finish your link';
            $result['message'] = ContentSubmission::CHECKOUT_LINK_MESSAGE;
            $result['notify_status'] = 'needs_checkout_link';
            if (! isset($result['report']) || ! is_array($result['report'])) {
                $result['report'] = [];
            }
            $result['report']['summary'] = $result['message'];

            return $result;
        }

        $result['notify_status'] = ! empty($result['approved'])
            ? 'approved'
            : $moderationStatus;

        if (! empty($result['approved']) && ! $submission->isReadyForCheckout()) {
            $params = $submission->staffApprovalLibraryParams();
            if ($params !== []) {
                $result['action_url'] = route('advertiser.content-library', $params, false);
            }
            if ($submission->isUsableAfterStaffApproval() || $submission->activeClaimOrderId()) {
                $result['approved_leftover'] = true;
                if (trim((string) ($result['message'] ?? '')) === '') {
                    $result['message'] = $submission->editorNotice()
                        ?: ContentSubmission::ACTIVE_ORDER_CLAIM_MESSAGE;
                }
            }
        }

        return $result;
    }

    /**
     * Keep the last mailed outcome across re-evaluations so the same
     * notify_status is not sent twice.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function evaluationReportWithNotifyStatus(ContentSubmission $submission, array $result): array
    {
        $report = $result['report'] ?? [];
        if (! is_array($report)) {
            $report = [];
        }
        if (! empty($result['highlighted_html'])) {
            $report['highlighted_preview'] = true;
        }

        $previous = $submission->evaluation_report;
        if (is_array($previous) && isset($previous['notified_status'])) {
            $report['notified_status'] = $previous['notified_status'];
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function persistPresentedEvaluationReport(ContentSubmission $submission, array $result): void
    {
        if (! in_array($result['notify_status'] ?? null, ['needs_image_rights', 'needs_checkout_link'], true)) {
            return;
        }

        $summary = trim((string) ($result['message'] ?? ''));
        if ($summary === '') {
            return;
        }

        $report = is_array($submission->evaluation_report) ? $submission->evaluation_report : [];
        if (($report['summary'] ?? null) === $summary) {
            return;
        }

        $report['summary'] = $summary;
        $submission->update(['evaluation_report' => $report]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function syncWizardStepWithPresentedResult(ContentSubmission $submission, array $result): void
    {
        $step = ! empty($result['approved']) ? max(2, (int) $submission->wizard_step) : 1;
        if ((int) $submission->wizard_step === $step) {
            return;
        }

        $submission->update(['wizard_step' => $step]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function notifyInApp(User $user, ContentSubmission $submission, array $result): void
    {
        app(InAppNotificationService::class)->notifyContentEvaluation($user, $submission, $result);
    }

    /**
     * Store an inline article image for preview/editor and return a public URL.
     * May compress to WebP; the original Word file is never rewritten.
     */
    public function storeArticleImage(string $binary, string $ext, string $originalName, User $user): ?string
    {
        try {
            [$binary, $ext] = app(ArticlePreviewImage::class)->compressForPreview($binary, $ext);
        } catch (\Throwable $e) {
            Log::notice('Article preview image compress skipped', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);
        }

        $dir = 'content-articles/'.$user->id;
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = $dir.'/'.$filename;

        if (! Storage::disk('public')->put($path, $binary)) {
            return null;
        }

        // Prefer rooted /storage/... paths so previews work across hosts / APP_URL changes.
        return '/storage/'.$path;
    }

    /**
     * Save edited article HTML (docs editor), refresh text/links, and re-evaluate.
     *
     * @return array{ok:bool, approved:bool, submission?:ContentSubmission, message?:string, title?:string, report?:array, links?:array, has_link?:bool}
     */
    public function updateArticleContent(ContentSubmission $submission, string $html, ?string $title = null): array
    {
        if ($submission->isLockedByPaidOrder()) {
            return ['ok' => false, 'approved' => false, 'message' => 'This article is already linked to an order and cannot be edited.'];
        }

        if ($submission->isUnusedExpired()) {
            return ['ok' => false, 'approved' => false, 'message' => 'Expired articles are preview only. The original file cannot be edited.'];
        }

        if (str_contains(strtolower($html), 'data:image')) {
            return [
                'ok' => false,
                'approved' => false,
                'message' => self::embeddedImageSaveMessage(),
            ];
        }

        $sanitizer = new ArticleHtmlSanitizer;
        $clean = ArticlePreviewHtml::normalize($sanitizer->sanitize($html));
        if ($clean === '') {
            return ['ok' => false, 'approved' => false, 'message' => 'Article content cannot be empty.'];
        }

        $text = $sanitizer->htmlToPlainText($clean);
        $wordCount = $sanitizer->countWords($text);
        if ($wordCount < 1 && ! str_contains($clean, '<img')) {
            return ['ok' => false, 'approved' => false, 'message' => 'Article content cannot be empty.'];
        }

        if ($sanitizer->countImages($clean) > self::IMAGE_MAX_PER_ARTICLE) {
            return [
                'ok' => false,
                'approved' => false,
                'message' => self::tooManyImagesMessage(),
            ];
        }

        $links = $sanitizer->extractLinksFromHtml($clean);
        $firstLink = $links[0] ?? null;

        [$text, $clean] = $this->fitStoredArticleColumns($text, $clean);

        $attrs = [
            'preview_html' => $clean,
            'extracted_text' => $text,
            'word_count' => $wordCount,
            'moderation_status' => ContentSubmission::STATUS_PROCESSING,
            'evaluation_status' => 'processing',
        ];

        if ($title !== null) {
            $title = trim($title);
            $attrs['title'] = $title !== '' ? $title : $submission->title;
        }

        if ($firstLink) {
            $attrs['anchor_text'] = $firstLink['anchor'];
            $attrs['target_url'] = $firstLink['url'];
        } else {
            $attrs['anchor_text'] = null;
            $attrs['target_url'] = null;
        }

        $payload = $submission->draft_payload ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }
        $payload['detected_links'] = ArticleDetectedLinks::normalizeList($links);
        $history = is_array($payload['content_history'] ?? null) ? $payload['content_history'] : [];
        $history[] = [
            'at' => now()->toIso8601String(),
            'action' => 'edited',
            'word_count' => $attrs['word_count'],
            'has_images' => str_contains($clean, '<img'),
            'link_count' => count($links),
        ];
        $payload['content_history'] = array_slice($history, -20);
        $attrs['draft_payload'] = $payload;

        $submission->fill($attrs)->save();

        $result = $this->reEvaluateSubmission($submission->fresh());

        $fresh = $result['submission'] ?? $submission->fresh();
        $links = $sanitizer->extractLinksFromHtml((string) ($fresh->preview_html ?? ''));
        $firstLink = $links[0] ?? null;

        return [
            'ok' => true,
            'approved' => (bool) ($result['approved'] ?? false),
            'submission' => $fresh,
            'title' => $result['title'] ?? null,
            'message' => $result['message'] ?? 'Article saved.',
            'report' => $result['report'] ?? [],
            'links' => $links,
            'has_link' => $firstLink !== null,
        ];
    }

    /**
     * Re-run uniqueness + policy scan and persist moderation fields (same as post-upload).
     *
     * @return array{approved:bool, submission:ContentSubmission, title:?string, message:string, report:array, moderation_status:string}
     */
    public function reEvaluateSubmission(ContentSubmission $submission, bool $notify = true): array
    {
        $submission->update([
            'moderation_status' => ContentSubmission::STATUS_PROCESSING,
            'evaluation_status' => 'processing',
        ]);

        $user = $submission->user;
        $result = $this->evaluation->evaluate($submission->fresh(), $user);

        $previewHtml = ArticlePreviewHtml::normalize((string) ($submission->preview_html ?? ''));
        if (! empty($result['highlighted_html'])) {
            $previewHtml = ArticlePreviewHtml::normalize((string) $result['highlighted_html']);
        }
        [, $previewHtml] = $this->fitStoredArticleColumns(
            (string) $submission->extracted_text,
            $previewHtml
        );

        $report = $this->evaluationReportWithNotifyStatus($submission, $result);

        $submission->update([
            'preview_html' => $previewHtml,
            'moderation_status' => $result['moderation_status'],
            'evaluation_status' => $result['evaluation_status'],
            'uniqueness_score' => $result['uniqueness_score'],
            'quality_score' => $result['quality_score'],
            'evaluation_report' => $report,
            'evaluated_at' => now(),
            'moderation_log_id' => $result['log']?->id,
            'scan_token' => $result['log']?->scan_token,
            'wizard_step' => $result['approved'] ? max(2, (int) $submission->wizard_step) : 1,
        ]);

        $fresh = $submission->fresh();
        $result = $this->presentEvaluationResult($fresh, $result);
        $this->persistPresentedEvaluationReport($fresh, $result);
        $this->syncWizardStepWithPresentedResult($fresh, $result);
        $fresh = $fresh->fresh();
        if ($notify) {
            $this->notifyAdvertiserOfEvaluation($fresh, $result);
        }

        return [
            'approved' => (bool) $result['approved'],
            'submission' => $fresh,
            'title' => $result['title'] ?? null,
            'message' => (string) ($result['message'] ?? ''),
            'report' => $result['report'] ?? $report,
            'moderation_status' => (string) ($result['moderation_status'] ?? $fresh->moderation_status),
            'notify_status' => (string) ($result['notify_status'] ?? $result['moderation_status'] ?? $fresh->moderation_status),
        ];
    }

    public function validateMarket(?string $country, ?string $language, ?ContentSubmission $replace = null): ?string
    {
        $country = strtolower(trim((string) ($country ?: $replace?->country)));
        $language = strtolower(trim((string) ($language ?: $replace?->language)));

        if ($country === '' || $language === '') {
            return 'Please select the market country first, then a paired language.';
        }

        $allowedCountries = array_map('strtolower', config('markets.allowed_country_codes', []));
        $allowedLanguages = array_map('strtolower', config('markets.allowed_language_codes', []));

        if ($allowedLanguages !== [] && ! in_array($language, $allowedLanguages, true)) {
            return 'Selected language is not available in the marketplace.';
        }

        if ($allowedCountries !== [] && ! in_array($country, $allowedCountries, true)) {
            return 'Selected country is not available in the marketplace.';
        }

        $map = app(CountryLanguagePairs::class);
        if (! $map->isAllowedPair($country, $language)) {
            return 'That language is not allowed for the selected country. Pick country first, then a paired language (e.g. Germany → German; UAE → Arabic or English).';
        }

        return null;
    }

    /**
     * Hard article cap: 10 MB. Files at or under this size are allowed;
     * anything larger is rejected. Admin / env cannot raise this.
     */
    public function effectiveMaxKilobytes(?array $cfg = null): int
    {
        return self::MAX_KILOBYTES;
    }

    public function clampMaxKilobytes(int $kilobytes): int
    {
        return self::MAX_KILOBYTES;
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    private function withClampedUploadLimit(array $cfg): array
    {
        $cfg['max_kilobytes'] = self::MAX_KILOBYTES;
        $help = is_array($cfg['help'] ?? null) ? $cfg['help'] : [];
        $help['before_upload'] = 'Supported format: Microsoft Word (.docx) only. Maximum size: 10 MB. Unused articles are kept for 6 months; after that the original file is removed and a preview stays in Expired.';
        $cfg['help'] = $help;

        return $cfg;
    }

    public function phpUploadMaxKilobytes(): int
    {
        return PhpIniSize::uploadMaxKilobytes(self::MAX_KILOBYTES);
    }

    public function phpLimitBlocksArticleCap(?array $cfg = null): bool
    {
        return $this->phpUploadMaxKilobytes() < $this->effectiveMaxKilobytes($cfg);
    }

    /**
     * PHP rejected the file before Laravel saw the bytes (UPLOAD_ERR_INI_SIZE /
     * FORM_SIZE / empty body after post_max_size).
     *
     * Never blame the 10 MB article cap unless the browser file is actually
     * over it. A dropped 5.4 MB body often arrives with no size hint; saying
     * “under 10 MB” is wrong and hides the real host/pipe failure.
     */
    public function phpSizeRejectedMessage(?array $cfg = null, ?int $clientFileBytes = null): string
    {
        $cap = self::MAX_KILOBYTES * 1024;
        if ($clientFileBytes !== null && $clientFileBytes > $cap) {
            $appMb = PhpIniSize::megabytesLabel($this->effectiveMaxKilobytes($cfg));

            return 'That file is over the '.$appMb.' MB limit.';
        }

        return 'The article could not be uploaded. Please try again.';
    }

    public function phpImageRejectedMessage(?int $clientFileBytes = null): string
    {
        if ($clientFileBytes !== null && $clientFileBytes > self::IMAGE_MAX_KILOBYTES * 1024) {
            return 'The image could not be uploaded. Use a JPG, PNG, GIF, or WebP under 5 MB and try again.';
        }

        return 'The image could not be uploaded. Use a JPG, PNG, GIF, or WebP and try again.';
    }

    public static function tooManyImagesMessage(): string
    {
        return 'This article can have up to '.self::IMAGE_MAX_PER_ARTICLE.' images. Remove one before adding another.';
    }

    public static function embeddedImageSaveMessage(): string
    {
        return 'Embedded images cannot be saved. Insert each picture with the image button (JPG, PNG, GIF, or WebP under 5 MB).';
    }

    public static function imageRightsRequiredMessage(): string
    {
        return 'This article now contains images. Confirm you own them, or add the source URL or copyright details, before saving.';
    }

    /**
     * Reasons not to persist article HTML (embedded data images or over the cap).
     */
    public static function articleHtmlBlockedMessage(string $html): ?string
    {
        if (str_contains(strtolower($html), 'data:image')) {
            return self::embeddedImageSaveMessage();
        }

        $sanitizer = new ArticleHtmlSanitizer;
        $clean = ArticlePreviewHtml::normalize($sanitizer->sanitize($html));
        if ($sanitizer->countImages($clean) > self::IMAGE_MAX_PER_ARTICLE) {
            return self::tooManyImagesMessage();
        }

        return null;
    }

    /**
     * Keep extracted text and preview HTML inside MariaDB's max_allowed_packet.
     *
     * A long .docx is stored twice (plain text and HTML). On a 1 MB packet that
     * insert fails, and the library shows a generic upload error.
     *
     * @return array{0:string,1:string}
     */
    public function fitStoredArticleColumns(string $text, string $html): array
    {
        $budget = $this->storedArticleByteBudget();
        if (strlen($text) + strlen($html) <= $budget) {
            return [$text, $html];
        }

        $textCap = min(strlen($text), 100000);
        if ($textCap + strlen($html) > $budget) {
            $html = $this->truncatePreviewHtml($html, max(0, $budget - $textCap));
        }
        $textCap = min($textCap, max(0, $budget - strlen($html)));
        if (strlen($text) > $textCap) {
            $text = mb_strcut($text, 0, $textCap, 'UTF-8');
        }

        return [$text, $html];
    }

    /**
     * Bytes available for extracted_text + preview_html in one INSERT/UPDATE.
     */
    private function storedArticleByteBudget(): int
    {
        $packet = 1048576;
        try {
            $row = DB::selectOne('select @@session.max_allowed_packet as packet');
            $value = (int) ($row->packet ?? 0);
            if ($value > 0) {
                $packet = $value;
            }
        } catch (\Throwable) {
            // Use the 1 MB fallback when the server will not report the setting.
        }

        return max(160000, $packet - 262144);
    }

    private function truncatePreviewHtml(string $html, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($html) <= $maxBytes) {
            return $html;
        }

        $slice = mb_strcut($html, 0, $maxBytes, 'UTF-8');
        $close = strrpos($slice, '</p>');
        if ($close !== false) {
            return substr($slice, 0, $close + 4);
        }

        return $slice;
    }

    /**
     * @return array<string, string>
     */
    public function uploadValidationMessages(?array $cfg = null): array
    {
        $mb = PhpIniSize::megabytesLabel($this->effectiveMaxKilobytes($cfg));

        return [
            'file.uploaded' => 'The article could not be uploaded. Please try again.',
            'file.extensions' => 'Word .docx only — not PDF, Google Doc, or pasted text.',
            'file.mimes' => 'Word .docx only — not PDF, Google Doc, or pasted text.',
            'file.max' => 'That file is over the '.$mb.' MB limit.',
            'file.required' => 'Drop a .docx or click the box to choose a file.',
            'file.file' => 'Drop a .docx or click the box to choose a file.',
        ];
    }

    /**
     * PHP discarded the multipart body (post_max_size) or the file (upload_max_filesize).
     * A 5 MB .docx then looks like "no file" unless we check Content-Length.
     *
     * Do not require Content-Length to exceed ini_get(). Hostinger can already
     * report 64M while LiteSpeed still drops a 5 MB body; comparing to the
     * reported cap then falls through to "country required" / "drop a .docx".
     */
    public function rejectedUploadMessage(?UploadedFile $file, ?array $cfg = null, ?int $contentLengthBytes = null, ?int $clientFileBytes = null): ?string
    {
        if ($file instanceof UploadedFile) {
            return $this->invalidUploadMessage($file, $cfg, $clientFileBytes);
        }

        if ($clientFileBytes !== null && $clientFileBytes > self::MAX_KILOBYTES * 1024) {
            $appMb = PhpIniSize::megabytesLabel($this->effectiveMaxKilobytes($cfg));

            return 'That file is over the '.$appMb.' MB limit.';
        }

        $hint = max($contentLengthBytes ?? 0, $clientFileBytes ?? 0);
        if ($this->contentLengthLooksLikeStrippedUpload($hint > 0 ? $hint : null)) {
            return $this->phpSizeRejectedMessage($cfg, $clientFileBytes ?? $contentLengthBytes);
        }

        return null;
    }

    public function rejectedImageUploadMessage(?UploadedFile $file, ?int $contentLengthBytes = null, ?int $clientFileBytes = null): ?string
    {
        if ($file instanceof UploadedFile && ! $file->isValid()) {
            return $this->phpImageRejectedMessage($clientFileBytes);
        }

        if ($file instanceof UploadedFile) {
            return null;
        }

        if ($clientFileBytes !== null && $clientFileBytes > self::IMAGE_MAX_KILOBYTES * 1024) {
            return $this->phpImageRejectedMessage($clientFileBytes);
        }

        $hint = max($contentLengthBytes ?? 0, $clientFileBytes ?? 0);
        if ($this->contentLengthLooksLikeStrippedUpload($hint > 0 ? $hint : null)) {
            return $this->phpImageRejectedMessage($clientFileBytes ?? $contentLengthBytes);
        }

        return null;
    }

    /**
     * Content-Length can be 0 after LiteSpeed drops the body. The browser still
     * knows the file size — JS sends it as X-Upload-Bytes and ?client_bytes=.
     *
     * @return array{0:?int, 1:?int} content-length, client file bytes
     */
    public function uploadByteHints(Request $request): array
    {
        return [
            $this->maxPositiveInt([
                $request->header('Content-Length'),
                $request->server('CONTENT_LENGTH'),
            ]),
            $this->maxPositiveInt([
                $request->header('X-Upload-Bytes'),
                $request->query('client_bytes'),
                $request->input('client_bytes'),
            ]),
        ];
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function maxPositiveInt(array $candidates): ?int
    {
        $best = null;
        foreach ($candidates as $value) {
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if ($value === null || $value === '' || ! is_numeric($value)) {
                continue;
            }
            $bytes = (int) $value;
            if ($bytes > 0) {
                $best = max($best ?? 0, $bytes);
            }
        }

        return $best;
    }

    /**
     * Larger than CSRF + country/language fields. A real missing-file submit
     * is a few KB; a discarded 5 MB .docx still sends Content-Length ≈ 5 MB.
     */
    public function contentLengthLooksLikeStrippedUpload(?int $contentLengthBytes): bool
    {
        return $contentLengthBytes !== null && $contentLengthBytes > 64 * 1024;
    }

    /**
     * PHP rejected the multipart file (size, tmp dir, partial, etc.) before we can parse it.
     * Laravel's default copy is "The file failed to upload."
     */
    public function invalidUploadMessage(?UploadedFile $file, ?array $cfg = null, ?int $clientFileBytes = null): ?string
    {
        if (! $file instanceof UploadedFile || $file->isValid()) {
            return null;
        }

        Log::notice('Content article upload rejected by PHP', [
            'error' => $file->getError(),
            'error_message' => $file->getErrorMessage(),
            'php_upload_max_kb' => $this->phpUploadMaxKilobytes(),
            'article_max_kb' => $this->effectiveMaxKilobytes($cfg),
            'user_id' => auth()->id(),
        ]);

        $knownBytes = $clientFileBytes ?: ($file->getSize() ?: null);

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->phpSizeRejectedMessage($cfg, $knownBytes),
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Drop a .docx or click the box to choose a file.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the upload. Please try again in a moment.',
            default => 'The article could not be uploaded. Please try again.',
        };
    }

    public function validateUpload(UploadedFile $file, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? $this->effectiveConfig();
        $maxKb = $this->effectiveMaxKilobytes($cfg);
        $allowedExt = array_map('strtolower', $cfg['allowed_extensions'] ?? ['docx']);
        $allowedMimes = $cfg['allowed_mimes'] ?? [];

        if (! $file->isValid()) {
            return $this->invalidUploadMessage($file, $cfg) ?? 'The article could not be uploaded. Please try again.';
        }

        if ($file->getSize() > $maxKb * 1024) {
            return 'That file is over the '.max(1, (int) round($maxKb / 1024)).' MB limit.';
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($extension, $allowedExt, true)) {
            return $cfg['help']['preferred_format']
                ?? 'Please upload a Microsoft Word (.docx) document only.';
        }

        $mime = (string) ($file->getMimeType() ?: '');
        $guessed = MimeTypes::getDefault()->getMimeTypes($extension);
        $mimeOk = $mime === ''
            || in_array($mime, $allowedMimes, true)
            || in_array($mime, $guessed, true)
            || str_contains($mime, 'wordprocessingml')
            || str_contains($mime, 'officedocument.word')
            || $mime === 'application/octet-stream'
            || ($extension === 'docx' && (str_contains($mime, 'zip') || $mime === 'application/x-zip-compressed'));

        if (! $mimeOk) {
            return 'File MIME type is not allowed. Please upload a .docx file.';
        }

        $head = @file_get_contents($file->getRealPath(), false, null, 0, 8) ?: '';
        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF")) {
            return 'This file type is not allowed for security reasons.';
        }

        // docx is a ZIP package
        if ($extension === 'docx' && ! str_starts_with($head, 'PK')) {
            return 'This does not look like a valid .docx file. Please re-save as Microsoft Word (.docx) and try again.';
        }

        return null;
    }

    /**
     * Accept one slice of a .docx when the host drops a 5 MB body at 2M.
     *
     * @return array{ok:true, complete:false, received:int, total:int}|array{ok:true, complete:true, file:UploadedFile}|array{ok:false, message:string}|null
     */
    public function receiveArticleChunk(Request $request, User $user): ?array
    {
        if (! $request->exists('chunk_index') && ! $request->exists('upload_id')) {
            return null;
        }

        $index = (int) scalar_text($request->input('chunk_index'));
        $total = (int) scalar_text($request->input('chunk_total'));
        $uploadId = strtolower(trim(scalar_text($request->input('upload_id'))));
        $file = $request->file('file');

        if ($total < 2 || $total > self::MAX_CHUNKS || $index < 0 || $index >= $total) {
            return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
        }

        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uploadId)) {
            return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
        }

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return [
                'ok' => false,
                'message' => $this->invalidUploadMessage($file instanceof UploadedFile ? $file : null)
                    ?? 'The article could not be uploaded. Please try again.',
            ];
        }

        if ($file->getSize() > self::MAX_RECEIVE_CHUNK_KILOBYTES * 1024) {
            return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
        }

        $realPath = $file->getRealPath();
        $contents = is_string($realPath) && $realPath !== '' ? file_get_contents($realPath) : false;
        if ($contents === false) {
            return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
        }

        $this->purgeStaleArticleChunks();

        $dir = $this->articleChunkDirectory((int) $user->id, $uploadId);
        Storage::disk('local')->put($dir.'/'.$index.'.part', $contents);

        $received = 0;
        for ($i = 0; $i < $total; $i++) {
            if (Storage::disk('local')->exists($dir.'/'.$i.'.part')) {
                $received++;
            }
        }

        if ($received < $total) {
            // Sequential clients send the last index only after the others.
            // A pending response here looks like a finished upload in the UI.
            if ($index === $total - 1) {
                Storage::disk('local')->deleteDirectory($dir);

                return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
            }

            return ['ok' => true, 'complete' => false, 'received' => $received, 'total' => $total];
        }

        return $this->assembleArticleChunks($user, $uploadId, $total, scalar_text($request->input('original_filename')));
    }

    private function articleChunkDirectory(int $userId, string $uploadId): string
    {
        return 'article-chunks/'.$userId.'/'.$uploadId;
    }

    /**
     * @return array{ok:true, complete:true, file:UploadedFile}|array{ok:false, message:string}
     */
    private function assembleArticleChunks(User $user, string $uploadId, int $total, string $originalName): array
    {
        $dir = $this->articleChunkDirectory((int) $user->id, $uploadId);
        $tmp = tempnam(sys_get_temp_dir(), 'libdocx');
        if ($tmp === false) {
            Storage::disk('local')->deleteDirectory($dir);

            return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
        }

        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            @unlink($tmp);
            Storage::disk('local')->deleteDirectory($dir);

            return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
        }

        $bytes = 0;
        for ($i = 0; $i < $total; $i++) {
            $part = $dir.'/'.$i.'.part';
            if (! Storage::disk('local')->exists($part)) {
                fclose($handle);
                @unlink($tmp);
                Storage::disk('local')->deleteDirectory($dir);

                return ['ok' => false, 'message' => 'The article could not be uploaded. Please try again.'];
            }
            $chunk = Storage::disk('local')->get($part);
            $bytes += strlen((string) $chunk);
            if ($bytes > self::MAX_KILOBYTES * 1024) {
                fclose($handle);
                @unlink($tmp);
                Storage::disk('local')->deleteDirectory($dir);

                return ['ok' => false, 'message' => 'That file is over the 10 MB limit.'];
            }
            fwrite($handle, (string) $chunk);
        }
        fclose($handle);
        Storage::disk('local')->deleteDirectory($dir);

        $head = (string) @file_get_contents($tmp, false, null, 0, 2);
        if ($head !== 'PK') {
            @unlink($tmp);

            return ['ok' => false, 'message' => 'This does not look like a valid .docx file. Please re-save as Microsoft Word (.docx) and try again.'];
        }

        $safeName = $this->safeDocxFilename($originalName);

        return [
            'ok' => true,
            'complete' => true,
            'file' => new UploadedFile(
                $tmp,
                $safeName,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                UPLOAD_ERR_OK,
                true
            ),
        ];
    }

    public function safeDocxFilename(string $name): string
    {
        $base = preg_replace('/\.docx$/i', '', $name) ?? '';
        $base = str_replace(["'", '’', '`'], '', $base);
        $base = preg_replace('/[^\w.\- ]+/u', '', $base) ?? '';
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? '');
        $base = substr($base, 0, 80);
        if ($base === '') {
            $base = 'article';
        }

        return $base.'.docx';
    }

    private function purgeStaleArticleChunks(): void
    {
        $root = 'article-chunks';
        if (! Storage::disk('local')->exists($root)) {
            return;
        }

        try {
            $cutoff = now()->subHours(2)->getTimestamp();
            foreach (Storage::disk('local')->directories($root) as $userDir) {
                foreach (Storage::disk('local')->directories($userDir) as $uploadDir) {
                    $stamp = Storage::disk('local')->lastModified($uploadDir);
                    if ($stamp !== false && $stamp < $cutoff) {
                        Storage::disk('local')->deleteDirectory($uploadDir);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::notice('Could not purge stale article upload chunks', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
