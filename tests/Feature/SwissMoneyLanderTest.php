<?php

namespace Tests\Feature;

use App\Support\AustrianMoneyLanders;
use App\Support\GermanMoneyLanders;
use App\Support\PublicI18n;
use App\Support\SwissMoneyLanders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SwissMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_swiss_money_landers_are_indexable_with_ch_canonical_and_de_ch_hreflang(): void
    {
        foreach (SwissMoneyLanders::slugs() as $slug) {
            $page = SwissMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/ch/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/ch/'.$slug).'"', false)
                ->assertSee('hreflang="de-CH"', false)
                ->assertSee('hreflang="de"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="de-CH"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/ch/marktplatz', false)
                ->getContent();

            $this->assertStringNotContainsString('hreflang="en-GB"', $html, $slug);
            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertStringNotContainsString('rel="canonical" href="'.url('/de/'.$slug).'"', $html, $slug);
            $this->assertStringNotContainsString('ß', (string) ($page['h1'] ?? ''), $slug);
            $this->assertStringNotContainsString('ß', (string) ($page['meta_title'] ?? ''), $slug);
            $this->assertStringNotContainsString('ß', (string) ($page['meta_description'] ?? ''), $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);
        }
    }

    public function test_switzerland_owns_prefixed_copies_without_stealing_germany(): void
    {
        $this->get('/ch/gastbeitrag-kaufen')->assertOk();
        $this->get('/de/gastbeitrag-kaufen')->assertOk();
        $this->get('/at/gastbeitrag-kaufen')->assertOk();
        $this->get('/gastbeitrag-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');
        $this->get('/fr/gastbeitrag-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');

        $this->get('/ch/digital-pr')->assertOk();
        $this->get('/de/digital-pr')->assertOk();
        $this->get('/it/digital-pr')->assertOk();
        $this->get('/es/digital-pr')->assertOk();
        $this->get('/fr/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/ch/comprare-guest-post')->assertRedirect('/it/comprare-guest-post');
    }

    public function test_research_aliases_redirect_without_creating_twins(): void
    {
        $this->get('/ch/marketplace')->assertRedirect('/ch/marktplatz');
        $this->get('/ch/preisliste')->assertRedirect('/ch/preise');
        $this->get('/ch/link-building')->assertRedirect('/ch/linkbuilding');
        $this->get('/ch/linkaufbau')->assertRedirect('/ch/linkbuilding');
        $this->get('/ch/guest-post-kaufen')->assertRedirect('/ch/gastbeitrag-kaufen');
        $this->get('/ch/medienplatzierung')->assertRedirect('/ch/advertorial');
        $this->get('/ch/was-ist-ein-gastbeitrag')->assertRedirect('/de/blog/was-ist-ein-gastbeitrag');
        $this->get('/ch/zurich')->assertNotFound();
        $this->get('/ch/zuerich')->assertNotFound();
    }

    public function test_swiss_home_marketplace_and_pricing_do_not_cannibalize_germany(): void
    {
        $home = $this->get('/ch')->assertOk()->getContent();
        $this->assertStringContainsString('Marktplatz für Gastbeiträge in der Schweiz | SEOLinkBuildings', $home);
        $this->assertStringContainsString('Schweizer Marktplatz für Gastbeiträge, Backlinks und Linkbuilding.', $home);
        $this->assertStringContainsString('/ch/gastbeitrag-kaufen', $home);
        $this->assertStringNotContainsString('Publisher-Marktplatz für Gastbeiträge | SEOLinkBuildings', $home);
        $this->assertStringNotContainsString('Gastbeitrag kaufen in Deutschland | SEOLinkBuildings', $home);

        $buy = $this->get('/ch/gastbeitrag-kaufen')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeiträge in der Schweiz kaufen', $buy);
        $this->assertStringContainsString('Gastbeiträge kaufen in der Schweiz | SEOLinkBuildings', $buy);
        $this->assertStringNotContainsString('Gastbeiträge kaufen – relevante Publisher für Ihre SEO', $buy);
        $this->assertStringContainsString('kein CHF-Wallet', $buy);

        $marktplatz = $this->get('/ch/marktplatz')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeitrag-Portale und Schweizer Publisher', $marktplatz);
        $this->assertStringContainsString('/ch/gastbeitrag-kaufen', $marktplatz);
        $this->assertStringNotContainsString('Beste Seiten für Gastbeiträge in Deutschland', $marktplatz);

        $preise = $this->get('/ch/preise')->assertOk()->getContent();
        $this->assertStringContainsString('Was kostet ein Gastbeitrag in der Schweiz', $preise);
        $this->assertStringContainsString('Gastbeitrag &amp; Backlink Preise Schweiz', $preise);
        $this->assertStringNotContainsString('Linkbuilding-Kosten Deutschland', $preise);
    }

    public function test_sitemap_ch_includes_money_landers_and_not_aliases(): void
    {
        $xml = $this->get('/sitemap-ch.xml')->assertOk()->getContent();
        foreach (SwissMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/ch/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/ch/marktplatz', $xml);
        $this->assertStringContainsString('/ch/preise', $xml);
        $this->assertStringNotContainsString('/ch/guest-post-kaufen', $xml);
        $this->assertStringNotContainsString('/ch/preisliste', $xml);
        $this->assertStringNotContainsString('/ch/linkaufbau', $xml);

        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/de/gastbeitrag-kaufen', $de);
        $this->assertStringContainsString('/ch/gastbeitrag-kaufen', $de);
    }

    public function test_language_switcher_moves_between_de_at_ch_and_shared_it(): void
    {
        $request = Request::create('/ch/gastbeitrag-kaufen', 'GET');
        $this->assertSame(url('/de/gastbeitrag-kaufen'), PublicI18n::switchUrl($request, 'de'));
        $this->assertSame(url('/at/gastbeitrag-kaufen'), PublicI18n::switchUrl($request, 'at'));
        $this->assertSame(url('/ch/gastbeitrag-kaufen'), PublicI18n::switchUrl($request, 'ch'));
        $this->assertSame(url('/it'), PublicI18n::switchUrl($request, 'it'));

        $shared = Request::create('/ch/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/de/digital-pr'), PublicI18n::switchUrl($shared, 'de'));
        $this->assertSame(url('/at/digital-pr'), PublicI18n::switchUrl($shared, 'at'));
        $this->assertSame(url('/ch/digital-pr'), PublicI18n::switchUrl($shared, 'ch'));
        $this->assertSame(url('/es/digital-pr'), PublicI18n::switchUrl($shared, 'es'));
        $this->assertSame(url('/fr'), PublicI18n::switchUrl($shared, 'fr'));
    }

    public function test_switzerland_english_lander_links_to_swiss_money_page(): void
    {
        $this->get('/guest-posts-switzerland')
            ->assertOk()
            ->assertSee('/ch/gastbeitrag-kaufen', false)
            ->assertSee('Gastbeitrag kaufen auf Deutsch (Schweiz)', false);
    }

    public function test_copy_redirects_and_hreflang_locales(): void
    {
        $this->assertSame(['de'], GermanMoneyLanders::copyRedirectLocales());
        $this->assertSame(['at'], AustrianMoneyLanders::copyRedirectLocales());
        $this->assertSame(['ch'], SwissMoneyLanders::copyRedirectLocales());
        $this->assertSame(['de', 'at', 'ch'], PublicI18n::moneyLanderLocales('gastbeitrag-kaufen'));
        $this->assertSame('de', PublicI18n::moneyLanderXDefault('gastbeitrag-kaufen'));
        $this->assertSame('de-CH', PublicI18n::hreflang('ch'));
        $this->assertSame(['ch'], PublicI18n::catalogTeaserCountries('ch'));
    }

    public function test_niche_edits_page_does_not_sell_a_fake_sku(): void
    {
        $html = $this->get('/ch/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('kein SKU', $html);
        $this->assertStringContainsString('/ch/gastbeitrag-kaufen', $html);
        $this->assertStringNotContainsString('garantiertes Ranking', $html);
    }
}
