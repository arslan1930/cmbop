<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

class CampaignHtml
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u',
        'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'blockquote',
    ];

    /**
     * @var list<string>
     */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'noscript',
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (! self::containsAllowedTag($html)) {
            return '<p>'.nl2br(e($html), false).'</p>';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="campaign-html-root">'.$html.'</div>';

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('campaign-html-root');
        if (! $root instanceof DOMElement) {
            return '<p>'.nl2br(e($html), false).'</p>';
        }

        self::scrubElement($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        $out = trim($out);

        return $out !== '' ? $out : '<p>'.nl2br(e($html), false).'</p>';
    }

    public static function isSafeHttpUrl(?string $url): bool
    {
        $safe = self::safeHref($url, ['http', 'https']);

        return $safe !== null;
    }

    /**
     * @param  list<string>  $schemes
     */
    public static function safeHref(?string $url, array $schemes = ['http', 'https', 'mailto']): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || preg_match('/%0[da]|[\r\n\x00]/i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, $schemes, true)) {
            return null;
        }

        // https://trusted.example@evil.test is a valid URL whose host is evil.test.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            $host = strtolower(trim((string) ($parts['host'] ?? '')));
            if ($host === '' || str_contains($host, '@') || str_contains($host, '\\')) {
                return null;
            }
        }

        return $url;
    }

    private static function containsAllowedTag(string $html): bool
    {
        $tags = implode('|', self::ALLOWED_TAGS);

        return (bool) preg_match('/<\s*\/?\s*(?:'.$tags.')\b/i', $html);
    }

    private static function scrubElement(DOMElement $el): void
    {
        $children = iterator_to_array($el->childNodes);

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild instanceof DOMNode) {
                    $el->insertBefore($child->firstChild, $child);
                }
                $child->parentNode?->removeChild($child);
                self::scrubElement($el);

                return;
            }

            self::scrubAttributes($child);
            self::scrubElement($child);
        }
    }

    private static function scrubAttributes(DOMElement $el): void
    {
        $names = [];
        if ($el->hasAttributes()) {
            foreach ($el->attributes as $attr) {
                $names[] = $attr->name;
            }
        }

        $isAnchor = strtolower($el->tagName) === 'a';

        foreach ($names as $name) {
            if ($isAnchor && strtolower($name) === 'href') {
                $safe = self::safeHref($el->getAttribute('href'));
                if ($safe) {
                    $el->setAttribute('href', $safe);
                } else {
                    $el->removeAttribute($name);
                }

                continue;
            }

            $el->removeAttribute($name);
        }

        if ($isAnchor && ! $el->hasAttribute('href')) {
            $parent = $el->parentNode;
            if ($parent) {
                while ($el->firstChild instanceof DOMNode) {
                    $parent->insertBefore($el->firstChild, $el);
                }
                $parent->removeChild($el);
            }
        }
    }
}
