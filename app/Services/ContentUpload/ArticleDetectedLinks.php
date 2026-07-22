<?php

namespace App\Services\ContentUpload;

/**
 * Normalize / apply multi-link metadata for article previews.
 *
 * @phpstan-type Link array{anchor:string, url:string}
 */
class ArticleDetectedLinks
{
    /**
     * @param  array<int, mixed>  $links
     * @return array<int, Link>
     */
    public static function normalizeList(array $links, int $anchorMax = 120): array
    {
        $out = [];
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            $anchor = trim(preg_replace('/\s+/u', ' ', (string) ($link['anchor'] ?? '')) ?? '');
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://')) {
                continue;
            }
            if ($anchor === '') {
                $anchor = $url;
            }
            $anchor = mb_substr($anchor, 0, $anchorMax);
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['anchor' => $anchor, 'url' => $url];
        }

        return $out;
    }

    /**
     * @return array<int, Link>
     */
    public static function fromHtml(string $html, int $anchorMax = 120): array
    {
        return (new ArticleHtmlSanitizer)->extractLinksFromHtml($html);
    }

    /**
     * Rewrite HTTPS anchors in document order to match the given link list.
     *
     * @param  array<int, Link>  $links
     */
    public static function applyToHtml(string $html, array $links): string
    {
        $links = self::normalizeList($links);
        if ($links === []) {
            return $html;
        }

        $index = 0;

        return (string) preg_replace_callback(
            '/<a\b([^>]*)href=("|\')(https:\/\/[^"\']+)\2([^>]*)>(.*?)<\/a>/is',
            static function (array $m) use (&$index, $links): string {
                if (! isset($links[$index])) {
                    return $m[0];
                }
                $link = $links[$index];
                $index++;
                $url = $link['url'];
                $anchor = $link['anchor'];

                return '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'.e($anchor).'</a>';
            },
            $html
        );
    }
}
