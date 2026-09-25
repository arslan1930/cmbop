<?php

namespace Tests\Unit;

use App\Http\Middleware\SetLocale;
use App\Support\EnglishOnlyMarketingSlugs;
use App\Support\PublicI18n;
use Illuminate\Http\Request;
use Tests\TestCase;

class PublicI18nLocaleMapTest extends TestCase
{
    public function test_route_patterns_include_new_locales(): void
    {
        $this->assertStringContainsString('es', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('it', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('us', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('at', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('ch', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('ro', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('pl', PublicI18n::prefixedPattern());
        $this->assertStringContainsString('pl', PublicI18n::supportedPattern());
        $this->assertStringContainsString('en', PublicI18n::supportedPattern());
        $this->assertFalse(PublicI18n::isPrefixed('en'));
        $this->assertTrue(PublicI18n::isPrefixed('us'));
        $this->assertTrue(PublicI18n::isPrefixed('ee'));
    }

    public function test_hreflang_and_og_locale_split_uk_and_us_english(): void
    {
        $this->assertSame('en-GB', PublicI18n::hreflang('en'));
        $this->assertSame('en-US', PublicI18n::hreflang('us'));
        $this->assertSame('es', PublicI18n::hreflang('es'));
        $this->assertSame('it', PublicI18n::hreflang('it'));
        $this->assertSame('de-AT', PublicI18n::hreflang('at'));
        $this->assertSame('de-CH', PublicI18n::hreflang('ch'));
        $this->assertSame('el-GR', PublicI18n::hreflang('gr'));
        $this->assertSame('sv-SE', PublicI18n::hreflang('se'));
        $this->assertSame('et-EE', PublicI18n::hreflang('ee'));
        $this->assertSame('pl-PL', PublicI18n::hreflang('pl'));

        $this->assertSame('en_GB', PublicI18n::ogLocale('en'));
        $this->assertSame('en_US', PublicI18n::ogLocale('us'));
        $this->assertSame('es_ES', PublicI18n::ogLocale('es'));
        $this->assertSame('it_IT', PublicI18n::ogLocale('it'));
        $this->assertSame('de_AT', PublicI18n::ogLocale('at'));
        $this->assertSame('el_GR', PublicI18n::ogLocale('gr'));
    }

    public function test_browser_tags_map_to_supported_locales(): void
    {
        $this->assertSame('us', PublicI18n::fromBrowserTag('en-US'));
        $this->assertSame('us', PublicI18n::fromBrowserTag('en_US'));
        $this->assertSame('en', PublicI18n::fromBrowserTag('en-GB'));
        $this->assertSame('en', PublicI18n::fromBrowserTag('en'));
        $this->assertSame('en', PublicI18n::fromBrowserTag('en-AU'));
        $this->assertSame('es', PublicI18n::fromBrowserTag('es-ES'));
        $this->assertSame('it', PublicI18n::fromBrowserTag('it-IT'));
        $this->assertSame('de', PublicI18n::fromBrowserTag('de-DE'));
        $this->assertSame('at', PublicI18n::fromBrowserTag('de-AT'));
        $this->assertSame('ch', PublicI18n::fromBrowserTag('de-CH'));
        $this->assertSame('gr', PublicI18n::fromBrowserTag('el-GR'));
        $this->assertSame('dk', PublicI18n::fromBrowserTag('da-DK'));
        $this->assertSame('se', PublicI18n::fromBrowserTag('sv-SE'));
        $this->assertSame('no', PublicI18n::fromBrowserTag('nb-NO'));
        $this->assertSame('ee', PublicI18n::fromBrowserTag('et-EE'));
        $this->assertSame('pl', PublicI18n::fromBrowserTag('pl-PL'));
        $this->assertSame('pl', PublicI18n::fromBrowserTag('pl'));
        $this->assertSame('ro', PublicI18n::fromBrowserTag('ro-RO'));
        $this->assertNull(PublicI18n::fromBrowserTag(''));
        $this->assertNull(PublicI18n::fromBrowserTag('ja-JP'));
    }

    public function test_short_label_uses_uk_for_default_english(): void
    {
        $this->assertSame('UK', PublicI18n::shortLabel('en'));
        $this->assertSame('US', PublicI18n::shortLabel('us'));
        $this->assertSame('ES', PublicI18n::shortLabel('es'));
        $this->assertSame('AT', PublicI18n::shortLabel('at'));
        $this->assertSame('CH', PublicI18n::shortLabel('ch'));
        $this->assertSame('de', PublicI18n::messagesFallback('at'));
        $this->assertSame('de', PublicI18n::messagesFallback('ch'));
        $this->assertSame(['ro'], PublicI18n::catalogTeaserCountries('ro'));
    }

    public function test_english_only_marketing_slugs_cover_landers_and_price_index(): void
    {
        $slugs = PublicI18n::englishOnlyMarketingSlugs();
        $this->assertContains('guest-post-prices-europe', $slugs);
        $this->assertContains('guest-posts-germany', $slugs);
        $this->assertContains('guest-posts-uk', $slugs);
        $this->assertSame(
            $slugs,
            EnglishOnlyMarketingSlugs::all()
        );
    }

    public function test_hreflang_tags_restrict_english_only_marketing_without_view_override(): void
    {
        $request = Request::create('/guest-posts-germany', 'GET');
        $tags = PublicI18n::hreflangTags($request);
        $cluster = [];
        foreach ($tags as $tag) {
            $cluster[$tag['hreflang']] = $tag['href'];
        }

        $canonical = url('/guest-posts-germany');
        $this->assertSame(
            [
                'en-GB' => $canonical,
                'x-default' => $canonical,
            ],
            $cluster
        );
    }

    public function test_country_maps_onto_the_public_locale_prefix(): void
    {
        $this->assertSame('us', PublicI18n::localeForCountry('US'));
        $this->assertSame('de', PublicI18n::localeForCountry('DE'));
        $this->assertSame('en', PublicI18n::localeForCountry('GB'));
        $this->assertNull(PublicI18n::localeForCountry('CA'));
    }

    public function test_unprefixed_home_follows_the_visitor_country(): void
    {
        config(['fx.fake_country' => 'US', 'fx.force_display' => '']);
        $request = Request::create('http://localhost/', 'GET', [], ['public_locale' => 'en']);
        $response = (new SetLocale)->handle($request, fn () => response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/us', (string) $response->headers->get('Location'));
    }

    public function test_uk_and_signed_in_app_stay_on_the_english_url(): void
    {
        config(['fx.fake_country' => 'GB', 'fx.force_display' => '']);
        $home = (new SetLocale)->handle(Request::create('http://localhost/', 'GET'), fn () => response('home'));
        $this->assertSame(200, $home->getStatusCode());

        config(['fx.fake_country' => 'DE', 'fx.force_display' => '']);
        $app = (new SetLocale)->handle(Request::create('http://localhost/advertiser/catalog', 'GET'), fn () => response('catalog'));
        $this->assertSame(200, $app->getStatusCode());
    }
}
