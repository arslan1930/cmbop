<?php

namespace Tests\Feature;

use App\Support\GermanMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\PortugueseMoneyLanders;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortugueseMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_portuguese_money_landers_are_indexable(): void
    {
        foreach (PortugueseMoneyLanders::slugs() as $slug) {
            $page = PortugueseMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/pt/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/pt/'.$slug).'"', false)
                ->assertSee('hreflang="pt-PT"', false)
                ->assertSee('lang="pt-PT"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/pt/marketplace', false)
                ->getContent();

            $this->assertStringNotContainsString('hreflang="en-GB"', $html, $slug);
            $this->assertStringNotContainsString('inLanguage":"it-IT"', $html, $slug);
            $this->assertStringNotContainsString('você', $html, $slug);
            $this->assertStringNotContainsString('listino', $html, $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
        }
    }

    public function test_portugal_owns_unique_slugs_and_shares_with_italy(): void
    {
        $this->get('/pt/comprar-guest-post')->assertOk();
        $this->get('/es/comprar-guest-post')->assertOk();
        $this->get('/comprar-guest-post')->assertRedirect('/es/comprar-guest-post');
        $this->get('/fr/comprar-guest-post')->assertRedirect('/es/comprar-guest-post');

        $this->get('/pt/link-building')->assertOk();
        $this->get('/it/link-building')->assertOk();
        $this->get('/link-building')->assertRedirect('/it/link-building');
        $this->get('/pt/digital-pr')->assertOk();
        $this->get('/it/digital-pr')->assertOk();
        $this->get('/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/pt/niche-edits')->assertOk();
        $this->get('/de/niche-edits')->assertOk();
        $this->get('/niche-edits')->assertRedirect('/de/niche-edits');
        $this->get('/pt/comprare-guest-post')->assertRedirect('/it/comprare-guest-post');
    }

    public function test_aliases_and_city_doorways(): void
    {
        $this->get('/pt/mercado')->assertRedirect('/pt/marketplace');
        $this->get('/pt/guest-post-portugal')->assertRedirect('/pt/comprar-guest-post');
        $this->get('/pt/preco-guest-post')->assertRedirect('/pt/precos');
        $this->get('/pt/nota-de-imprensa')->assertRedirect('/pt/digital-pr');
        $this->get('/pt/lisboa')->assertNotFound();
        $this->get('/pt/porto')->assertNotFound();
    }

    public function test_home_marketplace_and_pricing(): void
    {
        $home = $this->get('/pt')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace de guest posts em Portugal | SEOLinkBuildings', $home);
        $this->assertStringContainsString('/pt/comprar-guest-post', $home);
        $this->assertStringContainsString('lang="pt-PT"', $home);

        $buy = $this->get('/pt/comprar-guest-post')->assertOk()->getContent();
        $this->assertStringContainsString('não há NIF português inventado', $buy);
        $this->assertStringNotContainsString('média portugueses guest post', $buy);
        $this->assertStringNotContainsString('guest post da alto', $buy);
        $this->assertStringNotContainsString('importâncias', $buy);
        $this->assertStringNotContainsString('activos', $buy);

        $market = $this->get('/pt/marketplace')->assertOk()->getContent();
        $this->assertStringContainsString('Catálogo de meios e publishers em Portugal', $market);
        $this->assertStringContainsString('/pt/comprar-guest-post', $market);

        $precos = $this->get('/pt/precos')->assertOk()->getContent();
        $this->assertStringContainsString('Quanto custa um guest post em Portugal', $precos);
        $this->assertStringContainsString('Preços de guest post e backlinks em Portugal', $precos);

        $agencias = $this->get('/pt/agencias')->assertOk()->getContent();
        $this->assertStringContainsString('equipas que refaturam', $agencias);
        $this->assertStringContainsString('faturação do anunciante', $agencias);
        $this->assertStringNotContainsString('refactur', $agencias);
        $this->assertStringNotContainsString('Billing', $agencias);
    }

    public function test_sitemap_and_english_lander(): void
    {
        $xml = $this->get('/sitemap-pt.xml')->assertOk()->getContent();
        foreach (PortugueseMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/pt/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/pt/marketplace', $xml);
        $this->assertStringContainsString('/pt/precos', $xml);
        $this->assertStringContainsString('/pt/guia', $xml);
        $this->assertStringNotContainsString('/pt/mercado', $xml);
        $this->assertStringNotContainsString('/pt/lisboa', $xml);

        $en = $this->get('/guest-posts-portugal')->assertOk()->getContent();
        $this->assertStringContainsString('/pt/comprar-guest-post', $en);
        $this->assertStringContainsString('Guest Posts in Portugal', $en);
    }

    public function test_copy_redirects_do_not_steal_italy_or_germany(): void
    {
        $this->assertSame(['pt'], PortugueseMoneyLanders::copyRedirectLocales());
        $this->assertSame(['pt'], PublicI18n::catalogTeaserCountries('pt'));
        $this->assertContains('pt', PublicI18n::moneyLanderLocales('comprar-guest-post'));
        $this->assertContains('es', PublicI18n::moneyLanderLocales('comprar-guest-post'));
        $this->assertContains('pt', PublicI18n::moneyLanderLocales('link-building'));
        $this->assertContains('it', PublicI18n::moneyLanderLocales('link-building'));
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('link-building'));
        $this->assertSame('pt-PT', PublicI18n::hreflang('pt'));
        $this->assertSame('pt', PublicI18n::fromBrowserTag('pt-PT'));
        $this->assertTrue(ItalianMoneyLanders::isSlug('link-building'));
        $this->assertTrue(GermanMoneyLanders::isSlug('niche-edits'));

        $it = $this->get('/it')->assertOk()->getContent();
        $this->assertStringNotContainsString('Comprar guest post em Portugal', $it);
    }

    public function test_niche_edits_and_guide_are_not_fake_skus(): void
    {
        $niche = $this->get('/pt/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('não são um SKU', $niche);

        $guia = $this->get('/pt/guia')->assertOk()->getContent();
        $this->assertStringContainsString('Dofollow, nofollow, sponsored', $guia);
        $this->assertStringContainsString('/pt/comprar-guest-post', $guia);
    }
}
