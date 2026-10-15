<?php

namespace Tests\Feature;

use App\Support\BrandPerson;
use App\Support\LocaleIndexability;
use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoGscFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_us_301s_to_contact_including_locale_variants(): void
    {
        $this->get('/contact-us')->assertRedirect('/contact');
        $this->assertSame(301, $this->get('/contact-us')->status());
        $this->get('/contact-us?ref=gsc')->assertRedirect('/contact?ref=gsc');

        $this->get('/de/contact-us')->assertRedirect('/de/kontakt');
        $this->assertSame(301, $this->get('/de/contact-us')->status());
        $this->get('/us/contact-us')->assertRedirect('/us/contact');
        $this->get('/se/contact-us')->assertRedirect('/se/kontakt');
        $this->get('/bg/contact-us')->assertRedirect('/bg/kontakt');
        $this->get('/nl/contact-us')->assertRedirect('/nl/contact');
    }

    public function test_company_nap_is_teqno_everywhere_public(): void
    {
        foreach (['/', '/about', '/contact', '/llms.txt', '/author/m-arslan'] as $path) {
            $body = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('Teqno Ltd', $body, $path);
            $this->assertStringContainsString('17392540', $body, $path);
            $this->assertStringContainsString('20 Wenlock Road', $body, $path);
            $this->assertStringNotContainsString('Topurlz', $body, $path);
            $this->assertStringNotContainsString('16607074', $body, $path);
        }

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('"foundingDate":"2024"', $home);
        $this->assertStringContainsString('"name":"M. Arslan"', $home);
        $this->assertStringContainsString('/author/m-arslan', $home);
    }

    public function test_founder_page_has_person_schema_and_locale_aliases_redirect(): void
    {
        $html = $this->get('/author/m-arslan')
            ->assertOk()
            ->assertSee('M. Arslan', false)
            ->assertSee('"@type":"Person"', false)
            ->assertSee('Teqno Ltd', false)
            ->assertSee('17392540', false)
            ->assertSee('rel="canonical" href="'.url('/author/m-arslan').'"', false)
            ->getContent();

        $this->assertStringContainsString('hreflang="en-GB"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringNotContainsString('hreflang="de"', $html);

        $this->get('/founder')->assertRedirect('/author/m-arslan');
        $this->assertSame(301, $this->get('/founder')->status());
        $this->get('/author/arslan-jason')->assertRedirect('/author/m-arslan');
        $this->get('/de/author')->assertRedirect('/author/m-arslan');
        $this->get('/de/author/m-arslan')->assertRedirect('/author/m-arslan');
        $this->get('/us/founder')->assertRedirect('/author/m-arslan');
    }

    public function test_untranslated_locale_bodies_are_noindex_and_out_of_hreflang(): void
    {
        $cases = [
            'bg' => ['about', 'contact', 'why-choose-us', 'privacy-policy'],
            'gr' => ['about', 'marketplace', 'contact'],
            'hu' => ['about', 'contact', 'why-choose-us', 'privacy-policy'],
            'pt' => ['about', 'contact', 'how-it-works'],
            'no' => ['about', 'contact', 'why-choose-us', 'privacy-policy'],
        ];

        foreach ($cases as $locale => $paths) {
            foreach ($paths as $path) {
                $this->assertFalse(LocaleIndexability::isIndexable($locale, $path), $locale.' '.$path);
            }
        }

        $html = $this->get(LocalizedPublicPath::publicPath('privacy-policy', 'bg'))->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.url('/privacy-policy').'"', $html);
        $this->assertStringNotContainsString('rel="alternate" hreflang=', $html);

        $grMarket = $this->get(LocalizedPublicPath::publicPath('marketplace', 'gr'))->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="noindex, follow"', $grMarket);
        $this->assertStringContainsString('rel="canonical" href="'.url('/marketplace').'"', $grMarket);
    }

    public function test_empty_us_sitemap_is_omitted_from_the_index_and_pl_is_not(): void
    {
        $index = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('sitemap-us.xml', $index);
        $this->assertStringContainsString('sitemap-pl.xml', $index);
        $this->assertStringContainsString('sitemap-en.xml', $index);

        $us = $this->get('/sitemap-us.xml')->assertOk()->getContent();
        preg_match_all('#<loc>([^<]+)</loc>#', $us, $matches);
        $this->assertSame([], array_values(array_unique($matches[1] ?? [])));

        $pl = $this->get('/sitemap-pl.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/pl</loc>', $pl);
        $this->assertStringContainsString('/pl/rynek', $pl);
        $this->assertStringContainsString('/pl/cennik', $pl);
        $this->assertStringNotContainsString('/pl/o-nas', $pl);

        $en = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/author/m-arslan', $en);
    }

    public function test_swedish_and_dutch_money_x_default_points_at_english_landers(): void
    {
        $this->assertSame('en', PublicI18n::moneyLanderXDefault('kopa-backlinks'));
        $this->assertSame('en', PublicI18n::moneyLanderXDefault('gastblog-kopen'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));

        $se = $this->get('/se/kopa-backlinks')->assertOk()->getContent();
        $this->assertStringContainsString('rel="canonical" href="'.url('/se/kopa-backlinks').'"', $se);
        $this->assertStringContainsString('hreflang="sv-SE" href="'.url('/se/kopa-backlinks').'"', $se);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/guest-posts-sweden').'"', $se);
        $this->assertStringNotContainsString('hreflang="x-default" href="'.url('/se/kopa-backlinks').'"', $se);

        $nl = $this->get('/nl/gastblog-kopen')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="nl" href="'.url('/nl/gastblog-kopen').'"', $nl);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/guest-posts-netherlands').'"', $nl);
        $this->assertStringNotContainsString('hreflang="x-default" href="'.url('/nl/gastblog-kopen').'"', $nl);

        $seXml = $this->get('/sitemap-se.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/se/kopa-backlinks', $seXml);
        $this->assertStringContainsString('hreflang="x-default"', $seXml);
        $this->assertMatchesRegularExpression(
            '#<loc>'.preg_quote(url('/se/kopa-backlinks'), '#').'</loc>.*?hreflang="x-default"[^>]*href="'.preg_quote(url('/guest-posts-sweden'), '#').'"#s',
            $seXml
        );
    }

    public function test_blog_bylines_use_public_founder_name(): void
    {
        $this->assertSame('M. Arslan', BrandPerson::displayName('Arslan Jason'));
        $this->assertSame('M. Arslan', BrandPerson::displayName(''));
        $this->assertSame('Ada Lovelace', BrandPerson::displayName('Ada Lovelace'));
    }
}
