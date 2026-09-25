<?php

namespace Tests\Feature;

use App\Support\DutchMoneyLanders;
use App\Support\GermanMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DutchMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dutch_money_landers_are_indexable(): void
    {
        foreach (DutchMoneyLanders::slugs() as $slug) {
            $page = DutchMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/nl/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/nl/'.$slug).'"', false)
                ->assertSee('hreflang="nl"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="nl"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/nl/marktplaats', false)
                ->getContent();

            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertStringNotContainsString('inLanguage":"it-IT"', $html, $slug);
            $this->assertStringNotContainsString('gastblog hoge da', $html, $slug);
            $this->assertStringNotContainsString('digitale media gastblog', $html, $slug);
            $this->assertStringNotContainsString('eur wallet linkbuilding', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);

            $sharedWithItalian = class_exists(ItalianMoneyLanders::class)
                && ItalianMoneyLanders::isSlug($slug);
            if ($sharedWithItalian) {
                $this->assertStringContainsString('hreflang="it"', $html, $slug);
            }

            $sharedWithGerman = class_exists(GermanMoneyLanders::class)
                && GermanMoneyLanders::isSlug($slug);
            if ($sharedWithGerman) {
                $this->assertStringContainsString('hreflang="de"', $html, $slug);
            }
        }
    }

    public function test_netherlands_owns_unique_slugs_and_shares_linkbuilding(): void
    {
        $this->get('/gastblog-kopen')->assertRedirect('/nl/gastblog-kopen');
        $this->get('/fr/gastblog-kopen')->assertRedirect('/nl/gastblog-kopen');
        $this->get('/es/gastblog-kopen')->assertRedirect('/nl/gastblog-kopen');
        $this->get('/gids')->assertRedirect('/nl/gids');

        $this->get('/linkbuilding')->assertRedirect('/de/linkbuilding');
        $this->get('/nl/linkbuilding')->assertOk();
        $this->get('/de/linkbuilding')->assertOk();
        $this->get('/at/linkbuilding')->assertOk();

        $this->get('/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/nl/digital-pr')->assertOk();
        $this->get('/it/digital-pr')->assertOk();
        $this->get('/nl/niche-edits')->assertOk();
        $this->get('/niche-edits')->assertRedirect('/de/niche-edits');
        $this->get('/nl/link-building')->assertRedirect('/it/link-building');
        $this->get('/it/link-building')->assertOk();
    }

    public function test_aliases_and_city_doorways(): void
    {
        $this->get('/nl/marketplace')->assertRedirect('/nl/marktplaats');
        $this->get('/nl/guest-post-kopen')->assertRedirect('/nl/gastblog-kopen');
        $this->get('/nl/prijs-guest-post')->assertRedirect('/nl/prijzen');
        $this->get('/nl/persbericht-kopen')->assertRedirect('/nl/digital-pr');
        $this->get('/nl/backlink-kopen')->assertRedirect('/nl/backlinks-kopen');
        $this->get('/nl/link-invoegen')->assertRedirect('/nl/niche-edits');
        $this->get('/nl/amsterdam')->assertNotFound();
        $this->get('/nl/rotterdam')->assertNotFound();
        $this->get('/nl/backlink.nl-alternatief')->assertNotFound();
        $this->get('/nl/whitepress-nederland')->assertNotFound();
    }

    public function test_home_marketplace_and_pricing(): void
    {
        $home = $this->get('/nl')->assertOk()->getContent();
        $this->assertStringContainsString('Linkbuilding-marktplaats Nederland | SEOLinkBuildings', $home);
        $this->assertStringContainsString('/nl/gastblog-kopen', $home);
        $this->assertStringContainsString('lang="nl"', $home);
        $this->assertStringNotContainsString('Guest-post and backlink marketplace connecting advertisers', $home);
        $this->assertStringNotContainsString('About SEOLinkBuildings', $home);

        $buy = $this->get('/nl/gastblog-kopen')->assertOk()->getContent();
        $this->assertStringContainsString('geen KvK-nummer in Nederland', $buy);
        $this->assertStringContainsString('Start', $buy);
        $this->assertStringNotContainsString('beste marketplace', $buy);
        $this->assertStringNotContainsString('checkout', strtolower($buy));
        $this->assertStringNotContainsString('listing', strtolower($buy));
        $this->assertStringNotContainsString('Semrush', $buy);

        $blade = (string) file_get_contents(resource_path('views/pages/dutch-money-lander.blade.php'));
        $this->assertStringNotContainsString('checkout', strtolower($blade));
        $this->assertStringNotContainsString('listing', strtolower($blade));

        $market = $this->get('/nl/marktplaats')->assertOk()->getContent();
        $this->assertStringContainsString('Catalogus van publishers in Nederland', $market);
        $this->assertStringContainsString('/nl/gastblog-kopen', $market);
        $this->assertStringNotContainsString('median advertiser prices', $market);

        $prijzen = $this->get('/nl/prijzen')->assertOk()->getContent();
        $this->assertStringContainsString('Wat kost een gastblog in Nederland', $prijzen);
        $this->assertStringContainsString('Prijslijst linkbuilding Nederland', $prijzen);
        $this->assertStringNotContainsString('outreach', strtolower($prijzen));
    }

    public function test_sitemap_and_english_lander(): void
    {
        $xml = $this->get('/sitemap-nl.xml')->assertOk()->getContent();
        foreach (DutchMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/nl/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/nl/marktplaats', $xml);
        $this->assertStringContainsString('/nl/prijzen', $xml);
        $this->assertStringContainsString('/nl/gids', $xml);
        $this->assertStringNotContainsString('/nl/amsterdam', $xml);
        $this->assertStringNotContainsString('/nl/guest-post-kopen', $xml);
        $this->assertStringNotContainsString('/nl/marketplace', $xml);

        $this->get('/guest-posts-netherlands')
            ->assertOk()
            ->assertSee('/nl/gastblog-kopen', false)
            ->assertSee('Gastblog kopen in het Nederlands', false);
    }

    public function test_copy_redirects_do_not_steal_germany_or_italy(): void
    {
        $this->assertSame(['nl'], DutchMoneyLanders::copyRedirectLocales());
        $this->assertSame(['nl'], PublicI18n::catalogTeaserCountries('nl'));
        $this->assertSame(['nl'], PublicI18n::moneyLanderLocales('gastblog-kopen'));
        $this->assertSame('nl', PublicI18n::moneyLanderXDefault('gastblog-kopen'));
        $this->assertContains('nl', PublicI18n::moneyLanderLocales('linkbuilding'));
        $this->assertContains('de', PublicI18n::moneyLanderLocales('linkbuilding'));
        $this->assertSame('de', PublicI18n::moneyLanderXDefault('linkbuilding'));
        $this->assertContains('nl', PublicI18n::moneyLanderLocales('digital-pr'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));

        $shared = Request::create('/nl/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/nl/digital-pr'), PublicI18n::switchUrl($shared, 'nl'));
        $this->assertSame(url('/de/digital-pr'), PublicI18n::switchUrl($shared, 'de'));

        $lb = Request::create('/nl/linkbuilding', 'GET');
        $this->assertSame(url('/de/linkbuilding'), PublicI18n::switchUrl($lb, 'de'));
        $this->assertSame(url('/nl/linkbuilding'), PublicI18n::switchUrl($lb, 'nl'));
    }

    public function test_niche_edits_and_guide_are_not_fake_skus(): void
    {
        $niche = $this->get('/nl/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('geen SKU', $niche);
        $this->assertStringContainsString('/nl/gastblog-kopen', $niche);

        $gids = $this->get('/nl/gids')->assertOk()->getContent();
        $this->assertStringContainsString('Dofollow, nofollow, sponsored', $gids);
        $this->assertStringContainsString('PBN versus gastblog', $gids);
        $this->get('/nl/gids/wat-is-een-gastblog')->assertNotFound();
        $this->get('/nl/gids/hoe-backlinks-kopen')->assertNotFound();
    }

    public function test_public_dutch_copy_avoids_leftover_english(): void
    {
        $how = $this->get('/nl/hoe-het-werkt')->assertOk()->getContent();
        $this->assertStringContainsString('portefeuille', $how);
        $this->assertStringNotContainsString('Wallet-checkout', $how);

        $about = $this->get('/nl/over-ons')->assertOk()->getContent();
        $this->assertStringContainsString('Italiaans', $about);
        $this->assertStringContainsString('Roemeens', $about);
        $this->assertStringNotContainsString('EN/DE/FR/NL', $about);

        $faq = $this->get('/nl/veelgestelde-vragen')->assertOk()->getContent();
        $this->assertStringContainsString('Portugees', $faq);
        $this->assertStringNotContainsString('SaaS-dashboard', $faq);

        $contact = $this->get('/nl/contact')->assertOk()->getContent();
        $this->assertStringNotContainsString('beter te scoren', $contact);
        $this->assertStringNotContainsString('goede rankings', $contact);
    }
}
