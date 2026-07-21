<?php

namespace Tests\Unit;

use App\Models\ContentSubmission;
use App\Models\Site;
use PHPUnit\Framework\TestCase;

class EnglishUniversalSiteMatchTest extends TestCase
{
    private function site(string $language, ?string $country = null): Site
    {
        $site = new Site;
        $site->language = $language;
        $site->languages = [$language];
        $site->country = $country ?: $language;
        $site->countries = [$site->country];

        return $site;
    }

    private function article(string $language): ContentSubmission
    {
        $article = new ContentSubmission;
        $article->language = $language;

        return $article;
    }

    public function test_english_article_matches_any_site_language(): void
    {
        $en = $this->article('en');

        $this->assertTrue($en->matchesSite($this->site('de', 'de')));
        $this->assertTrue($en->matchesSite($this->site('fr', 'fr')));
        $this->assertTrue($en->matchesSite($this->site('nl', 'nl')));
        $this->assertTrue($en->matchesSite($this->site('en', 'us')));
    }

    public function test_german_article_only_matches_german_sites(): void
    {
        $de = $this->article('de');

        $this->assertTrue($de->matchesSite($this->site('de', 'de')));
        $this->assertFalse($de->matchesSite($this->site('nl', 'nl')));
        $this->assertFalse($de->matchesSite($this->site('fr', 'fr')));
        $this->assertFalse($de->matchesSite($this->site('en', 'us')));
    }

    public function test_language_fits_site_languages_helper(): void
    {
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en', ['de']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('de', ['de']));
        $this->assertFalse(ContentSubmission::languageFitsSiteLanguages('nl', ['de']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('de', []));
    }
}
