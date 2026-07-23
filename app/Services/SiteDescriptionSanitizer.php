<?php

namespace App\Services;

/**
 * Sanitize publisher/admin site descriptions for safe HTML storage and display.
 * Stricter than strip_tags alone: drops event handlers and non-http(s) links.
 */
class SiteDescriptionSanitizer
{
    private const ALLOWED = '<p><a><b><strong><i><em><ul><ol><li><br>';

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $clean = strip_tags($html, self::ALLOWED);

        // Drop event handlers / javascript: URLs from remaining tags
        $clean = preg_replace('/\son\w+\s*=\s*("|\').*?\1/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/iu', '', $clean) ?? $clean;

        // Strip any remaining attributes except on anchors (normalized below)
        $clean = preg_replace_callback(
            '/<(p|b|strong|i|em|ul|ol|li|br)\b[^>]*>/iu',
            fn (array $m): string => '<'.strtolower($m[1]).'>',
            $clean
        ) ?? $clean;

        $clean = preg_replace_callback(
            '/<a\b([^>]*)>/iu',
            function (array $m): string {
                $attrs = $m[1];
                $href = '';
                if (preg_match('/\bhref\s*=\s*("|\')(.*?)\1/iu', $attrs, $hm)) {
                    $href = trim(html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
                if ($href === '' || ! preg_match('#^https?://#i', $href)) {
                    return '<a>';
                }

                return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">';
            },
            $clean
        ) ?? $clean;

        return trim($clean);
    }
}
