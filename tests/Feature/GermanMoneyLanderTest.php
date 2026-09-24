<?php

namespace Tests\Feature;

use App\Services\CuratedBlogWriter;
use App\Support\DofollowNofollowAnchorsEnBlogPost;
use App\Support\GermanMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\PublicI18n;
use Database\Seeders\LinkBuildingGuidesBlogsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class GermanMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_german_money_landers_are_indexable_with_german_canonical_and_hreflang(): void
    {
        foreach (GermanMoneyLanders::slugs() as $slug) {
            $page = GermanMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/de/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/de/'.$slug).'"', false)
                ->assertSee('hreflang="de"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="de"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/de/marktplatz', false)
                ->getContent();

            $this->assertStringNotContainsString('hreflang="en-GB"', $html, $slug);
            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
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

    public function test_other_locales_and_unprefixed_urls_redirect_to_german_except_italian_owned_slugs(): void
    {
        $this->get('/gastbeitrag-kaufen')
            ->assertRedirect('/de/gastbeitrag-kaufen');
        $this->get('/it/gastbeitrag-kaufen')
            ->assertRedirect('/de/gastbeitrag-kaufen');
        $this->get('/fr/backlinks-kaufen')
            ->assertRedirect('/de/backlinks-kaufen');
        $this->assertSame(301, $this->get('/nl/linkbuilding')->status());

        $this->get('/de/digital-pr')->assertOk();
        $this->get('/it/digital-pr')->assertOk();
        $this->get('/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/fr/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/fr/link-building')->assertRedirect('/it/link-building');

        $this->get('/at/digital-pr')->assertOk();
        $this->get('/ch/digital-pr')->assertOk();
        $this->get('/es/digital-pr')->assertOk();
        $this->get('/at/link-building')->assertRedirect('/at/linkbuilding');
        $this->get('/ch/gastbeitrag-kaufen')->assertOk();
        $this->get('/at/comprare-guest-post')->assertRedirect('/it/comprare-guest-post');
    }

    public function test_every_prefixed_locale_copy_goes_to_the_owning_money_page(): void
    {
        $prefixed = array_values(array_filter(
            PublicI18n::prefixed(),
            static fn ($locale) => is_string($locale) && $locale !== ''
        ));
        $this->assertNotEmpty($prefixed);

        foreach ($prefixed as $locale) {
            if ($locale === 'de') {
                $this->get('/de/gastbeitrag-kaufen')->assertOk();

                continue;
            }
            if ($locale === 'at') {
                $this->get('/at/gastbeitrag-kaufen')->assertOk();

                continue;
            }
            if ($locale === 'ch') {
                $this->get('/ch/gastbeitrag-kaufen')->assertOk();

                continue;
            }
            $this->get('/'.$locale.'/gastbeitrag-kaufen')
                ->assertRedirect('/de/gastbeitrag-kaufen');
        }

        foreach ($prefixed as $locale) {
            if ($locale === 'it') {
                $this->get('/it/comprare-guest-post')->assertOk();

                continue;
            }
            $this->get('/'.$locale.'/comprare-guest-post')
                ->assertRedirect('/it/comprare-guest-post');
        }

        foreach ($prefixed as $locale) {
            if (in_array($locale, ['de', 'ch'], true)) {
                $this->get('/'.$locale.'/digital-pr')->assertOk();

                continue;
            }
            if ($locale === 'at') {
                $this->get('/at/digital-pr')->assertOk();

                continue;
            }
            if ($locale === 'it') {
                $this->get('/it/digital-pr')->assertOk();

                continue;
            }
            if ($locale === 'pt') {
                $this->get('/pt/digital-pr')->assertOk();

                continue;
            }
            $this->get('/'.$locale.'/digital-pr')->assertRedirect('/it/digital-pr');
        }
    }

    public function test_catalog_and_research_aliases_redirect_without_creating_twins(): void
    {
        $this->get('/de/marketplace')->assertRedirect('/de/marktplatz');
        $this->get('/de/preisliste')->assertRedirect('/de/preise');
        $this->get('/de/link-building')->assertRedirect('/de/linkbuilding');
        $this->get('/de/linkaufbau')->assertRedirect('/de/linkbuilding');
        $this->get('/de/guest-post-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');
        $this->get('/guest-post-kaufen')->assertRedirect('/de/gastbeitrag-kaufen');
        $this->get('/fr/preisliste')->assertRedirect('/de/preise');
    }

    public function test_german_home_no_longer_cannibalizes_buy_guest_post(): void
    {
        $html = $this->get('/de')->assertOk()->getContent();
        $this->assertStringContainsString('Publisher-Marktplatz für Gastbeiträge | SEOLinkBuildings', $html);
        $this->assertStringContainsString('Der Publisher-Marktplatz für Gastbeiträge, Backlinks und Linkbuilding.', $html);
        $this->assertStringContainsString('/de/gastbeitrag-kaufen', $html);
        $this->assertStringNotContainsString('Gastbeiträge kaufen — Gastbeitrag-Marktplatz | SEOLinkBuildings', $html);

        $buy = $this->get('/de/gastbeitrag-kaufen')->assertOk()->getContent();
        $this->assertStringContainsString('Gastbeiträge kaufen – relevante Publisher für Ihre SEO', $buy);
        $this->assertStringContainsString('Gastbeitrag kaufen in Deutschland | SEOLinkBuildings', $buy);
    }

    public function test_german_marketplace_and_pricing_target_catalog_and_cost_queries(): void
    {
        $marktplatz = $this->get('/de/marktplatz')->assertOk()->getContent();
        $this->assertStringContainsString('Beste Seiten für Gastbeiträge', $marktplatz);
        $this->assertStringContainsString('/de/gastbeitrag-kaufen', $marktplatz);

        $preise = $this->get('/de/preise')->assertOk()->getContent();
        $this->assertStringContainsString('Was kostet ein Gastbeitrag', $preise);
        $this->assertStringContainsString('Gastbeitrag-Kosten', $preise);
    }

    public function test_sitemap_de_includes_money_landers_and_not_aliases(): void
    {
        $xml = $this->get('/sitemap-de.xml')->assertOk()->getContent();
        foreach (GermanMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/de/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/de/marktplatz', $xml);
        $this->assertStringContainsString('/de/preise', $xml);
        $this->assertStringNotContainsString('/de/guest-post-kaufen', $xml);
        $this->assertStringNotContainsString('/de/preisliste', $xml);
        $this->assertStringNotContainsString('/de/linkaufbau', $xml);

        $it = $this->get('/sitemap-it.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/de/gastbeitrag-kaufen', $it);
        $this->assertStringContainsString('/it/comprare-guest-post', $it);
    }

    public function test_english_and_italian_homes_are_unchanged(): void
    {
        $en = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('/de/gastbeitrag-kaufen', $en);
        $this->assertStringContainsString('The guest post marketplace for verified publisher sites.', $en);

        $it = $this->get('/it')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace guest post e link building | SEOLinkBuildings', $it);
        $this->assertStringNotContainsString('Gastbeitrag kaufen in Deutschland', $it);
    }

    public function test_language_switcher_on_money_page_leaves_other_locales_on_their_home(): void
    {
        $request = Request::create('/de/gastbeitrag-kaufen', 'GET');
        $this->assertSame(url('/it'), PublicI18n::switchUrl($request, 'it'));
        $this->assertSame(url('/de/gastbeitrag-kaufen'), PublicI18n::switchUrl($request, 'de'));

        $shared = Request::create('/de/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/de/digital-pr'), PublicI18n::switchUrl($shared, 'de'));
        $this->assertSame(url('/at/digital-pr'), PublicI18n::switchUrl($shared, 'at'));
        $this->assertSame(url('/ch/digital-pr'), PublicI18n::switchUrl($shared, 'ch'));
        $this->assertSame(url('/es/digital-pr'), PublicI18n::switchUrl($shared, 'es'));
        $this->assertSame(url('/fr'), PublicI18n::switchUrl($shared, 'fr'));
    }

    public function test_germany_english_lander_links_to_german_money_page(): void
    {
        $this->get('/guest-posts-germany')
            ->assertOk()
            ->assertSee('/de/gastbeitrag-kaufen', false)
            ->assertSee('Gastbeitrag kaufen auf Deutsch', false);
    }

    public function test_german_teaser_countries_stay_germany(): void
    {
        $this->assertSame(['de'], PublicI18n::catalogTeaserCountries('de'));
        $this->assertSame(['de'], PublicI18n::catalogTeaserCountries('fr'));
    }

    public function test_german_pillar_translations_render_on_localized_slugs(): void
    {
        $this->seed(LinkBuildingGuidesBlogsSeeder::class);
        CuratedBlogWriter::upsert(
            DofollowNofollowAnchorsEnBlogPost::SLUG,
            DofollowNofollowAnchorsEnBlogPost::payload()
        );

        $cases = [
            '/de/blog/was-ist-ein-gastbeitrag' => 'Was ist ein Gastbeitrag',
            '/de/blog/was-sind-backlinks' => 'Was sind Backlinks',
            '/de/blog/linkaufbau-strategien' => 'Linkaufbau-Strategien',
            '/de/blog/dofollow-vs-nofollow-ankertext' => 'Dofollow vs. Nofollow',
        ];

        foreach ($cases as $path => $needle) {
            $this->get($path)
                ->assertOk()
                ->assertSee($needle, false)
                ->assertSee('rel="canonical" href="'.url($path).'"', false);
        }

        $this->get('/de/blog/gastbeitraege-leitfaden-pitch-und-text')
            ->assertStatus(301)
            ->assertRedirect('/de/blog/was-ist-ein-gastbeitrag');
        $this->get('/de/was-ist-ein-gastbeitrag')
            ->assertRedirect('/de/blog/was-ist-ein-gastbeitrag');
        $this->get('/de/wie-funktioniert-linkbuilding')
            ->assertRedirect('/de/blog/linkaufbau-strategien');
    }

    public function test_niche_edits_page_does_not_sell_a_fake_sku(): void
    {
        $html = $this->get('/de/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('kein SKU', $html);
        $this->assertStringContainsString('/de/gastbeitrag-kaufen', $html);
        $this->assertStringNotContainsString('garantiertes Ranking', $html);
    }
}
