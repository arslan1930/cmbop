<?php

namespace App\Services\ContentUpload;

use App\Models\ContentModerationLog;
use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentModeration\ContentQualityAnalyzer;
use App\Services\OrderChatContactGuard;
use App\Support\UserMessages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Article evaluation: gambling/adult, language, off-platform contact,
 * placeholder text, URL shorteners, and excess outbound links are blocking.
 * Uniqueness and readability scores stay advisory.
 */
class ArticleEvaluationService
{
    /** Cap uniqueness shingles so a 10 MB article cannot OOM the request. */
    private const UNIQUENESS_WORD_LIMIT = 15000;

    /** SQL truncate for corpus extracted_text (~15k words). */
    private const UNIQUENESS_TEXT_CHARS = 100000;

    private ArticleLanguageGuard $languageGuard;

    public function __construct(
        private ContentModerationService $moderation,
        private ContentQualityAnalyzer $quality,
        ?ArticleLanguageGuard $languageGuard = null,
    ) {
        $this->languageGuard = $languageGuard ?? new ArticleLanguageGuard;
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadConfig(): array
    {
        $base = config('content_upload', []);
        $override = ContentModerationSetting::getValue('upload_config', []) ?: [];

        return (! is_array($override) || $override === [])
            ? $base
            : array_replace_recursive($base, $override);
    }

    /**
     * @return array{
     *   approved:bool,
     *   moderation_status:string,
     *   evaluation_status:string,
     *   uniqueness_score:int,
     *   quality_score:int,
     *   report:array,
     *   title:string,
     *   message:string,
     *   log:?ContentModerationLog,
     *   highlighted_html?:?string,
     *   matched_terms?:array<int,string>
     * }
     */
    public function evaluate(ContentSubmission $submission, ?User $user = null): array
    {
        try {
            return $this->attachFingerprint($submission, $this->evaluateUnchecked($submission, $user));
        } catch (\Throwable $e) {
            Log::error('Content evaluation failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_ERROR,
                'evaluation_status' => 'error',
                'uniqueness_score' => 0,
                'quality_score' => 0,
                'report' => [
                    'summary' => 'Automatic review could not finish. Please try saving again.',
                    'error' => $e->getMessage(),
                ],
                'title' => 'Review failed',
                'message' => 'Automatic review could not finish. Please try saving again.',
                'log' => null,
                'highlighted_html' => null,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }
    }

    /**
     * @return array{
     *   approved:bool,
     *   moderation_status:string,
     *   evaluation_status:string,
     *   uniqueness_score:int,
     *   quality_score:int,
     *   report:array,
     *   title:string,
     *   message:string,
     *   log:?ContentModerationLog,
     *   highlighted_html?:?string,
     *   matched_terms?:array<int,string>
     * }
     */
    private function evaluateUnchecked(ContentSubmission $submission, ?User $user = null): array
    {
        $cfg = $this->uploadConfig();
        $evalCfg = $cfg['evaluation'] ?? [];
        $minUniqueness = max(0, min(100, (int) ($evalCfg['min_uniqueness'] ?? 50)));
        $minQuality = max(0, min(100, (int) ($evalCfg['min_quality'] ?? 50)));

        $text = $this->moderation->scanTextFromSubmission($submission);
        $html = ArticlePreviewHtml::normalize((string) ($submission->preview_html ?? ''));
        $title = $this->moderation->scanTitle($submission);

        if ($this->moderation->storedFileUnreadable($submission)) {
            $message = 'The stored Word file could not be re-checked. Please re-upload the article.';
            $report = [
                'word_count' => str_word_count($text),
                'summary' => $message,
                'fix_hints' => [$message],
                'matched_terms' => [],
                'checks' => [[
                    'key' => 'stored_file',
                    'label' => 'Stored Word file',
                    'status' => 'fail',
                    'detail' => $message,
                ]],
                'passed_compliance' => false,
                'passed_language' => true,
                'passed_uniqueness' => true,
                'passed_quality' => true,
            ];

            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_ERROR,
                'evaluation_status' => 'error',
                'uniqueness_score' => 0,
                'quality_score' => 0,
                'report' => $report,
                'title' => 'Unable to Check Article',
                'message' => $message,
                'log' => null,
                'highlighted_html' => null,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        // 0) Language vs selection — hard-block only on high-confidence mismatch;
        // mid-confidence / mixed copy is advisory (warn) and does not reject.
        $languageCheck = $this->languageGuard->assertMatches(
            $text,
            (string) ($submission->language ?? '')
        );
        if (! $languageCheck['ok']) {
            $message = (string) $languageCheck['message'];
            $report = [
                'word_count' => str_word_count($text),
                'summary' => $message,
                'language' => $languageCheck,
                'fix_hints' => [
                    $message,
                    'Tip: English articles must use the English language selection (with a matching country).',
                ],
                'matched_terms' => [],
                'checks' => [[
                    'key' => 'language_match',
                    'label' => 'Article language',
                    'status' => 'fail',
                    'detail' => $message,
                ]],
                'passed_compliance' => true,
                'passed_language' => false,
                'passed_uniqueness' => true,
                'passed_quality' => true,
            ];

            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_REJECTED,
                'evaluation_status' => 'rejected',
                'uniqueness_score' => 0,
                'quality_score' => 0,
                'report' => $report,
                'title' => 'Language mismatch',
                'message' => $message,
                'log' => null,
                'highlighted_html' => null,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        $imageCount = (new ArticleHtmlSanitizer)->countImages($html);
        if ($imageCount > ContentUploadService::IMAGE_MAX_PER_ARTICLE) {
            $message = ContentUploadService::tooManyImagesMessage();
            $report = [
                'word_count' => str_word_count($text),
                'summary' => $message,
                'language' => $languageCheck,
                'fix_hints' => [$message],
                'matched_terms' => [],
                'checks' => [[
                    'key' => 'image_count',
                    'label' => 'Image limit',
                    'status' => 'fail',
                    'detail' => $message,
                ]],
                'passed_compliance' => true,
                'passed_language' => true,
                'passed_uniqueness' => true,
                'passed_quality' => true,
            ];

            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_REJECTED,
                'evaluation_status' => 'rejected',
                'uniqueness_score' => 0,
                'quality_score' => 0,
                'report' => $report,
                'title' => 'Too many images',
                'message' => $message,
                'log' => null,
                'highlighted_html' => null,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        $linkUrls = $this->moderation->linksFromSubmission($submission);
        $contactGuard = app(OrderChatContactGuard::class);
        $contactHaystack = trim($title."\n".$text."\n".$html."\n".implode("\n", $linkUrls));
        if ($contactGuard->isBlocked($contactHaystack, OrderChatContactGuard::MODE_CONTENT)) {
            $message = UserMessages::get('moderation.contact_article');
            $report = [
                'word_count' => str_word_count($text),
                'summary' => $message,
                'language' => $languageCheck,
                'fix_hints' => [$message],
                'matched_terms' => [],
                'checks' => [[
                    'key' => 'off_platform_contact',
                    'label' => 'Off-platform contact',
                    'status' => 'fail',
                    'detail' => $message,
                ]],
                'passed_compliance' => true,
                'passed_language' => true,
                'passed_uniqueness' => true,
                'passed_quality' => false,
            ];

            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_REJECTED,
                'evaluation_status' => 'rejected',
                'uniqueness_score' => 0,
                'quality_score' => 0,
                'report' => $report,
                'title' => 'Article needs changes',
                'message' => $message,
                'log' => null,
                'highlighted_html' => null,
                'matched_terms' => [],
                'blocked_urls' => [],
            ];
        }

        // 1) Policy compliance (casino / gambling / betting / adult) — includes cloaked hrefs,
        // stored .docx parts the publisher downloads, backlink anchors, and image alt text.
        $scan = $this->moderation->scanExtractedContent(
            text: $this->moderation->scanPolicyTextFromSubmission($submission),
            html: $html,
            sourceLabel: 'upload:'.$submission->id,
            user: $user ?? $submission->user,
            title: $title,
            links: $linkUrls,
            contentSubmissionId: (int) $submission->id,
        );

        // 2) Quality heuristics — placeholder, shorteners, and excess links can block.
        $qualityCfg = $this->moderation->effectiveConfig()['quality'] ?? config('content_moderation.quality', []);
        $quality = $this->quality->analyze(
            $text,
            $html,
            $linkUrls,
            is_array($qualityCfg) ? $qualityCfg : []
        );

        // 3) Uniqueness vs platform corpus (advisory)
        $uniqueness = $this->scoreUniqueness($text, $submission->id, (int) ($evalCfg['corpus_limit'] ?? 200));

        // 4) Optional AI narrative enrichment
        $aiNotes = $this->optionalAiNotes($text, $title, $evalCfg);

        $qualityScore = $this->composeQualityScore($quality, $uniqueness['internal_originality'] ?? 100);
        $uniquenessScore = (int) $uniqueness['score'];

        $checks = $quality['checks'] ?? [];
        $languageSeverity = (string) ($languageCheck['severity'] ?? 'pass');
        if ($languageSeverity === 'warn' && filled($languageCheck['message'] ?? null)) {
            $checks[] = [
                'key' => 'language_match',
                'label' => 'Article language',
                'status' => 'warn',
                'detail' => (string) $languageCheck['message'].' (advisory — does not block approval)',
            ];
        } else {
            $checks[] = [
                'key' => 'language_match',
                'label' => 'Article language',
                'status' => 'pass',
                'detail' => 'Matches selected language ('.strtoupper((string) $submission->language).')',
            ];
        }
        $checks[] = [
            'key' => 'uniqueness',
            'label' => 'Uniqueness',
            'status' => $uniquenessScore >= $minUniqueness ? 'pass' : 'warn',
            'detail' => $uniquenessScore.'% unique (advisory — does not block approval)',
        ];
        $checks[] = [
            'key' => 'overall_quality',
            'label' => 'Overall Quality',
            'status' => $qualityScore >= $minQuality ? 'pass' : 'warn',
            'detail' => $qualityScore.'% quality score (advisory — does not block approval)',
        ];

        $matchedTerms = array_values(array_unique(array_map(
            'strval',
            $scan['matched_terms'] ?? ($scan['report']['matched_terms'] ?? [])
        )));
        $blockedUrls = array_values(array_unique(array_map(
            'strval',
            $scan['blocked_urls'] ?? ($scan['report']['blocked_urls'] ?? [])
        )));

        $report = [
            'word_count' => $quality['word_count'] ?? str_word_count($text),
            'uniqueness_score' => $uniquenessScore,
            'quality_score' => $qualityScore,
            'min_uniqueness' => $minUniqueness,
            'min_quality' => $minQuality,
            'checks' => $checks,
            'uniqueness' => $uniqueness,
            'compliance' => $scan['report'] ?? [],
            'language' => $languageCheck,
            'ai_notes' => $aiNotes,
            'matched_terms' => $matchedTerms,
            'blocked_urls' => $blockedUrls,
            'fix_hints' => $scan['report']['fix_hints'] ?? [],
            'passed_compliance' => (bool) $scan['passed'],
            'passed_language' => true,
            'passed_uniqueness' => $uniquenessScore >= $minUniqueness,
            'passed_quality' => $qualityScore >= $minQuality,
        ];

        if (! $scan['passed']) {
            $status = ($scan['status'] ?? '') === 'error'
                ? ContentSubmission::STATUS_ERROR
                : ContentSubmission::STATUS_REJECTED;
            $message = $scan['user_message']
                ?: ($cfg['help']['compliance_reject'] ?? 'Please revise restricted content and resubmit.');
            $report['summary'] = $message;
            $category = $scan['log']?->detected_category;
            if (! $category && ! empty($quality['blocking_issues'])) {
                $message = $this->qualityRejectionMessage($quality);
                $report['summary'] = $message;
                $report['passed_quality'] = false;
                $report['checks'][] = [
                    'key' => 'quality_policy',
                    'label' => 'Quality policy',
                    'status' => 'fail',
                    'detail' => $message,
                ];
            } else {
                $policyLabel = 'Restricted content ('.$this->moderation->categoryTopic($category).')';
                $report['checks'][] = [
                    'key' => 'restricted_content',
                    'label' => $policyLabel,
                    'status' => 'fail',
                    'detail' => $blockedUrls !== []
                        ? 'Blocked links: '.implode(', ', array_slice($blockedUrls, 0, 5))
                        : ($matchedTerms !== []
                            ? 'Found: '.implode(', ', array_slice($matchedTerms, 0, 10))
                            : 'Restricted content detected'),
                ];
            }

            $highlighted = $html;
            $canHighlight = mb_strlen($html) <= 200000;
            if ($canHighlight && $matchedTerms !== []) {
                $highlighted = ArticlePreviewHtml::highlightTerms($highlighted, $matchedTerms);
            }
            if ($canHighlight && $blockedUrls !== []) {
                $highlighted = ArticlePreviewHtml::highlightBlockedLinks($highlighted, $blockedUrls);
            }

            return [
                'approved' => false,
                'moderation_status' => $status,
                'evaluation_status' => $status === ContentSubmission::STATUS_ERROR ? 'error' : 'rejected',
                'uniqueness_score' => $uniquenessScore,
                'quality_score' => $qualityScore,
                'report' => $report,
                'title' => $scan['user_title'] ?? 'Article needs changes',
                'message' => $message,
                'log' => $scan['log'] ?? null,
                'highlighted_html' => $highlighted,
                'matched_terms' => $matchedTerms,
                'blocked_urls' => $blockedUrls,
            ];
        }

        $qualityBlocks = ! empty($quality['blocking_issues']);
        if ($qualityBlocks) {
            $message = $this->qualityRejectionMessage($quality);
            $report['summary'] = $message;
            $report['passed_quality'] = false;
            $report['fix_hints'] = array_values(array_unique(array_merge(
                $report['fix_hints'] ?? [],
                [$message]
            )));

            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_REJECTED,
                'evaluation_status' => 'rejected',
                'uniqueness_score' => $uniquenessScore,
                'quality_score' => $qualityScore,
                'report' => $report,
                'title' => 'Article needs changes',
                'message' => $message,
                'log' => $scan['log'] ?? null,
                'highlighted_html' => null,
                'matched_terms' => [],
                'blocked_urls' => array_values(array_unique(array_map(
                    'strval',
                    $quality['shortener_urls'] ?? []
                ))),
            ];
        }

        $message = 'Your article was approved for publication. You can now select websites and place an order.';
        $report['summary'] = $message;

        return [
            'approved' => true,
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'evaluation_status' => 'approved',
            'uniqueness_score' => $uniquenessScore,
            'quality_score' => $qualityScore,
            'report' => $report,
            'title' => 'Article approved for publication',
            'message' => $message,
            'log' => $scan['log'] ?? null,
            'highlighted_html' => null,
            'matched_terms' => [],
            'blocked_urls' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $quality
     */
    private function qualityRejectionMessage(array $quality): string
    {
        $blocking = $quality['blocking_issues'] ?? [];
        if (in_array('url_shortener', $blocking, true)) {
            return UserMessages::get('moderation.quality_shortener');
        }
        if (in_array('external_links', $blocking, true)) {
            $max = (int) (config('content_moderation.quality.max_external_links') ?? 15);

            return UserMessages::get('moderation.quality_links', ['max' => $max]);
        }
        if (in_array('placeholder', $blocking, true)) {
            return UserMessages::get('moderation.quality_placeholder');
        }

        return UserMessages::get('moderation.quality');
    }

    /**
     * @return array{score:int, max_similarity:float, matched_submission_id:?int, internal_originality:int, method:string}
     */
    public function scoreUniqueness(string $text, ?int $excludeId = null, int $corpusLimit = 200): array
    {
        $shingles = $this->shingles($text, 3);
        $internal = $this->internalOriginality(
            mb_strlen($text) > self::UNIQUENESS_TEXT_CHARS
                ? mb_substr($text, 0, self::UNIQUENESS_TEXT_CHARS)
                : $text
        );

        if ($shingles === []) {
            return [
                'score' => 0,
                'max_similarity' => 1.0,
                'matched_submission_id' => null,
                'internal_originality' => $internal,
                'method' => 'shingle_jaccard',
            ];
        }

        $query = ContentSubmission::query()
            ->select(['id'])
            ->selectRaw('substr(extracted_text, 1, ?) as extracted_text', [self::UNIQUENESS_TEXT_CHARS])
            ->whereNotNull('extracted_text')
            ->where('extracted_text', '!=', '')
            ->whereIn('moderation_status', [
                ContentSubmission::STATUS_APPROVED,
                ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                ContentSubmission::STATUS_REJECTED,
                ContentSubmission::STATUS_PROCESSING,
            ])
            ->latest('id')
            ->limit($corpusLimit);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $maxSim = 0.0;
        $matchedId = null;

        foreach ($query->get() as $row) {
            $other = $this->shingles((string) $row->extracted_text, 3);
            if ($other === []) {
                continue;
            }
            $sim = $this->jaccard($shingles, $other);
            if ($sim > $maxSim) {
                $maxSim = $sim;
                $matchedId = (int) $row->id;
            }
        }

        $corpusUnique = (int) round(max(0, min(100, (1 - $maxSim) * 100)));
        $score = (int) round(($corpusUnique * 0.75) + ($internal * 0.25));

        return [
            'score' => $score,
            'max_similarity' => round($maxSim, 4),
            'matched_submission_id' => $matchedId,
            'internal_originality' => $internal,
            'method' => 'shingle_jaccard',
            'corpus_unique' => $corpusUnique,
        ];
    }

    /**
     * @param  array<string, mixed>  $quality
     */
    protected function composeQualityScore(array $quality, int $internalOriginality): int
    {
        $base = (int) ($quality['score'] ?? 60);
        $wordCount = (int) ($quality['word_count'] ?? 0);

        if ($wordCount >= 500) {
            $base = min(100, $base + 5);
        } elseif ($wordCount < 200) {
            $base = max(0, $base - 25);
        } elseif ($wordCount < 300) {
            $base = max(0, $base - 15);
        }

        return (int) max(0, min(100, round(($base * 0.7) + ($internalOriginality * 0.3))));
    }

    /**
     * @return array<int, string>
     */
    protected function shingles(string $text, int $n = 3): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? '';
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > self::UNIQUENESS_WORD_LIMIT) {
            $words = array_slice($words, 0, self::UNIQUENESS_WORD_LIMIT);
        }
        if (count($words) < $n) {
            return $words === [] ? [] : [implode(' ', $words)];
        }

        $out = [];
        $limit = count($words) - $n + 1;
        for ($i = 0; $i < $limit; $i++) {
            $out[] = implode(' ', array_slice($words, $i, $n));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    protected function jaccard(array $a, array $b): float
    {
        $setA = array_fill_keys($a, true);
        $setB = array_fill_keys($b, true);
        $intersect = 0;
        foreach ($setA as $k => $_) {
            if (isset($setB[$k])) {
                $intersect++;
            }
        }
        $union = count($setA) + count($setB) - $intersect;

        return $union > 0 ? $intersect / $union : 0.0;
    }

    protected function internalOriginality(string $text): int
    {
        $paragraphs = preg_split("/\n\s*\n/", trim($text)) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs)));
        if (count($paragraphs) <= 1) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
            $sentences = array_values(array_filter(array_map('trim', $sentences)));
            if (count($sentences) <= 1) {
                return 80;
            }
            $unique = count(array_unique(array_map('mb_strtolower', $sentences)));

            return (int) round(($unique / max(1, count($sentences))) * 100);
        }

        $normalized = array_map(fn ($p) => mb_strtolower(preg_replace('/\s+/', ' ', $p) ?? $p), $paragraphs);
        $unique = count(array_unique($normalized));

        return (int) round(($unique / max(1, count($normalized))) * 100);
    }

    /**
     * @param  array<string, mixed>  $evalCfg
     * @return array<string, mixed>|null
     */
    protected function optionalAiNotes(string $text, string $title, array $evalCfg): ?array
    {
        $key = $evalCfg['openai_api_key'] ?? null;
        if (! $key || ! is_string($key) || strlen($key) < 10) {
            return null;
        }

        try {
            $excerpt = mb_substr($text, 0, 6000);
            $response = Http::withToken($key)
                ->timeout(25)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $evalCfg['openai_model'] ?? 'gpt-4o-mini',
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an article quality reviewer for a guest-post marketplace. Reply with JSON only: {"summary":"...","strengths":["..."],"improvements":["..."],"estimated_uniqueness_note":"..."}',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Title: {$title}\n\nArticle:\n{$excerpt}",
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (! $response->successful()) {
                return null;
            }

            $content = data_get($response->json(), 'choices.0.message.content');
            $decoded = is_string($content) ? json_decode($content, true) : null;

            return is_array($decoded) ? $decoded : ['raw' => $content];
        } catch (\Throwable $e) {
            Log::info('Optional AI content notes skipped', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function attachFingerprint(ContentSubmission $submission, array $result): array
    {
        try {
            $fingerprint = $this->moderation->contentFingerprint($submission);
        } catch (\Throwable) {
            return $result;
        }

        $result['content_fingerprint'] = $fingerprint;
        $report = is_array($result['report'] ?? null) ? $result['report'] : [];
        $report['content_fingerprint'] = $fingerprint;

        $duplicate = $this->findDuplicateSubmission($submission, $fingerprint);
        if ($duplicate) {
            $result['duplicate_of_id'] = (int) $duplicate->id;
            $report['duplicate_of_id'] = (int) $duplicate->id;
            $hints = is_array($report['fix_hints'] ?? null) ? $report['fix_hints'] : [];
            $hints[] = 'Duplicate of library article #'.$duplicate->id.' (“'.$duplicate->title.'”).';
            $report['fix_hints'] = $hints;
            $report['checks'] = is_array($report['checks'] ?? null) ? $report['checks'] : [];
            $report['checks'][] = [
                'key' => 'duplicate_upload',
                'label' => 'Duplicate article',
                'status' => 'warn',
                'detail' => 'Same content as article #'.$duplicate->id,
            ];
        }

        $result['report'] = $report;

        return $result;
    }

    private function findDuplicateSubmission(ContentSubmission $submission, string $fingerprint): ?ContentSubmission
    {
        if ($fingerprint === '' || ! $submission->id) {
            return null;
        }

        try {
            $query = ContentSubmission::query()
                ->where('id', '!=', $submission->id)
                ->orderByDesc('id');
            if (Schema::hasColumn('content_submissions', 'archived_at')) {
                $query->whereNull('archived_at');
            }
            if (Schema::hasColumn('content_submissions', 'content_fingerprint')) {
                $match = (clone $query)->where('content_fingerprint', $fingerprint)->first();
                if ($match) {
                    return $match;
                }
            }

            return $query
                ->where('evaluation_report', 'like', '%'.$fingerprint.'%')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
