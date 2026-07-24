<?php

namespace Tests\Unit;

use App\Services\ContentModeration\ContentModerationEngine;
use PHPUnit\Framework\TestCase;

class ContentModerationHardeningTest extends TestCase
{
    private ContentModerationEngine $engine;

    /** @var array<string, mixed> */
    private array $categories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ContentModerationEngine;
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $this->categories = $cfg['categories'];
    }

    public function test_single_prohibited_keyword_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Entertainment notes',
            text: 'This article mentions casino once in passing about entertainment venues.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertContains('casino', $result['matched_terms']);
    }

    public function test_adult_keyword_porn_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Health guide',
            text: 'This guide discusses porn addiction recovery for families.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('adult', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_xxx_keyword_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Safety',
            text: 'Parents should filter xxx content on school devices.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('adult', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_restricted_domain_mentioned_in_text_without_href_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Odds tips',
            text: 'Check bet365 for the latest match odds before kickoff.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
    }

    public function test_www_domain_without_protocol_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'News',
            text: 'Visit www.pokerstars.com for tournament news and schedule updates.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
    }

    public function test_obfuscated_domain_with_spaced_dots_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Promo',
            text: 'See stake . com for games and bonuses this week.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_obfuscated_domain_with_dot_token_is_rejected(): void
    {
        $result = $this->engine->score(
            title: 'Promo',
            text: 'Join stake[dot]com today for exclusive offers.',
            links: [],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
    }

    public function test_cloaked_href_still_rejected(): void
    {
        $result = $this->engine->score(
            title: 'SEO tips',
            text: 'This article shares helpful SEO strategies for growing organic traffic.',
            links: ['https://www.bet365.com/en/sports'],
            categories: $this->categories,
        );

        $this->assertSame('gambling', $result['detected_category']);
        $this->assertGreaterThanOrEqual(70, $result['max_confidence']);
        $this->assertNotEmpty($result['blocked_urls']);
    }

    public function test_clean_marketing_article_still_passes(): void
    {
        $result = $this->engine->score(
            title: 'Digital marketing guide',
            text: str_repeat(
                'This article explains digital marketing strategies that help brands grow organic traffic with useful content. ',
                8
            ),
            links: ['https://example.com/guide'],
            categories: $this->categories,
        );

        $this->assertLessThan(70, $result['max_confidence']);
        $this->assertEmpty($result['blocked_urls']);
    }

    public function test_casino_royale_exception_does_not_false_positive_alone(): void
    {
        $cfg = require dirname(__DIR__, 2).'/config/content_moderation.php';
        $result = $this->engine->score(
            title: 'Movie night',
            text: 'We watched Casino Royale and talked about the soundtrack and cinematography.',
            links: [],
            categories: $this->categories,
            exceptions: $cfg['exceptions'] ?? [],
        );

        $this->assertLessThan(70, $result['max_confidence']);
    }

    public function test_extract_urls_from_text_finds_www_and_https(): void
    {
        $urls = $this->engine->extractUrlsFromText(
            'Read https://example.com/a and also www.pokerstars.com/play for details.'
        );

        $joined = implode(' ', $urls);
        $this->assertStringContainsString('example.com', $joined);
        $this->assertStringContainsString('pokerstars.com', $joined);
    }
}
