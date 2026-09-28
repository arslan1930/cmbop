<?php

namespace Tests\Unit;

use App\Services\ContentModeration\ContentModerationEngine;
use App\Services\ContentUpload\ArticleHtmlSanitizer;
use App\Services\ContentUpload\ArticleLanguageGuard;
use App\Services\ContentUpload\ArticlePreviewHtml;
use PHPUnit\Framework\TestCase;

class ContentLibraryModerationUxTest extends TestCase
{
    public function test_preview_html_normalizes_storage_paths(): void
    {
        // Local public disk (= APP_URL/storage) keeps root-relative paths so
        // previews work when the browser host differs from APP_URL.
        $out = ArticlePreviewHtml::normalizeSrc(
            '/storage/content-articles/1/a.png',
            'https://example.test',
            'https://example.test/storage'
        );
        $this->assertSame('/storage/content-articles/1/a.png', $out);

        $rewritten = ArticlePreviewHtml::normalizeSrc(
            'https://wrong-host.test/storage/content-articles/1/a.png',
            'https://example.test',
            'https://example.test/storage'
        );
        $this->assertSame('/storage/content-articles/1/a.png', $rewritten);

        // External CDN public disk still gets absolute URLs.
        $cdn = ArticlePreviewHtml::normalizeSrc(
            '/storage/content-articles/1/a.png',
            'https://example.test',
            'https://cdn.example.test/media'
        );
        $this->assertSame('https://cdn.example.test/media/content-articles/1/a.png', $cdn);

        $html = '<p>Hi</p><p><img src="https://old.example/storage/content-articles/1/a.png" alt=""></p>';
        $normalized = ArticlePreviewHtml::normalize($html);
        $this->assertStringContainsString('src="/storage/content-articles/1/a.png"', $normalized);
        $this->assertStringContainsString('<img', $normalized);
        $this->assertSame('', ArticlePreviewHtml::normalizeSrc('javascript:alert(1)'));
        $this->assertSame('', ArticlePreviewHtml::normalizeSrc('vbscript:msgbox(1)'));
    }

    public function test_html_sanitizer_keeps_media_fallback_images_as_storage_paths(): void
    {
        $sanitizer = new ArticleHtmlSanitizer;
        $clean = $sanitizer->sanitize(
            '<p>Body</p><p><img src="/media/content-articles/1/a.png" alt="Chart"></p>'
        );

        $this->assertStringContainsString('src="/storage/content-articles/1/a.png"', $clean);
        $this->assertStringNotContainsString('src="/media/content-articles/1/a.png"', $clean);
    }

    public function test_html_sanitizer_strips_preview_download_chrome(): void
    {
        $sanitizer = new ArticleHtmlSanitizer;
        $clean = $sanitizer->sanitize(
            '<p>Body</p><div class="article-img-wrap">'
            .'<img src="/storage/content-articles/1/a.png" alt="Chart">'
            .'<button type="button" class="article-img-download btn btn-sm btn-dark">'
            .'<i class="fa fa-download me-1"></i>Download</button></div>'
        );

        $this->assertStringContainsString('src="/storage/content-articles/1/a.png"', $clean);
        $this->assertStringContainsString('Body', $clean);
        $this->assertStringNotContainsString('article-img-wrap', $clean);
        $this->assertStringNotContainsString('Download', $clean);
        $this->assertStringNotContainsString('<button', $clean);
    }

    public function test_html_sanitizer_counts_img_tags(): void
    {
        $sanitizer = new ArticleHtmlSanitizer;

        $this->assertSame(0, $sanitizer->countImages(null));
        $this->assertSame(0, $sanitizer->countImages(''));
        $this->assertSame(0, $sanitizer->countImages('<p>imagine this without pictures</p>'));
        $this->assertSame(2, $sanitizer->countImages(
            '<p><img src="/storage/a.png" alt=""></p><IMG SRC="/storage/b.png" alt="B">'
        ));
    }

    public function test_html_sanitizer_drops_unquoted_event_handlers(): void
    {
        $sanitizer = new ArticleHtmlSanitizer;
        $clean = $sanitizer->sanitize(
            '<p>Body</p><img src="/storage/a.png" onerror=alert(1) alt="Chart">'
            .'<a href="https://example.com/x" onclick=alert(1)>keyword</a>'
            .'<script>alert(1)</script>'
        );

        $this->assertStringContainsString('Body', $clean);
        $this->assertStringContainsString('src="/storage/a.png"', $clean);
        $this->assertStringContainsString('href="https://example.com/x"', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
    }

    public function test_html_sanitizer_keeps_content_after_a_body_close(): void
    {
        $sanitizer = new ArticleHtmlSanitizer;
        $clean = $sanitizer->sanitize(
            '<p>Before</p></body><script>alert(1)</script><p>After</p><img src=x onerror=alert(1)>'
        );

        $this->assertStringContainsString('Before', $clean);
        $this->assertStringContainsString('After', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
    }

    public function test_html_sanitizer_drops_embedded_data_images(): void
    {
        $sanitizer = new ArticleHtmlSanitizer;
        $clean = $sanitizer->sanitize(
            '<p>Body</p><p><img src="data:image/png;base64,iVBORw0KGgo=" alt="Huge"></p>'
        );

        $this->assertStringContainsString('Body', $clean);
        $this->assertStringNotContainsString('data:image', $clean);
        $this->assertStringNotContainsString('<img', $clean);
    }

    public function test_normalize_strips_legacy_detected_link_footer(): void
    {
        $html = '<p>Body with <a href="https://example.com/x">keyword</a>.</p>'
            .'<p class="article-detected-link"><strong>Detected link:</strong> keyword → '
            .'<a href="https://example.com/x">https://example.com/x</a></p>';

        $normalized = ArticlePreviewHtml::normalize($html);

        $this->assertStringContainsString('Body with', $normalized);
        $this->assertStringContainsString('keyword', $normalized);
        $this->assertStringNotContainsString('article-detected-link', $normalized);
        $this->assertStringNotContainsString('Detected link', $normalized);
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

    public function test_engine_rejects_casino_only_in_url_path(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $result = $engine->score(
            title: 'Marketing tips',
            text: 'This article shares helpful SEO strategies for growing organic traffic with useful content.',
            links: ['https://example.com/best-online-casino-bonus'],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_engine_rejects_glued_filename_and_punctuation_split_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        foreach ([
            'casinobanner.jpg',
            'casinologo.png',
            'mycasino.docx',
            'Play at the best online cas+ino tonight.',
            'Play at the best online cas|ino tonight.',
            'Play at the best online cas(ino) tonight.',
        ] as $text) {
            $result = $engine->score(
                title: 'Marketing tips',
                text: $text,
                links: [],
                categories: $categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $text);
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $text);
        }
    }

    public function test_engine_rejects_underscore_and_camelcase_casino_slugs(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        foreach ([
            'best_online_casino_bonus.jpg',
            'BestOnlineCasinoBonus.docx',
            'https://cdn.example/best_online_casino_bonus',
        ] as $text) {
            $result = $engine->score(
                title: 'Marketing tips',
                text: $text,
                links: [],
                categories: $categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $text);
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $text);
        }

        $result = $engine->score(
            title: 'Marketing tips',
            text: 'This article shares helpful SEO strategies for growing organic traffic with useful content.',
            links: ['https://example.com/best_online_casino_bonus'],
            categories: $categories,
        );
        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_engine_rejects_html_entity_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        foreach ([
            'Play at the best online cas&#105;no tonight.',
            'Play at the best online cas&#x69;no tonight.',
            'Play at the best online cas&amp;#105;no tonight.',
        ] as $text) {
            $result = $engine->score(
                title: 'Marketing tips',
                text: $text,
                links: [],
                categories: $categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $text);
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $text);
        }
    }

    public function test_engine_rejects_percent_encoded_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        foreach ([
            'Play at the best online cas%69no tonight.',
            'Play at the best online cas%2569no tonight.',
            'Play at the best online cas％６９no tonight.',
        ] as $text) {
            $result = $engine->score(
                title: 'Marketing tips',
                text: $text,
                links: [],
                categories: $categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $text);
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $text);
        }

        $result = $engine->score(
            title: 'Marketing tips',
            text: 'This article shares helpful SEO strategies for growing organic traffic with useful content.',
            links: ['https://example.com/best-online-cas%69no-bonus'],
            categories: $categories,
        );
        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_engine_rejects_css_escaped_and_slash_split_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        foreach ([
            'Play at the best online cas\69no tonight.',
            'Play at the best online cas\\ino tonight.',
            'Play at the best online cas/ino tonight.',
        ] as $text) {
            $result = $engine->score(
                title: 'Marketing tips',
                text: $text,
                links: [],
                categories: $categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $text);
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $text);
        }
    }

    public function test_engine_rejects_fullwidth_and_homoglyph_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        foreach ([
            'Play at the best online ｃａｓｉｎｏ tonight.',
            'Play at the best online ca'."\u{0455}".'ino tonight.',
        ] as $text) {
            $result = $engine->score(
                title: 'Marketing tips',
                text: $text,
                links: [],
                categories: $categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $text);
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $text);
        }
    }

    public function test_engine_rejects_leet_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $result = $engine->score(
            title: 'Marketing tips',
            text: 'Play at the best online casin0 tonight and claim your bonus.',
            links: [],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_engine_rejects_zero_width_and_split_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        foreach ([
            'Play at the best online cas'."\u{200B}".'ino tonight.',
            'Play at the best online cas-ino tonight.',
            'Play at the best online c a s i n o tonight.',
        ] as $text) {
            $result = $engine->score(
                title: 'Marketing tips',
                text: $text,
                links: [],
                categories: $categories,
            );
            $this->assertSame('gambling', $result['detected_category'], $text);
            $this->assertGreaterThanOrEqual(70, $result['max_confidence'], $text);
        }
    }

    public function test_engine_rejects_combining_mark_casino(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $hidden = 'Play at the best online c'."\u{0338}".'a'."\u{0338}".'s'."\u{0338}".'i'."\u{0338}".'n'."\u{0338}".'o tonight.';
        $result = $engine->score(
            title: 'Marketing tips',
            text: $hidden,
            links: [],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_engine_rejects_casino_past_the_first_score_window(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $pad = str_repeat('Useful editorial copy about software teams. ', 5500);
        $this->assertGreaterThan(ContentModerationEngine::SCORE_TEXT_CHARS, mb_strlen($pad));

        $result = $engine->score(
            title: 'Marketing tips',
            text: $pad.'Play at the best online casino tonight and claim your bonus.',
            links: [],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_engine_rejects_casino_split_across_score_windows(): void
    {
        $engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $categories = $cfg['categories'];

        $text = str_repeat('x', ContentModerationEngine::SCORE_TEXT_CHARS - 4)
            .' casino tonight and claim your bonus.';

        $result = $engine->score(
            title: 'Marketing tips',
            text: $text,
            links: [],
            categories: $categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
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
        $this->assertArrayHasKey('no', $cfg['categories']['gambling']['keywords_by_locale']);
        $this->assertArrayHasKey('ca', $cfg['categories']['gambling']['keywords_by_locale']);
        $this->assertArrayHasKey('de', $cfg['categories']['adult']['keywords_by_locale']);
        $this->assertArrayHasKey('zh', $cfg['categories']['adult']['keywords_by_locale']);
        $this->assertArrayHasKey('ar', $cfg['categories']['adult']['keywords_by_locale']);
        $this->assertContains('pornhub', $cfg['categories']['adult']['domains']);
    }

    public function test_language_guard_rejects_slovak_under_german_selection(): void
    {
        $guard = new ArticleLanguageGuard;
        $slovak = str_repeat('Toto je slovenský článok o marketingu a SEO pre firmy ktoré chcú rásť. ', 12)
            .'Je dôležité, že text je napísaný po slovensky a používa bežné slová ako ktorý ktorá ktoré sú bola bolo.';

        $result = $guard->assertMatches($slovak, 'de');
        $this->assertFalse($result['ok']);
        $this->assertSame('fail', $result['severity']);
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
        $this->assertSame('pass', $result['severity']);
        $this->assertSame('en', $result['detected']);
    }

    public function test_language_guard_skips_short_mixed_snippets(): void
    {
        $guard = new ArticleLanguageGuard;
        // Too short for reliable stopword scoring — must not hard-block.
        $short = 'Der Marketing tip for your brand growth and SEO.';
        $result = $guard->assertMatches($short, 'en');
        $this->assertTrue($result['ok']);
        $this->assertSame('pass', $result['severity']);
        $this->assertNull($result['message']);
    }

    public function test_language_guard_warns_on_mixed_english_german_instead_of_blocking(): void
    {
        $guard = new ArticleLanguageGuard;
        // Mixed EN/DE signals: selected English should stay orderable (warn or pass), not fail.
        $mixed = str_repeat(
            'This article explains digital marketing strategies that help brands grow. '
            .'Die besten Tipps für Unternehmen mit dem Fokus auf Content und SEO. '
            .'Readers will find clear tips about content and conversion which are useful. '
            .'Mit der richtigen Strategie werden auch deutsche Leser angesprochen. ',
            6
        );

        $result = $guard->assertMatches($mixed, 'en');
        $this->assertTrue($result['ok'], 'Mixed copy must not hard-block: '.json_encode($result));
        $this->assertContains($result['severity'], ['pass', 'warn']);
        if ($result['severity'] === 'warn') {
            $this->assertNotEmpty($result['message']);
        }
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
