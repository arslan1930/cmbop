<?php

namespace App\Services\ContentModeration;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\ContentUpload\DocumentTextExtractor;
use App\Services\OrderChatContactGuard;
use App\Support\UserMessages;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentModerationService
{
    /** @var array<string, array{hash:string, text:string, links:list<string>, ok:bool}> */
    private array $storedFilePolicyCache = [];

    public function __construct(
        private GoogleDocsFetcher $fetcher,
        private ContentModerationEngine $engine,
        private ContentQualityAnalyzer $quality,
    ) {}

    public function effectiveConfig(): array
    {
        return Cache::remember('content_moderation_effective_config', 60, function () {
            $base = config('content_moderation', []);
            $override = ContentModerationSetting::getValue('config_override', []);

            if (! is_array($override) || $override === []) {
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

    /**
     * Categories as the scanner will actually apply them.
     *
     * effectiveConfig() merges the admin's config_override but not the separate
     * disabled/enabled category lists, so reading categories from it reports a
     * category as active when the scanner is skipping it. That gap is invisible
     * by nature — a category that is off simply never flags anything — so the
     * resolution lives here and every caller reads the same answer.
     *
     * @return array<string, array<string, mixed>>
     */
    public function activeCategories(): array
    {
        $categories = $this->effectiveConfig()['categories'] ?? [];

        foreach ((array) (ContentModerationSetting::getValue('disabled_categories', []) ?: []) as $key) {
            if (isset($categories[$key])) {
                $categories[$key]['enabled'] = false;
            }
        }

        // Applied second so an explicit enable wins, matching the settings form.
        foreach ((array) (ContentModerationSetting::getValue('enabled_categories', []) ?: []) as $key) {
            if (isset($categories[$key])) {
                $categories[$key]['enabled'] = true;
            }
        }

        return $categories;
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

        if (! $this->isEnabled()) {
            $token = Str::random(40);
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'content_submission_id' => $this->submissionIdFromSource($url),
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

        $cacheKey = 'content_moderation_scan:'.sha1(mb_strtolower($url).':'.($user?->id ?? 0));
        if (! $force && ($cached = Cache::get($cacheKey)) instanceof ContentModerationLog) {
            $fresh = ContentModerationLog::query()->find($cached->id);
            if ($fresh && $fresh->isUsableApproval((int) ($cfg['scan_cache_seconds'] ?? 900))) {
                return $this->resultFromLog($fresh);
            }
        }

        $fetched = $this->fetcher->fetch($url);
        if (! $fetched['ok']) {
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'content_submission_id' => $this->submissionIdFromSource($url),
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
            contentSubmissionId: $this->submissionIdFromSource($url),
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
        ?int $contentSubmissionId = null,
    ): array {
        $cfg = $this->effectiveConfig();
        $text = trim($text);
        $contentSubmissionId = $contentSubmissionId ?: $this->submissionIdFromSource($sourceLabel);

        if ($text === '') {
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'content_submission_id' => $contentSubmissionId,
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
                'user_title' => 'Unable to Check Article',
                'user_message' => $log->error_message,
                'loading_done' => true,
                'log' => $log,
                'report' => ['error' => true, 'error_code' => 'empty_document'],
                'scan_token' => $log->scan_token,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        if (mb_strlen($text) > ContentModerationEngine::maxScannableChars()) {
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'content_submission_id' => $contentSubmissionId,
                'document_url' => $sourceLabel,
                'document_id' => $documentId,
                'status' => ContentModerationLog::STATUS_ERROR,
                'passed' => false,
                'error_code' => 'article_too_large',
                'error_message' => 'This article is too large to re-check against content policy. Shorten it and try again.',
                'scan_token' => Str::random(40),
            ]);

            return [
                'passed' => false,
                'status' => 'error',
                'user_title' => 'Unable to Check Article',
                'user_message' => $log->error_message,
                'loading_done' => true,
                'log' => $log,
                'report' => ['error' => true, 'error_code' => 'article_too_large'],
                'scan_token' => $log->scan_token,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        $contactHaystack = trim($title."\n".$text."\n".$html."\n".implode("\n", array_map('strval', $links)));
        if (app(OrderChatContactGuard::class)->isBlocked($contactHaystack, OrderChatContactGuard::MODE_CONTENT)) {
            $message = UserMessages::get('moderation.contact_article');
            $token = Str::random(40);
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'content_submission_id' => $contentSubmissionId,
                'document_url' => $sourceLabel,
                'document_id' => $documentId,
                'status' => ContentModerationLog::STATUS_REJECTED,
                'passed' => false,
                'error_code' => 'off_platform_contact',
                'error_message' => $message,
                'scan_token' => $token,
                'word_count' => str_word_count($text),
                'signals' => ['off_platform_contact' => true, 'source' => 'upload'],
                'quality_report' => ['blocking_issues' => ['off_platform_contact'], 'word_count' => str_word_count($text)],
            ]);

            return [
                'passed' => false,
                'status' => 'rejected',
                'user_title' => 'Article needs changes',
                'user_message' => $message,
                'loading_done' => true,
                'log' => $log,
                'report' => ['summary' => $message, 'fix_hints' => [$message]],
                'scan_token' => $token,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        $quality = $this->quality->analyze(
            $text,
            $html,
            $links,
            $cfg['quality'] ?? []
        );
        $blocking = $quality['blocking_issues'] ?? [];
        $alwaysBlock = array_intersect($blocking, ['url_shortener', 'placeholder']);
        $qualityBlocks = $alwaysBlock !== []
            || ((bool) ($cfg['quality']['block_on_quality_failure'] ?? false) && $blocking !== []);

        if (! $this->isEnabled()) {
            if ($qualityBlocks) {
                $token = Str::random(40);
                $log = ContentModerationLog::create([
                    'user_id' => $user?->id,
                    'content_submission_id' => $contentSubmissionId,
                    'document_url' => $sourceLabel,
                    'document_id' => $documentId,
                    'status' => ContentModerationLog::STATUS_REJECTED,
                    'passed' => false,
                    'max_confidence' => 0,
                    'scan_token' => $token,
                    'word_count' => $quality['word_count'] ?? str_word_count($text),
                    'signals' => ['source' => 'upload', 'quality_only' => true],
                    'quality_report' => $quality,
                ]);

                return $this->resultFromLog($log);
            }

            $token = Str::random(40);
            $log = ContentModerationLog::create([
                'user_id' => $user?->id,
                'content_submission_id' => $contentSubmissionId,
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

        $categories = $this->activeCategories();
        $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
        $exceptions = array_merge(
            $cfg['exceptions'] ?? [],
            ContentModerationSetting::getValue('exceptions', []) ?: []
        );

        $score = $this->engine->score(
            title: $title,
            text: $text,
            links: $links,
            categories: $categories,
            extraKeywords: is_array($extraKeywords) ? $extraKeywords : [],
            exceptions: is_array($exceptions) ? $exceptions : [],
        );

        $threshold = $this->threshold();
        $restrictedFail = $score['max_confidence'] >= $threshold;
        $passed = ! $restrictedFail && ! $qualityBlocks;

        $matchedTerms = $score['matched_terms'] ?? [];
        $blockedUrls = $score['blocked_urls'] ?? [];
        $token = Str::random(40);
        $log = ContentModerationLog::create([
            'user_id' => $user?->id,
            'content_submission_id' => $contentSubmissionId,
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
                'matched_terms' => $matchedTerms,
                'blocked_urls' => $blockedUrls,
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
     * Always re-runs the full policy scan — even for previously approved articles —
     * so advertisers cannot keep a stale pass after editing in sensitive links/keywords.
     *
     * @param  array<int, ContentSubmission|int>  $submissions
     * @return array{ok:bool, failures:array<int, array<string, mixed>>}
     */
    public function assertSubmissionsApproved(array $submissions, ?User $user = null): array
    {
        // Always re-scan. scanExtractedContent still blocks off-platform
        // contact, URL shorteners, and placeholder/link-spam when the
        // restricted-category scanner is switched off.
        $failures = [];

        foreach ($submissions as $submission) {
            if (! $submission instanceof ContentSubmission) {
                $submission = ContentSubmission::query()->find($submission);
            }
            if (! $submission) {
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

            if (! filled($submission->extracted_text) && ! filled($submission->preview_html)) {
                $failures[] = [
                    'url' => 'upload:'.$submission->id,
                    'title' => 'Content check required',
                    'message' => config('content_upload.help.compliance_reject')
                        ?: 'Please upload a revised document before continuing.',
                ];

                continue;
            }

            if ($this->storedFileUnreadable($submission)) {
                $failures[] = [
                    'url' => 'upload:'.$submission->id,
                    'title' => 'Content check required',
                    'message' => 'The stored Word file could not be re-checked. Please re-upload the article.',
                ];

                continue;
            }

            if ($this->usableAdminOverride($submission)) {
                continue;
            }

            if ($this->usableAdminReject($submission)) {
                $failures[] = [
                    'url' => 'upload:'.$submission->id,
                    'title' => 'Article needs changes',
                    'message' => config('content_upload.help.compliance_reject')
                        ?: 'A staff member rejected this article. Please revise it before ordering.',
                ];

                continue;
            }

            $result = $this->scanExtractedContent(
                text: $this->scanPolicyTextFromSubmission($submission),
                html: (string) ($submission->preview_html ?? ''),
                sourceLabel: 'upload:'.$submission->id,
                user: $user,
                title: $this->scanTitle($submission),
                links: $this->linksFromSubmission($submission),
                contentSubmissionId: (int) $submission->id,
            );

            $newStatus = $result['passed']
                ? ContentSubmission::STATUS_APPROVED
                : (($result['status'] ?? '') === 'error'
                    ? ContentSubmission::STATUS_ERROR
                    : ContentSubmission::STATUS_REJECTED);
            $fields = [
                'moderation_status' => $newStatus,
                'moderation_log_id' => $result['log'] instanceof ContentModerationLog ? $result['log']->id : null,
                'scan_token' => $result['scan_token'],
            ];
            if ($this->checkoutShouldSyncEvaluation($submission, $newStatus, $result)) {
                $fields = array_merge($fields, $this->evaluationFieldsFromScan($submission, $result));
            }
            try {
                $submission->update($fields);
            } catch (\Throwable $e) {
                Log::warning('Live-policy scan fields could not be saved', [
                    'submission_id' => $submission->id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }

            // scanExtractedContent / resultFromLog use passed + user_title/user_message
            // (not approved/title/message). Wrong keys crashed checkout on reject
            // and falsely failed every recheck when the "approved" key was missing.
            if (! ($result['passed'] ?? false)) {
                $failures[] = [
                    'url' => 'upload:'.$submission->id,
                    'title' => $result['user_title'] ?? 'Article needs changes',
                    'message' => $result['user_message']
                        ?? (config('content_upload.help.compliance_reject')
                            ?: 'Please revise this article before ordering.'),
                    'report' => $result['report'] ?? [],
                ];
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
    }

    public function submissionPassesLivePolicy(ContentSubmission $submission, ?User $user = null): bool
    {
        return (bool) ($this->assertSubmissionsApproved([$submission], $user)['ok'] ?? false);
    }

    public function assertLinksApproved(array $urls, ?User $user = null): array
    {
        $cfg = $this->effectiveConfig();
        if (! $this->isEnabled()) {
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
                ->where('passed', true)
                ->latest('id')
                ->first();

            if ($recent && $recent->isUsableApproval($within)) {
                continue;
            }

            // Re-scan synchronously before allowing order
            $result = $this->scan($url, $user, force: true);
            if (! $result['passed']) {
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
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        if ($log->passed) {
            return $this->successPayload($log);
        }

        return [
            'passed' => false,
            'status' => 'rejected',
            'user_title' => 'Article needs changes',
            'user_message' => $this->rejectionMessage($log),
            'loading_done' => true,
            'log' => $log,
            'report' => $this->publicReport($log),
            'scan_token' => $log->scan_token,
            'matched_terms' => $this->matchedTermsFromLog($log),
            'blocked_urls' => $this->blockedUrlsFromLog($log),
        ];
    }

    protected function successPayload(ContentModerationLog $log, ?string $message = null): array
    {
        return [
            'passed' => true,
            'status' => 'approved',
            'user_title' => 'Article Approved',
            'user_message' => $message ?: 'Your article complies with our content guidelines. Continue with your order.',
            'loading_done' => true,
            'log' => $log,
            'report' => $this->publicReport($log),
            'scan_token' => $log->scan_token,
            'matched_terms' => [],
            'blocked_urls' => [],
        ];
    }

    public function rejectionMessage(?ContentModerationLog $log = null): string
    {
        $quality = is_array($log?->quality_report) ? $log->quality_report : [];
        $blocking = $quality['blocking_issues'] ?? [];
        if (is_array($blocking) && $blocking !== [] && ! $log?->detected_category) {
            if (in_array('off_platform_contact', $blocking, true)
                || ($log?->error_code === 'off_platform_contact')) {
                return UserMessages::get('moderation.contact_article');
            }
            if (in_array('url_shortener', $blocking, true)) {
                return UserMessages::get('moderation.quality_shortener');
            }
            if (in_array('external_links', $blocking, true)) {
                $max = (int) (($this->effectiveConfig()['quality']['max_external_links'] ?? 15));

                return UserMessages::get('moderation.quality_links', ['max' => $max]);
            }
            if (in_array('placeholder', $blocking, true)) {
                return UserMessages::get('moderation.quality_placeholder');
            }

            return UserMessages::get('moderation.quality');
        }

        $category = $log?->detected_category;
        $topic = $this->categoryTopic($category);
        $blockedUrls = $log ? $this->blockedUrlsFromLog($log) : [];
        $terms = $log ? $this->matchedTermsFromLog($log) : [];

        if ($blockedUrls !== []) {
            $shown = implode(', ', array_slice($blockedUrls, 0, 3));

            return "This article links to restricted {$topic} sites ({$shown}). "
                .'Remove or replace those links (even if the anchor text looks harmless) and resubmit.';
        }

        if ($terms !== []) {
            $list = implode(', ', array_slice($terms, 0, 8));

            return "This article contains {$topic} content we do not allow: "
                .$list
                .'. Remove those topics and resubmit.';
        }

        return (string) (config('content_upload.help.compliance_reject')
            ?: "This article contains restricted {$topic} content. Please revise and resubmit.");
    }

    public function categoryLabel(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === ContentModerationLog::CATEGORY_CUSTOM) {
            return 'Extra prohibited keywords';
        }
        if ($key === '') {
            return 'restricted content';
        }

        $label = config('content_moderation.categories.'.$key.'.label');

        return is_string($label) && $label !== '' ? $label : $key;
    }

    public function categoryTopic(?string $key): string
    {
        return match (trim((string) $key)) {
            'adult' => 'adult / 18+ / porn',
            'gambling' => 'casino / poker / gambling / betting',
            'cbd' => 'CBD / cannabis',
            'alcohol' => 'alcohol',
            'tobacco' => 'tobacco / vaping',
            'weapons' => 'weapons',
            'crypto_promo' => 'cryptocurrency promotions',
            ContentModerationLog::CATEGORY_CUSTOM => 'restricted keywords',
            default => 'restricted content',
        };
    }

    /**
     * @return list<string>
     */
    public function matchedTermsFromLog(ContentModerationLog $log): array
    {
        $signals = $log->signals ?? [];
        $terms = $signals['matched_terms'] ?? [];

        return is_array($terms)
            ? array_values(array_unique(array_map('strval', $terms)))
            : [];
    }

    /**
     * @return list<string>
     */
    public function blockedUrlsFromLog(ContentModerationLog $log): array
    {
        $signals = $log->signals ?? [];
        $urls = $signals['blocked_urls'] ?? [];

        return is_array($urls)
            ? array_values(array_unique(array_map('strval', $urls)))
            : [];
    }

    public function publicReport(ContentModerationLog $log): array
    {
        $quality = $log->quality_report ?? [];
        $checks = $quality['checks'] ?? [];
        $matchedTerms = $this->matchedTermsFromLog($log);
        $blockedUrls = $this->blockedUrlsFromLog($log);
        $category = $log->detected_category;
        $topic = $this->categoryTopic($category);
        $policyLabel = 'Restricted content ('.$topic.')';

        $publicChecks = [];
        foreach ($checks as $check) {
            $publicChecks[] = [
                'label' => $check['label'] ?? 'Check',
                'status' => $check['status'] ?? 'warn',
                'detail' => $check['detail'] ?? '',
            ];
        }

        $fixHints = [];
        $qualityBlocking = is_array($quality['blocking_issues'] ?? null) ? $quality['blocking_issues'] : [];
        $qualityOnlyReject = $log->status === ContentModerationLog::STATUS_REJECTED
            && ! $log->passed
            && ! filled($log->detected_category)
            && $qualityBlocking !== [];
        if ($qualityOnlyReject) {
            foreach ($qualityBlocking as $issue) {
                if ($issue === 'off_platform_contact') {
                    $fixHints[] = UserMessages::get('moderation.contact_article');
                } elseif ($issue === 'url_shortener') {
                    $fixHints[] = UserMessages::get('moderation.quality_shortener');
                } elseif ($issue === 'external_links') {
                    $max = (int) (($this->effectiveConfig()['quality']['max_external_links'] ?? 15));
                    $fixHints[] = UserMessages::get('moderation.quality_links', ['max' => $max]);
                } elseif ($issue === 'placeholder') {
                    $fixHints[] = UserMessages::get('moderation.quality_placeholder');
                }
            }
        } elseif ($log->status === ContentModerationLog::STATUS_REJECTED && ! $log->passed) {
            if ($blockedUrls !== []) {
                $detail = 'Remove or replace blocked links: '.implode(', ', array_slice($blockedUrls, 0, 5));
                foreach (array_slice($blockedUrls, 0, 5) as $url) {
                    $fixHints[] = 'Remove or replace blocked link: '.$url;
                }
            } elseif ($matchedTerms !== []) {
                $detail = 'Remove these terms: '.implode(', ', array_slice($matchedTerms, 0, 10));
            } else {
                $detail = ucfirst($topic).' content is not allowed';
            }
            $publicChecks[] = [
                'label' => $policyLabel,
                'status' => 'fail',
                'detail' => $detail,
            ];
            if ($matchedTerms !== []) {
                $fixHints[] = 'Remove or rewrite sections that mention: '.implode(', ', array_slice($matchedTerms, 0, 10));
            }
        } elseif ($log->admin_override && $log->passed) {
            $publicChecks[] = [
                'label' => 'Content policy',
                'status' => 'pass',
                'detail' => 'Cleared by admin override'
                    .($log->admin_notes ? ': '.$log->admin_notes : ''),
            ];
        } elseif ($log->passed) {
            $publicChecks[] = [
                'label' => 'Content policy',
                'status' => 'pass',
                'detail' => 'No restricted content detected',
            ];
        }

        return [
            'word_count' => $log->word_count,
            'quality_score' => $quality['score'] ?? null,
            'checks' => $publicChecks,
            'passed' => (bool) $log->passed,
            'status' => $log->status,
            'matched_terms' => $matchedTerms,
            'blocked_urls' => $blockedUrls,
            'fix_hints' => $fixHints,
        ];
    }

    public function adminStats(): array
    {
        $empty = [
            'total' => 0,
            'needs' => 0,
            'approved' => 0,
            'rejected' => 0,
            'errors' => 0,
            'skipped' => 0,
            'overridden' => 0,
            'today' => 0,
        ];

        if (! ContentModerationLog::tableAvailable()) {
            return $empty;
        }

        try {
            return [
                'total' => ContentModerationLog::query()->count(),
                'needs' => ContentModerationLog::query()->needsDecision()->count(),
                'approved' => ContentModerationLog::query()
                    ->where('status', ContentModerationLog::STATUS_APPROVED)
                    ->notSkipped()
                    ->where('admin_override', false)
                    ->count(),
                'rejected' => ContentModerationLog::query()
                    ->where('status', ContentModerationLog::STATUS_REJECTED)
                    ->count(),
                'errors' => ContentModerationLog::query()
                    ->where('status', ContentModerationLog::STATUS_ERROR)
                    ->count(),
                'skipped' => ContentModerationLog::query()->skipped()->count(),
                'overridden' => ContentModerationLog::query()->where('admin_override', true)->count(),
                'today' => ContentModerationLog::query()->whereDate('created_at', today())->count(),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Score text or a fetched URL without writing a log or touching checkout.
     *
     * @return array{
     *     ok:bool,
     *     passed:bool,
     *     status:string,
     *     message:string,
     *     word_count:?int,
     *     max_confidence:int,
     *     detected_category:?string,
     *     matched_terms:list<string>,
     *     blocked_urls:list<string>,
     *     skipped:bool
     * }
     */
    public function previewScan(?string $url, ?string $text): array
    {
        $empty = [
            'ok' => false,
            'passed' => false,
            'status' => 'error',
            'message' => 'Paste article text or a public URL.',
            'word_count' => null,
            'max_confidence' => 0,
            'detected_category' => null,
            'matched_terms' => [],
            'blocked_urls' => [],
            'skipped' => false,
        ];

        $url = trim((string) $url);
        $text = trim((string) $text);
        $title = '';
        $html = '';
        $links = [];

        if ($text === '' && $url !== '') {
            if (! preg_match('#^https?://#i', $url)) {
                return array_merge($empty, ['message' => 'Use an http(s) URL.']);
            }
            $fetched = $this->fetcher->fetch($url);
            if (! ($fetched['ok'] ?? false)) {
                return array_merge($empty, [
                    'message' => (string) ($fetched['error_message'] ?? 'Could not fetch that URL.'),
                ]);
            }
            $text = trim((string) ($fetched['text'] ?? ''));
            $html = (string) ($fetched['html'] ?? '');
            $title = (string) ($fetched['title'] ?? '');
            $links = is_array($fetched['links'] ?? null) ? $fetched['links'] : [];
        }

        if ($text === '') {
            return $empty;
        }

        $cfg = $this->effectiveConfig();
        $quality = $this->quality->analyze($text, $html, $links, $cfg['quality'] ?? []);
        $blocking = $quality['blocking_issues'] ?? [];
        $alwaysBlock = array_intersect($blocking, ['url_shortener', 'placeholder']);
        $qualityBlocks = $alwaysBlock !== []
            || ((bool) ($cfg['quality']['block_on_quality_failure'] ?? false) && $blocking !== []);

        if (! $this->isEnabled()) {
            return [
                'ok' => true,
                'passed' => ! $qualityBlocks,
                'status' => $qualityBlocks ? 'rejected' : 'skipped',
                'message' => $qualityBlocks
                    ? 'Moderation is off, but quality rules still blocked this text.'
                    : 'Moderation is switched off. This is a skip, not a real pass.',
                'word_count' => $quality['word_count'] ?? str_word_count($text),
                'max_confidence' => 0,
                'detected_category' => null,
                'matched_terms' => [],
                'blocked_urls' => [],
                'skipped' => ! $qualityBlocks,
            ];
        }

        $categories = $this->activeCategories();
        $extraKeywords = ContentModerationSetting::getValue('extra_keywords', []) ?: [];
        $exceptions = array_merge(
            $cfg['exceptions'] ?? [],
            ContentModerationSetting::getValue('exceptions', []) ?: []
        );
        $score = $this->engine->score(
            title: $title,
            text: $text,
            links: $links,
            categories: $categories,
            extraKeywords: is_array($extraKeywords) ? $extraKeywords : [],
            exceptions: is_array($exceptions) ? $exceptions : [],
        );
        $threshold = $this->threshold();
        $restrictedFail = $score['max_confidence'] >= $threshold;
        $passed = ! $restrictedFail && ! $qualityBlocks;
        $category = $restrictedFail ? $score['detected_category'] : null;

        return [
            'ok' => true,
            'passed' => $passed,
            'status' => $passed ? 'approved' : 'rejected',
            'message' => $passed
                ? 'Would pass current policy.'
                : ($restrictedFail
                    ? 'Would reject: '.($category ?: 'restricted content').' at '.$score['max_confidence'].'%.'
                    : 'Would reject on quality rules.'),
            'word_count' => $quality['word_count'] ?? str_word_count($text),
            'max_confidence' => (int) $score['max_confidence'],
            'detected_category' => $category,
            'matched_terms' => array_values(array_map('strval', $score['matched_terms'] ?? [])),
            'blocked_urls' => array_values(array_map('strval', $score['blocked_urls'] ?? [])),
            'skipped' => false,
        ];
    }

    /**
     * Collect absolute http(s) URLs from article HTML/text + optional extras.
     *
     * @param  array<int, string|array{url?:string}>  $extraLinks
     * @return list<string>
     */
    public function linksFromSubmissionHtml(
        string $html,
        ?string $targetUrl = null,
        string $plainText = '',
        array $extraLinks = [],
    ): array {
        $urls = [];

        if ($html !== '') {
            foreach ($this->htmlQuotedOrBareValues($html, 'href|src') as $href) {
                if ($href === '') {
                    continue;
                }
                if (str_starts_with($href, '//')) {
                    $href = 'https:'.$href;
                }
                if (preg_match('#^https?://#i', $href) || preg_match('#^(www\.)?[a-z0-9.-]+\.[a-z]{2,}(/.*)?$#i', $href)) {
                    $urls[] = $href;
                }
            }
        }

        $body = trim($plainText) !== ''
            ? $plainText
            : ($html !== '' ? strip_tags($html) : '');

        // Plain https / www URLs in body text (docx fallback / pasted text)
        if ($body !== '') {
            $urls = array_merge($urls, $this->engine->extractUrlsFromText($body));
        }

        if ($html !== '' && preg_match_all('#https?://[^\s<>"\']+#iu', strip_tags($html), $plain)) {
            foreach ($plain[0] as $url) {
                $urls[] = rtrim((string) $url, '.,);]');
            }
        }

        if (is_string($targetUrl) && trim($targetUrl) !== '') {
            $urls[] = trim($targetUrl);
        }

        foreach ($extraLinks as $link) {
            if (is_string($link) && trim($link) !== '') {
                $urls[] = trim($link);
            } elseif (is_array($link) && trim((string) ($link['url'] ?? '')) !== '') {
                $urls[] = trim((string) $link['url']);
            }
        }

        return $this->engine->normalizeLinkList($urls);
    }

    /**
     * Collect every link we know about for a submission (HTML, text, target, detected).
     *
     * @return list<string>
     */
    public function linksFromSubmission(ContentSubmission $submission): array
    {
        $fromPreview = $this->linksFromSubmissionHtml(
            html: (string) ($submission->preview_html ?? ''),
            targetUrl: $submission->target_url ? (string) $submission->target_url : null,
            plainText: (string) ($submission->extracted_text ?? ''),
            extraLinks: array_merge(
                $submission->detectedLinks(),
                array_filter([(string) ($submission->feature_image_url ?? '')]),
            ),
        );
        $fromFile = $this->storedFilePolicySignals($submission)['links'];

        return $this->engine->normalizeLinkList(array_merge($fromPreview, $fromFile));
    }

    public function submissionIdFromSource(string $sourceLabel): ?int
    {
        if (preg_match('/^upload:(\d+)$/', trim($sourceLabel), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function submissionForLog(ContentModerationLog $log): ?ContentSubmission
    {
        if (! ContentSubmission::tableAvailable()) {
            return null;
        }

        try {
            if ($log->content_submission_id) {
                return ContentSubmission::query()->find($log->content_submission_id);
            }

            $fromUrl = $this->submissionIdFromSource((string) $log->document_url);
            if ($fromUrl) {
                return ContentSubmission::query()->find($fromUrl);
            }

            return ContentSubmission::query()->where('moderation_log_id', $log->id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Body copy for language / quality / uniqueness.
     * extracted_text can lag preview_html after a silent or partial edit.
     */
    public function scanTextFromSubmission(ContentSubmission $submission): string
    {
        $extracted = trim((string) $submission->extracted_text);
        $html = (string) ($submission->preview_html ?? '');
        $fromHtml = $html !== ''
            ? trim((new ArticleHtmlSanitizer)->htmlToPlainText($html))
            : '';

        if ($extracted === '') {
            return $fromHtml;
        }
        if ($fromHtml === '' || $fromHtml === $extracted) {
            return $extracted;
        }

        return $extracted."\n".$fromHtml;
    }

    /**
     * Publisher-visible backlink labels (primary pair + detected_links).
     *
     * @return list<string>
     */
    public function anchorTextsFromSubmission(ContentSubmission $submission): array
    {
        $anchors = [];
        $primary = trim((string) ($submission->anchor_text ?? ''));
        if ($primary !== '') {
            $anchors[] = $primary;
        }

        foreach ($submission->detectedLinks() as $link) {
            $anchor = trim((string) ($link['anchor'] ?? ''));
            if ($anchor !== '') {
                $anchors[] = $anchor;
            }
        }

        $anchors = array_values(array_unique($anchors));
        sort($anchors);

        return $anchors;
    }

    /**
     * Quoted or bare HTML attribute values (`src="x"` and `src=x`).
     *
     * @return list<string>
     */
    public function htmlQuotedOrBareValues(string $html, string $names): array
    {
        if ($html === '' || $names === '') {
            return [];
        }

        $texts = [];
        if (! preg_match_all('/\b(?:'.$names.')\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/iu', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $value = ($match[1] ?? '') !== '' ? ($match[2] ?? '') : ($match[3] ?? '');
            $value = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($value !== '') {
                $texts[] = $value;
            }
        }

        return $texts;
    }

    /**
     * Attribute text strip_tags drops (image alt, title, aria-label).
     *
     * @return list<string>
     */
    public function htmlAttributeTexts(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $texts = $this->htmlQuotedOrBareValues($html, 'alt|title|aria-label');
        $texts = array_values(array_unique($texts));
        sort($texts);

        return $texts;
    }

    /**
     * href/src values strip_tags drops, including local /storage image paths.
     *
     * @return list<string>
     */
    public function htmlResourceTexts(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $texts = [];
        foreach ($this->htmlQuotedOrBareValues($html, 'href|src|srcset|data-src|data-original|data-href|data-lazy-src|poster') as $value) {
            foreach (preg_split('/\s*,\s*/', $value) ?: [] as $part) {
                $url = trim((string) preg_replace('/\s+\d+(?:\.\d+)?[wx]$/i', '', trim($part)));
                if ($url !== '') {
                    $texts[] = $url;
                }
            }
        }

        $texts = array_values(array_unique($texts));
        sort($texts);

        return $texts;
    }

    /**
     * Inline CSS the sanitizer leaves on span/div (background url, content).
     *
     * @return list<string>
     */
    public function htmlStyleTexts(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $texts = [];
        $styles = $this->htmlQuotedOrBareValues($html, 'style');
        if ($styles === []) {
            return [];
        }

        foreach ($styles as $style) {
            if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/iu', $style, $urls)) {
                foreach ($urls[2] as $url) {
                    $url = trim((string) $url);
                    if ($url !== '') {
                        $texts[] = $url;
                    }
                }
            }
            if (preg_match_all('/content\s*:\s*([\'"])(.*?)\1/iu', $style, $contents)) {
                foreach ($contents[2] as $content) {
                    $content = trim((string) $content);
                    if ($content !== '') {
                        $texts[] = $content;
                    }
                }
            }
        }

        $texts = array_values(array_unique($texts));
        sort($texts);

        return $texts;
    }

    /**
     * Restricted-term haystack: body + stored anchors + HTML attributes/src +
     * filename + the Word package the publisher downloads.
     * Keep language / uniqueness on scanTextFromSubmission() so a short
     * English backlink does not false-fail a non-English article.
     */
    public function scanPolicyTextFromSubmission(ContentSubmission $submission): string
    {
        $parts = [
            $this->scanTextFromSubmission($submission),
            implode("\n", $this->anchorTextsFromSubmission($submission)),
            implode("\n", $this->htmlAttributeTexts((string) ($submission->preview_html ?? ''))),
            implode("\n", $this->htmlResourceTexts((string) ($submission->preview_html ?? ''))),
            implode("\n", $this->htmlStyleTexts((string) ($submission->preview_html ?? ''))),
            trim((string) ($submission->original_filename ?? '')),
            $this->storedFilePolicySignals($submission)['text'],
        ];

        return trim(implode("\n", array_filter($parts, static fn (string $part) => trim($part) !== '')));
    }

    /**
     * Publisher download is the stored .docx, which can diverge from preview_html
     * after an editor save. Re-read the package (headers/footers/comments + rels).
     *
     * @return array{hash:string, text:string, links:list<string>, ok:bool}
     */
    public function storedFilePolicySignals(ContentSubmission $submission): array
    {
        $empty = ['hash' => '', 'text' => '', 'links' => [], 'ok' => true];
        if (! $submission->hasStoredFile()) {
            return $empty;
        }

        try {
            $disk = Storage::disk($submission->disk ?: 'local');
            if (! $disk->exists((string) $submission->path)) {
                return $empty;
            }
            $absolute = $disk->path((string) $submission->path);
        } catch (\Throwable) {
            return $empty;
        }

        if (! is_file($absolute)) {
            return $empty;
        }

        $cacheKey = $absolute.'|'.(int) filemtime($absolute).'|'.(int) filesize($absolute);
        if (isset($this->storedFilePolicyCache[$cacheKey])) {
            return $this->storedFilePolicyCache[$cacheKey];
        }

        $hash = hash_file('sha256', $absolute) ?: '';
        $extracted = (new DocumentTextExtractor)->extractPolicySignals($absolute);

        return $this->storedFilePolicyCache[$cacheKey] = [
            'hash' => $hash,
            'text' => $extracted['text'],
            'links' => $extracted['links'],
            'ok' => $extracted['ok'],
        ];
    }

    public function storedFileUnreadable(ContentSubmission $submission): bool
    {
        if (! $submission->hasStoredFile()) {
            return false;
        }

        try {
            $disk = Storage::disk($submission->disk ?: 'local');
            if (! $disk->exists((string) $submission->path)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return ! $this->storedFilePolicySignals($submission)['ok'];
    }

    public function scanTitle(ContentSubmission $submission): string
    {
        $title = trim((string) $submission->title);
        if ($title !== '') {
            return $title;
        }

        $fromFile = pathinfo((string) $submission->original_filename, PATHINFO_FILENAME);

        return $fromFile !== '' ? $fromFile : 'Article';
    }

    public function contentFingerprint(ContentSubmission $submission): string
    {
        $links = $this->linksFromSubmission($submission);
        sort($links);

        return hash('sha256', implode("\n", [
            $this->scanTitle($submission),
            (string) $submission->extracted_text,
            (string) $submission->preview_html,
            (string) $submission->target_url,
            implode("\n", $links),
            implode("\n", $this->anchorTextsFromSubmission($submission)),
            (string) $submission->feature_image_url,
            (string) $submission->original_filename,
            $this->storedFilePolicySignals($submission)['hash'],
        ]));
    }

    public function usableAdminOverride(ContentSubmission $submission): bool
    {
        if ($submission->moderation_status !== ContentSubmission::STATUS_APPROVED) {
            return false;
        }

        $log = $submission->moderationLog;
        if (! $log || ! $log->admin_override || ! $log->passed) {
            return false;
        }

        return $this->overrideFingerprintMatches($log, $submission);
    }

    public function usableAdminReject(ContentSubmission $submission): bool
    {
        if ($submission->moderation_status !== ContentSubmission::STATUS_REJECTED) {
            return false;
        }

        $log = $submission->moderationLog;
        if (! $log || ! $log->admin_override || $log->passed) {
            return false;
        }

        return $this->overrideFingerprintMatches($log, $submission);
    }

    /**
     * Library staff approve/reject. Always fingerprints the current article so
     * checkout honors the decision until the advertiser edits.
     *
     * @return array{ok:bool, already:bool, submission:?ContentSubmission, message:string}
     */
    public function applyStaffOverride(ContentSubmission $submission, string $decision, User $admin, string $notes): array
    {
        $decision = $decision === ContentSubmission::STATUS_REJECTED
            ? ContentSubmission::STATUS_REJECTED
            : ContentSubmission::STATUS_APPROVED;

        return DB::transaction(function () use ($submission, $decision, $admin, $notes) {
            $submission = ContentSubmission::query()->whereKey($submission->id)->lockForUpdate()->first() ?? $submission;
            $log = $this->currentOrNewLogForSubmission($submission);

            if ($this->overrideAlreadyApplies($log, $submission, $notes, $decision)) {
                if ($this->overrideFingerprintState($log, $submission) === 'missing') {
                    $this->stampOverride($log, $submission, $admin, $notes, $decision);
                }

                $message = $decision === ContentSubmission::STATUS_APPROVED
                    ? 'Article #'.$submission->id.' is already approved by override.'
                    : 'Article #'.$submission->id.' is already rejected by override.';

                return [
                    'ok' => true,
                    'already' => true,
                    'submission' => $submission->fresh(),
                    'message' => $message,
                ];
            }

            $this->stampOverride($log, $submission, $admin, $notes, $decision);
            $this->logOverrideActivity($admin, $log, $submission, $notes, $decision);

            $message = $decision === ContentSubmission::STATUS_APPROVED
                ? 'Article #'.$submission->id.' approved by override. Checkout will accept it until the advertiser edits the content.'
                : 'Article #'.$submission->id.' rejected. Checkout will block this version until the advertiser edits.';

            return [
                'ok' => true,
                'already' => false,
                'submission' => $submission->fresh(),
                'message' => $message,
            ];
        });
    }

    /**
     * Checkout re-scans unless this fingerprint is on the linked override log.
     * Library force-approve must stamp it or the next order undoes the decision.
     */
    public function stampStaffApprovalOverride(ContentSubmission $submission, User $admin, string $notes): ContentModerationLog
    {
        $fingerprint = $this->contentFingerprint($submission);
        $log = $submission->moderation_log_id
            ? ContentModerationLog::query()->whereKey($submission->moderation_log_id)->first()
            : null;

        $signals = is_array($log?->signals) ? $log->signals : [];
        $signals['override_fingerprint'] = $fingerprint;

        $payload = [
            'user_id' => $submission->user_id,
            'content_submission_id' => $submission->id,
            'document_url' => $log?->document_url ?: 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_APPROVED,
            'passed' => true,
            'admin_override' => true,
            'overridden_by' => $admin->id,
            'overridden_at' => now(),
            'admin_notes' => $notes,
            'scan_token' => $submission->scan_token ?: $log?->scan_token,
            'word_count' => $submission->word_count,
            'signals' => $signals,
        ];

        if ($log) {
            $log->update($payload);
        } else {
            $log = ContentModerationLog::create($payload);
        }

        if ((int) $submission->moderation_log_id !== (int) $log->id) {
            $submission->forceFill([
                'moderation_log_id' => $log->id,
                'scan_token' => $log->scan_token ?: $submission->scan_token,
            ])->save();
        }

        return $log->fresh();
    }

    /**
     * @return array{ok:bool, already?:bool, submission:?ContentSubmission, message:string}
     */
    public function applyAdminOverride(ContentModerationLog $log, User $admin, string $notes): array
    {
        if ($log->wasSkipped()) {
            return ['ok' => false, 'submission' => null, 'message' => 'Skipped scans cannot be overridden.'];
        }

        $overridable = in_array($log->status, [
            ContentModerationLog::STATUS_REJECTED,
            ContentModerationLog::STATUS_ERROR,
        ], true) || $log->admin_override;
        if (! $overridable) {
            return ['ok' => false, 'submission' => $this->submissionForLog($log), 'message' => 'Only rejected or error scans can be overridden.'];
        }

        $submission = $this->submissionForLog($log);
        if (! $submission && $this->submissionIdFromSource((string) $log->document_url)) {
            return ['ok' => false, 'submission' => null, 'message' => 'The linked article no longer exists.'];
        }

        if ($submission?->isArchived()) {
            return [
                'ok' => false,
                'submission' => $submission,
                'message' => 'Archived articles cannot be overridden. Restore the article first. The scan log was left unchanged.',
            ];
        }

        return DB::transaction(function () use ($log, $admin, $notes, $submission) {
            if ($submission) {
                $submission = ContentSubmission::query()->whereKey($submission->id)->lockForUpdate()->first() ?? $submission;
                if (! $log->isCurrentDecision($submission)) {
                    return [
                        'ok' => false,
                        'submission' => $submission,
                        'message' => 'This scan is no longer the current decision. Open the latest scan.',
                    ];
                }
            }

            if ($this->overrideAlreadyApplies($log, $submission, $notes, ContentSubmission::STATUS_APPROVED)) {
                if ($this->overrideFingerprintState($log, $submission) === 'missing') {
                    $this->stampOverride($log, $submission, $admin, $notes, ContentSubmission::STATUS_APPROVED);
                }

                return [
                    'ok' => true,
                    'already' => true,
                    'submission' => $submission?->fresh(),
                    'message' => $submission
                        ? 'Article #'.$submission->id.' is already approved by override.'
                        : 'This scan is already approved by override.',
                ];
            }

            $this->stampOverride($log, $submission, $admin, $notes, ContentSubmission::STATUS_APPROVED);
            $this->logOverrideActivity($admin, $log, $submission, $notes, ContentSubmission::STATUS_APPROVED);

            $fresh = $submission?->fresh();
            if (! $fresh) {
                $message = 'Scan overridden as approved. No linked article was found to update.';
            } elseif ($fresh->isReadyForCheckout()) {
                $message = 'Article #'.$fresh->id.' approved by override. Checkout will accept it until the advertiser edits the content.';
            } elseif ($fresh->isUsableAfterStaffApproval()) {
                $message = 'Article #'.$fresh->id.' approved by override. It stays on the open order and can be fulfilled.';
            } else {
                $message = 'Article #'.$fresh->id.' approved by override, but it is still not checkout-ready.';
            }

            return ['ok' => true, 'already' => false, 'submission' => $fresh, 'message' => $message];
        });
    }

    /**
     * @return array{ok:bool, submission:?ContentSubmission, message:string}
     */
    public function revertAdminOverride(ContentModerationLog $log, User $admin): array
    {
        if (! $log->admin_override) {
            return ['ok' => false, 'submission' => null, 'message' => 'This log is not an admin override.'];
        }

        $submission = $this->submissionForLog($log);
        if ($submission && (int) $submission->moderation_log_id !== (int) $log->id) {
            return [
                'ok' => false,
                'submission' => $submission,
                'message' => 'This override is no longer the current decision. Open the latest scan.',
            ];
        }

        return DB::transaction(function () use ($log, $admin, $submission) {
            if ($submission) {
                $submission = ContentSubmission::query()->whereKey($submission->id)->lockForUpdate()->first() ?? $submission;
                if ((int) $submission->moderation_log_id !== (int) $log->id) {
                    return [
                        'ok' => false,
                        'submission' => $submission,
                        'message' => 'This override is no longer the current decision. Open the latest scan.',
                    ];
                }
            }

            $signals = is_array($log->signals) ? $log->signals : [];
            $signals['reverted'] = true;
            $signals['reverted_by'] = $admin->id;
            unset($signals['override_fingerprint']);

            $log->update([
                'passed' => false,
                'status' => ContentModerationLog::STATUS_REJECTED,
                'admin_override' => false,
                'signals' => $signals,
                'admin_notes' => trim((string) $log->admin_notes."\nReverted by ".$admin->email),
            ]);

            if ($submission && (filled($submission->extracted_text) || filled($submission->preview_html))) {
                app(ContentUploadService::class)->reEvaluateSubmission($submission, true);
            } elseif ($submission) {
                $report = is_array($submission->evaluation_report) ? $submission->evaluation_report : [];
                $report['summary'] = 'Override reverted. Re-upload the article to re-check it.';
                $report['passed_compliance'] = false;
                unset($report['admin_override'], $report['admin_override_notes'], $report['admin_override_decision']);
                $submission->update([
                    'moderation_status' => ContentSubmission::STATUS_REJECTED,
                    'evaluation_status' => 'rejected',
                    'evaluation_report' => $report,
                ]);
            }

            try {
                ActivityLogger::log(
                    'moderation.override_reverted',
                    ($admin->name ?: $admin->email).' reverted moderation override #'.$log->id,
                    $submission,
                    [
                        'log_id' => $log->id,
                        'submission_id' => $submission?->id,
                    ],
                    $submission?->title ?: $submission?->original_filename
                );
            } catch (\Throwable) {
            }

            return [
                'ok' => true,
                'submission' => $submission?->fresh(),
                'message' => 'Override reverted. The article was re-checked against current policy.',
            ];
        });
    }

    /**
     * @return array{ok:bool, log:?ContentModerationLog, submission:?ContentSubmission, message:string}
     */
    public function rescanStaffLog(ContentModerationLog $log, User $admin): array
    {
        if ($log->status !== ContentModerationLog::STATUS_ERROR) {
            return ['ok' => false, 'log' => $log, 'submission' => $this->submissionForLog($log), 'message' => 'Only failed scans can be re-run from here.'];
        }

        $submission = $this->submissionForLog($log);
        if ($submission) {
            if ($submission->isArchived()) {
                return ['ok' => false, 'log' => $log, 'submission' => $submission, 'message' => 'Archived articles cannot be re-scanned. Restore the article first.'];
            }
            if (! filled($submission->extracted_text) && ! filled($submission->preview_html)) {
                return ['ok' => false, 'log' => $log, 'submission' => $submission, 'message' => 'This article has no text to re-scan.'];
            }

            $result = $this->scanExtractedContent(
                text: $this->scanPolicyTextFromSubmission($submission),
                html: (string) ($submission->preview_html ?? ''),
                sourceLabel: 'upload:'.$submission->id,
                user: $submission->user,
                title: $this->scanTitle($submission),
                links: $this->linksFromSubmission($submission),
                contentSubmissionId: (int) $submission->id,
            );
            $newLog = $result['log'] instanceof ContentModerationLog ? $result['log'] : null;
            $newStatus = $result['passed']
                ? ContentSubmission::STATUS_APPROVED
                : (($result['status'] ?? '') === 'error'
                    ? ContentSubmission::STATUS_ERROR
                    : ContentSubmission::STATUS_REJECTED);
            $fields = [
                'moderation_status' => $newStatus,
                'moderation_log_id' => $newLog?->id,
                'scan_token' => $result['scan_token'] ?? $submission->scan_token,
            ];
            if ($this->checkoutShouldSyncEvaluation($submission, $newStatus, $result)) {
                $fields = array_merge($fields, $this->evaluationFieldsFromScan($submission, $result));
            }
            $submission->update($fields);

            return [
                'ok' => true,
                'log' => $newLog,
                'submission' => $submission->fresh(),
                'message' => $newLog
                    ? 'Re-scan finished ('.$newLog->status.').'
                    : 'Re-scan finished.',
            ];
        }

        $url = trim((string) $log->document_url);
        if (! preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'log' => $log, 'submission' => null, 'message' => 'This failed scan has no article or public URL to fetch.'];
        }

        $result = $this->scan($url, $log->user, true);
        $newLog = $result['log'] instanceof ContentModerationLog ? $result['log'] : null;

        return [
            'ok' => (bool) $newLog,
            'log' => $newLog,
            'submission' => null,
            'message' => $newLog
                ? 'Re-scan finished ('.$newLog->status.').'
                : (string) ($result['user_message'] ?? 'Could not re-scan that URL.'),
        ];
    }

    protected function overrideAlreadyApplies(
        ContentModerationLog $log,
        ?ContentSubmission $submission,
        string $notes,
        string $decision,
    ): bool {
        if (! $log->admin_override) {
            return false;
        }

        $approved = $decision === ContentSubmission::STATUS_APPROVED;
        if ((bool) $log->passed !== $approved) {
            return false;
        }

        if (trim((string) $log->admin_notes) !== trim($notes)) {
            return false;
        }

        return ! $submission || $this->overrideFingerprintState($log, $submission) !== 'mismatch';
    }

    /**
     * @return 'match'|'missing'|'mismatch'
     */
    protected function overrideFingerprintState(ContentModerationLog $log, ?ContentSubmission $submission): string
    {
        if (! $submission) {
            return 'match';
        }

        $stored = $log->signals['override_fingerprint'] ?? null;
        if (! is_string($stored) || $stored === '') {
            return 'missing';
        }

        return hash_equals($stored, $this->contentFingerprint($submission)) ? 'match' : 'mismatch';
    }

    protected function overrideFingerprintMatches(ContentModerationLog $log, ContentSubmission $submission): bool
    {
        return $this->overrideFingerprintState($log, $submission) === 'match';
    }

    protected function currentOrNewLogForSubmission(ContentSubmission $submission): ContentModerationLog
    {
        if ($submission->moderation_log_id) {
            $current = ContentModerationLog::query()->whereKey($submission->moderation_log_id)->lockForUpdate()->first();
            if ($current) {
                return $current;
            }
        }

        return ContentModerationLog::create([
            'user_id' => $submission->user_id,
            'content_submission_id' => $submission->id,
            'document_url' => 'upload:'.$submission->id,
            'status' => ContentModerationLog::STATUS_REJECTED,
            'passed' => false,
            'scan_token' => $submission->scan_token ?: Str::random(40),
            'word_count' => str_word_count((string) $submission->extracted_text),
            'signals' => ['staff_override' => true],
        ]);
    }

    protected function stampOverride(
        ContentModerationLog $log,
        ?ContentSubmission $submission,
        User $admin,
        string $notes,
        string $decision,
    ): void {
        $approved = $decision === ContentSubmission::STATUS_APPROVED;
        $signals = is_array($log->signals) ? $log->signals : [];
        unset($signals['moderation_disabled']);
        if ($submission) {
            $signals['override_fingerprint'] = $this->contentFingerprint($submission);
        }
        $signals['staff_decision'] = $decision;
        $signals['staff_override'] = true;

        $log->update([
            'passed' => $approved,
            'status' => $approved
                ? ContentModerationLog::STATUS_APPROVED
                : ContentModerationLog::STATUS_REJECTED,
            'admin_override' => true,
            'overridden_by' => $admin->id,
            'overridden_at' => now(),
            'admin_notes' => $notes,
            'signals' => $signals,
            'content_submission_id' => $log->content_submission_id ?: $submission?->id,
        ]);

        if (! $submission) {
            return;
        }

        $submission->update(array_merge(
            $this->evaluationReportForOverride($submission, $notes, $decision),
            [
                'moderation_status' => $decision,
                'evaluation_status' => $approved ? 'approved' : 'rejected',
                'evaluated_at' => now(),
                'moderation_log_id' => $log->id,
                'scan_token' => $log->scan_token,
            ]
        ));
    }

    protected function logOverrideActivity(
        User $admin,
        ContentModerationLog $log,
        ?ContentSubmission $submission,
        string $notes,
        string $decision,
    ): void {
        try {
            ActivityLogger::log(
                'moderation.overridden',
                ($admin->name ?: $admin->email).' '.$decision.' moderation log #'.$log->id,
                $submission,
                [
                    'log_id' => $log->id,
                    'submission_id' => $submission?->id,
                    'decision' => $decision,
                    'notes' => $notes,
                ],
                $submission?->title ?: $submission?->original_filename
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{evaluation_report: array<string, mixed>}
     */
    protected function evaluationReportForOverride(ContentSubmission $submission, string $notes, string $decision): array
    {
        $report = is_array($submission->evaluation_report) ? $submission->evaluation_report : [];
        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        $approved = $decision === ContentSubmission::STATUS_APPROVED;

        if ($approved) {
            foreach ($checks as $i => $check) {
                if (! is_array($check) || strtolower((string) ($check['status'] ?? '')) !== 'fail') {
                    continue;
                }
                $checks[$i]['status'] = 'pass';
                $detail = trim((string) ($check['detail'] ?? $check['label'] ?? 'Restricted content'));
                $checks[$i]['detail'] = 'Cleared by admin override: '.$detail;
            }
            $report['passed_compliance'] = true;
            $report['summary'] = 'Approved by admin override.';
            $report['matched_terms'] = [];
            $report['blocked_urls'] = [];
        } else {
            $replaced = false;
            foreach ($checks as $i => $check) {
                if (! is_array($check) || ($check['key'] ?? '') !== 'admin_override') {
                    continue;
                }
                $checks[$i] = [
                    'key' => 'admin_override',
                    'label' => 'Staff decision',
                    'status' => 'fail',
                    'detail' => 'Rejected by admin override: '.$notes,
                ];
                $replaced = true;
            }
            if (! $replaced) {
                $checks[] = [
                    'key' => 'admin_override',
                    'label' => 'Staff decision',
                    'status' => 'fail',
                    'detail' => 'Rejected by admin override: '.$notes,
                ];
            }
            $report['passed_compliance'] = false;
            $report['summary'] = 'Rejected by admin override.';
        }

        $report['checks'] = $checks;
        $report['admin_override'] = true;
        $report['admin_override_notes'] = $notes;
        $report['admin_override_decision'] = $decision;

        return ['evaluation_report' => $report];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function checkoutShouldSyncEvaluation(ContentSubmission $submission, string $newStatus, array $result): bool
    {
        $passed = (bool) ($result['passed'] ?? false);
        $error = ($result['status'] ?? '') === 'error';
        $expectedEval = $passed ? 'approved' : ($error ? 'error' : 'rejected');
        $report = is_array($submission->evaluation_report) ? $submission->evaluation_report : [];

        return (string) $submission->moderation_status !== $newStatus
            || (string) $submission->evaluation_status !== $expectedEval
            || ! empty($report['admin_override']);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{evaluation_status:string, evaluation_report:array<string, mixed>, evaluated_at:CarbonInterface}
     */
    protected function evaluationFieldsFromScan(ContentSubmission $submission, array $result): array
    {
        $report = is_array($submission->evaluation_report) ? $submission->evaluation_report : [];
        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        $passed = (bool) ($result['passed'] ?? false);
        $error = ($result['status'] ?? '') === 'error';
        $terms = is_array($result['matched_terms'] ?? null) ? array_values(array_map('strval', $result['matched_terms'])) : [];
        $urls = is_array($result['blocked_urls'] ?? null) ? array_values(array_map('strval', $result['blocked_urls'])) : [];

        unset($report['admin_override'], $report['admin_override_notes'], $report['admin_override_decision']);
        $checks = array_values(array_filter($checks, function ($check) {
            return ! is_array($check) || ($check['key'] ?? '') !== 'admin_override';
        }));

        if ($passed) {
            foreach ($checks as $i => $check) {
                if (! is_array($check) || strtolower((string) ($check['status'] ?? '')) !== 'fail') {
                    continue;
                }
                if (($check['key'] ?? '') !== 'restricted_content') {
                    continue;
                }
                $checks[$i]['status'] = 'pass';
                $checks[$i]['detail'] = 'Cleared on re-check.';
            }
            $report['passed_compliance'] = true;
            $report['summary'] = 'Your article was approved for publication. You can now select websites and place an order.';
            $report['matched_terms'] = [];
            $report['blocked_urls'] = [];
        } else {
            $message = (string) ($result['user_message'] ?? 'Please revise restricted content before ordering.');
            $log = $result['log'] instanceof ContentModerationLog ? $result['log'] : null;
            $qualityReport = is_array($log?->quality_report) ? $log->quality_report : [];
            $qualityBlocking = is_array($qualityReport['blocking_issues'] ?? null)
                ? $qualityReport['blocking_issues']
                : [];
            $qualityOnly = ! filled($log?->detected_category)
                && ($qualityBlocking !== [] || $log?->error_code === 'off_platform_contact');
            $detail = $urls !== []
                ? 'Blocked links: '.implode(', ', array_slice($urls, 0, 5))
                : ($terms !== []
                    ? 'Found: '.implode(', ', array_slice($terms, 0, 10))
                    : $message);
            $report['passed_compliance'] = $qualityOnly;
            if ($qualityOnly) {
                $report['passed_quality'] = false;
            }
            $report['summary'] = $message;
            $report['matched_terms'] = $terms;
            $report['blocked_urls'] = $urls;

            $updated = false;
            $topic = $this->categoryTopic($log?->detected_category);
            $checkKey = $qualityOnly ? 'quality_policy' : 'restricted_content';
            $checkLabel = $qualityOnly
                ? 'Article needs changes'
                : 'Restricted content ('.$topic.')';
            foreach ($checks as $i => $check) {
                if (! is_array($check) || ($check['key'] ?? '') !== $checkKey) {
                    continue;
                }
                $checks[$i]['status'] = 'fail';
                $checks[$i]['label'] = $checkLabel;
                $checks[$i]['detail'] = $detail;
                $updated = true;
            }
            if (! $updated && ! $error) {
                $checks[] = [
                    'key' => $checkKey,
                    'label' => $checkLabel,
                    'status' => 'fail',
                    'detail' => $detail,
                ];
            }
        }

        $report['checks'] = $checks;

        return [
            'evaluation_status' => $passed ? 'approved' : ($error ? 'error' : 'rejected'),
            'evaluation_report' => $report,
            'evaluated_at' => now(),
        ];
    }
}
