<?php

namespace App\Services\ContentUpload;

use App\Models\ContentModerationSetting;
use App\Models\ContentSubmission;
use App\Models\User;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\ContentModeration\ContentQualityAnalyzer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Advanced article evaluation: uniqueness, quality, and compliance.
 * Uploads are always accepted; publication (ordering) requires approval.
 */
class ArticleEvaluationService
{
    public function __construct(
        private ContentModerationService $moderation,
        private ContentQualityAnalyzer $quality,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadConfig(): array
    {
        $base = config('content_upload', []);
        $override = ContentModerationSetting::getValue('upload_config', []) ?: [];

        return (!is_array($override) || $override === [])
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
     *   log:?\App\Models\ContentModerationLog
     * }
     */
    public function evaluate(ContentSubmission $submission, ?User $user = null): array
    {
        $cfg = $this->uploadConfig();
        $evalCfg = $cfg['evaluation'] ?? [];
        $minUniqueness = max(0, min(100, (int) ($evalCfg['min_uniqueness'] ?? 50)));
        $minQuality = max(0, min(100, (int) ($evalCfg['min_quality'] ?? 50)));

        $text = trim((string) $submission->extracted_text);
        $html = (string) ($submission->preview_html ?? '');
        $title = $submission->title
            ?: pathinfo((string) $submission->original_filename, PATHINFO_FILENAME)
            ?: 'Article';

        // 1) Policy compliance (casino / gambling / adult / etc.)
        $scan = $this->moderation->scanExtractedContent(
            text: $text,
            html: $html,
            sourceLabel: 'upload:' . $submission->id,
            user: $user ?? $submission->user,
            title: $title,
        );

        // 2) Quality heuristics
        $quality = $this->quality->analyze(
            $text,
            $html,
            [],
            array_merge(config('content_moderation.quality', []), ['block_on_quality_failure' => false])
        );

        // 3) Uniqueness vs platform corpus + internal originality
        $uniqueness = $this->scoreUniqueness($text, $submission->id, (int) ($evalCfg['corpus_limit'] ?? 200));

        // 4) Optional AI narrative enrichment
        $aiNotes = $this->optionalAiNotes($text, $title, $evalCfg);

        $qualityScore = $this->composeQualityScore($quality, $uniqueness['internal_originality'] ?? 100);
        $uniquenessScore = (int) $uniqueness['score'];

        $checks = $quality['checks'] ?? [];
        $checks[] = [
            'key' => 'uniqueness',
            'label' => 'Uniqueness',
            'status' => $uniquenessScore >= $minUniqueness ? 'pass' : 'fail',
            'detail' => $uniquenessScore . '% unique (minimum ' . $minUniqueness . '%)',
        ];
        $checks[] = [
            'key' => 'overall_quality',
            'label' => 'Overall Quality',
            'status' => $qualityScore >= $minQuality ? 'pass' : 'fail',
            'detail' => $qualityScore . '% quality score (minimum ' . $minQuality . '%)',
        ];

        $report = [
            'word_count' => $quality['word_count'] ?? str_word_count($text),
            'uniqueness_score' => $uniquenessScore,
            'quality_score' => $qualityScore,
            'min_uniqueness' => $minUniqueness,
            'min_quality' => $minQuality,
            'checks' => $checks,
            'uniqueness' => $uniqueness,
            'compliance' => $scan['report'] ?? [],
            'ai_notes' => $aiNotes,
            'passed_compliance' => (bool) $scan['passed'],
            'passed_uniqueness' => $uniquenessScore >= $minUniqueness,
            'passed_quality' => $qualityScore >= $minQuality,
        ];

        if (!$scan['passed']) {
            $status = ($scan['status'] ?? '') === 'error'
                ? ContentSubmission::STATUS_ERROR
                : ContentSubmission::STATUS_REJECTED;

            return [
                'approved' => false,
                'moderation_status' => $status,
                'evaluation_status' => $status === ContentSubmission::STATUS_ERROR ? 'error' : 'rejected',
                'uniqueness_score' => $uniquenessScore,
                'quality_score' => $qualityScore,
                'report' => $report,
                'title' => $scan['user_title'] ?? 'Article Cannot Be Accepted',
                'message' => $cfg['help']['compliance_reject'] ?? $scan['user_message'],
                'log' => $scan['log'] ?? null,
            ];
        }

        if ($uniquenessScore < $minUniqueness) {
            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                'evaluation_status' => 'needs_improvement',
                'uniqueness_score' => $uniquenessScore,
                'quality_score' => $qualityScore,
                'report' => $report,
                'title' => 'Improve uniqueness',
                'message' => $cfg['help']['uniqueness_reject']
                    ?? 'Uniqueness is below 50%. Please improve and resubmit.',
                'log' => $scan['log'] ?? null,
            ];
        }

        if ($qualityScore < $minQuality) {
            return [
                'approved' => false,
                'moderation_status' => ContentSubmission::STATUS_NEEDS_IMPROVEMENT,
                'evaluation_status' => 'needs_improvement',
                'uniqueness_score' => $uniquenessScore,
                'quality_score' => $qualityScore,
                'report' => $report,
                'title' => 'Improve content quality',
                'message' => $cfg['help']['quality_reject']
                    ?? 'Content quality is below the required threshold. Please improve and resubmit.',
                'log' => $scan['log'] ?? null,
            ];
        }

        return [
            'approved' => true,
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
            'evaluation_status' => 'approved',
            'uniqueness_score' => $uniquenessScore,
            'quality_score' => $qualityScore,
            'report' => $report,
            'title' => 'Article approved for publication',
            'message' => 'Your article passed uniqueness, quality, and compliance checks. You can now select websites and place an order.',
            'log' => $scan['log'] ?? null,
        ];
    }

    /**
     * @return array{score:int, max_similarity:float, matched_submission_id:?int, internal_originality:int, method:string}
     */
    public function scoreUniqueness(string $text, ?int $excludeId = null, int $corpusLimit = 200): array
    {
        $shingles = $this->shingles($text, 3);
        $internal = $this->internalOriginality($text);

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

        foreach ($query->get(['id', 'extracted_text']) as $row) {
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

        // Blend corpus uniqueness with internal originality (self-plagiarism / spinning)
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

        // Reward adequate length
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
        if (!$key || !is_string($key) || strlen($key) < 10) {
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

            if (!$response->successful()) {
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
}
