<?php

namespace App\Support;

/**
 * Staff-facing last-decision copy: strip markdown/soft hyphens and keep a one-line summary.
 */
class StaffDecisionNote
{
    public static function plain(?string $raw): string
    {
        $text = (string) $raw;
        $text = str_replace(["\u{00AD}", "\xC2\xAD", '&shy;'], '', $text);
        $text = preg_replace('/\*{1,2}([^*]+)\*{1,2}/u', '$1', $text) ?? $text;
        $text = str_replace(['**', '__'], '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text;
    }

    public static function summary(?string $raw, bool $archived = false, int $limit = 80): string
    {
        $text = self::plain($raw);
        if ($text === '') {
            return '';
        }

        if (self::looksLikeQualityRejection($text)) {
            return $archived ? 'Archived: below quality bar' : 'Rejected: below quality bar';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1)).'…';
    }

    public static function expandable(?string $raw, bool $archived = false, int $limit = 80): bool
    {
        $plain = self::plain($raw);
        if ($plain === '') {
            return false;
        }

        return $plain !== self::summary($raw, $archived, $limit);
    }

    public static function looksLikeQualityRejection(string $text): bool
    {
        $hay = mb_strtolower($text);

        return str_contains($hay, 'quality bar')
            || str_contains($hay, 'below quality')
            || str_contains($hay, 'quality threshold');
    }
}
