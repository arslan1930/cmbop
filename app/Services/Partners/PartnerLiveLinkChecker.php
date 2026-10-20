<?php

namespace App\Services\Partners;

use App\Services\Marketplace\LinkPresenceChecker;
use Illuminate\Support\Carbon;

class PartnerLiveLinkChecker
{
    public function __construct(private LinkPresenceChecker $presence) {}

    /**
     * Fetch the live page (HTTP 200–399), confirm the target URL is linked,
     * and report the actual rel plus whether the anchor text matches.
     *
     * @return array{
     *     ok: bool,
     *     status: string,
     *     http_status: ?int,
     *     href_found: bool,
     *     rel: ?string,
     *     rel_tokens: list<string>,
     *     anchor_match: bool,
     *     matched_anchor: ?string,
     *     checked_at: Carbon,
     *     message: string
     * }
     */
    public function check(string $liveUrl, string $targetUrl, string $anchorText = ''): array
    {
        $presence = $this->presence->check($liveUrl, $targetUrl);
        $httpStatus = $presence['status'] ?? null;
        $httpOk = is_int($httpStatus) && $httpStatus >= 200 && $httpStatus < 400;
        $hrefFound = (bool) ($presence['href_found'] ?? false);
        $rel = null;
        $relTokens = [];
        $anchorMatch = false;
        $matchedAnchor = null;

        if ($httpOk && $hrefFound && ($presence['body'] ?? '') !== '') {
            $match = $this->inspectAnchors((string) $presence['body'], $targetUrl, $anchorText);
            $rel = $match['rel'];
            $relTokens = $match['rel_tokens'];
            $anchorMatch = $match['anchor_match'];
            $matchedAnchor = $match['matched_anchor'];
        }

        $ok = $httpOk && $hrefFound;
        $message = ! $httpOk
            ? (string) ($presence['message'] ?? 'Live URL is not reachable.')
            : ($hrefFound
                ? 'Target URL is linked.'.($anchorMatch ? ' Anchor text matches.' : ' Anchor text does not match.')
                : 'Live page is reachable but the target URL was not found as a hyperlink.');

        return [
            'ok' => $ok,
            'status' => $ok ? 'ok' : 'failed',
            'http_status' => $httpStatus,
            'href_found' => $hrefFound,
            'rel' => $rel,
            'rel_tokens' => $relTokens,
            'anchor_match' => $anchorMatch,
            'matched_anchor' => $matchedAnchor,
            'checked_at' => now(),
            'message' => $message,
        ];
    }

    /**
     * @return array{rel: ?string, rel_tokens: list<string>, anchor_match: bool, matched_anchor: ?string}
     */
    public function inspectAnchors(string $html, string $targetUrl, string $anchorText): array
    {
        $best = [
            'rel' => null,
            'rel_tokens' => [],
            'anchor_match' => false,
            'matched_anchor' => null,
        ];
        if (! preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return $best;
        }

        $wanted = $this->normalizeAnchor($anchorText);
        foreach ($matches as $match) {
            $attrs = (string) ($match[1] ?? '');
            $inner = html_entity_decode(strip_tags((string) ($match[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $href = $this->attribute($attrs, 'href');
            if ($href === '' || ! $this->hrefMatches($href, $targetUrl)) {
                continue;
            }
            $relRaw = strtolower($this->attribute($attrs, 'rel'));
            $tokens = $relRaw === '' ? [] : (preg_split('/\s+/', $relRaw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $known = array_values(array_intersect($tokens, ['nofollow', 'sponsored', 'ugc']));
            $rel = $this->relLabel($known);
            $anchorMatch = $wanted !== '' && $this->normalizeAnchor($inner) === $wanted;
            $candidate = [
                'rel' => $rel,
                'rel_tokens' => $known !== [] ? $known : ['dofollow'],
                'anchor_match' => $anchorMatch,
                'matched_anchor' => trim($inner) !== '' ? trim($inner) : null,
            ];
            if ($anchorMatch || $best['rel'] === null) {
                $best = $candidate;
            }
            if ($anchorMatch) {
                break;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $tokens
     */
    public function relLabel(array $tokens): string
    {
        if (in_array('sponsored', $tokens, true)) {
            return in_array('nofollow', $tokens, true) ? 'nofollow sponsored' : 'sponsored';
        }
        if (in_array('ugc', $tokens, true)) {
            return in_array('nofollow', $tokens, true) ? 'nofollow ugc' : 'ugc';
        }
        if (in_array('nofollow', $tokens, true)) {
            return 'nofollow';
        }

        return 'dofollow';
    }

    private function hrefMatches(string $href, string $targetUrl): bool
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '') {
            return false;
        }
        $snippet = '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">x</a>';

        return $this->presence->hrefContainsTarget($snippet, $targetUrl);
    }

    private function attribute(string $attrs, string $name): string
    {
        if (preg_match('/\b'.preg_quote($name, '/').'\s*=\s*(["\'])(.*?)\1/i', $attrs, $m)) {
            return html_entity_decode((string) $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function normalizeAnchor(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return mb_strtolower($text);
    }
}
