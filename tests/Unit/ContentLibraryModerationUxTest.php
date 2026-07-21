<?php

namespace Tests\Unit;

use App\Services\ContentModeration\ContentModerationEngine;
use App\Services\ContentUpload\ArticleLanguageGuard;
use App\Services\ContentUpload\ArticlePreviewHtml;
use PHPUnit\Framework\TestCase;

class ContentLibraryModerationUxTest extends TestCase
{
    public function test_preview_html_normalizes_storage_paths(): void
    {
        $html = '<p>Hi</p><p><img src="/storage/content-articles/1/a.png" alt=""></p>';
        $out = ArticlePreviewHtml::normalizeSrc('/storage/content-articles/1/a.png', 'https://example.test', 'https://example.test/storage');
        $this->assertSame('https://example.test/storage/content-articles/1/a.png', $out);

        $normalized = ArticlePreviewHtml::normalize($html);
        $this->assertStringContainsString('content-articles/1/a.png', $normalized);
        $this->assertStringContainsString('<img', $normalized);
    }

    public function test_highlight_terms_wraps_matches_outside_tags(): void
    {
        $html = '<p>Visit our casino tonight</p><p><a href="/x">casino</a></p>';
        $out = ArticlePreviewHtml::highlightTerms($html, ['casino']);
        $this->assertStringContainsString('<mark class="slb-mod-hit">casino</mark>', $out);
        $this->assertStringContainsString('<a href="/x">', $out);
    }

    public function test_highlight_blocked_links_marks_cloaked_anchors(): void
    {
        $html = '<p>Read more <a href="https://www.bet365.com/sports">click here</a> for tips.</p>';
        $out = ArticlePreviewHtml::highlightBlockedLinks($html, ['https://www.bet365.com/sports']);
        $this->assertStringContainsString('slb-mod-hit-link', $out);
        $this->assertStringContainsString('<mark class="slb-mod-hit">click here</mark>', $out);
    }

    public function test_gambling_engine_matches_german_keywords(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $result = $engine->score(
            title: 'Sportnachrichten',
            text: 'Die besten Sportwetten und Online Casino Tipps für Deutschland mit Wettanbieter Vergleich und Poker Turniere.',
            links: [],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertNotEmpty($result['matched_terms']);
        $this->assertGreaterThanOrEqual(60, $result['max_confidence']);
    }

    public function test_engine_rejects_cloaked_gambling_url_with_clean_anchor_text(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $result = $engine->score(
            title: 'Marketing tips',
            text: 'This article shares helpful SEO strategies for growing organic traffic with useful content.',
            links: ['https://www.bet365.com/en/sports'],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
        $this->assertStringContainsString('bet365', implode(' ', $result['matched_terms']));
    }

    public function test_engine_rejects_adult_porn_domain(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $result = $engine->score(
            title: 'Travel guide',
            text: 'Discover the best museums and cafes in the city with this short travel checklist.',
            links: ['https://www.pornhub.com/video/123'],
            categories: $categories,
        );

        $this->assertSame('adult', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
    }

    public function test_adult_category_is_enabled_by_default_in_config_file(): void
    {
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $this->assertTrue((bool) ($cfg['categories']['adult']['enabled'] ?? false));
        $this->assertTrue((bool) ($cfg['categories']['gambling']['enabled'] ?? false));
        $this->assertArrayHasKey('de', $cfg['categories']['gambling']['keywords_by_locale']);
        $this->assertArrayHasKey('sk', $cfg['categories']['gambling']['keywords_by_locale']);
        $this->assertContains('pornhub', $cfg['categories']['adult']['domains']);
    }

    public function test_language_guard_rejects_slovak_under_german_selection(): void
    {
        $guard = new ArticleLanguageGuard;
        $slovak = str_repeat('Toto je slovenský článok o marketingu a SEO pre firmy ktoré chcú rásť. ', 12)
            .'Je dôležité, že text je napísaný po slovensky a používa bežné slová ako ktorý ktorá ktoré sú bola bolo.';

        $result = $guard->assertMatches($slovak, 'de');
        $this->assertFalse($result['ok']);
        $this->assertSame('sk', $result['detected']);
        $this->assertStringContainsString('DE', $result['message'] ?? '');
    }

    public function test_language_guard_accepts_english_when_english_selected(): void
    {
        $guard = new ArticleLanguageGuard;
        $english = str_repeat('This article explains digital marketing strategies that help brands grow organic traffic with useful content. ', 10)
            .'Readers will find clear tips about SEO, content, and conversion which are useful for their business.';

        $result = $guard->assertMatches($english, 'en');
        $this->assertTrue($result['ok']);
        $this->assertSame('en', $result['detected']);
    }

    public function test_normalize_link_list_accepts_anchor_url_objects(): void
    {
        $engine = new ContentModerationEngine;
        $urls = $engine->normalizeLinkList([
            ['anchor' => 'click here', 'url' => 'https://pokerstars.com/play'],
            'https://example.com',
        ]);

        $this->assertSame([
            'https://pokerstars.com/play',
            'https://example.com',
        ], $urls);
    }
}
