<?php

namespace App\Services\ContentUpload;

/**
 * Normalize Content Library preview HTML so inline images resolve publicly.
 */
class ArticlePreviewHtml
{
    public static function normalize(?string $html): string
    {
        $html = (string) $html;
        if ($html === '' || ! str_contains($html, '<img')) {
            return $html;
        }

        $base = self::appUrl();
        $publicBase = self::publicDiskUrl($base);

        return (string) preg_replace_callback(
            '/(<img\b[^>]*\bsrc=["\'])([^"\']+)(["\'])/i',
            static function (array $m) use ($base, $publicBase): string {
                $src = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
                $normalized = self::normalizeSrc($src, $base, $publicBase);

                return $m[1].e($normalized).$m[3];
            },
            $html
        );
    }

    public static function normalizeSrc(string $src, ?string $appUrl = null, ?string $publicUrl = null): string
    {
        $src = trim($src);
        if ($src === '') {
            return $src;
        }

        $base = rtrim($appUrl ?? self::appUrl(), '/');
        $publicBase = rtrim($publicUrl ?? self::publicDiskUrl($base), '/');

        // Already absolute http(s) or data URI
        if (preg_match('#^(https?:)?//#i', $src) || str_starts_with($src, 'data:')) {
            // Rewrite wrong-host /storage paths onto current public disk URL
            if (preg_match('#^https?://[^/]+(/storage/.+)$#i', $src, $m)) {
                return $publicBase.substr($m[1], strlen('/storage'));
            }

            return $src;
        }

        if (str_starts_with($src, '/storage/')) {
            return $publicBase.substr($src, strlen('/storage'));
        }

        if (str_starts_with($src, 'storage/')) {
            return $publicBase.'/'.substr($src, strlen('storage/'));
        }

        if (str_starts_with($src, '/')) {
            return $base.$src;
        }

        return $src;
    }

    private static function appUrl(): string
    {
        try {
            if (function_exists('app') && app()->bound('config')) {
                return rtrim((string) config('app.url'), '/');
            }
        } catch (\Throwable) {
            // Pure unit tests without a container.
        }

        return '';
    }

    private static function publicDiskUrl(string $appUrl): string
    {
        try {
            if (function_exists('app') && app()->bound('config')) {
                return rtrim((string) config('filesystems.disks.public.url', $appUrl.'/storage'), '/');
            }
        } catch (\Throwable) {
            // Pure unit tests without a container.
        }

        return ($appUrl !== '' ? $appUrl : '').'/storage';
    }

    /**
     * Wrap matched terms in <mark> for rejection preview (plain-text safe over HTML tags).
     *
     * @param  array<int, string>  $terms
     */
    public static function highlightTerms(string $html, array $terms): string
    {
        $terms = array_values(array_unique(array_filter(array_map(
            static fn ($t) => trim((string) $t),
            $terms
        ), static fn ($t) => $t !== '')));

        if ($html === '' || $terms === []) {
            return $html;
        }

        // Longest first to avoid partial overlaps
        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        foreach ($parts as $i => $part) {
            if ($part === '' || str_starts_with($part, '<')) {
                continue;
            }
            foreach ($terms as $term) {
                $quoted = preg_quote($term, '/');
                $pattern = str_contains($term, ' ')
                    ? '/('.$quoted.')/iu'
                    : '/\b('.$quoted.')\b/iu';
                $parts[$i] = (string) preg_replace(
                    $pattern,
                    '<mark class="slb-mod-hit">$1</mark>',
                    $parts[$i]
                );
            }
        }

        return implode('', $parts);
    }

    /**
     * Highlight <a> tags whose href matches a blocked casino/adult URL.
     *
     * @param  array<int, string>  $blockedUrls
     */
    public static function highlightBlockedLinks(string $html, array $blockedUrls): string
    {
        $blockedUrls = array_values(array_unique(array_filter(array_map(
            static fn ($u) => trim((string) $u),
            $blockedUrls
        ), static fn ($u) => $u !== '')));

        if ($html === '' || $blockedUrls === []) {
            return $html;
        }

        $normalizedBlocked = array_map(static function (string $url): string {
            return mb_strtolower(rtrim($url, '/'));
        }, $blockedUrls);

        return (string) preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            static function (array $m) use ($normalizedBlocked): string {
                $attrs = $m[1];
                $inner = $m[2];
                if (! preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/iu', $attrs, $hrefMatch)) {
                    return $m[0];
                }
                $href = html_entity_decode($hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with($href, '//')) {
                    $href = 'https:'.$href;
                }
                $norm = mb_strtolower(rtrim($href, '/'));
                $isBlocked = false;
                foreach ($normalizedBlocked as $blocked) {
                    if ($norm === $blocked || str_contains($norm, $blocked) || str_contains($blocked, $norm)) {
                        $isBlocked = true;
                        break;
                    }
                    // Domain token match (e.g. blocked list has full URL, href has same host)
                    $blockedHost = parse_url($blocked, PHP_URL_HOST) ?: $blocked;
                    $hrefHost = parse_url($href, PHP_URL_HOST) ?: '';
                    if ($hrefHost !== '' && $blockedHost !== '' && str_contains(mb_strtolower((string) $hrefHost), mb_strtolower((string) $blockedHost))) {
                        $isBlocked = true;
                        break;
                    }
                }
                if (! $isBlocked) {
                    return $m[0];
                }

                if (! preg_match('/\bclass\s*=/i', $attrs)) {
                    $attrs .= ' class="slb-mod-hit-link"';
                } elseif (! str_contains($attrs, 'slb-mod-hit-link')) {
                    $attrs = preg_replace('/\bclass\s*=\s*(["\'])(.*?)(\1)/i', 'class=$1$2 slb-mod-hit-link$1', $attrs, 1) ?? $attrs;
                }

                if (! str_contains($inner, 'slb-mod-hit')) {
                    $inner = '<mark class="slb-mod-hit">'.$inner.'</mark>';
                }

                return '<a'.$attrs.'>'.$inner.'</a>';
            },
            $html
        );
    }
}
