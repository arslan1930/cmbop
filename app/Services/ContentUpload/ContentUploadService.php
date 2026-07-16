<?php

namespace App\Services\ContentUpload;

use App\Mail\ContentEvaluationResult;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
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
    ) {
    }

    public function effectiveConfig(): array
    {
        $base = config('content_upload', []);
        $override = ContentModerationSetting::getValue('upload_config', []) ?: [];

        if (!is_array($override) || $override === []) {
            return $base;
        }

        return array_replace_recursive($base, $override);
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
    ): array {
        $cfg = $this->effectiveConfig();
        $validationError = $this->validateUpload($file, $cfg);
        if ($validationError !== null) {
            return ['ok' => false, 'accepted' => false, 'approved' => false, 'title' => 'Upload rejected', 'message' => $validationError];
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $disk = (string) ($cfg['disk'] ?? 'local');
        $dir = trim((string) ($cfg['directory'] ?? 'content-uploads'), '/');
        $filename = Str::uuid()->toString() . '.' . $extension;
        $path = $file->storeAs($dir . '/' . $user->id, $filename, $disk);

        if (!$path) {
            return ['ok' => false, 'accepted' => false, 'approved' => false, 'title' => 'Upload failed', 'message' => 'Unable to store the file. Please try again.'];
        }

        $absolute = Storage::disk($disk)->path($path);
        $extracted = $this->extractor->extract($absolute, $extension);

        if (!$extracted['ok']) {
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

        $attrs = [
            'site_id' => $siteId,
            'copy_index' => $copyIndex,
            'cart_key' => $cartKey,
            'original_filename' => $file->getClientOriginalName(),
            'title' => $docTitle,
            'disk' => $disk,
            'path' => $path,
            'mime' => $file->getMimeType(),
            'extension' => $extension,
            'size_bytes' => (int) $file->getSize(),
            'extracted_text' => $extracted['text'],
            'preview_html' => $extracted['html'],
            'word_count' => $extracted['word_count'],
            'moderation_status' => ContentSubmission::STATUS_PROCESSING,
            'evaluation_status' => 'processing',
            'expires_at' => now()->addMonths($retentionMonths),
        ];

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

        $submission->update([
            'moderation_status' => $result['moderation_status'],
            'evaluation_status' => $result['evaluation_status'],
            'uniqueness_score' => $result['uniqueness_score'],
            'quality_score' => $result['quality_score'],
            'evaluation_report' => $result['report'],
            'evaluated_at' => now(),
            'moderation_log_id' => $result['log']?->id,
            'scan_token' => $result['log']?->scan_token,
            'wizard_step' => $result['approved'] ? max(2, (int) $submission->wizard_step) : 1,
        ]);

        $fresh = $submission->fresh();
        $this->notifyAdvertiserOfEvaluation($fresh, $result);

        // Upload was accepted into the library; approval is separate.
        return [
            'ok' => true,
            'accepted' => true,
            'approved' => (bool) $result['approved'],
            'submission' => $fresh,
            'title' => $result['title'],
            'message' => $result['message'],
            'report' => $result['report'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function notifyAdvertiserOfEvaluation(ContentSubmission $submission, array $result): void
    {
        try {
            if ($submission->approval_notified_at && $submission->isApproved()) {
                return;
            }

            $user = $submission->user;
            if (!$user?->email) {
                return;
            }

            $mailable = new ContentEvaluationResult($submission, $result);
            $mailable->notificationType = 'content_evaluation_result';
            $mailable->dedupeKey = 'content_eval:' . $submission->id . ':' . $submission->moderation_status;

            Mail::to($user->email)->send($mailable);

            $submission->update(['approval_notified_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Content evaluation email failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function validateUpload(UploadedFile $file, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? $this->effectiveConfig();
        $maxKb = max(100, (int) ($cfg['max_kilobytes'] ?? 5120));
        $allowedExt = array_map('strtolower', $cfg['allowed_extensions'] ?? ['docx']);
        $allowedMimes = $cfg['allowed_mimes'] ?? [];

        if (!$file->isValid()) {
            return 'The upload failed. Please try again.';
        }

        if ($file->getSize() > $maxKb * 1024) {
            return 'File is too large. Maximum size is ' . round($maxKb / 1024, 1) . ' MB.';
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($extension, $allowedExt, true)) {
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
            || $mime === 'application/octet-stream';

        if (!$mimeOk) {
            return 'File MIME type is not allowed. Please upload a .docx file.';
        }

        $head = @file_get_contents($file->getRealPath(), false, null, 0, 8) ?: '';
        if (str_starts_with($head, 'MZ') || str_starts_with($head, "\x7fELF")) {
            return 'This file type is not allowed for security reasons.';
        }

        // docx is a ZIP package
        if ($extension === 'docx' && !str_starts_with($head, 'PK')) {
            return 'This does not look like a valid .docx file. Please re-save as Microsoft Word (.docx) and try again.';
        }

        return null;
    }
}
