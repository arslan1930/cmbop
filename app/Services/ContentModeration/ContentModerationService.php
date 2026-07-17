<?php

namespace App\Services\ContentModeration;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ContentModerationService
{
    public function __construct(
        private GoogleDocsFetcher $fetcher,
        private ContentModerationEngine $engine,
        private ContentQualityAnalyzer $quality,
    ) {
    }

    public function effectiveConfig(): array
    {
        return Cache::remember('content_moderation_effective_config', 60, function () {
            $base = config('content_moderation', []);
            $override = ContentModerationSetting::getValue('config_override', []);

            if (!is_array($override) || $override === []) {
                return $base;
            }

            return array_replace_recursive($base, $override);
        });
    }

    public function isEnabled(): bool
    {
        $cfg = $this->effectiveConfig();

        return (bool) ($cfg['enabled'] ?? true);
    }

    public function threshold(): int
    {
        return (int) ($this->effectiveConfig()['confidence_threshold'] ?? 70);
    }

    /**
     * Scan a Google Docs URL for the given user.
     *
     * @return array{
     *   passed:bool,
     *   status:string,
     *   user_message:string,
     *   user_title:string,
     *   loading_done:bool,
     *   log:?ContentModerationLog,
     *   report:array,
     *   scan_token:?string
     * }
     */
    public function scan(string $url, ?User $user = null, bool $force = false): array
    {
        $url = trim($url);
        $cfg = $this->effectiveConfig();

        if (!$this->isEnabled()) {
            $token = Str::random(40);
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'document_url' => $url,
                'status' => ContentModerationLog::STATUS_APPROVED,
                'passed' => true,
                'max_confidence' => 0,
                'scan_token' => $token,
                'signals' => ['moderation_disabled' => true],
                'quality_report' => ['checks' => [], 'score' => 100],
            ]);

            return $this->successPayload($log, 'Moderation is currently disabled. You may continue.');
        }

        $cacheKey = 'content_moderation_scan:' . sha1(mb_strtolower($url) . ':' . ($user?->id ?? 0));
        if (!$force && ($cached = Cache::get($cacheKey)) instanceof ContentModerationLog) {
            $fresh = ContentModerationLog::query()->find($cached->id);
            if ($fresh && $fresh->isUsableApproval((int) ($cfg['scan_cache_seconds'] ?? 900))) {
                return $this->resultFromLog($fresh);
            }
        }

        $fetched = $this->fetcher->fetch($url);
        if (!$fetched['ok']) {
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'document_url' => $url,
                'document_id' => $fetched['document_id'],
                'status' => ContentModerationLog::STATUS_ERROR,
                'passed' => false,
                'error_code' => $fetched['error_code'],
                'error_message' => $fetched['error_message'],
                'scan_token' => Str::random(40),
            ]);

            return [
                'passed' => false,
                'status' => 'error',
                'user_title' => 'Unable to Check Article',
                'user_message' => $fetched['error_message'],
                'loading_done' => true,
                'log' => $log,
                'report' => ['error' => true, 'error_code' => $fetched['error_code']],
                'scan_token' => $log->scan_token,
            ];
        }

        $result = $this->scanExtractedContent(
            text: (string) $fetched['text'],
            html: (string) ($fetched['html'] ?? ''),
            sourceLabel: $url,
            user: $user,
            title: (string) ($fetched['title'] ?? ''),
            documentId: $fetched['document_id'] ?? null,
            links: $fetched['links'] ?? [],
        );

        if ($result['passed'] && $result['log']) {
            Cache::put($cacheKey, $result['log'], (int) ($cfg['scan_cache_seconds'] ?? 900));
        }

        return $result;
    }

    /**
     * Run the same compliance + quality rules against extracted document text
     * (native uploads). Input source differs; scoring rules stay identical.
     *
     * @param  array<int, mixed>  $links
     * @return array{
     *   passed:bool,
     *   status:string,
     *   user_message:string,
     *   user_title:string,
     *   loading_done:bool,
     *   log:?ContentModerationLog,
     *   report:array,
     *   scan_token:?string
     * }
     */
    public function scanExtractedContent(
        string $text,
        string $html,
        string $sourceLabel,
        ?User $user = null,
        string $title = '',
        ?string $documentId = null,
        array $links = [],
    ): array {
        $cfg = $this->effectiveConfig();
        $text = trim($text);

        if ($text === '') {
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'document_url' => $sourceLabel,
                'document_id' => $documentId,
                'status' => ContentModerationLog::STATUS_ERROR,
                'passed' => false,
                'error_code' => 'empty_document',
                'error_message' => 'This document appears empty. Please upload an article with content.',
                'scan_token' => Str::random(40),
            ]);

            return [
                'passed' => false,
                'status' => 'error',
                'user_title' => 'Empty Document',
                'user_message' => $log->error_message,
                'loading_done' => true,
                'log' => $log,
                'report' => ['error' => true, 'error_code' => 'empty_document'],
                'scan_token' => $log->scan_token,
            ];
        }

        if (!$this->isEnabled()) {
            $token = Str::random(40);
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'document_url' => $sourceLabel,
                'document_id' => $documentId,
                'status' => ContentModerationLog::STATUS_APPROVED,
                'passed' => true,
                'max_confidence' => 0,
                'scan_token' => $token,
                'word_count' => str_word_count($text),
                'signals' => ['moderation_disabled' => true, 'source' => 'upload'],
                'quality_report' => ['checks' => [], 'score' => 100, 'word_count' => str_word_count($text)],
            ]);

            return $this->successPayload($log, 'Moderation is currently disabled. You may continue.');
        }

        $categories = $cfg['categories'] ?? [];
        $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
        $exceptions = array_merge(
            $cfg['exceptions'] ?? [],
            ContentModerationSetting::getValue('exceptions', []) ?: []
        );

        $disabled = ContentModerationSetting::getValue('disabled_categories', []) ?: [];
        foreach ($disabled as $catKey) {
            if (isset($categories[$catKey])) {
                $categories[$catKey]['enabled'] = false;
            }
        }
        $enabledExtra = ContentModerationSetting::getValue('enabled_categories', []) ?: [];
        foreach ($enabledExtra as $catKey) {
            if (isset($categories[$catKey])) {
                $categories[$catKey]['enabled'] = true;
            }
        }

        $score = $this->engine->score(
            title: $title,
            text: $text,
            links: $links,
            categories: $categories,
            extraKeywords: is_array($extraKeywords) ? $extraKeywords : [],
            exceptions: is_array($exceptions) ? $exceptions : [],
        );

        $quality = $this->quality->analyze(
            $text,
            $html,
            $links,
            $cfg['quality'] ?? []
        );

        $threshold = $this->threshold();
        $restrictedFail = $score['max_confidence'] >= $threshold;
        $qualityFail = !empty($quality['blocking_issues']);
        $passed = !$restrictedFail && !$qualityFail;

        $token = Str::random(40);
        $log = ContentModerationLog::create([
            'user_id' => $user?->id,
            'document_url' => $sourceLabel,
            'document_id' => $documentId,
            'status' => $passed
                ? ContentModerationLog::STATUS_APPROVED
                : ContentModerationLog::STATUS_REJECTED,
            'passed' => $passed,
            'max_confidence' => $score['max_confidence'],
            'detected_category' => $restrictedFail ? $score['detected_category'] : null,
            'category_scores' => $score['scores'],
            'quality_report' => $quality,
            'signals' => [
                'title' => $title,
                'link_count' => count($links),
                'threshold' => $threshold,
                'engine_hits' => $score['signals']['hits'] ?? [],
                'source' => str_starts_with($sourceLabel, 'upload:') ? 'upload' : 'url',
            ],
            'word_count' => $quality['word_count'] ?? 0,
            'scan_token' => $token,
        ]);

        return $this->resultFromLog($log);
    }

    /**
     * Ensure each content submission is approved for the current user.
     *
     * @param  array<int, \App\Models\ContentSubmission|int>  $submissions
     * @return array{ok:bool, failures:array<int, array<string, mixed>>}
     */
    public function assertSubmissionsApproved(array $submissions, ?User $user = null): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => true, 'failures' => []];
        }

        $failures = [];
        $within = (int) ($this->effectiveConfig()['scan_cache_seconds'] ?? 900);
        $requirePrior = (bool) ($this->effectiveConfig()['require_approved_scan'] ?? true);

        foreach ($submissions as $submission) {
            if (!$submission instanceof \App\Models\ContentSubmission) {
                $submission = \App\Models\ContentSubmission::query()->find($submission);
            }
            if (!$submission) {
                $failures[] = [
                    'title' => 'Content check required',
                    'message' => 'Each placement needs an uploaded article that passed content validation.',
                ];
                continue;
            }

            if ($user && (int) $submission->user_id !== (int) $user->id) {
                $failures[] = [
                    'title' => 'Content check required',
                    'message' => 'Invalid content submission for this account.',
                ];
                continue;
            }

            if ($submission->isApproved()) {
                continue;
            }

            if ($requirePrior || !$submission->extracted_text) {
                $failures[] = [
                    'url' => 'upload:' . $submission->id,
                    'title' => 'Content check required',
                    'message' => config('content_upload.help.compliance_reject')
                        ?: 'Please upload a revised document before continuing.',
                ];
                continue;
            }

            $result = $this->scanExtractedContent(
                text: (string) $submission->extracted_text,
                html: (string) ($submission->preview_html ?? ''),
                sourceLabel: 'upload:' . $submission->id,
                user: $user,
                title: pathinfo((string) $submission->original_filename, PATHINFO_FILENAME) ?: 'Article',
            );

            $submission->update([
                'moderation_status' => $result['passed']
                    ? \App\Models\ContentSubmission::STATUS_APPROVED
                    : ($result['status'] === 'error'
                        ? \App\Models\ContentSubmission::STATUS_ERROR
                        : \App\Models\ContentSubmission::STATUS_REJECTED),
                'moderation_log_id' => $result['log']?->id,
                'scan_token' => $result['scan_token'],
            ]);

            if (!$result['passed']) {
                $failures[] = [
                    'url' => 'upload:' . $submission->id,
                    'title' => $result['user_title'],
                    'message' => config('content_upload.help.compliance_reject') ?: $result['user_message'],
                    'report' => $result['report'],
                ];
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
    }

    public function assertLinksApproved(array $urls, ?User $user = null): array
    {
        $cfg = $this->effectiveConfig();
        if (!$this->isEnabled()) {
            return ['ok' => true, 'failures' => []];
        }

        $failures = [];
        $within = (int) ($cfg['scan_cache_seconds'] ?? 900);

        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            $recent = ContentModerationLog::query()
                ->where('document_url', $url)
                ->when($user?->id, fn ($q) => $q->where('user_id', $user->id))
                ->where(function ($q) {
                    $q->where('passed', true)
                        ->orWhere('admin_override', true);
                })
                ->latest('id')
                ->first();

            if ($recent && ($recent->admin_override || $recent->isUsableApproval($within))) {
                continue;
            }

            // Re-scan synchronously before allowing order
            $result = $this->scan($url, $user, force: true);
            if (!$result['passed']) {
                $failures[] = [
                    'url' => $url,
                    'title' => $result['user_title'],
                    'message' => $result['user_message'],
                    'report' => $result['report'],
                ];
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
    }

    public function resultFromLog(ContentModerationLog $log): array
    {
        if ($log->status === ContentModerationLog::STATUS_ERROR) {
            return [
                'passed' => false,
                'status' => 'error',
                'user_title' => 'Unable to Check Article',
                'user_message' => $log->error_message ?: 'We could not validate this document.',
                'loading_done' => true,
                'log' => $log,
                'report' => $this->publicReport($log),
                'scan_token' => $log->scan_token,
            ];
        }

        if ($log->passed) {
            return $this->successPayload($log);
        }

        return [
            'passed' => false,
            'status' => 'rejected',
            'user_title' => '❌ Article Cannot Be Accepted',
            'user_message' => $this->rejectionMessage(),
            'loading_done' => true,
            'log' => $log,
            'report' => $this->publicReport($log),
            'scan_token' => $log->scan_token,
        ];
    }

    protected function successPayload(ContentModerationLog $log, ?string $message = null): array
    {
        return [
            'passed' => true,
            'status' => 'approved',
            'user_title' => '✅ Article Approved',
            'user_message' => $message ?: 'Your article complies with our content guidelines. Continue with your order.',
            'loading_done' => true,
            'log' => $log,
            'report' => $this->publicReport($log),
            'scan_token' => $log->scan_token,
        ];
    }

    public function rejectionMessage(): string
    {
        return (string) (config('content_upload.help.compliance_reject')
            ?: ("This article contains content that violates our publishing guidelines.\n\n"
                . 'Please upload a revised document before continuing.'));
    }

    public function publicReport(ContentModerationLog $log): array
    {
        $quality = $log->quality_report ?? [];
        $checks = $quality['checks'] ?? [];

        // Never expose raw category scores / algorithm details to advertisers
        $publicChecks = [];
        foreach ($checks as $check) {
            $publicChecks[] = [
                'label' => $check['label'] ?? 'Check',
                'status' => $check['status'] ?? 'warn',
                'detail' => $check['detail'] ?? '',
            ];
        }

        if ($log->status === ContentModerationLog::STATUS_REJECTED && !$log->passed) {
            $publicChecks[] = [
                'label' => 'Restricted Content',
                'status' => 'fail',
                'detail' => 'Content policy violation detected',
            ];
        } elseif ($log->passed) {
            $publicChecks[] = [
                'label' => 'Content Policy',
                'status' => 'pass',
                'detail' => 'No restricted topics detected',
            ];
        }

        return [
            'word_count' => $log->word_count,
            'quality_score' => $quality['score'] ?? null,
            'checks' => $publicChecks,
            'passed' => (bool) $log->passed,
            'status' => $log->status,
        ];
    }

    public function adminStats(): array
    {
        return [
            'total' => ContentModerationLog::query()->count(),
            'approved' => ContentModerationLog::query()->where('status', 'approved')->count(),
            'rejected' => ContentModerationLog::query()->where('status', 'rejected')->count(),
            'errors' => ContentModerationLog::query()->where('status', 'error')->count(),
            'today' => ContentModerationLog::query()->whereDate('created_at', today())->count(),
        ];
    }
}
