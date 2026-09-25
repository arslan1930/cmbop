<?php

namespace Tests\Feature;

use App\Support\BulgarianMoneyLanders;
use App\Support\DanishMoneyLanders;
use App\Support\EstonianMoneyLanders;
use App\Support\HungarianMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\MoneyLanderCatalog;
use App\Support\NorwegianMoneyLanders;
use App\Support\PolishMoneyLanders;
use App\Support\PublicI18n;
use App\Support\SwedishMoneyLanders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class NordicCeeMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function markets(): array
    {
        return [
            'dk' => [
                'locale' => 'dk',
                'class' => DanishMoneyLanders::class,
                'guest' => 'koeb-gaesteindlaeg',
                'sponsored' => 'sponsoreret-artikel',
                'backlinks' => 'koeb-backlinks',
                'linkbuilding' => 'linkbuilding',
                'agencies' => 'bureauer',
                'guide' => 'vejledning',
                'market' => 'markedsplads',
                'prices' => 'priser',
                'hreflang' => 'da-DK',
                'home_title' => 'Gæsteindlæg og linkbuilding i Danmark | SEOLinkBuildings',
                'market_title' => 'Katalog over publishers i Danmark',
                'price_title' => 'Hvad koster et gæsteindlæg i Danmark',
                'vat' => 'opfinder ikke et CVR',
                'home_crumb' => 'Hjem',
                'english_lander' => '/guest-posts-denmark',
                'native_label' => 'Køb gæsteindlæg på dansk',
                'city' => 'kobenhavn',
                'alias_guest' => 'guest-post-denmark',
                'alias_prices' => 'hvad-koster-gaesteindlaeg',
                'sitemap' => '/sitemap-dk.xml',
                'awkward' => 'indsæt link i artikel København dør',
            ],
            'se' => [
                'locale' => 'se',
                'class' => SwedishMoneyLanders::class,
                'guest' => 'kopa-gastinlagg',
                'sponsored' => 'sponsrat-inlagg',
                'backlinks' => 'kopa-backlinks',
                'linkbuilding' => 'linkbuilding',
                'agencies' => 'byraer',
                'guide' => 'handledning',
                'market' => 'marknadsplats',
                'prices' => 'priser',
                'hreflang' => 'sv-SE',
                'home_title' => 'Gästinlägg och linkbuilding i Sverige | SEOLinkBuildings',
                'market_title' => 'Katalog över publishers i Sverige',
                'price_title' => 'Vad kostar ett gästinlägg i Sverige',
                'vat' => 'hittar inte på ett svenskt org.nr',
                'home_crumb' => 'Hem',
                'english_lander' => '/guest-posts-sweden',
                'native_label' => 'Köp gästinlägg på svenska',
                'city' => 'stockholm',
                'alias_guest' => 'guest-post-sweden',
                'alias_prices' => 'vad-kostar-gastinlagg',
                'sitemap' => '/sitemap-se.xml',
                'awkward' => 'infoga länk i artikel Stockholm dörr',
            ],
            'no' => [
                'locale' => 'no',
                'class' => NorwegianMoneyLanders::class,
                'guest' => 'kjope-gjesteinnlegg',
                'sponsored' => 'sponset-artikkel',
                'backlinks' => 'kjope-backlinks',
                'linkbuilding' => 'linkbuilding',
                'agencies' => 'for-byraer',
                'guide' => 'veiledning',
                'market' => 'markedsplass',
                'prices' => 'priser',
                'hreflang' => 'nb-NO',
                'home_title' => 'Gjesteinnlegg og linkbuilding i Norge | SEOLinkBuildings',
                'market_title' => 'Katalog over publishers i Norge',
                'price_title' => 'Hva koster et gjesteinnlegg i Norge',
                'vat' => 'dikter ikke opp et org.nr',
                'home_crumb' => 'Hjem',
                'english_lander' => '/guest-posts-norway',
                'native_label' => 'Kjøp gjesteinnlegg på norsk',
                'city' => 'oslo',
                'alias_guest' => 'guest-post-norway',
                'alias_prices' => 'hva-koster-gjesteinnlegg',
                'sitemap' => '/sitemap-no.xml',
                'awkward' => 'sett inn lenke i artikkel Oslo dør',
            ],
            'bg' => [
                'locale' => 'bg',
                'class' => BulgarianMoneyLanders::class,
                'guest' => 'kupi-guest-post',
                'sponsored' => 'sponsorirana-statiya',
                'backlinks' => 'kupi-backlinks',
                'linkbuilding' => 'linkbuilding',
                'agencies' => 'agencii',
                'guide' => 'rukovodstvo',
                'market' => 'pazar',
                'prices' => 'ceni',
                'hreflang' => 'bg',
                'home_title' => 'Гост пост и linkbuilding в България | SEOLinkBuildings',
                'market_title' => 'Каталог на publishers в България',
                'price_title' => 'Колко струва гост пост в България',
                'vat' => 'не измисляме ЕИК',
                'home_crumb' => 'Начало',
                'english_lander' => '/guest-posts-bulgaria',
                'native_label' => 'Купи гост пост на български',
                'city' => 'sofia',
                'alias_guest' => 'guest-post-bulgaria',
                'alias_prices' => 'kolko-struva',
                'sitemap' => '/sitemap-bg.xml',
                'awkward' => 'вмъкни линк в статия София врата',
            ],
            'hu' => [
                'locale' => 'hu',
                'class' => HungarianMoneyLanders::class,
                'guest' => 'vendegposzt',
                'sponsored' => 'szponzoralt-cikk',
                'backlinks' => 'backlink-vasarlas',
                'linkbuilding' => 'linkepites',
                'agencies' => 'ugynoksegek',
                'guide' => 'utmutato',
                'market' => 'piac',
                'prices' => 'arak',
                'hreflang' => 'hu',
                'home_title' => 'Vendégposzt és linképítés Magyarországon | SEOLinkBuildings',
                'market_title' => 'Publishers katalógusa Magyarországon',
                'price_title' => 'Mennyibe kerül egy vendégposzt Magyarországon',
                'vat' => 'nem találunk ki magyar adószámot',
                'home_crumb' => 'Kezdőlap',
                'english_lander' => '/guest-posts-hungary',
                'native_label' => 'Vendégposzt magyarul',
                'city' => 'budapest',
                'alias_guest' => 'guest-post-hungary',
                'alias_prices' => 'vendegposzt-ar',
                'sitemap' => '/sitemap-hu.xml',
                'awkward' => 'link beszúrás cikkbe Budapest ajtó',
            ],
            'ee' => [
                'locale' => 'ee',
                'class' => EstonianMoneyLanders::class,
                'guest' => 'osta-kulalispostitus',
                'sponsored' => 'sponsoreeritud-artikkel',
                'backlinks' => 'osta-backlinke',
                'linkbuilding' => 'linkbuilding',
                'agencies' => 'agentuurid',
                'guide' => 'juhend',
                'market' => 'turg',
                'prices' => 'hinnad',
                'hreflang' => 'et-EE',
                'home_title' => 'Külalispostitus ja linkbuilding Eestis | SEOLinkBuildings',
                'market_title' => 'Publishersite kataloog Eestis',
                'price_title' => 'Kui palju maksab külalispostitus Eestis',
                'vat' => 'ei leiuta Eesti registrikoodi',
                'home_crumb' => 'Avaleht',
                'english_lander' => '/guest-posts-estonia',
                'native_label' => 'Osta külalispostitus eesti keeles',
                'city' => 'tallinn',
                'alias_guest' => 'guest-post-estonia',
                'alias_prices' => 'kulalispostituse-hind',
                'sitemap' => '/sitemap-ee.xml',
                'awkward' => 'lisa link artiklisse Tallinn uks',
            ],
            'pl' => [
                'locale' => 'pl',
                'class' => PolishMoneyLanders::class,
                'guest' => 'wpis-goscinny',
                'sponsored' => 'artykul-sponsorowany',
                'backlinks' => 'kup-backlinki',
                'linkbuilding' => 'link-building',
                'agencies' => 'agencje',
                'guide' => 'przewodnik',
                'market' => 'rynek',
                'prices' => 'cennik',
                'hreflang' => 'pl-PL',
                'home_title' => 'Wpis gościnny i link building w Polsce | SEOLinkBuildings',
                'market_title' => 'Katalog publishers w Polsce',
                'price_title' => 'Ile kosztuje wpis gościnny w Polsce',
                'vat' => 'nie wymyślamy polskiego NIP-u',
                'home_crumb' => 'Strona główna',
                'english_lander' => '/guest-posts-poland',
                'native_label' => 'Kup wpis gościnny po polsku',
                'city' => 'warszawa',
                'alias_guest' => 'guest-post-poland',
                'alias_prices' => 'ile-kosztuje-artykul',
                'sitemap' => '/sitemap-pl.xml',
                'awkward' => 'wstawienie linku do artykułu Warszawa drzwi',
            ],
        ];
    }

    public function test_catalog_lists_all_seven_locales(): void
    {
        $this->assertSame(
            ['dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl'],
            MoneyLanderCatalog::nordicCeeLocales()
        );
        foreach (MoneyLanderCatalog::nordicCeeLocales() as $locale) {
            $this->assertNotNull(MoneyLanderCatalog::classFor($locale), $locale);
        }
    }

    public function test_money_landers_are_indexable(): void
    {
        foreach (self::markets() as $market) {
            $class = $market['class'];
            $locale = $market['locale'];

            foreach ($class::slugs() as $slug) {
                $page = $class::find($slug);
                $this->assertNotNull($page, $locale.' '.$slug);

                $html = $this->get('/'.$locale.'/'.$slug)
                    ->assertOk()
                    ->assertSee($page['h1'], false)
                    ->assertSee($page['meta_title'], false)
                    ->assertSee('rel="canonical" href="'.url('/'.$locale.'/'.$slug).'"', false)
                    ->assertSee('hreflang="'.$market['hreflang'].'"', false)
                    ->assertSee('hreflang="x-default"', false)
                    ->assertSee('lang="'.$market['hreflang'].'"', false)
                    ->assertSee(url('/register'), false)
                    ->assertSee('/'.$locale.'/'.$market['market'], false)
                    ->getContent();

                $this->assertStringNotContainsString('advertiser/catalog', $html, $locale.' '.$slug);
                $this->assertStringNotContainsString('inLanguage":"it-IT"', $html, $locale.' '.$slug);
                $this->assertStringNotContainsString($market['awkward'], $html, $locale.' '.$slug);
                $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $locale.' '.$slug);
                $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $locale.' '.$slug);
                $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $locale.' '.$slug);

                $sharedWithItalian = class_exists(ItalianMoneyLanders::class)
                    && ItalianMoneyLanders::isSlug($slug);
                if ($sharedWithItalian) {
                    $this->assertStringContainsString('hreflang="it"', $html, $locale.' '.$slug);
                }
            }
        }
    }

    public function test_unique_slugs_and_shared_digital_pr(): void
    {
        $this->get('/koeb-gaesteindlaeg')->assertRedirect('/dk/koeb-gaesteindlaeg');
        $this->get('/fr/koeb-gaesteindlaeg')->assertRedirect('/dk/koeb-gaesteindlaeg');
        $this->get('/kopa-gastinlagg')->assertRedirect('/se/kopa-gastinlagg');
        $this->get('/kjope-gjesteinnlegg')->assertRedirect('/no/kjope-gjesteinnlegg');
        $this->get('/kupi-guest-post')->assertRedirect('/bg/kupi-guest-post');
        $this->get('/vendegposzt')->assertRedirect('/hu/vendegposzt');
        $this->get('/osta-kulalispostitus')->assertRedirect('/ee/osta-kulalispostitus');
        $this->get('/wpis-goscinny')->assertRedirect('/pl/wpis-goscinny');

        $this->get('/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/fr/digital-pr')->assertRedirect('/it/digital-pr');
        foreach (['dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl'] as $locale) {
            $this->get('/'.$locale.'/digital-pr')->assertOk();
            $this->get('/'.$locale.'/niche-edits')->assertOk();
        }
        $this->get('/hu/linkbuilding')->assertRedirect('/hu/linkepites');
        $this->get('/de/linkbuilding')->assertOk();
        $this->get('/it/link-building')->assertOk();
        $this->get('/pl/link-building')->assertOk();
        $this->get('/it/comprare-guest-post')->assertOk();
        $this->get('/de/gastbeitrag-kaufen')->assertOk();
        $this->get('/ro/cumpara-guest-post')->assertOk();
    }

    public function test_aliases_and_city_doorways(): void
    {
        foreach (self::markets() as $market) {
            $locale = $market['locale'];
            $this->get('/'.$locale.'/marketplace')->assertRedirect('/'.$locale.'/'.$market['market']);
            $this->get('/'.$locale.'/'.$market['alias_guest'])->assertRedirect('/'.$locale.'/'.$market['guest']);
            $this->get('/'.$locale.'/'.$market['alias_prices'])->assertRedirect('/'.$locale.'/'.$market['prices']);
            $this->get('/'.$locale.'/'.$market['city'])->assertNotFound();
            $this->get('/'.$locale.'/whitepress')->assertNotFound();
        }
        $this->get('/ee/sponsorartikkel')->assertRedirect('/ee/osta-kulalispostitus');
    }

    public function test_home_marketplace_and_pricing(): void
    {
        foreach (self::markets() as $market) {
            $locale = $market['locale'];
            $home = $this->get('/'.$locale)->assertOk()->getContent();
            $this->assertStringContainsString($market['home_title'], $home);
            $this->assertStringContainsString('/'.$locale.'/'.$market['guest'], $home);
            $this->assertStringContainsString('lang="'.$market['hreflang'].'"', $home);

            $buy = $this->get('/'.$locale.'/'.$market['guest'])->assertOk()->getContent();
            $this->assertStringContainsString($market['vat'], $buy);
            $this->assertStringContainsString($market['home_crumb'], $buy);

            $catalog = $this->get('/'.$locale.'/'.$market['market'])->assertOk()->getContent();
            $this->assertStringContainsString($market['market_title'], $catalog);
            $this->assertStringContainsString('/'.$locale.'/'.$market['guest'], $catalog);

            $prices = $this->get('/'.$locale.'/'.$market['prices'])->assertOk()->getContent();
            $this->assertStringContainsString($market['price_title'], $prices);
        }
    }

    public function test_sitemap_and_english_lander(): void
    {
        foreach (self::markets() as $market) {
            $class = $market['class'];
            $locale = $market['locale'];
            $xml = $this->get($market['sitemap'])->assertOk()->getContent();
            foreach ($class::slugs() as $slug) {
                $this->assertStringContainsString('/'.$locale.'/'.$slug, $xml, $locale.' '.$slug);
            }
            $this->assertStringContainsString('/'.$locale.'/'.$market['market'], $xml);
            $this->assertStringContainsString('/'.$locale.'/'.$market['prices'], $xml);
            $this->assertStringContainsString('/'.$locale.'/'.$market['guide'], $xml);
            $this->assertStringNotContainsString('/'.$locale.'/'.$market['city'], $xml);
            $this->assertStringNotContainsString('/'.$locale.'/'.$market['alias_guest'], $xml);

            $this->get($market['english_lander'])
                ->assertOk()
                ->assertSee($market['guest'] === 'koeb-gaesteindlaeg' ? '/dk/koeb-gaesteindlaeg' : '/'.$locale.'/'.$market['guest'], false)
                ->assertSee($market['native_label'], false);
        }
    }

    public function test_copy_redirects_do_not_steal_italy_or_germany(): void
    {
        $this->assertSame(['dk'], DanishMoneyLanders::copyRedirectLocales());
        $this->assertSame(['dk'], PublicI18n::moneyLanderLocales('koeb-gaesteindlaeg'));
        $this->assertSame('dk', PublicI18n::moneyLanderXDefault('koeb-gaesteindlaeg'));
        $this->assertSame(['hu'], PublicI18n::moneyLanderLocales('linkepites'));
        $this->assertContains('pl', PublicI18n::moneyLanderLocales('link-building'));
        $this->assertContains('it', PublicI18n::moneyLanderLocales('link-building'));
        $this->assertContains('dk', PublicI18n::moneyLanderLocales('digital-pr'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));
        $this->assertSame('de', PublicI18n::moneyLanderXDefault('linkbuilding'));

        $shared = Request::create('/dk/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/dk/digital-pr'), PublicI18n::switchUrl($shared, 'dk'));
        $this->assertSame(url('/se/digital-pr'), PublicI18n::switchUrl($shared, 'se'));
    }

    public function test_niche_edits_and_guide_are_not_fake_skus(): void
    {
        foreach (self::markets() as $market) {
            $locale = $market['locale'];
            $niche = $this->get('/'.$locale.'/niche-edits')->assertOk()->getContent();
            $this->assertStringContainsString('SKU', $niche, $locale);
            $this->assertStringContainsString('/'.$locale.'/'.$market['guest'], $niche);

            $guide = $this->get('/'.$locale.'/'.$market['guide'])->assertOk()->getContent();
            $this->assertStringContainsString('Dofollow, nofollow, sponsored', $guide, $locale);
            $this->assertStringContainsString('PBN', $guide, $locale);
        }
    }

    public function test_hreflang_language_codes_are_not_country_codes(): void
    {
        $this->assertSame('da-DK', PublicI18n::hreflang('dk'));
        $this->assertSame('sv-SE', PublicI18n::hreflang('se'));
        $this->assertSame('nb-NO', PublicI18n::hreflang('no'));
        $this->assertSame('bg', PublicI18n::hreflang('bg'));
        $this->assertSame('hu', PublicI18n::hreflang('hu'));
        $this->assertSame('et-EE', PublicI18n::hreflang('ee'));
        $this->assertSame('pl-PL', PublicI18n::hreflang('pl'));
        $this->assertSame('da_DK', PublicI18n::ogLocale('dk'));
        $this->assertSame('sv_SE', PublicI18n::ogLocale('se'));
        $this->assertSame('nb_NO', PublicI18n::ogLocale('no'));
        $this->assertSame('bg_BG', PublicI18n::ogLocale('bg'));
        $this->assertSame('et_EE', PublicI18n::ogLocale('ee'));
    }
}
