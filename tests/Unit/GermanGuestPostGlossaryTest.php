<?php

namespace Tests\Unit;

use App\Services\BlogHtmlSanitizer;
use App\Support\GuestPostingGuideI18n;
use Tests\TestCase;

class GermanGuestPostGlossaryTest extends TestCase
{
    public function test_german_guest_post_glossary_matches_the_keyword_record(): void
    {
        $de = GuestPostingGuideI18n::all()['de'];
        $record = GuestPostingGuideI18n::seoRecords()['de'];
        $html = $de['content'];

        $this->assertSame('was-ist-ein-gastbeitrag', $de['slug']);
        $this->assertSame('Was ist ein Gastbeitrag?', $de['title']);
        $this->assertSame($de['meta_title'], $record['seo_title']);
        $this->assertSame($de['meta_description'], $record['meta_description']);
        $this->assertGreaterThanOrEqual(50, mb_strlen($de['meta_title']));
        $this->assertLessThanOrEqual(70, mb_strlen($de['meta_title']));
        $this->assertLessThanOrEqual(180, mb_strlen($de['meta_description']));
        $this->assertSame('informational', $record['search_intent']);
        $this->assertSame('25 Easy', $record['difficulty']);
        $this->assertStringContainsString('not Semrush', $record['difficulty_note']);
        $this->assertSame('Medium', $record['demand_band']);
        $this->assertSame(40, $record['traffic_potential']);
        $this->assertSame(56, $record['opportunity_score']);

        $this->assertGreaterThanOrEqual(1500, $record['word_count']);
        $this->assertLessThanOrEqual(2500, $record['word_count']);
        $this->assertSame(GuestPostingGuideI18n::plainWordCount($html), $record['word_count']);

        preg_match('/glossary-definition">(.*?)<\/p>/s', $html, $definition);
        $definitionWords = preg_split('/\s+/u', trim(strip_tags($definition[1] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->assertGreaterThanOrEqual(40, count($definitionWords));
        $this->assertLessThanOrEqual(60, count($definitionWords));

        $words = preg_split('/\s+/u', trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $opening = mb_strtolower(implode(' ', array_slice($words, 0, 150)));
        $this->assertStringContainsString('was ist ein gastbeitrag', $opening);
        $this->assertStringContainsString('was ist ein gastbeitrag', mb_strtolower($de['title']));
        $this->assertStringContainsString('was ist ein gastbeitrag', mb_strtolower($de['meta_title']));
        $this->assertStringContainsString('was ist ein gastbeitrag', mb_strtolower($de['meta_description']));
        $this->assertMatchesRegularExpression('/<h2[^>]*>Was ist ein Gastbeitrag/u', $html);

        $this->assertSame(1, substr_count($html, $record['money_url']));
        $this->assertStringNotContainsString('/de/marktplatz', $html);
        foreach ($record['sister_urls'] as $url) {
            $this->assertStringContainsString($url, $html);
        }

        $clean = app(BlogHtmlSanitizer::class)->sanitize($html);
        $this->assertStringContainsString('id="gastbeitrag-oder-nicht"', $clean);
        $this->assertStringContainsString('href="#gastbeitrag-oder-nicht"', $clean);
        $this->assertDoesNotMatchRegularExpression('/<a href="#[^"]*"[^>]*target="_blank"/', $clean);
        $this->assertStringNotContainsString('guest-posting-guide-workflow', $clean);
        $this->assertStringNotContainsString('Find sites', $clean);
        $this->assertStringNotContainsString('Rechtsbegriffe', $clean);
        $this->assertStringNotContainsString('Schreibweise tun es nicht', $clean);
        $this->assertNull($record['image']['filename']);

        $this->assertStringContainsString('<table>', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, '<h3>'));
        $this->assertStringContainsString('Österreich', $html);
        $this->assertStringContainsString('Schweiz', $html);
        $this->assertStringNotContainsString('monatliche Suchanfragen', $html);
        $this->assertStringNotContainsString('Keyword Difficulty', $html);
    }
}
