<?php

namespace Tests\Unit;

use App\Services\Catalog\CatalogSearchQuery;
use PHPUnit\Framework\TestCase;

class CatalogSearchQueryTest extends TestCase
{
    private CatalogSearchQuery $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = new CatalogSearchQuery;
    }

    public function test_parse_extracts_metric_operators_and_leaves_text(): void
    {
        $parsed = $this->search->parse('tech blogs da>40 price<=120');

        $this->assertSame('tech blogs', $parsed['text']);
        $this->assertSame(41, $parsed['ranges']['da_min']);
        $this->assertSame(120, $parsed['ranges']['price_max']);
    }

    public function test_parse_supports_plus_range_and_traffic_suffix(): void
    {
        $parsed = $this->search->parse('dr 50+ traffic>10k');

        $this->assertSame('', $parsed['text']);
        $this->assertSame(50, $parsed['ranges']['dr_min']);
        $this->assertSame(10001, $parsed['ranges']['traffic_min']);
    }

    public function test_parse_supports_metric_min_max_span(): void
    {
        $parsed = $this->search->parse('finance da 30-60');

        $this->assertSame('finance', $parsed['text']);
        $this->assertSame(30, $parsed['ranges']['da_min']);
        $this->assertSame(60, $parsed['ranges']['da_max']);
    }

    public function test_merge_does_not_override_explicit_range_inputs(): void
    {
        $merge = $this->search->mergeIntoRequestInput(
            'da>40',
            '',
            ['da_min' => 41],
            ['search' => 'da>40', 'da_min' => '20']
        );

        $this->assertSame(['search' => ''], $merge);
        $this->assertArrayNotHasKey('da_min', $merge);
    }

    public function test_merge_stringifies_parsed_ranges(): void
    {
        $merge = $this->search->mergeIntoRequestInput(
            'da>=50',
            '',
            ['da_min' => 50],
            ['search' => 'da>=50']
        );

        $this->assertSame(['search' => '', 'da_min' => '50'], $merge);
    }

    public function test_parse_caps_character_length(): void
    {
        $raw = str_repeat('ab ', 80);
        $parsed = $this->search->parse($raw);

        $this->assertLessThanOrEqual(CatalogSearchQuery::MAX_SEARCH_CHARS, mb_strlen($parsed['text']));
    }

    public function test_tokens_cap_at_eight(): void
    {
        $tokens = $this->search->tokens('one two three four five six seven eight nine ten');

        $this->assertCount(CatalogSearchQuery::MAX_TOKENS, $tokens);
        $this->assertSame(['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight'], $tokens);
    }

    public function test_tokens_dedupe_before_cap(): void
    {
        $tokens = $this->search->tokens('one one one one one one one one two three');

        $this->assertSame(['one', 'two', 'three'], $tokens);
    }
}
