<?php

namespace Tests\Unit;

use App\Models\ContentSubmission;
use App\Models\Site;
use PHPUnit\Framework\TestCase;

class AnyLanguageSiteMatchTest extends TestCase
{
    private function site(string $language): Site
    {
        $site = new Site;
        $site->language = $language;
        $site->languages = [$language];
        $site->country = $language;
        $site->countries = [$language];

        return $site;
    }

    private function article(string $language): ContentSubmission
    {
        $article = new ContentSubmission;
        $article->language = $language;

        return $article;
    }

    public function test_any_article_language_matches_any_site(): void
    {
        $this->assertTrue($this->article('en')->matchesSite($this->site('de')));
        $this->assertTrue($this->article('de')->matchesSite($this->site('nl')));
        $this->assertTrue($this->article('nl')->matchesSite($this->site('fr')));
        $this->assertTrue($this->article('sk')->matchesSite($this->site('en')));
    }

    public function test_language_fits_helper_always_allows(): void
    {
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('nl', ['de']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('de', ['fr']));
        $this->assertTrue(ContentSubmission::languageFitsSiteLanguages('en', []));
    }
}
