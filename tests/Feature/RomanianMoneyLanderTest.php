<?php

namespace Tests\Feature;

use App\Support\ItalianMoneyLanders;
use App\Support\PublicI18n;
use App\Support\RomanianMoneyLanders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RomanianMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_romanian_money_landers_are_indexable(): void
    {
        foreach (RomanianMoneyLanders::slugs() as $slug) {
            $page = RomanianMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/ro/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/ro/'.$slug).'"', false)
                ->assertSee('hreflang="ro"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="ro"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/ro/piata', false)
                ->getContent();

            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertStringNotContainsString('inLanguage":"it-IT"', $html, $slug);
            $this->assertStringNotContainsString('media digitale guest post', $html, $slug);
            $this->assertStringNotContainsString('guest post da mare', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);

            $sharedWithItalian = class_exists(ItalianMoneyLanders::class)
                && ItalianMoneyLanders::isSlug($slug);
            if ($sharedWithItalian) {
                $this->assertStringContainsString('hreflang="it"', $html, $slug);
            }
        }
    }

    public function test_romania_owns_unique_slugs_and_shares_digital_pr(): void
    {
        $this->get('/cumpara-guest-post')->assertRedirect('/ro/cumpara-guest-post');
        $this->get('/fr/cumpara-guest-post')->assertRedirect('/ro/cumpara-guest-post');
        $this->get('/es/cumpara-guest-post')->assertRedirect('/ro/cumpara-guest-post');

        $this->get('/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/fr/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/ro/digital-pr')->assertOk();
        $this->get('/ro/link-building')->assertOk();
        $this->get('/ro/niche-edits')->assertOk();
        $this->get('/es/digital-pr')->assertOk();
        $this->get('/it/link-building')->assertOk();
    }

    public function test_aliases_and_city_doorways(): void
    {
        $this->get('/ro/marketplace')->assertRedirect('/ro/piata');
        $this->get('/ro/guest-post-romania')->assertRedirect('/ro/cumpara-guest-post');
        $this->get('/ro/pret-guest-post')->assertRedirect('/ro/preturi');
        $this->get('/ro/comunicat-de-presa')->assertRedirect('/ro/digital-pr');
        $this->get('/ro/cumpara-backlinks')->assertRedirect('/ro/cumpara-backlink');
        $this->get('/ro/bucuresti')->assertNotFound();
        $this->get('/ro/cluj')->assertNotFound();
    }

    public function test_home_marketplace_and_pricing(): void
    {
        $home = $this->get('/ro')->assertOk()->getContent();
        $this->assertStringContainsString('Guest post și link building în România | SEOLinkBuildings', $home);
        $this->assertStringContainsString('/ro/cumpara-guest-post', $home);
        $this->assertStringContainsString('lang="ro"', $home);

        $buy = $this->get('/ro/cumpara-guest-post')->assertOk()->getContent();
        $this->assertStringContainsString('nu inventăm un CUI românesc', $buy);
        $this->assertStringContainsString('Acasă', $buy);
        $this->assertStringNotContainsString('teletip', $buy);
        $this->assertStringNotContainsString('ușa de oraș', $buy);
        $this->assertStringNotContainsString('rankează', $buy);

        $market = $this->get('/ro/piata')->assertOk()->getContent();
        $this->assertStringContainsString('Catalog de publicații și publishers în România', $market);
        $this->assertStringContainsString('/ro/cumpara-guest-post', $market);

        $preturi = $this->get('/ro/preturi')->assertOk()->getContent();
        $this->assertStringContainsString('Cât costă un guest post în România', $preturi);
        $this->assertStringContainsString('Preț guest post și link building România', $preturi);
    }

    public function test_sitemap_and_english_lander(): void
    {
        $xml = $this->get('/sitemap-ro.xml')->assertOk()->getContent();
        foreach (RomanianMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/ro/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/ro/piata', $xml);
        $this->assertStringContainsString('/ro/preturi', $xml);
        $this->assertStringContainsString('/ro/ghid', $xml);
        $this->assertStringNotContainsString('/ro/bucuresti', $xml);
        $this->assertStringNotContainsString('/ro/guest-post-romania', $xml);

        $this->get('/guest-posts-romania')
            ->assertOk()
            ->assertSee('/ro/cumpara-guest-post', false)
            ->assertSee('Cumpără guest post în română', false);
    }

    public function test_copy_redirects_do_not_steal_italy(): void
    {
        $this->assertSame(['ro'], RomanianMoneyLanders::copyRedirectLocales());
        $this->assertSame(['ro'], PublicI18n::moneyLanderLocales('cumpara-guest-post'));
        $this->assertSame('ro', PublicI18n::moneyLanderXDefault('cumpara-guest-post'));
        $this->assertContains('ro', PublicI18n::moneyLanderLocales('link-building'));
        $this->assertContains('it', PublicI18n::moneyLanderLocales('link-building'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));

        $shared = Request::create('/ro/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/ro/digital-pr'), PublicI18n::switchUrl($shared, 'ro'));
    }

    public function test_niche_edits_and_guide_are_not_fake_skus(): void
    {
        $niche = $this->get('/ro/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('nu sunt un SKU', $niche);
        $this->assertStringContainsString('/ro/cumpara-guest-post', $niche);

        $ghid = $this->get('/ro/ghid')->assertOk()->getContent();
        $this->assertStringContainsString('Dofollow, nofollow, sponsored', $ghid);
        $this->assertStringContainsString('PBN vs guest post', $ghid);
    }
}
