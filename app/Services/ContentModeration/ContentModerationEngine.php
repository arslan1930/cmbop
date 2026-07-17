<?php

namespace App\Services\ContentModeration;

/**
 * Contextual policy scorer — keywords + phrases + domains + intent co-occurrence.
 * Does not expose raw rule internals to end users.
 */
class ContentModerationEngine
{
    /**
     * @param  array<string, mixed>  $categories
     * @param  array<int, string>  $links
     * @param  array<int, string>  $extraKeywords
     * @param  array<int, string>  $exceptions
     * @return array{scores: array<string,int>, max_confidence:int, detected_category:?string, signals:array}
     */
    public function score(
        string $title,
        string $text,
        array $links,
        array $categories,
        array $extraKeywords = [],
        array $exceptions = [],
    ): array {
        $haystack = mb_strtolower($title . "\n" . $text);
        $haystack = $this->applyExceptions($haystack, $exceptions);
        $linkBlob = mb_strtolower(implode(' ', $links));

        $scores = [];
        $signals = ['hits' => []];

        foreach ($categories as $key => $cat) {
            if (empty($cat['enabled'])) {
                continue;
            }

            $points = 0.0;
            $hits = 0;

            foreach ($cat['keywords'] ?? [] as $kw) {
                $kw = mb_strtolower(trim((string) $kw));
                if ($kw === '') {
                    continue;
                }
                $count = $this->countTerm($haystack, $kw);
                if ($count > 0) {
                    $points += min(35, 12 + ($count - 1) * 6);
                    $hits += $count;
                }
            }

            foreach ($cat['intent_phrases'] ?? [] as $phrase) {
                $phrase = mb_strtolower(trim((string) $phrase));
                if ($phrase !== '' && str_contains($haystack, $phrase)) {
                    $points += 22;
                    $hits++;
                }
            }

            foreach ($cat['domains'] ?? [] as $domain) {
                $domain = mb_strtolower(trim((string) $domain));
                if ($domain !== '' && (str_contains($linkBlob, $domain) || str_contains($haystack, $domain))) {
                    $points += 28;
                    $hits++;
                }
            }

            // Extra admin keywords apply to all enabled cats if tagged, else gambling/adult generic bucket
            foreach ($extraKeywords as $extra) {
                $extra = mb_strtolower(trim((string) $extra));
                if ($extra !== '' && str_contains($haystack, $extra)) {
                    $points += 10;
                    $hits++;
                }
            }

            // Contextual boost: multiple distinct hits imply intent
            if ($hits >= 3) {
                $points *= 1.25;
            } elseif ($hits >= 2) {
                $points *= 1.12;
            }

            $weight = (float) ($cat['weight'] ?? 1.0);
            $confidence = (int) min(99, round($points * $weight));

            // Soften single weak keyword (e.g. "odds" alone in sports journalism)
            if ($hits === 1 && $confidence < 40) {
                $confidence = (int) round($confidence * 0.55);
            }

            $scores[$key] = $confidence;
            if ($confidence > 0) {
                $signals['hits'][$key] = [
                    'confidence_hits' => $hits,
                    'confidence' => $confidence,
                ];
            }
        }

        arsort($scores);
        $detected = null;
        $max = 0;
        foreach ($scores as $key => $conf) {
            if ($conf > $max) {
                $max = $conf;
                $detected = $key;
            }
        }

        return [
            'scores' => $scores,
            'max_confidence' => $max,
            'detected_category' => $max > 0 ? $detected : null,
            'signals' => $signals,
        ];
    }

    protected function countTerm(string $haystack, string $term): int
    {
        if (str_contains($term, ' ')) {
            return substr_count($haystack, $term);
        }

        return preg_match_all('/\b' . preg_quote($term, '/') . '\b/u', $haystack) ?: 0;
    }

    /**
     * @param  array<int, string|array>  $exceptions
     */
    protected function applyExceptions(string $haystack, array $exceptions): string
    {
        foreach ($exceptions as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $haystack = str_ireplace($value, ' ', $haystack);
            } elseif (is_string($key) && is_array($value)) {
                foreach ($value as $phrase) {
                    $haystack = str_ireplace((string) $phrase, ' ', $haystack);
                }
            }
        }

        return $haystack;
    }
}
