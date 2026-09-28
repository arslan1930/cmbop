<?php

namespace App\Services\ContentUpload;

/**
 * Sanitize advertiser article HTML for preview/editor storage.
 */
class ArticleHtmlSanitizer
{
    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '' || $html === '<p><br></p>' || $html === '<p></p>') {
            return '';
        }

        $html = $this->stripPreviewChrome($html);

        return trim($this->allowlist($html));
    }

    /**
     * Rebuild the fragment from an allowlist. Attributes that are not copied
     * (including unquoted event handlers) do not survive the parser.
     */
    private function allowlist(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return '';
        }

        $out = '';
        foreach ($dom->childNodes as $child) {
            $out .= $this->renderDocumentNode($child);
        }

        return $out;
    }

    /**
     * A pasted </body> or </html> makes libxml lift later nodes out of the
     * wrapper. Walk the whole document so that content is kept and script
     * siblings are still dropped.
     */
    private function renderDocumentNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMElement && in_array(strtolower($node->tagName), ['html', 'body', 'head'], true)) {
            return $this->renderChildren($node);
        }

        return $this->renderNode($node);
    }

    private function renderNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return e($node->textContent ?? '');
        }

        if (! $node instanceof \DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
            return '';
        }

        if (! in_array($tag, ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'img', 'span', 'div'], true)) {
            return $this->renderChildren($node);
        }

        if ($tag === 'br') {
            return '<br>';
        }

        if ($tag === 'img') {
            return $this->renderImage($node);
        }

        if ($tag === 'a') {
            return $this->renderAnchor($node);
        }

        return '<'.$tag.'>'.$this->renderChildren($node).'</'.$tag.'>';
    }

    private function renderChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->renderNode($child);
        }

        return $out;
    }

    private function renderAnchor(\DOMElement $node): string
    {
        $href = $this->safeHttpUrl($node->getAttribute('href'));
        $inner = $this->renderChildren($node);
        if ($href === null) {
            return '<a>'.$inner.'</a>';
        }

        return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">'.$inner.'</a>';
    }

    private function renderImage(\DOMElement $node): string
    {
        $src = trim(html_entity_decode($node->getAttribute('src'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($src, '/media/')) {
            $src = '/storage/'.substr($src, strlen('/media/'));
        }

        $ok = $this->safeHttpUrl($src) !== null
            || (str_starts_with($src, '/storage/') && ! str_contains($src, '..'));
        if (! $ok) {
            return '';
        }

        $alt = $node->getAttribute('alt');

        return '<img src="'.e($src).'" alt="'.e($alt).'">';
    }

    private function safeHttpUrl(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || preg_match('/[\s\x00-\x1F]/', $value) === 1) {
            return null;
        }

        if (! preg_match('#^https?://#i', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Preview injects Download buttons around images. Those must not persist.
     */
    private function stripPreviewChrome(string $html): string
    {
        $html = preg_replace(
            '/<button\b[^>]*article-img-download[^>]*>[\s\S]*?<\/button>/iu',
            '',
            $html
        ) ?? $html;

        return preg_replace(
            '/<div\b[^>]*article-img-wrap[^>]*>([\s\S]*?)<\/div>/iu',
            '$1',
            $html
        ) ?? $html;
    }

    public function htmlToPlainText(string $html): string
    {
        $withBreaks = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n\n", $html) ?? $html;
        $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<int, array{anchor:string, url:string}>
     */
    public function extractLinksFromHtml(string $html): array
    {
        $links = [];
        if (! preg_match_all('/<a\b[^>]*href=("|\')(https:\/\/[^"\']+)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $seen = [];
        foreach ($matches as $m) {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $anchor = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($anchor === '') {
                $anchor = $url;
            }
            $links[] = ['anchor' => mb_substr($anchor, 0, 120), 'url' => $url];
        }

        return $links;
    }

    public function countWords(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text) ?: []);
    }

    public function countImages(?string $html): int
    {
        if ($html === null || $html === '') {
            return 0;
        }

        return preg_match_all('/<img\b/i', $html) ?: 0;
    }
}
