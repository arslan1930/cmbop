<?php

namespace App\Services;

use App\Support\BlogInlineImages;

/**
 * Sanitize stored blog HTML (Quill editor output) before it is rendered.
 *
 * Blog bodies are saved as raw HTML, so anything rendered with {!! !!} has to
 * pass through here first.
 */
class BlogHtmlSanitizer
{
    /**
     * Tags the blog editor can legitimately produce.
     */
    private const ALLOWED = '<p><br><hr><strong><b><em><i><u><s><strike><ul><ol><li>'
        .'<a><h1><h2><h3><h4><h5><h6><blockquote><pre><code><img><span><div>'
        .'<figure><figcaption><table><thead><tbody><tr><th><td><iframe>';

    /**
     * Hosts allowed for the editor's video embeds.
     */
    private const EMBED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
    ];

    /**
     * True when Quill submitted an unused locale tab (`<p><br></p>`), not when
     * the body is image- or embed-only.
     */
    public static function isBlank(?string $html): bool
    {
        $html = trim((string) $html);
        if ($html === '' || $html === '<p><br></p>' || $html === '<p></p>') {
            return true;
        }

        if (preg_match('/<(img|iframe|video|figure|hr)\b/i', $html)) {
            return false;
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '';
    }

    /**
     * @deprecated Use isBlank() — kept so a master merge that still calls isEmptyHtml() does not 500.
     */
    public static function isEmptyHtml(?string $html): bool
    {
        return self::isBlank($html);
    }

    /**
     * Encode stored HTML for a <script type="application/json"> payload.
     * JSON_HEX_TAG prevents </script> in the body from breaking the edit page.
     */
    public static function encodeForScript(?string $html): string
    {
        $encoded = json_encode(
            self::utf8((string) $html),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($encoded) ? $encoded : '""';
    }

    /**
     * Point stored /storage/blogs/... (and absolute twins) at /media/blogs/...
     * so Hostinger broken public/storage symlinks do not blank inline images.
     * Curated rows stay /storage/ in the database until a post is saved; public
     * render and the editor boot script rewrite on the way out.
     */
    public static function rewriteStorageBlogUrls(?string $html): string
    {
        $html = (string) $html;
        $rewritten = preg_replace(
            '#(?:https?://[^"\']+)?/storage/(blogs/(?:content|featured)/)#i',
            '/media/$1',
            $html
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Hostinger-safe blog image URLs for render, editor boot, and save.
     * /assets/img/blog/... must be rewritten before sanitize, or those imgs
     * are dropped as untrusted and a routine admin save wipes pillar screenshots.
     */
    public static function rewritePublicBlogUrls(?string $html): string
    {
        return self::rewriteStorageBlogUrls(
            BlogInlineImages::rewriteLegacyAssetUrls((string) $html)
        );
    }

    /**
     * Encode stored HTML for the Quill boot script, with Hostinger-safe image URLs.
     */
    public static function encodeForEditor(?string $html): string
    {
        return self::encodeForScript((new self)->sanitize($html));
    }

    public function sanitize(?string $html): string
    {
        if (self::isBlank($html)) {
            return '';
        }

        $html = self::rewritePublicBlogUrls(self::utf8(trim((string) $html)));

        // strip_tags keeps inner text, so remove these elements with their contents first.
        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>.*?<\/\1>/isu', '', $html) ?? $html;
        $html = preg_replace('/<(script|style|noscript|template)\b[^>]*>/iu', '', $html) ?? $html;

        $clean = strip_tags($html, self::ALLOWED);

        // Drop event handlers and javascript: URLs from whatever survived.
        $clean = preg_replace('/\son\w+\s*=\s*("|\').*?\1/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\son\w+\s*=\s*[^\s>]+/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s(href|src)\s*=\s*("|\')\s*(javascript|data\s*:\s*text\/html|vbscript):[^"\']*\2/iu', '', $clean) ?? $clean;

        $clean = $this->normalizeAnchors($clean);
        $clean = $this->normalizeImages($clean);
        $clean = $this->normalizeIframes($clean);

        return trim($clean);
    }

    private function normalizeAnchors(string $html): string
    {
        return preg_replace_callback(
            '/<a\b([^>]*)>/iu',
            function (array $m): string {
                $href = $this->attribute($m[1], 'href');
                if ($href === '' || ! preg_match('~^(https?://|/|mailto:|#)~i', $href)) {
                    return '<a>';
                }

                // In-page glossary links must stay on this page. A new tab
                // drops the reader at the top of a second copy.
                if (str_starts_with($href, '#')) {
                    return '<a href="'.e($href).'">';
                }

                return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">';
            },
            $html
        ) ?? $html;
    }

    private function normalizeImages(string $html): string
    {
        return preg_replace_callback(
            '/<img\b([^>]*)>/iu',
            function (array $m): string {
                $src = $this->attribute($m[1], 'src');
                if (str_starts_with($src, '//')) {
                    $src = 'https:'.$src;
                }
                $allowed = $src !== '' && (
                    preg_match('#^https?://#i', $src)
                    || str_starts_with($src, '/storage/')
                    || str_starts_with($src, '/media/blogs/')
                    || str_starts_with($src, 'data:image/')
                );
                if (! $allowed) {
                    return '';
                }

                return '<img src="'.e($src).'" alt="'.e($this->attribute($m[1], 'alt')).'">';
            },
            $html
        ) ?? $html;
    }

    private function normalizeIframes(string $html): string
    {
        $accepted = [];

        // Match the whole element so a rejected embed leaves no stray closing tag.
        $clean = preg_replace_callback(
            '/<iframe\b([^>]*)>.*?<\/iframe>/isu',
            function (array $m) use (&$accepted): string {
                $src = $this->attribute($m[1], 'src');
                if ($src === '' || ! preg_match('#^https://#i', $src)) {
                    return '';
                }

                $host = strtolower((string) parse_url($src, PHP_URL_HOST));
                if (! in_array($host, self::EMBED_HOSTS, true)) {
                    return '';
                }

                $token = '%%BLOG_EMBED_'.count($accepted).'%%';
                $accepted[$token] = '<iframe src="'.e($src).'" loading="lazy" allowfullscreen '
                    .'referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe>';

                return $token;
            },
            $html
        ) ?? $html;

        // Drop unpaired iframe tags left over from malformed input, then restore
        // the embeds that passed the host check.
        $clean = preg_replace('/<iframe\b[^>]*>/iu', '', $clean) ?? $clean;
        $clean = str_ireplace('</iframe>', '', $clean);

        return strtr($clean, $accepted);
    }

    private function attribute(string $attrs, string $name): string
    {
        if (! preg_match('/\b'.preg_quote($name, '/').'\s*=\s*("|\')(.*?)\1/iu', $attrs, $m)) {
            return '';
        }

        return trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * /u preg_replace returns null on invalid UTF-8, and `?? $html` would keep
     * the dirty markup (event handlers, javascript: URLs). Scrub first.
     */
    private static function utf8(string $html): string
    {
        if (function_exists('mb_scrub')) {
            return mb_scrub($html, 'UTF-8');
        }

        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $html);

        return is_string($converted) ? $converted : '';
    }
}
