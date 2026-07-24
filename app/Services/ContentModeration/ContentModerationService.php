<?php

namespace App\Services\ContentModeration;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ContentUpload\ContentUploadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ContentModerationService
{
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

        if (! $this->isEnabled()) {
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
        // Quality is advisory unless explicitly configured to block.
        $qualityBlocks = (bool) (($cfg['quality']['block_on_quality_failure'] ?? false)
            && ! empty($quality['blocking_issues']));
        $passed = ! $restrictedFail && ! $qualityBlocks;

        $matchedTerms = $score['matched_terms'] ?? [];
        $blockedUrls = $score['blocked_urls'] ?? [];
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
        if (! $this->isEnabled()) {
            return ['ok' => true, 'failures' => []];
        }

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

            // Full re-check at order time (quiet — no duplicate evaluation emails).
            $result = app(ContentUploadService::class)
                ->reEvaluateSubmission($submission->fresh(), notify: false);

            if (! ($result['approved'] ?? false)) {
                $failures[] = [
                    'url' => 'upload:'.$submission->id,
                    'title' => $result['title'] ?: 'Article needs changes',
                    'message' => config('content_upload.help.compliance_reject')
                        ?: ($result['message'] ?: 'Please revise restricted content before ordering.'),
                    'report' => $result['report'] ?? [],
                ];
            }
        }

        return ['ok' => $failures === [], 'failures' => $failures];
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
        $category = $log?->detected_category;
        $blockedUrls = $log ? $this->blockedUrlsFromLog($log) : [];
        $terms = $log ? $this->matchedTermsFromLog($log) : [];

        if ($blockedUrls !== []) {
            $shown = implode(', ', array_slice($blockedUrls, 0, 3));
            $topic = $category === 'adult'
                ? 'adult / 18+ / porn'
                : 'casino / poker / gambling / betting';

            return "This article links to restricted {$topic} sites ({$shown}). "
                .'Remove or replace those links (even if the anchor text looks harmless) and resubmit.';
        }

        if ($category === 'adult' && $terms !== []) {
            $list = implode(', ', array_slice($terms, 0, 8));

            return 'This article contains adult / 18+ / porn content we do not allow: '
                .$list
                .'. Remove those topics and resubmit.';
        }

        if ($terms !== []) {
            $shown = array_slice($terms, 0, 8);
            $list = implode(', ', $shown);

            return 'This article mentions casino / poker / gambling / betting terms we do not allow: '
                .$list
                .'. Remove those topics and resubmit.';
        }

        return (string) (config('content_upload.help.compliance_reject')
            ?: 'This article contains restricted casino, betting, poker, or adult content. Please revise and resubmit.');
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
        $policyLabel = $category === 'adult'
            ? 'Restricted content (adult / 18+ / porn)'
            : 'Restricted content (casino / gambling / betting)';

        $publicChecks = [];
        foreach ($checks as $check) {
            $publicChecks[] = [
                'label' => $check['label'] ?? 'Check',
                'status' => $check['status'] ?? 'warn',
                'detail' => $check['detail'] ?? '',
            ];
        }

        $fixHints = [];
        if ($log->status === ContentModerationLog::STATUS_REJECTED && ! $log->passed) {
            if ($blockedUrls !== []) {
                $detail = 'Remove or replace blocked links: '.implode(', ', array_slice($blockedUrls, 0, 5));
                foreach (array_slice($blockedUrls, 0, 5) as $url) {
                    $fixHints[] = 'Remove or replace blocked link: '.$url;
                }
            } elseif ($matchedTerms !== []) {
                $detail = 'Remove these terms: '.implode(', ', array_slice($matchedTerms, 0, 10));
            } else {
                $detail = 'Casino / betting / poker / adult content is not allowed';
            }
            $publicChecks[] = [
                'label' => $policyLabel,
                'status' => 'fail',
                'detail' => $detail,
            ];
            if ($matchedTerms !== []) {
                $fixHints[] = 'Remove or rewrite sections that mention: '.implode(', ', array_slice($matchedTerms, 0, 10));
            }
        } elseif ($log->passed) {
            $publicChecks[] = [
                'label' => 'Content policy',
                'status' => 'pass',
                'detail' => 'No restricted casino, betting, poker, or adult content detected',
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
        return [
            'total' => ContentModerationLog::query()->count(),
            'approved' => ContentModerationLog::query()->where('status', 'approved')->count(),
            'rejected' => ContentModerationLog::query()->where('status', 'rejected')->count(),
            'errors' => ContentModerationLog::query()->where('status', 'error')->count(),
            'today' => ContentModerationLog::query()->whereDate('created_at', today())->count(),
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

        if ($html !== '' && preg_match_all('/\bhref\s*=\s*(["\'])(.*?)\1/iu', $html, $matches)) {
            foreach ($matches[2] as $href) {
                $href = trim(html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
        return $this->linksFromSubmissionHtml(
            html: (string) ($submission->preview_html ?? ''),
            targetUrl: $submission->target_url ? (string) $submission->target_url : null,
            plainText: (string) ($submission->extracted_text ?? ''),
            extraLinks: $submission->detectedLinks(),
        );
    }
}
