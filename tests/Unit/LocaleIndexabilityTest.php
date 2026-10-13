<?php

namespace Tests\Unit;

use App\Support\LocaleIndexability;
use Illuminate\Http\Request;
use Tests\TestCase;

class LocaleIndexabilityTest extends TestCase
{
    public function test_parent_locale_maps_regional_clones(): void
    {
        $this->assertSame('en', LocaleIndexability::parentLocale('us'));
        $this->assertSame('de', LocaleIndexability::parentLocale('at'));
        $this->assertSame('de', LocaleIndexability::parentLocale('ch'));
        $this->assertSame('en', LocaleIndexability::parentLocale('pt'));
        $this->assertSame('en', LocaleIndexability::parentLocale('bg'));
        $this->assertSame('en', LocaleIndexability::parentLocale('en'));
        $this->assertSame('en', LocaleIndexability::parentLocale('de'));
    }

    public function test_us_pages_are_not_indexable_until_copy_differs(): void
    {
        foreach (['', 'about', 'marketplace', 'pricing', 'faq', 'contact', 'blog'] as $path) {
            $this->assertFalse(LocaleIndexability::isIndexable('us', $path), $path);
        }
    }

    public function test_at_and_ch_keep_unique_money_pages_and_drop_german_clones(): void
    {
        foreach (['at', 'ch'] as $locale) {
            $this->assertTrue(LocaleIndexability::isIndexable($locale, ''));
            $this->assertTrue(LocaleIndexability::isIndexable($locale, 'marketplace'));
            $this->assertTrue(LocaleIndexability::isIndexable($locale, 'pricing'));
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'about'));
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'contact'));
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'faq'));
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'how-it-works'));
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'become-a-publisher'));
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'privacy-policy'));
        }
    }

    public function test_partial_locales_keep_home_market_price_not_english_bodies(): void
    {
        foreach (['pt', 'bg', 'hu', 'no', 'ro', 'dk', 'se', 'ee', 'pl'] as $locale) {
            $this->assertTrue(LocaleIndexability::isIndexable($locale, ''), $locale.' home');
            $this->assertTrue(LocaleIndexability::isIndexable($locale, 'marketplace'), $locale.' marketplace');
            $this->assertTrue(LocaleIndexability::isIndexable($locale, 'pricing'), $locale.' pricing');
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'about'), $locale.' about');
            $this->assertFalse(LocaleIndexability::isIndexable($locale, 'contact'), $locale.' contact');
        }

        $this->assertTrue(LocaleIndexability::isIndexable('gr', ''));
        $this->assertFalse(LocaleIndexability::isIndexable('gr', 'marketplace'));
        $this->assertFalse(LocaleIndexability::isIndexable('gr', 'about'));
    }

    public function test_fully_translated_locales_keep_about_and_legal(): void
    {
        foreach (['en', 'de', 'fr', 'nl', 'es', 'it'] as $locale) {
            $this->assertTrue(LocaleIndexability::isIndexable($locale, 'about'), $locale);
            $this->assertTrue(LocaleIndexability::isIndexable($locale, 'contact'), $locale);
            $this->assertTrue(LocaleIndexability::isIndexable($locale, 'become-a-publisher'), $locale);
        }
    }

    public function test_hreflang_cluster_for_about_omits_thin_locales(): void
    {
        $locales = LocaleIndexability::indexableLocales('about');

        $this->assertContains('en', $locales);
        $this->assertContains('de', $locales);
        $this->assertContains('it', $locales);
        $this->assertNotContains('us', $locales);
        $this->assertNotContains('at', $locales);
        $this->assertNotContains('ch', $locales);
        $this->assertNotContains('pt', $locales);
        $this->assertNotContains('bg', $locales);
        $this->assertNotContains('gr', $locales);
    }

    public function test_thin_request_points_canonical_at_the_parent(): void
    {
        app()->setLocale('us');
        $request = Request::create('http://localhost/us/about', 'GET');
        $this->assertTrue(LocaleIndexability::isThinDuplicate($request));
        $this->assertSame(url('/about'), LocaleIndexability::canonicalUrl($request));

        app()->setLocale('at');
        $at = Request::create('http://localhost/at/ueber-uns', 'GET');
        $this->assertTrue(LocaleIndexability::isThinDuplicate($at));
        $this->assertSame(url('/de/ueber-uns'), LocaleIndexability::canonicalUrl($at));

        app()->setLocale('de');
        $de = Request::create('http://localhost/de/ueber-uns', 'GET');
        $this->assertFalse(LocaleIndexability::isThinDuplicate($de));
        $this->assertSame(url('/de/ueber-uns'), LocaleIndexability::canonicalUrl($de));
    }
}
