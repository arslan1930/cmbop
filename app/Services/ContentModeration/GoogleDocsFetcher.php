<?php

namespace App\Services\ContentModeration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleDocsFetcher
{
    /**
     * @return array{ok:bool, document_id:?string, title:?string, text:?string, html:?string, links:array<int,string>, error_code:?string, error_message:?string}
     */
    public function fetch(string $url): array
    {
        $url = trim($url);
        if (!$this->isGoogleDocsUrl($url)) {
            return $this->fail('invalid_url', 'Please provide a valid Google Docs URL.');
        }

        $docId = $this->extractDocumentId($url);
        if (!$docId) {
            return $this->fail('invalid_url', 'We could not read a document ID from that Google Docs link.');
        }

        $txtUrl = "https://docs.google.com/document/d/{$docId}/export?format=txt";
        $htmlUrl = "https://docs.google.com/document/d/{$docId}/export?format=html";

        try {
            $txtResponse = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'SEOLinkBuildings-ContentModeration/1.0'])
                ->get($txtUrl);

            if ($txtResponse->status() === 404) {
                return $this->fail('deleted', 'This Google Doc could not be found. It may have been deleted.');
            }

            if (in_array($txtResponse->status(), [401, 403], true) || $this->looksLikeLoginPage($txtResponse->body())) {
                return $this->fail(
                    'private',
                    'This Google Doc is private or missing share permissions. Please set sharing to “Anyone with the link can view” and try again.'
                );
            }

            if (!$txtResponse->successful()) {
                return $this->fail('network', 'We could not retrieve the document right now. Please try again in a moment.');
            }

            $text = $this->normalizeText($txtResponse->body());
            if ($text === '') {
                return $this->fail('empty', 'The document appears to be empty. Please submit an article with content.');
            }

            $html = '';
            $links = [];
            $title = null;
            try {
                $htmlResponse = Http::timeout(12)
                    ->withHeaders(['User-Agent' => 'SEOLinkBuildings-ContentModeration/1.0'])
                    ->get($htmlUrl);
                if ($htmlResponse->successful()) {
                    $html = (string) $htmlResponse->body();
                    $links = $this->extractLinks($html);
                    $title = $this->extractTitle($html);
                }
            } catch (\Throwable) {
                // HTML enrich is optional
            }

            return [
                'ok' => true,
                'document_id' => $docId,
                'title' => $title,
                'text' => $text,
                'html' => $html,
                'links' => $links,
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Throwable $e) {
            return $this->fail('network', 'Network error while retrieving the Google Doc. Please try again.');
        }
    }

    public function isGoogleDocsUrl(string $url): bool
    {
        return (bool) preg_match('/^https?:\/\/(docs\.google\.com|drive\.google\.com)\/.+/i', $url);
    }

    public function extractDocumentId(string $url): ?string
    {
        if (preg_match('/document\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/file\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function looksLikeLoginPage(string $body): bool
    {
        $body = Str::lower($body);

        return str_contains($body, 'accounts.google.com')
            || str_contains($body, 'sign in')
            || str_contains($body, 'request access');
    }

    protected function normalizeText(string $raw): string
    {
        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n?/", "\n", $text) ?: $text;
        $text = preg_replace("/[ \t]+/", ' ', $text) ?: $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?: $text;

        return trim($text);
    }

    /**
     * @return array<int, string>
     */
    protected function extractLinks(string $html): array
    {
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);
        $links = [];
        foreach ($matches[1] ?? [] as $href) {
            if (str_starts_with($href, 'http')) {
                $links[] = $href;
            }
        }

        return array_values(array_unique($links));
    }

    protected function extractTitle(string $html): ?string
    {
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = preg_replace('/\s*-\s*Google Docs$/i', '', $title) ?: $title;

            return $title !== '' ? $title : null;
        }

        return null;
    }

    protected function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'document_id' => null,
            'title' => null,
            'text' => null,
            'html' => null,
            'links' => [],
            'error_code' => $code,
            'error_message' => $message,
        ];
    }
}
