<?php

namespace Tests\Unit;

use App\Services\BlogHtmlSanitizer;
use App\Support\GuestPostingGuideI18n;
use Tests\TestCase;

class ItalianGuestPostGlossaryTest extends TestCase
{
    public function test_italian_guest_post_glossary_matches_the_keyword_record(): void
    {
        $it = GuestPostingGuideI18n::all()['it'];
        $record = GuestPostingGuideI18n::seoRecords()['it'];
        $html = $it['content'];

        $this->assertSame('cose-un-guest-post', $it['slug']);
        $this->assertSame('Cos’è un guest post?', $it['title']);
        $this->assertSame($it['meta_title'], $record['seo_title']);
        $this->assertSame($it['meta_description'], $record['meta_description']);
        $this->assertGreaterThanOrEqual(50, mb_strlen($it['meta_title']));
        $this->assertLessThanOrEqual(70, mb_strlen($it['meta_title']));
        $this->assertLessThanOrEqual(180, mb_strlen($it['meta_description']));
        $this->assertLessThanOrEqual(255, mb_strlen($it['excerpt']));
        $this->assertSame('informational', $record['search_intent']);
        $this->assertSame('50 Medium', $record['difficulty']);
        $this->assertStringContainsString('not Semrush', $record['difficulty_note']);
        $this->assertSame('High', $record['demand_band']);
        $this->assertSame(55, $record['traffic_potential']);
        $this->assertSame(48, $record['opportunity_score']);
        $this->assertSame('Medium', $record['competition']);
        $this->assertSame('Low', $record['business_fit']);
        $this->assertSame('L', $record['effort']);

        $this->assertGreaterThanOrEqual(1500, $record['word_count']);
        $this->assertLessThanOrEqual(2500, $record['word_count']);
        $this->assertSame(GuestPostingGuideI18n::plainWordCount($html), $record['word_count']);

        preg_match('/glossary-definition">(.*?)<\/p>/s', $html, $definition);
        $definitionWords = preg_split('/\s+/u', trim(strip_tags($definition[1] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->assertGreaterThanOrEqual(40, count($definitionWords));
        $this->assertLessThanOrEqual(60, count($definitionWords));

        $words = preg_split('/\s+/u', trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $opening = mb_strtolower(implode(' ', array_slice($words, 0, 150)));
        $this->assertStringContainsString('cos’è un guest post', $opening);
        $this->assertStringContainsString('cos’è un guest post', mb_strtolower($it['title']));
        $this->assertStringContainsString('cos’è un guest post', mb_strtolower($it['meta_title']));
        $this->assertStringContainsString('cos’è un guest post', mb_strtolower($it['meta_description']));

        $this->assertSame(1, substr_count($html, $record['money_url']));
        $this->assertStringNotContainsString('/it/mercato', $html);
        $this->assertStringNotContainsString('Österreich', $html);
        $this->assertStringNotContainsString('Schweiz', $html);
        foreach ($record['sister_urls'] as $url) {
            $this->assertStringContainsString($url, $html);
        }

        $clean = app(BlogHtmlSanitizer::class)->sanitize($html);
        $this->assertStringContainsString('id="passaggi"', $clean);
        $this->assertStringContainsString('href="#passaggi"', $clean);
        $this->assertDoesNotMatchRegularExpression('/<a href="#[^"]*"[^>]*target="_blank"/', $clean);
        $this->assertStringContainsString('guest-posting-guide-workflow-it.svg', $clean);
        $this->assertStringNotContainsString('guest-posting-guide-workflow.jpg', $clean);
        $this->assertStringNotContainsString('Find sites', $clean);
        $this->assertStringNotContainsString('Guest Article Draft', $clean);
        $this->assertSame('guest-posting-guide-workflow-it.svg', $record['image']['filename']);
        $this->assertFalse($record['image']['ai_generated']);
        $this->assertStringContainsString($record['image']['alt'], $clean);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('Cosa sono i guest post', $html);
        $this->assertStringContainsString('Pubbliredazionale', $html);
        $this->assertDoesNotMatchRegularExpression('/<h2[^>]*>[^<]*[Pp]ubbliredazionale/u', $html);
        $this->assertStringNotContainsString('<h3>Cosa significa rel sponsored', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, '<h3>'));
        $this->assertStringNotContainsString('indirizzo live', $html);
        $this->assertStringNotContainsString('la home', $html);
        $this->assertStringNotContainsString('quale dei due', $html);
        $this->assertStringNotContainsString('non si aggiunge una frase', $html);
        $this->assertStringNotContainsString('Keyword Difficulty', $html);
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*euro\b/iu', $html);
    }
}
