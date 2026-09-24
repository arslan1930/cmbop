<?php

namespace Tests\Feature;

use App\Services\CuratedBlogWriter;
use App\Support\DofollowNofollowAnchorsEnBlogPost;
use App\Support\ItalianMoneyLanders;
use App\Support\PublicI18n;
use Database\Seeders\LinkBuildingGuidesBlogsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ItalianMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_italian_money_landers_are_indexable_with_italian_canonical_and_hreflang(): void
    {
        foreach (ItalianMoneyLanders::slugs() as $slug) {
            $page = ItalianMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/it/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/it/'.$slug).'"', false)
                ->assertSee('hreflang="it"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="it"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/it/mercato', false)
                ->getContent();

            $this->assertStringNotContainsString('hreflang="de"', $html, $slug);
            $this->assertStringNotContainsString('hreflang="en-GB"', $html, $slug);
            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);
        }
    }

    public function test_other_locales_and_unprefixed_urls_redirect_to_italian(): void
    {
        $this->get('/comprare-guest-post')
            ->assertRedirect('/it/comprare-guest-post');
        $this->get('/de/comprare-guest-post')
            ->assertRedirect('/it/comprare-guest-post');
        $this->get('/fr/articoli-sponsorizzati')
            ->assertRedirect('/it/articoli-sponsorizzati');
        $this->assertSame(301, $this->get('/nl/link-building')->status());
    }

    public function test_catalog_and_pricing_aliases_redirect_without_creating_twins(): void
    {
        $this->get('/it/catalogo')->assertRedirect('/it/mercato');
        $this->get('/it/prezzi-guest-post')->assertRedirect('/it/prezzi');
        $this->get('/catalogo')->assertRedirect('/it/mercato');
        $this->get('/de/prezzi-guest-post')->assertRedirect('/it/prezzi');
    }

    public function test_italian_home_no_longer_cannibalizes_buy_guest_post(): void
    {
        $html = $this->get('/it')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace guest post e link building | SEOLinkBuildings', $html);
        $this->assertStringContainsString('Il marketplace di guest post e link building per l’Italia.', $html);
        $this->assertStringContainsString('/it/comprare-guest-post', $html);
        $this->assertStringNotContainsString('Comprare guest post da editori verificati | SEOLinkBuildings', $html);

        $buy = $this->get('/it/comprare-guest-post')->assertOk()->getContent();
        $this->assertStringContainsString('Acquistare guest post su siti di editori verificati', $buy);
        $this->assertStringContainsString('Acquistare guest post in Italia | SEOLinkBuildings', $buy);
    }

    public function test_italian_marketplace_and_pricing_target_catalog_and_cost_queries(): void
    {
        $mercato = $this->get('/it/mercato')->assertOk()->getContent();
        $this->assertStringContainsString('Lista siti per guest post in Italia', $mercato);
        $this->assertStringContainsString('/it/comprare-guest-post', $mercato);

        $prezzi = $this->get('/it/prezzi')->assertOk()->getContent();
        $this->assertStringContainsString('Prezzi guest post e costo del link building', $prezzi);
        $this->assertStringContainsString('Quanto costa un guest post', $prezzi);
    }

    public function test_sitemap_it_includes_money_landers_and_not_aliases(): void
    {
        $xml = $this->get('/sitemap-it.xml')->assertOk()->getContent();
        foreach (ItalianMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/it/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/it/mercato', $xml);
        $this->assertStringNotContainsString('/it/catalogo', $xml);
        $this->assertStringNotContainsString('/it/prezzi-guest-post', $xml);
        $this->assertStringNotContainsString('/it/niche-edits', $xml);

        $de = $this->get('/sitemap-de.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/it/comprare-guest-post', $de);
        $this->assertStringNotContainsString('/de/comprare-guest-post', $de);
    }

    public function test_english_and_german_homes_are_unchanged(): void
    {
        $en = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('/it/comprare-guest-post', $en);
        $this->assertStringContainsString('The guest post marketplace for verified publisher sites.', $en);

        $de = $this->get('/de')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeiträge kaufen — Gastbeitrag-Marktplatz | SEOLinkBuildings', $de);
        $this->assertStringNotContainsString('Acquistare guest post in Italia', $de);
    }

    public function test_language_switcher_on_money_page_leaves_other_locales_on_their_home(): void
    {
        $request = Request::create('/it/comprare-guest-post', 'GET');
        $this->assertSame(url('/de'), PublicI18n::switchUrl($request, 'de'));
        $this->assertSame(url('/it/comprare-guest-post'), PublicI18n::switchUrl($request, 'it'));
    }

    public function test_italy_english_lander_links_to_italian_money_page(): void
    {
        $this->get('/guest-posts-italy')
            ->assertOk()
            ->assertSee('/it/comprare-guest-post', false)
            ->assertSee('Acquistare guest post in italiano', false);
    }

    public function test_italian_teaser_countries_are_italy(): void
    {
        $this->assertSame(['it'], PublicI18n::catalogTeaserCountries('it'));
        $this->assertSame(['de'], PublicI18n::catalogTeaserCountries('fr'));
    }

    public function test_italian_pillar_translations_render_on_localized_slugs(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);
        CuratedBlogWriter::upsert(
            DofollowNofollowAnchorsEnBlogPost::SLUG,
            DofollowNofollowAnchorsEnBlogPost::payload()
        );

        $cases = [
            '/it/blog/cose-un-guest-post' => 'Cos’è un guest post',
            '/it/blog/come-ottenere-backlink' => 'Come ottenere backlink',
            '/it/blog/come-fare-link-building' => 'Come fare link building',
            '/it/blog/guest-post-vs-articolo-sponsorizzato' => 'articolo sponsorizzato',
            '/it/blog/dofollow-vs-nofollow' => 'Dofollow vs nofollow',
        ];

        foreach ($cases as $path => $needle) {
            $this->get($path)
                ->assertOk()
                ->assertSee($needle, false)
                ->assertSee('rel="canonical" href="'.url($path).'"', false);
        }

        $this->get('/it/blog/guest-posting-guide')
            ->assertStatus(301)
            ->assertRedirect(url('/it/blog/cose-un-guest-post'));
    }
}
