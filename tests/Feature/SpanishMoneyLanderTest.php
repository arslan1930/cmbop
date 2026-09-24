<?php

namespace Tests\Feature;

use App\Support\ItalianMoneyLanders;
use App\Support\PublicI18n;
use App\Support\SpanishMoneyLanders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SpanishMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_spanish_money_landers_are_indexable_with_spanish_canonical_and_hreflang(): void
    {
        foreach (SpanishMoneyLanders::slugs() as $slug) {
            $page = SpanishMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/es/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/es/'.$slug).'"', false)
                ->assertSee('hreflang="es"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="es"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/es/mercado', false)
                ->getContent();

            $this->assertStringNotContainsString('hreflang="en-GB"', $html, $slug);
            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertStringNotContainsString('inLanguage":"it-IT"', $html, $slug);
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

    public function test_spain_owns_unique_slugs_and_shares_digital_pr(): void
    {
        $this->get('/es/comprar-guest-post')->assertOk();
        $this->get('/comprar-guest-post')->assertRedirect('/es/comprar-guest-post');
        $this->get('/fr/comprar-guest-post')->assertRedirect('/es/comprar-guest-post');
        $this->get('/it/comprar-guest-post')->assertRedirect('/es/comprar-guest-post');

        $this->get('/es/digital-pr')->assertOk();
        $this->get('/it/digital-pr')->assertOk();
        $this->get('/de/digital-pr')->assertOk();
        $this->get('/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/fr/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/es/comprare-guest-post')->assertRedirect('/it/comprare-guest-post');
    }

    public function test_research_aliases_redirect_without_creating_twins(): void
    {
        $this->get('/es/marketplace')->assertRedirect('/es/mercado');
        $this->get('/es/catalogo-de-medios')->assertRedirect('/es/mercado');
        $this->get('/es/guia')->assertRedirect('/es/como-funciona');
        $this->get('/es/precio-guest-post')->assertRedirect('/es/precios');
        $this->get('/es/comprar-enlaces-seo')->assertRedirect('/es/comprar-backlinks');
        $this->get('/es/publicar-guest-post')->assertRedirect('/es/comprar-guest-post');
        $this->get('/es/madrid')->assertNotFound();
        $this->get('/es/nichos')->assertNotFound();
        $this->get('/es/barcelona')->assertNotFound();
    }

    public function test_spanish_home_marketplace_and_pricing_do_not_cannibalize_buy_page(): void
    {
        $home = $this->get('/es')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace de guest posts en España | SEOLinkBuildings', $home);
        $this->assertStringContainsString('Marketplace de guest posts, backlinks y link building para España.', $home);
        $this->assertStringContainsString('/es/comprar-guest-post', $home);
        $this->assertStringNotContainsString('Comprar guest post en España | SEOLinkBuildings', $home);

        $buy = $this->get('/es/comprar-guest-post')->assertOk()->getContent();
        $this->assertStringContainsString('Comprar guest post en España', $buy);
        $this->assertStringContainsString('Comprar guest post en España | SEOLinkBuildings', $buy);
        $this->assertStringContainsString('no hay CIF español inventado', $buy);

        $mercado = $this->get('/es/mercado')->assertOk()->getContent();
        $this->assertStringContainsString('Catálogo de medios y publishers en España', $mercado);
        $this->assertStringContainsString('/es/comprar-guest-post', $mercado);

        $precios = $this->get('/es/precios')->assertOk()->getContent();
        $this->assertStringContainsString('Qué cuesta un guest post en España', $precios);
        $this->assertStringContainsString('Precios guest post y backlinks en España', $precios);
    }

    public function test_sitemap_es_includes_money_landers_and_not_aliases(): void
    {
        $xml = $this->get('/sitemap-es.xml')->assertOk()->getContent();
        foreach (SpanishMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/es/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/es/mercado', $xml);
        $this->assertStringContainsString('/es/precios', $xml);
        $this->assertStringNotContainsString('/es/catalogo-de-medios', $xml);
        $this->assertStringNotContainsString('/es/precio-guest-post', $xml);
        $this->assertStringNotContainsString('/es/guia', $xml);
    }

    public function test_italy_and_germany_homes_are_unchanged(): void
    {
        $it = $this->get('/it')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace guest post e link building | SEOLinkBuildings', $it);
        $this->assertStringNotContainsString('Comprar guest post en España', $it);

        $de = $this->get('/de')->assertOk()->getContent();
        $this->assertStringContainsString('Publisher-Marktplatz für Gastbeiträge | SEOLinkBuildings', $de);
        $this->assertStringNotContainsString('Comprar guest post en España', $de);
    }

    public function test_language_switcher_on_spanish_money_page(): void
    {
        $request = Request::create('/es/comprar-guest-post', 'GET');
        $this->assertSame(url('/es/comprar-guest-post'), PublicI18n::switchUrl($request, 'es'));
        $this->assertSame(url('/it'), PublicI18n::switchUrl($request, 'it'));
        $this->assertSame(url('/de'), PublicI18n::switchUrl($request, 'de'));

        $shared = Request::create('/es/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/de/digital-pr'), PublicI18n::switchUrl($shared, 'de'));
        $this->assertSame(url('/es/digital-pr'), PublicI18n::switchUrl($shared, 'es'));
        $this->assertSame(url('/ch/digital-pr'), PublicI18n::switchUrl($shared, 'ch'));
        $this->assertSame(url('/fr'), PublicI18n::switchUrl($shared, 'fr'));
    }

    public function test_spain_english_lander_links_to_spanish_money_page(): void
    {
        $this->get('/guest-posts-spain')
            ->assertOk()
            ->assertSee('/es/comprar-guest-post', false)
            ->assertSee('Comprar guest post en español', false);
    }

    public function test_copy_redirects_and_teaser_countries(): void
    {
        $this->assertSame(['es'], SpanishMoneyLanders::copyRedirectLocales());
        $this->assertSame(['es'], PublicI18n::catalogTeaserCountries('es'));
        $this->assertSame(['es'], PublicI18n::moneyLanderLocales('comprar-guest-post'));
        $this->assertSame('es', PublicI18n::moneyLanderXDefault('comprar-guest-post'));
        $this->assertSame(['it', 'de', 'at', 'ch', 'es'], PublicI18n::moneyLanderLocales('digital-pr'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));
    }

    public function test_niche_edits_page_does_not_sell_a_fake_sku(): void
    {
        $html = $this->get('/es/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('no son un SKU', $html);
        $this->assertStringContainsString('/es/comprar-guest-post', $html);
    }
}
