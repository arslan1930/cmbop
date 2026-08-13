<?php

namespace App\Services\ContentUpload;

use App\Mail\ContentEvaluationResult;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Support\SiteImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class ContentUploadService
{
    public function __construct(
        private DocumentTextExtractor $extractor,
        private ArticleEvaluationService $evaluation,
    ) {}

    public function effectiveConfig(): array
    {
        $base = config('content_upload', []);
        $override = ContentModerationSetting::getValue('upload_config', []) ?: [];

        if (! is_array($override) || $override === []) {
            return $base;
        }

        return array_replace_recursive($base, $override);
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
            'extracted_text' => $extracted['text'],
            'preview_html' => ArticlePreviewHtml::normalize((string) ($extracted['html'] ?? '')),
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

        $report = $result['report'] ?? [];
        if (! empty($result['highlighted_html'])) {
            $report['highlighted_preview'] = true;
        }

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
        $this->notifyAdvertiserOfEvaluation($fresh, $result);

        // Upload was accepted into the library; approval is separate.
        return [
            'ok' => true,
            'accepted' => true,
            'approved' => (bool) $result['approved'],
            'submission' => $fresh,
            'title' => $result['title'],
            'message' => $result['message'],
            'report' => $report,
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

            $approved = (bool) ($result['approved'] ?? false);
            $status = (string) ($result['moderation_status'] ?? $submission->moderation_status);

            // Allow a later approval email after an earlier rejection/needs-fix notice.
            $alreadyNotifiedSameOutcome = $submission->approval_notified_at
                && $approved
                && $submission->isApproved()
                && ($submission->evaluation_report['notified_status'] ?? null) === $status;

            if (! $alreadyNotifiedSameOutcome && $user->email) {
                $mailable = new ContentEvaluationResult($submission, $result);
                $mailable->notificationType = 'content_evaluation_result';
                $mailable->dedupeKey = 'content_eval:'.$submission->id.':'.$status;
                Mail::to($user->email)->send($mailable);
            }

            $this->notifyInApp($user, $submission, $result);

            $report = $submission->evaluation_report ?? [];
            if (! is_array($report)) {
                $report = [];
            }
            $report['notified_status'] = $status;
            $submission->update([
                'approval_notified_at' => now(),
                'evaluation_report' => $report,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Content evaluation notification failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
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
     */
    public function storeArticleImage(string $binary, string $ext, string $originalName, User $user): ?string
    {
        $ext = strtolower(ltrim($ext, '.'));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            $ext = 'png';
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
        if ($submission->order_id) {
            return ['ok' => false, 'approved' => false, 'message' => 'This article is already linked to an order and cannot be edited.'];
        }

        $sanitizer = new ArticleHtmlSanitizer;
        $clean = ArticlePreviewHtml::normalize($sanitizer->sanitize($html));
        if ($clean === '') {
            return ['ok' => false, 'approved' => false, 'message' => 'Article content cannot be empty.'];
        }

        $text = $sanitizer->htmlToPlainText($clean);
        if ($sanitizer->countWords($text) < 1 && ! str_contains($clean, '<img')) {
            return ['ok' => false, 'approved' => false, 'message' => 'Article content cannot be empty.'];
        }

        $links = $sanitizer->extractLinksFromHtml($clean);
        $firstLink = $links[0] ?? null;

        $attrs = [
            'preview_html' => $clean,
            'extracted_text' => $text,
            'word_count' => $sanitizer->countWords($text),
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

        $report = $result['report'] ?? [];
        if (! empty($result['highlighted_html'])) {
            $report['highlighted_preview'] = true;
        }

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
        if ($notify) {
            $this->notifyAdvertiserOfEvaluation($fresh, $result);
        }

        return [
            'approved' => (bool) $result['approved'],
            'submission' => $fresh,
            'title' => $result['title'] ?? null,
            'message' => (string) ($result['message'] ?? ''),
            'report' => $report,
            'moderation_status' => (string) ($result['moderation_status'] ?? $fresh->moderation_status),
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
     * App cap from config, clamped so we never accept more than PHP will store.
     */
    public function effectiveMaxKilobytes(?array $cfg = null): int
    {
        $cfg = $cfg ?? $this->effectiveConfig();
        $configured = max(100, (int) ($cfg['max_kilobytes'] ?? 5120));

        return max(100, min($configured, SiteImageUpload::phpUploadMaxKilobytes()));
    }

    /**
     * @return array<string, string>
     */
    public function uploadValidationMessages(?array $cfg = null): array
    {
        $mb = max(1, (int) round($this->effectiveMaxKilobytes($cfg) / 1024));

        return [
            'file.uploaded' => 'The article could not be uploaded. Use a Word .docx under '.$mb.' MB and try again.',
            'file.extensions' => 'Word .docx only — not PDF, Google Doc, or pasted text.',
            'file.mimes' => 'Word .docx only — not PDF, Google Doc, or pasted text.',
            'file.max' => 'That file is over the '.$mb.' MB limit.',
            'file.required' => 'Drop a .docx or click the box to choose a file.',
            'file.file' => 'Drop a .docx or click the box to choose a file.',
        ];
    }

    /**
     * PHP rejected the multipart file (size, tmp dir, partial, etc.) before we can parse it.
     * Laravel's default copy is "The file failed to upload."
     */
    public function invalidUploadMessage(?UploadedFile $file, ?array $cfg = null): ?string
    {
        if (! $file instanceof UploadedFile || $file->isValid()) {
            return null;
        }

        $mb = max(1, (int) round($this->effectiveMaxKilobytes($cfg) / 1024));

        Log::notice('Content article upload rejected by PHP', [
            'error' => $file->getError(),
            'error_message' => $file->getErrorMessage(),
            'user_id' => auth()->id(),
        ]);

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is over the '.$mb.' MB limit. Save as a smaller .docx and try again.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Drop a .docx or click the box to choose a file.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the upload. Please try again in a moment.',
            default => 'The article could not be uploaded. Use a Word .docx under '.$mb.' MB and try again.',
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
}
