<?php

namespace Tests\Feature;

use App\Support\BelgianMoneyLanders;
use App\Support\CountryLander;
use App\Support\IrishMoneyLanders;
use App\Support\MoneyLanderCatalog;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class BelgiumIrelandSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_belgium_is_a_prefixed_locale_and_ireland_is_not(): void
    {
        $this->assertContains('be', PublicI18n::supported());
        $this->assertTrue(PublicI18n::isPrefixed('be'));
        $this->assertSame('nl-BE', PublicI18n::hreflang('be'));
        $this->assertSame('nl_BE', PublicI18n::ogLocale('be'));
        $this->assertSame(['be'], PublicI18n::catalogTeaserCountries('be'));
        $this->assertSame('be', PublicI18n::fromBrowserTag('nl-BE'));
        $this->assertSame('be', PublicI18n::fromBrowserTag('fr-BE'));

        $this->assertNotContains('ie', PublicI18n::supported());
        $this->assertFalse(PublicI18n::isPrefixed('ie'));
        $this->assertFalse(PublicI18n::isPrefixed('uk'));
        $this->assertSame('en', PublicI18n::fromBrowserTag('en-IE'));
        $this->assertSame('en-GB', PublicI18n::hreflang('uk'));
        $this->assertNull(MoneyLanderCatalog::classFor('uk'));
        $this->assertNull(MoneyLanderCatalog::classFor('ie'));
    }

    public function test_belgium_money_landers_are_indexable(): void
    {
        $this->assertContains('be', MoneyLanderCatalog::chromeLocales());

        foreach (BelgianMoneyLanders::slugs() as $slug) {
            $page = BelgianMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/be/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/be/'.$slug).'"', false)
                ->assertSee('hreflang="nl-BE"', false)
                ->assertSee('lang="nl-BE"', false)
                ->assertSee(url('/register'), false)
                ->getContent();

            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertStringNotContainsString('/ie/', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);
        }
    }

    public function test_belgium_home_marketplace_and_pricing(): void
    {
        $home = $this->get('/be')->assertOk()->getContent();
        $this->assertStringContainsString('Guest posts en linkbuilding in België | SEOLinkBuildings', $home);
        $this->assertStringContainsString('/be/koop-guest-post-belgie', $home);
        $this->assertStringContainsString('lang="nl-BE"', $home);
        $this->assertStringNotContainsString('ondernemingsnummer dat we verzonnen', $home);

        $this->get('/be/koop-guest-post-belgie')
            ->assertOk()
            ->assertSee('verzinnen geen Belgisch ondernemingsnummer', false)
            ->assertSee('Home', false);

        $this->get('/be/marketplace')
            ->assertOk()
            ->assertSee('Catalogus van publishers in België', false)
            ->assertSee('/be/koop-guest-post-belgie', false);

        $this->get('/be/prijzen')
            ->assertOk()
            ->assertSee('Wat kost een guest post in België', false);

        $this->get('/be/pricing')->assertRedirect('/be/prijzen');
        $this->get('/be/marktplaats')->assertRedirect('/be/marketplace');
        $this->get('/be/acheter-guest-post-belgique')->assertRedirect('/be/koop-guest-post-belgie');
        $this->get('/koop-guest-post-belgie')->assertRedirect('/be/koop-guest-post-belgie');
        $this->get('/fr/koop-guest-post-belgie')->assertRedirect('/be/koop-guest-post-belgie');
        $this->get('/be/brussel')->assertNotFound();
        $this->get('/be/whitepress')->assertNotFound();
        $this->get('/nl/marktplaats')->assertOk();
    }

    public function test_ireland_pages_use_uk_prefix_not_ie_locale(): void
    {
        foreach (IrishMoneyLanders::slugs() as $slug) {
            $page = IrishMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/uk/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/uk/'.$slug).'"', false)
                ->assertSee('hreflang="en-GB"', false)
                ->assertSee('lang="en-GB"', false)
                ->assertDontSee('hreflang="en-IE"', false)
                ->getContent();

            $this->assertStringNotContainsString('rel="canonical" href="'.url('/ie/'), $html, $slug);
            $this->assertStringNotContainsString('hreflang="en-IE"', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
        }

        $this->get('/ie')->assertNotFound();
        $this->get('/ie/marketplace')->assertNotFound();
        $this->get('/uk')
            ->assertRedirect('/')
            ->assertCookie(config('i18n.cookie', 'public_locale'), 'en');
        $this->get('/uk/marketplace')->assertRedirect('/marketplace');
        $this->get('/uk/pricing')->assertRedirect('/pricing');
        $this->get('/buy-guest-posts-ireland')->assertRedirect('/uk/buy-guest-posts-ireland');
        $this->get('/de/buy-guest-posts-ireland')->assertRedirect('/uk/buy-guest-posts-ireland');
        $this->get('/uk/guest-post-ireland')->assertRedirect('/uk/buy-guest-posts-ireland');
        $this->get('/uk/dublin')->assertNotFound();
        $this->withCookie(config('i18n.cookie', 'public_locale'), 'us')
            ->get('/uk/buy-guest-posts-ireland')
            ->assertOk()
            ->assertSee('Buy guest posts in Ireland', false);
        $this->withCookie(config('i18n.cookie', 'public_locale'), 'us')
            ->get('/uk')
            ->assertRedirect('/')
            ->assertCookie(config('i18n.cookie', 'public_locale'), 'en');
        $this->withCookie(config('i18n.cookie', 'public_locale'), 'us')
            ->get('/buy-guest-posts-ireland')
            ->assertRedirect('/uk/buy-guest-posts-ireland');
    }

    public function test_ireland_copy_is_not_a_uk_swap_and_does_not_invent_facts(): void
    {
        $ireland = $this->get('/uk/marketplace-ireland')->assertOk()->getContent();
        $this->assertStringContainsString('Irish publishers', $ireland);
        $this->assertStringContainsString('.ie', $ireland);
        $this->assertStringContainsString('do not invent an Irish office', $ireland);
        $this->assertStringContainsString('not a second UK homepage', $ireland);

        $guest = $this->get('/uk/buy-guest-posts-ireland')->assertOk()->getContent();
        $this->assertStringContainsString('Irish publishers, not a UK swap', $guest);

        $uk = $this->get('/guest-posts-uk')->assertOk()->getContent();
        $this->assertStringContainsString('United Kingdom', $uk);
        $this->assertStringNotContainsString('Buy guest posts in Ireland', $uk);

        $this->get('/uk/niche-edits-ireland')
            ->assertOk()
            ->assertSee('SKU', false)
            ->assertSee('/uk/buy-guest-posts-ireland', false);

        $this->get('/uk/guide-ireland')
            ->assertOk()
            ->assertSee('Dofollow, nofollow, sponsored', false)
            ->assertSee('PBN', false);

        $this->get('/be/niche-edits')
            ->assertOk()
            ->assertSee('SKU', false);

        $this->get('/be/gids-belgie')
            ->assertOk()
            ->assertSee('Dofollow, nofollow, sponsored', false)
            ->assertSee('PBN', false);
        $this->get('/be/gids')->assertRedirect('/be/gids-belgie');
    }

    public function test_english_country_landers_and_sitemaps(): void
    {
        $this->assertContains('guest-posts-belgium', CountryLander::slugs());
        $this->assertContains('guest-posts-ireland', CountryLander::slugs());

        $this->get('/guest-posts-belgium')
            ->assertOk()
            ->assertSee('Koop guest posts in België', false)
            ->assertSee('/be/koop-guest-post-belgie', false);

        $this->get('/guest-posts-ireland')
            ->assertOk()
            ->assertSee('Buy guest posts in Ireland', false)
            ->assertSee('/uk/buy-guest-posts-ireland', false)
            ->assertDontSee('hreflang="en-IE"', false);

        $this->get('/be/guest-posts-belgium')->assertRedirect('/guest-posts-belgium');
        $this->get('/de/guest-posts-ireland')->assertRedirect('/guest-posts-ireland');

        $beXml = $this->get('/sitemap-be.xml')->assertOk()->getContent();
        foreach (BelgianMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/be/'.$slug, $beXml, $slug);
        }
        $this->assertStringContainsString('/be/marketplace', $beXml);
        $this->assertStringContainsString('/be/prijzen', $beXml);
        $this->assertStringNotContainsString('/be/brussel', $beXml);
        $this->assertStringNotContainsString('/ie/', $beXml);

        $enXml = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        foreach (IrishMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/uk/'.$slug, $enXml, $slug);
        }
        $this->assertStringContainsString('/guest-posts-ireland', $enXml);
        $this->assertStringContainsString('/guest-posts-belgium', $enXml);
        $this->assertStringNotContainsString('/ie/', $enXml);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('sitemap-be.xml', false)
            ->assertDontSee('sitemap-ie.xml', false)
            ->assertDontSee('sitemap-uk.xml', false);
    }

    public function test_hreflang_and_language_switch_for_ireland(): void
    {
        $this->assertSame(['en'], PublicI18n::moneyLanderLocales('buy-guest-posts-ireland'));
        $this->assertSame('en', PublicI18n::moneyLanderXDefault('buy-guest-posts-ireland'));
        $this->assertSame(['be'], PublicI18n::moneyLanderLocales('koop-guest-post-belgie'));
        $this->assertContains('be', PublicI18n::moneyLanderLocales('digital-pr'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));

        $ireland = Request::create('/uk/buy-guest-posts-ireland', 'GET');
        $this->assertSame(url('/uk/buy-guest-posts-ireland'), PublicI18n::switchUrl($ireland, 'en'));
        $this->assertSame(url('/de'), PublicI18n::switchUrl($ireland, 'de'));
        $this->assertSame(url('/be'), PublicI18n::switchUrl($ireland, 'be'));

        $usHome = Request::create('/us', 'GET');
        $this->assertSame(url('/uk'), PublicI18n::switchUrl($usHome, 'en'));
        $usMarket = Request::create('/us/marketplace', 'GET');
        $this->assertSame(url('/uk/marketplace'), PublicI18n::switchUrl($usMarket, 'en'));

        $beGuest = Request::create('/be/koop-guest-post-belgie', 'GET');
        $this->assertSame(url('/be/koop-guest-post-belgie'), PublicI18n::switchUrl($beGuest, 'be'));
        $this->assertSame(url('/de'), PublicI18n::switchUrl($beGuest, 'de'));

        $this->get('/uk/buy-guest-posts-ireland')
            ->assertOk()
            ->assertSee('rel="alternate" hreflang="en-GB" href="'.url('/uk/buy-guest-posts-ireland').'"', false)
            ->assertDontSee('hreflang="nl-BE" href="'.url('/uk/buy-guest-posts-ireland'), false);
    }

    public function test_uk_home_links_to_ireland_cluster_and_robots_allow_landers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/uk/marketplace-ireland', false)
            ->assertSee('/uk/buy-guest-posts-ireland', false);

        $this->get('/marketplace')
            ->assertOk()
            ->assertSee('/uk/marketplace-ireland', false)
            ->assertSee('/guest-posts-ireland', false)
            ->assertSee('/guest-posts-belgium', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /guest-posts-belgium', false)
            ->assertSee('Allow: /guest-posts-ireland', false);

        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('/guest-posts-belgium', false)
            ->assertSee('/uk/buy-guest-posts-ireland', false)
            ->assertSee('no /ie/ locale', false);
    }
}
