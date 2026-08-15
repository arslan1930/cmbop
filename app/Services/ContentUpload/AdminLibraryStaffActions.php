<?php

namespace App\Services\ContentUpload;

use App\Models\ContentModerationLog;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminLibraryStaffActions
{
    public function __construct(
        private ContentUploadService $uploads,
        private InAppNotificationService $notifications,
    ) {}

    public function fileOnDisk(ContentSubmission $submission): bool
    {
        if (! $submission->hasStoredFile()) {
            return false;
        }

        try {
            return Storage::disk($submission->disk ?: 'local')->exists($submission->path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{approved:bool, submission:ContentSubmission, message:string, moderation_status:string}
     */
    public function retry(ContentSubmission $submission): array
    {
        if ($submission->isArchived()) {
            throw ValidationException::withMessages([
                'submission' => 'Archived articles cannot be re-evaluated. Restore the article first.',
            ]);
        }

        if ($submission->isLockedByPaidOrder()) {
            throw ValidationException::withMessages([
                'submission' => 'Cannot re-evaluate an article on a paid order. Use an order dispute if the placement must be unwound.',
            ]);
        }

        if ($submission->isExpired() && ! $submission->isInUse()) {
            throw ValidationException::withMessages([
                'submission' => 'Expired unused articles are preview only. Ask the advertiser to upload a new file.',
            ]);
        }

        if (! $this->fileOnDisk($submission) && ! $submission->hasPreviewHtml()) {
            throw ValidationException::withMessages([
                'submission' => 'This article has no file or preview to evaluate.',
            ]);
        }

        return $this->uploads->reEvaluateSubmission($submission, true);
    }

    public function override(ContentSubmission $submission, string $decision, User $admin, string $notes): ContentSubmission
    {
        $decision = $decision === ContentSubmission::STATUS_REJECTED
            ? ContentSubmission::STATUS_REJECTED
            : ContentSubmission::STATUS_APPROVED;

        if ($submission->isArchived()) {
            throw ValidationException::withMessages([
                'submission' => 'Archived articles cannot be overridden. Restore the article first.',
            ]);
        }

        if ($decision === ContentSubmission::STATUS_REJECTED && $submission->isLockedByPaidOrder()) {
            throw ValidationException::withMessages([
                'decision' => 'Cannot reject an article on a paid order. Use an order dispute instead.',
            ]);
        }

        $report = is_array($submission->evaluation_report) ? $submission->evaluation_report : [];
        $report['admin_override'] = [
            'at' => now()->toIso8601String(),
            'by' => $admin->id,
            'decision' => $decision,
            'notes' => $notes,
        ];
        $report['summary'] = $decision === ContentSubmission::STATUS_APPROVED
            ? 'Manually approved by staff: '.$notes
            : 'Manually rejected by staff: '.$notes;

        if ($decision === ContentSubmission::STATUS_APPROVED) {
            $report['matched_terms'] = [];
            $report['blocked_urls'] = [];
            $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
            $report['checks'] = array_values(array_filter($checks, static function ($check) {
                if (! is_array($check)) {
                    return false;
                }

                return strtolower((string) ($check['status'] ?? '')) !== 'fail';
            }));
        }

        $submission->forceFill([
            'moderation_status' => $decision,
            'evaluation_status' => $decision,
            'evaluated_at' => now(),
            'evaluation_report' => $report,
        ])->save();

        if ($submission->moderation_log_id) {
            ContentModerationLog::query()
                ->whereKey($submission->moderation_log_id)
                ->update([
                    'passed' => $decision === ContentSubmission::STATUS_APPROVED,
                    'status' => $decision === ContentSubmission::STATUS_APPROVED
                        ? ContentModerationLog::STATUS_APPROVED
                        : ContentModerationLog::STATUS_REJECTED,
                    'admin_override' => true,
                    'overridden_by' => $admin->id,
                    'overridden_at' => now(),
                    'admin_notes' => $notes,
                ]);
        }

        try {
            ActivityLogger::log(
                'content_library.override',
                ($admin->name ?: 'Admin').' '.$decision.' article #'.$submission->id,
                $submission,
                [
                    'decision' => $decision,
                    'notes' => $notes,
                    'advertiser_id' => $submission->user_id,
                ],
                $submission->title ?: $submission->original_filename
            );
        } catch (\Throwable) {
        }

        $owner = $submission->user;
        if ($owner) {
            try {
                $this->notifications->notifyContentEvaluation($owner, $submission->fresh(), [
                    'approved' => $decision === ContentSubmission::STATUS_APPROVED,
                    'title' => $decision === ContentSubmission::STATUS_APPROVED
                        ? 'Article approved'
                        : 'Article needs changes',
                    'message' => $decision === ContentSubmission::STATUS_APPROVED
                        ? 'A staff member approved this article. You can attach it in the catalog.'
                        : 'A staff member rejected this article. '.$notes,
                    'moderation_status' => $decision,
                ]);
            } catch (\Throwable) {
            }
        }

        return $submission->fresh();
    }

    public function archive(ContentSubmission $submission): ContentSubmission
    {
        if (($submission->isInUse() || $submission->isClaimedByAnotherOrder()) && ! $submission->isPublished()) {
            throw ValidationException::withMessages([
                'submission' => $submission->isClaimedByAnotherOrder() && ! $submission->isInUse()
                    ? ContentSubmission::ACTIVE_ORDER_CLAIM_MESSAGE
                    : 'Articles in progress cannot be archived until the order is completed or cancelled.',
            ]);
        }

        $submission->archive();

        return $submission->fresh();
    }

    public function restore(ContentSubmission $submission): ContentSubmission
    {
        $submission->restoreFromArchive();

        return $submission->fresh();
    }

    public function submissionForLog(ContentModerationLog $log): ?ContentSubmission
    {
        $byLogId = ContentSubmission::query()
            ->where('moderation_log_id', $log->id)
            ->first();
        if ($byLogId) {
            return $byLogId;
        }

        if (! filled($log->scan_token)) {
            return null;
        }

        return ContentSubmission::query()
            ->where('scan_token', $log->scan_token)
            ->when($log->user_id, fn ($q) => $q->where('user_id', $log->user_id))
            ->latest('id')
            ->first();
    }
}
