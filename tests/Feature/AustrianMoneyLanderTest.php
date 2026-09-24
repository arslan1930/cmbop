<?php

namespace Tests\Feature;

use App\Support\AustrianMoneyLanders;
use App\Support\GermanMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AustrianMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_austrian_money_landers_are_indexable_with_at_canonical_and_de_at_hreflang(): void
    {
        foreach (AustrianMoneyLanders::slugs() as $slug) {
            $page = AustrianMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/at/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/at/'.$slug).'"', false)
                ->assertSee('hreflang="de-AT"', false)
                ->assertSee('hreflang="de"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="de-AT"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/at/marktplatz', false)
                ->getContent();

            $this->assertStringNotContainsString('hreflang="en-GB"', $html, $slug);
            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertStringNotContainsString('rel="canonical" href="'.url('/de/'.$slug).'"', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);

            $sharedWithItalian = class_exists(ItalianMoneyLanders::class)
                && ItalianMoneyLanders::isSlug($slug);
            if ($sharedWithItalian) {
                $this->assertStringContainsString('hreflang="it"', $html, $slug);
            } else {
                $this->assertStringNotContainsString('hreflang="it"', $html, $slug);
            }
        }
    }

    public function test_austria_owns_prefixed_copies_and_switzerland_owns_ch(): void
    {
        $this->get('/at/gastbeitrag-kaufen')->assertOk();
        $this->get('/de/gastbeitrag-kaufen')->assertOk();
        $this->get('/ch/gastbeitrag-kaufen')->assertOk();
        $this->get('/gastbeitrag-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');
        $this->get('/fr/gastbeitrag-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');

        $this->get('/at/digital-pr')->assertOk();
        $this->get('/de/digital-pr')->assertOk();
        $this->get('/it/digital-pr')->assertOk();
        $this->get('/ch/digital-pr')->assertOk();
        $this->get('/fr/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/at/comprare-guest-post')->assertRedirect('/it/comprare-guest-post');
    }

    public function test_research_aliases_redirect_without_creating_twins(): void
    {
        $this->get('/at/marketplace')->assertRedirect('/at/marktplatz');
        $this->get('/at/preisliste')->assertRedirect('/at/preise');
        $this->get('/at/link-building')->assertRedirect('/at/linkbuilding');
        $this->get('/at/linkaufbau')->assertRedirect('/at/linkbuilding');
        $this->get('/at/guest-post-kaufen')->assertRedirect('/at/gastbeitrag-kaufen');
        $this->get('/at/medienplatzierung')->assertRedirect('/at/advertorial');
        $this->get('/at/was-ist-ein-gastbeitrag')->assertRedirect('/de/blog/was-ist-ein-gastbeitrag');
        $this->get('/guest-post-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');
        $this->get('/at/wien')->assertNotFound();
        $this->get('/at/whitepress-alternative')->assertNotFound();
    }

    public function test_austrian_home_marketplace_and_pricing_do_not_cannibalize_germany(): void
    {
        $home = $this->get('/at')->assertOk()->getContent();
        $this->assertStringContainsString('Marktplatz für Gastbeiträge in Österreich | SEOLinkBuildings', $home);
        $this->assertStringContainsString('Österreichischer Marktplatz für Gastbeiträge, Backlinks und Linkbuilding.', $home);
        $this->assertStringContainsString('/at/gastbeitrag-kaufen', $home);
        $this->assertStringNotContainsString('Publisher-Marktplatz für Gastbeiträge | SEOLinkBuildings', $home);
        $this->assertStringNotContainsString('Gastbeitrag kaufen in Deutschland | SEOLinkBuildings', $home);

        $buy = $this->get('/at/gastbeitrag-kaufen')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeiträge in Österreich kaufen', $buy);
        $this->assertStringContainsString('Gastbeiträge kaufen in Österreich | SEOLinkBuildings', $buy);
        $this->assertStringNotContainsString('Gastbeiträge kaufen – relevante Publisher für Ihre SEO', $buy);

        $marktplatz = $this->get('/at/marktplatz')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeitrag-Portale und österreichische Publisher', $marktplatz);
        $this->assertStringContainsString('/at/gastbeitrag-kaufen', $marktplatz);
        $this->assertStringNotContainsString('Beste Seiten für Gastbeiträge in Deutschland', $marktplatz);

        $preise = $this->get('/at/preise')->assertOk()->getContent();
        $this->assertStringContainsString('Was kostet ein Gastbeitrag in Österreich', $preise);
        $this->assertStringContainsString('Gastbeitrag &amp; Backlink Preise Österreich', $preise);
        $this->assertStringNotContainsString('Linkbuilding-Kosten Deutschland', $preise);
    }

    public function test_sitemap_at_includes_money_landers_and_not_aliases(): void
    {
        $xml = $this->get('/sitemap-at.xml')->assertOk()->getContent();
        foreach (AustrianMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/at/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/at/marktplatz', $xml);
        $this->assertStringContainsString('/at/preise', $xml);
        $this->assertStringNotContainsString('/at/guest-post-kaufen', $xml);
        $this->assertStringNotContainsString('/at/preisliste', $xml);
        $this->assertStringNotContainsString('/at/linkaufbau', $xml);
        $this->assertStringNotContainsString('/at/medienplatzierung', $xml);

        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/de/gastbeitrag-kaufen', $de);
        $this->assertStringContainsString('/at/gastbeitrag-kaufen', $de);
        $this->assertStringNotContainsString('/at/guest-post-kaufen', $de);
    }

    public function test_germany_and_italy_homes_are_unchanged(): void
    {
        $de = $this->get('/de')->assertOk()->getContent();
        $this->assertStringContainsString('Publisher-Marktplatz für Gastbeiträge | SEOLinkBuildings', $de);
        $this->assertStringNotContainsString('Marktplatz für Gastbeiträge in Österreich | SEOLinkBuildings', $de);
        $this->assertStringContainsString('/de/gastbeitrag-kaufen', $de);

        $it = $this->get('/it')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace guest post e link building | SEOLinkBuildings', $it);
        $this->assertStringNotContainsString('Gastbeitrag kaufen in Österreich', $it);
    }

    public function test_language_switcher_moves_between_de_at_and_shared_it(): void
    {
        $request = Request::create('/at/gastbeitrag-kaufen', 'GET');
        $this->assertSame(url('/de/gastbeitrag-kaufen'), PublicI18n::switchUrl($request, 'de'));
        $this->assertSame(url('/at/gastbeitrag-kaufen'), PublicI18n::switchUrl($request, 'at'));
        $this->assertSame(url('/it'), PublicI18n::switchUrl($request, 'it'));
        $this->assertSame(url('/ch/gastbeitrag-kaufen'), PublicI18n::switchUrl($request, 'ch'));

        $shared = Request::create('/at/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/de/digital-pr'), PublicI18n::switchUrl($shared, 'de'));
        $this->assertSame(url('/at/digital-pr'), PublicI18n::switchUrl($shared, 'at'));
        $this->assertSame(url('/ch/digital-pr'), PublicI18n::switchUrl($shared, 'ch'));
        $this->assertSame(url('/es/digital-pr'), PublicI18n::switchUrl($shared, 'es'));
        $this->assertSame(url('/fr'), PublicI18n::switchUrl($shared, 'fr'));
    }

    public function test_austria_english_lander_links_to_austrian_money_page(): void
    {
        $this->get('/guest-posts-austria')
            ->assertOk()
            ->assertSee('/at/gastbeitrag-kaufen', false)
            ->assertSee('Gastbeitrag kaufen auf Deutsch (Österreich)', false);
    }

    public function test_austrian_teaser_countries_stay_austria(): void
    {
        $this->assertSame(['at'], PublicI18n::catalogTeaserCountries('at'));
        $this->assertSame(['de'], PublicI18n::catalogTeaserCountries('de'));
        $this->assertSame(['ch'], PublicI18n::catalogTeaserCountries('ch'));
    }

    public function test_germany_still_owns_unprefixed_german_slugs(): void
    {
        $this->assertSame(['de'], GermanMoneyLanders::copyRedirectLocales());
        $this->assertSame(['at'], AustrianMoneyLanders::copyRedirectLocales());
        $this->assertSame(['it', 'de', 'at', 'pt'], PublicI18n::moneyLanderLocales('digital-pr'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));
        $this->assertSame(['de', 'at', 'ch'], PublicI18n::moneyLanderLocales('gastbeitrag-kaufen'));
        $this->assertSame('de', PublicI18n::moneyLanderXDefault('gastbeitrag-kaufen'));
    }

    public function test_niche_edits_page_does_not_sell_a_fake_sku(): void
    {
        $html = $this->get('/at/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('kein SKU', $html);
        $this->assertStringContainsString('/at/gastbeitrag-kaufen', $html);
        $this->assertStringNotContainsString('garantiertes Ranking', $html);
    }

    public function test_hreflang_uses_de_at_not_a_fake_austrian_language(): void
    {
        $this->assertSame('de-AT', PublicI18n::hreflang('at'));
        $this->assertSame('de', PublicI18n::hreflang('de'));
        $this->assertSame('de-CH', PublicI18n::hreflang('ch'));
        $this->assertSame('de', PublicI18n::messagesFallback('at'));

        $html = $this->get('/at/linkbuilding')->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="de-AT"', $html);
        $this->assertStringNotContainsString('hreflang="at"', $html);
        $this->assertStringNotContainsString('lang="at"', $html);
    }
}
