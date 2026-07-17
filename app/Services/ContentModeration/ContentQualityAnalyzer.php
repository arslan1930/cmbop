<?php

namespace App\Services\ContentModeration;

class ContentQualityAnalyzer
{
    /**
     * @param  array<int, string>  $links
     * @return array{checks: array<int, array{key:string,label:string,status:string,detail:string}>, score:int, blocking_issues:array<int,string>}
     */
    public function analyze(string $text, string $html, array $links, array $qualityConfig): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($words);
        $min = (int) ($qualityConfig['min_word_count'] ?? 500);
        $warn = (int) ($qualityConfig['warn_word_count'] ?? 300);
        $maxLinks = (int) ($qualityConfig['max_external_links'] ?? 15);

        $checks = [];
        $blocking = [];

        // Word count
        if ($wordCount >= $min) {
            $checks[] = $this->check('word_count', 'Word Count', 'pass', number_format($wordCount) . ' words');
        } elseif ($wordCount >= $warn) {
            $checks[] = $this->check('word_count', 'Word Count', 'warn', number_format($wordCount) . " words (recommended ≥ {$min})");
        } else {
            $checks[] = $this->check('word_count', 'Word Count', 'fail', number_format($wordCount) . " words (recommended ≥ {$min})");
            if (!empty($qualityConfig['block_on_quality_failure'])) {
                $blocking[] = 'word_count';
            }
        }

        // Readability (Flesch-like approximation)
        $readability = $this->readabilityScore($text);
        $checks[] = $this->check(
            'readability',
            'Readability',
            $readability['status'],
            $readability['label'] . ' (' . $readability['score'] . ')'
        );

        // Headings
        $hasHeadings = (bool) preg_match('/<h[1-3][^>]*>/i', $html)
            || (bool) preg_match('/^#{1,3}\s+\S+/m', $text)
            || $this->hasLikelyHeadings($text);
        $checks[] = $this->check(
            'headings',
            'Headings',
            $hasHeadings ? 'pass' : 'warn',
            $hasHeadings ? 'Valid structure detected' : 'No clear H1/H2-style headings found'
        );

        // Placeholder
        $hasPlaceholder = (bool) preg_match('/lorem ipsum|dolor sit amet|placeholder text|your text here|insert content/i', $text);
        if ($hasPlaceholder) {
            $checks[] = $this->check('placeholder', 'Placeholder Text', 'fail', 'Placeholder / dummy text detected');
            if (!empty($qualityConfig['block_placeholder_text']) || !empty($qualityConfig['block_on_quality_failure'])) {
                $blocking[] = 'placeholder';
            }
        } else {
            $checks[] = $this->check('placeholder', 'Placeholder Text', 'pass', 'None detected');
        }

        // Keyword stuffing heuristic
        $stuffing = $this->keywordStuffingRatio($words);
        $checks[] = $this->check(
            'keyword_stuffing',
            'Keyword Density',
            $stuffing > 0.12 ? 'warn' : 'pass',
            $stuffing > 0.12 ? 'Possible keyword stuffing' : 'Looks balanced'
        );

        // Links
        $external = array_values(array_filter($links, fn ($l) => !str_contains(strtolower($l), 'google.com')));
        $suspicious = array_values(array_filter($external, function ($l) {
            return (bool) preg_match('/bit\.ly|tinyurl|t\.co|goo\.gl|is\.gd|spam|xxx|porn|casino|bet\d/i', $l);
        }));
        if (count($external) > $maxLinks) {
            $checks[] = $this->check('external_links', 'External Links', 'warn', count($external) . " found (high)");
        } elseif (count($suspicious) > 0) {
            $checks[] = $this->check('external_links', 'External Links', 'warn', count($external) . ' found · ' . count($suspicious) . ' look suspicious');
        } else {
            $checks[] = $this->check('external_links', 'External Links', 'pass', count($external) . ' found');
        }

        $pass = count(array_filter($checks, fn ($c) => $c['status'] === 'pass'));
        $score = (int) round(($pass / max(count($checks), 1)) * 100);

        return [
            'checks' => $checks,
            'score' => $score,
            'blocking_issues' => array_values(array_unique($blocking)),
            'word_count' => $wordCount,
            'external_link_count' => count($external),
        ];
    }

    protected function check(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }

    protected function readabilityScore(string $text): array
    {
        $sentences = preg_split('/[.!?]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $syllables = 0;
        foreach ($words as $w) {
            $syllables += max(1, preg_match_all('/[aeiouy]+/i', $w));
        }
        $sc = max(count($sentences), 1);
        $wc = max(count($words), 1);
        // Flesch Reading Ease approximation
        $score = (int) round(206.835 - 1.015 * ($wc / $sc) - 84.6 * ($syllables / $wc));
        $score = max(0, min(100, $score));

        if ($score >= 60) {
            return ['score' => $score, 'status' => 'pass', 'label' => 'Good'];
        }
        if ($score >= 40) {
            return ['score' => $score, 'status' => 'warn', 'label' => 'Fair'];
        }

        return ['score' => $score, 'status' => 'warn', 'label' => 'Difficult'];
    }

    protected function hasLikelyHeadings(string $text): bool
    {
        $lines = preg_split('/\n+/', $text) ?: [];
        $shortLines = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && mb_strlen($line) <= 80 && !str_ends_with($line, '.')) {
                $shortLines++;
            }
        }

        return $shortLines >= 2;
    }

    /**
     * @param  array<int, string>  $words
     */
    protected function keywordStuffingRatio(array $words): float
    {
        if (count($words) < 40) {
            return 0.0;
        }
        $freq = [];
        foreach ($words as $w) {
            $k = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $w) ?? '');
            if (mb_strlen($k) < 4) {
                continue;
            }
            $freq[$k] = ($freq[$k] ?? 0) + 1;
        }
        if (!$freq) {
            return 0.0;
        }
        arsort($freq);
        $top = (int) reset($freq);

        return $top / max(count($words), 1);
    }
}
