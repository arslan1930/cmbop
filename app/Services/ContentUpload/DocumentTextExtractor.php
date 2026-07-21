<?php

namespace App\Services\ContentUpload;

use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

class DocumentTextExtractor
{
    /**
     * @param  (callable(string $binary, string $extension, string $originalName): ?string)|null  $storeImage
     *                                                                                                         Callback stores image bytes and returns a public URL (or null to skip).
     * @return array{
     *   ok:bool,
     *   text:?string,
     *   html:?string,
     *   word_count:int,
     *   links:array<int, array{anchor:string, url:string}>,
     *   images:array<int, string>,
     *   error_code:?string,
     *   error_message:?string
     * }
     */
    public function extract(string $absolutePath, string $extension, ?callable $storeImage = null): array
    {
        $extension = strtolower(ltrim($extension, '.'));

        try {
            return match ($extension) {
                'docx' => $this->extractDocx($absolutePath, $storeImage),
                'doc' => $this->extractDoc($absolutePath),
                'pdf' => $this->extractPdf($absolutePath),
                default => $this->fail('unsupported_file', 'Unsupported file type. Please upload a .docx document.'),
            };
        } catch (\Throwable $e) {
            return $this->fail('corrupted_file', 'This document appears corrupted or unreadable. Please re-export it as .docx and try again.');
        }
    }

    /**
     * @param  (callable(string $binary, string $extension, string $originalName): ?string)|null  $storeImage
     * @return array{
     *   ok:bool,
     *   text:?string,
     *   html:?string,
     *   word_count:int,
     *   links:array<int, array{anchor:string, url:string}>,
     *   images:array<int, string>,
     *   error_code:?string,
     *   error_message:?string
     * }
     */
    protected function extractDocx(string $path, ?callable $storeImage = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            return $this->fail('unsupported_file', 'Document processing is unavailable on this server.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return $this->fail('corrupted_file', 'Unable to open the Word document. Please re-save as .docx and upload again.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels') ?: '';

        if ($xml === false || trim((string) $xml) === '') {
            $zip->close();

            return $this->fail('empty_document', 'This document appears empty. Please upload an article with content.');
        }

        $xml = (string) $xml;
        $relsXml = (string) $relsXml;

        $links = $this->extractDocxHyperlinks($xml, $relsXml);
        $imageUrlsByRid = $this->extractAndStoreDocxImages($zip, $relsXml, $storeImage);
        $zip->close();

        // Replace drawings / pictures with stable markers before text extraction
        $xml = preg_replace_callback(
            '/<w:drawing\b[\s\S]*?<\/w:drawing>/iu',
            function (array $m) use ($imageUrlsByRid): string {
                if (preg_match('/r:embed="([^"]+)"/i', $m[0], $em) && isset($imageUrlsByRid[$em[1]])) {
                    return '<w:r><w:t>[[IMG:'.$em[1].']]</w:t></w:r>';
                }

                return '';
            },
            $xml
        ) ?? $xml;

        $xml = preg_replace_callback(
            '/<w:pict\b[\s\S]*?<\/w:pict>/iu',
            function (array $m) use ($imageUrlsByRid): string {
                if (preg_match('/r:embed="([^"]+)"/i', $m[0], $em) && isset($imageUrlsByRid[$em[1]])) {
                    return '<w:r><w:t>[[IMG:'.$em[1].']]</w:t></w:r>';
                }

                return '';
            },
            $xml
        ) ?? $xml;

        // Preserve paragraphs for preview / quality checks
        $withBreaks = preg_replace('/<\/w:p>/', "\n\n", $xml) ?? $xml;
        $withBreaks = preg_replace('/<\/w:tr>/', "\n", $withBreaks) ?? $withBreaks;
        $withBreaks = preg_replace('/<w:tab[^>]*\/>/', "\t", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);

        $plainForCount = trim(preg_replace('/\[\[IMG:[^\]]+\]\]/', ' ', $text) ?? $text);
        if ($plainForCount === '' && $imageUrlsByRid === []) {
            return $this->fail('empty_document', 'No readable text was found in this document.');
        }
        if ($plainForCount === '' && $imageUrlsByRid !== []) {
            $text = '[[IMG:'.array_key_first($imageUrlsByRid).']]';
        }

        // Plain-text URL fallback when Word hyperlinks are missing
        if ($links === []) {
            $links = $this->extractPlainTextLinks(preg_replace('/\[\[IMG:[^\]]+\]\]/', ' ', $text) ?? $text);
        }

        $html = $this->textToPreviewHtml($text, $links, $imageUrlsByRid);
        $words = $this->countWords(preg_replace('/\[\[IMG:[^\]]+\]\]/', ' ', $text) ?? $text);

        return [
            'ok' => true,
            'text' => preg_replace('/\[\[IMG:[^\]]+\]\]/', ' ', $text) ?? $text,
            'html' => $html,
            'word_count' => $words,
            'links' => $links,
            'images' => array_values($imageUrlsByRid),
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @param  (callable(string $binary, string $extension, string $originalName): ?string)|null  $storeImage
     * @return array<string, string> rId => public URL
     */
    protected function extractAndStoreDocxImages(ZipArchive $zip, string $relsXml, ?callable $storeImage): array
    {
        if ($storeImage === null || $relsXml === '') {
            return [];
        }

        $mediaRels = [];
        if (preg_match_all('/<Relationship\b[^>]*>/i', $relsXml, $tagMatches)) {
            foreach ($tagMatches[0] as $tag) {
                if (! preg_match('/\bType="[^"]*\/image"/i', $tag) && ! preg_match("/\bType='[^']*\/image'/i", $tag)) {
                    continue;
                }
                if (! preg_match('/\bId="([^"]+)"/i', $tag, $idM)) {
                    continue;
                }
                if (! preg_match('/\bTarget="([^"]+)"/i', $tag, $tgM)) {
                    continue;
                }
                $target = html_entity_decode($tgM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $target = ltrim(str_replace('\\', '/', $target), '/');
                if (! str_starts_with($target, 'word/')) {
                    $target = 'word/'.$target;
                }
                $mediaRels[$idM[1]] = $target;
            }
        }

        $urls = [];
        foreach ($mediaRels as $rid => $zipPath) {
            $binary = $zip->getFromName($zipPath);
            if ($binary === false || $binary === '') {
                // Some packages use media/ without word/ prefix variants
                $alt = preg_replace('#^word/#', '', $zipPath) ?? $zipPath;
                $binary = $zip->getFromName('word/'.$alt);
            }
            if ($binary === false || $binary === '') {
                continue;
            }

            $basename = basename($zipPath);
            $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION) ?: 'png');
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
                $ext = 'png';
            }

            $url = $storeImage($binary, $ext, $basename);
            if (is_string($url) && $url !== '') {
                $urls[$rid] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return array<int, array{anchor:string, url:string}>
     */
    protected function extractDocxHyperlinks(string $documentXml, string $relsXml): array
    {
        $relMap = [];
        if ($relsXml !== '' && preg_match_all(
            '/Relationship[^>]+Id="([^"]+)"[^>]+Target="([^"]+)"[^>]*TargetMode="External"/i',
            $relsXml,
            $relMatches,
            PREG_SET_ORDER
        )) {
            foreach ($relMatches as $m) {
                $relMap[$m[1]] = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        // Also catch External relationships where TargetMode comes before Target
        if ($relsXml !== '' && preg_match_all(
            '/Relationship[^>]+Target="([^"]+)"[^>]+Id="([^"]+)"[^>]*TargetMode="External"/i',
            $relsXml,
            $relMatches2,
            PREG_SET_ORDER
        )) {
            foreach ($relMatches2 as $m) {
                $relMap[$m[2]] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        if ($relsXml !== '' && preg_match_all(
            '/<Relationship\b[^>]*>/i',
            $relsXml,
            $tagMatches
        )) {
            foreach ($tagMatches[0] as $tag) {
                if (! stripos($tag, 'TargetMode="External"') && ! stripos($tag, "TargetMode='External'")) {
                    continue;
                }
                if (! preg_match('/\bId="([^"]+)"/i', $tag, $idM)) {
                    continue;
                }
                if (! preg_match('/\bTarget="([^"]+)"/i', $tag, $tgM)) {
                    continue;
                }
                $relMap[$idM[1]] = html_entity_decode($tgM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $links = [];

        // w:hyperlink with r:id
        if (preg_match_all(
            '/<w:hyperlink\b[^>]*r:id="([^"]+)"[^>]*>(.*?)<\/w:hyperlink>/is',
            $documentXml,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $rid = $m[1];
                $url = $relMap[$rid] ?? null;
                if (! $url || ! preg_match('#^https?://#i', $url)) {
                    continue;
                }
                $anchor = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $anchor = preg_replace('/\s+/u', ' ', $anchor) ?? $anchor;
                if ($anchor === '') {
                    $anchor = $url;
                }
                $links[] = ['anchor' => mb_substr($anchor, 0, 120), 'url' => $url];
            }
        }

        // HYPERLINK field codes: HYPERLINK "https://..."
        if (preg_match_all('/HYPERLINK\s+"([^"]+)"/i', $documentXml, $fieldMatches)) {
            foreach ($fieldMatches[1] as $url) {
                $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (! preg_match('#^https?://#i', $url)) {
                    continue;
                }
                $links[] = ['anchor' => $url, 'url' => $url];
            }
        }

        return $this->uniqueLinks($links);
    }

    /**
     * @return array<int, array{anchor:string, url:string}>
     */
    public function extractPlainTextLinks(string $text): array
    {
        $links = [];
        if (! preg_match_all('#https://[^\s<>"\')\]]+#i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as [$url, $offset]) {
            $url = rtrim($url, '.,;:!?');
            $before = substr($text, max(0, $offset - 80), min(80, $offset));
            $words = preg_split('/\s+/u', trim($before)) ?: [];
            $tail = array_slice($words, -4);
            $anchor = trim(implode(' ', $tail));
            if ($anchor === '' || preg_match('#^https?://#i', $anchor)) {
                $anchor = $url;
            }
            $links[] = ['anchor' => mb_substr($anchor, 0, 120), 'url' => $url];
        }

        return $this->uniqueLinks($links);
    }

    /**
     * @param  array<int, array{anchor:string, url:string}>  $links
     * @return array<int, array{anchor:string, url:string}>
     */
    protected function uniqueLinks(array $links): array
    {
        $seen = [];
        $out = [];
        foreach ($links as $link) {
            $key = strtolower($link['url']);
            if (isset($seen[$key])) {
                continue;
            }
            // Prefer https for autofill
            if (! str_starts_with(strtolower($link['url']), 'https://')) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $link;
        }

        return $out;
    }

    /**
     * Legacy .doc is optional — attempt a best-effort binary string scrape.
     *
     * @return array{ok:bool, text:?string, html:?string, word_count:int, links:array, images:array, error_code:?string, error_message:?string}
     */
    protected function extractDoc(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $this->fail('corrupted_file', 'Unable to read this .doc file. Please convert it to .docx and upload again.');
        }

        $utf16 = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
        $candidate = is_string($utf16) ? $utf16 : '';
        $candidate = preg_replace('/[^\P{C}\n\t]+/u', ' ', $candidate) ?? '';
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate) ?? '');

        if ($this->countWords($candidate) < 30) {
            preg_match_all('/[\x20-\x7E]{4,}/', $raw, $matches);
            $candidate = trim(implode(' ', $matches[0] ?? []));
        }

        if ($this->countWords($candidate) < 20) {
            return $this->fail(
                'unsupported_file',
                'We could not reliably extract text from this .doc file. Please upload a .docx document instead.'
            );
        }

        $links = $this->extractPlainTextLinks($candidate);
        $html = $this->textToPreviewHtml($candidate, $links);

        return [
            'ok' => true,
            'text' => $candidate,
            'html' => $html,
            'word_count' => $this->countWords($candidate),
            'links' => $links,
            'images' => [],
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @return array{ok:bool, text:?string, html:?string, word_count:int, links:array, images:array, error_code:?string, error_message:?string}
     */
    protected function extractPdf(string $path): array
    {
        if (! class_exists(PdfParser::class)) {
            return $this->fail('unsupported_file', 'PDF extraction is unavailable. Please upload a .docx document.');
        }

        $parser = new PdfParser;
        $pdf = $parser->parseFile($path);
        $text = trim((string) $pdf->getText());

        if ($text === '') {
            return $this->fail('empty_document', 'No readable text was found in this PDF. Please upload a .docx document.');
        }

        $links = $this->extractPlainTextLinks($text);
        $html = $this->textToPreviewHtml($text, $links);

        return [
            'ok' => true,
            'text' => $text,
            'html' => $html,
            'word_count' => $this->countWords($text),
            'links' => $links,
            'images' => [],
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * @param  array<int, array{anchor:string, url:string}>  $links
     * @param  array<string, string>  $imageUrlsByRid
     */
    protected function textToPreviewHtml(string $text, array $links = [], array $imageUrlsByRid = []): string
    {
        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [$text];
        $html = '';
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            // Paragraph that is only an image marker
            if (preg_match('/^\[\[IMG:([^\]]+)\]\]$/', $p, $onlyImg) && isset($imageUrlsByRid[$onlyImg[1]])) {
                $html .= '<p><img src="'.e($imageUrlsByRid[$onlyImg[1]]).'" alt=""></p>';

                continue;
            }

            $escaped = e($p);
            $escaped = preg_replace_callback(
                '/\[\[IMG:([^\]]+)\]\]/',
                function (array $m) use ($imageUrlsByRid): string {
                    $rid = $m[1];
                    if (! isset($imageUrlsByRid[$rid])) {
                        return '';
                    }

                    return '</p><p><img src="'.e($imageUrlsByRid[$rid]).'" alt=""></p><p>';
                },
                $escaped
            ) ?? $escaped;

            // Highlight detected https URLs in preview
            $escaped = preg_replace(
                '#(https://[^\s<]+)#i',
                '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
                $escaped
            ) ?? $escaped;

            $html .= '<p>'.$escaped.'</p>';
        }

        // Clean empty paragraphs introduced by image splits
        $html = preg_replace('/<p>\s*<\/p>/', '', $html) ?? $html;

        if ($html === '') {
            $html = '<p>'.e(preg_replace('/\[\[IMG:[^\]]+\]\]/', '', $text) ?? $text).'</p>';
        }

        if ($links !== []) {
            $first = $links[0];
            $html .= '<p class="article-detected-link"><strong>Detected link:</strong> '
                .e($first['anchor'])
                .' → <a href="'.e($first['url']).'" target="_blank" rel="noopener noreferrer">'
                .e($first['url']).'</a></p>';
        }

        return $html;
    }

    protected function countWords(string $text): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text) ?: []);
    }

    /**
     * @return array{ok:bool, text:?string, html:?string, word_count:int, links:array, images:array, error_code:?string, error_message:?string}
     */
    protected function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'text' => null,
            'html' => null,
            'word_count' => 0,
            'links' => [],
            'images' => [],
            'error_code' => $code,
            'error_message' => $message,
        ];
    }
}
