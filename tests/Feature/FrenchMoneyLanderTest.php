<?php

namespace Tests\Feature;

use App\Support\FrenchMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FrenchMoneyLanderTest extends TestCase
{
    use RefreshDatabase;

    public function test_french_money_landers_are_indexable_with_french_canonical_and_hreflang(): void
    {
        foreach (FrenchMoneyLanders::slugs() as $slug) {
            $page = FrenchMoneyLanders::find($slug);
            $this->assertNotNull($page, $slug);

            $html = $this->get('/fr/'.$slug)
                ->assertOk()
                ->assertSee($page['h1'], false)
                ->assertSee($page['meta_title'], false)
                ->assertSee('rel="canonical" href="'.url('/fr/'.$slug).'"', false)
                ->assertSee('hreflang="fr"', false)
                ->assertSee('hreflang="x-default"', false)
                ->assertSee('lang="fr"', false)
                ->assertSee(url('/register'), false)
                ->assertSee('/fr/marche', false)
                ->getContent();

            $this->assertStringNotContainsString('hreflang="en-GB"', $html, $slug);
            $this->assertStringNotContainsString('advertiser/catalog', $html, $slug);
            $this->assertStringNotContainsString('inLanguage":"en"', $html, $slug);
            $this->assertGreaterThanOrEqual(30, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(70, mb_strlen((string) $page['meta_title']), $slug);
            $this->assertLessThanOrEqual(180, mb_strlen((string) $page['meta_description']), $slug);

            $shared = class_exists(ItalianMoneyLanders::class) && ItalianMoneyLanders::isSlug($slug);
            if ($shared) {
                $this->assertStringContainsString('hreflang="it"', $html, $slug);
            }
        }
    }

    public function test_france_owns_unique_slugs_and_shares_digital_pr(): void
    {
        $this->get('/fr/acheter-guest-post')->assertOk();
        $this->get('/acheter-guest-post')->assertRedirect('/fr/acheter-guest-post');
        $this->get('/de/acheter-guest-post')->assertRedirect('/fr/acheter-guest-post');

        $this->get('/fr/netlinking')->assertOk();
        $this->get('/netlinking')->assertRedirect('/fr/netlinking');
        $this->get('/it/netlinking')->assertRedirect('/fr/netlinking');

        $this->get('/fr/digital-pr')->assertOk();
        $this->get('/it/digital-pr')->assertOk();
        $this->get('/digital-pr')->assertRedirect('/it/digital-pr');
        $this->get('/fr/niche-edits')->assertOk();
        $this->get('/niche-edits')->assertRedirect('/de/niche-edits');
        $this->get('/fr/link-building')->assertRedirect('/it/link-building');
        $this->get('/fr/comprare-guest-post')->assertRedirect('/it/comprare-guest-post');
    }

    public function test_research_aliases_redirect_without_creating_twins(): void
    {
        $this->get('/fr/marketplace')->assertRedirect('/fr/marche');
        $this->get('/fr/prix')->assertRedirect('/fr/tarifs');
        $this->get('/fr/medias-francais')->assertRedirect('/fr/marche');
        $this->get('/fr/guest-post-france')->assertRedirect('/fr/acheter-guest-post');
        $this->get('/fr/acheter-article-sponsorise')->assertRedirect('/fr/article-sponsorise');
        $this->get('/fr/communique-de-presse')->assertRedirect('/fr/digital-pr');
        $this->get('/fr/guide-netlinking')->assertRedirect('/fr/guide');
        $this->get('/fr/paris')->assertNotFound();
        $this->get('/fr/lyon')->assertNotFound();
    }

    public function test_french_home_marketplace_and_pricing_do_not_cannibalize_buy_page(): void
    {
        $home = $this->get('/fr')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace de netlinking en France | SEOLinkBuildings', $home);
        $this->assertStringContainsString('Marketplace de guest posts, backlinks et netlinking pour la France.', $home);
        $this->assertStringContainsString('/fr/acheter-guest-post', $home);
        $this->assertStringNotContainsString('résultats mesurables', $home);
        $this->assertStringNotContainsString('Checkout wallet', $home);
        $this->assertStringNotContainsString('Semrush', $home);
        $this->assertStringNotContainsString('Paiement par wallet', $home);
        $this->assertStringNotContainsString('package Digital PR managé', $home);
        $this->assertStringNotContainsString('Guest-post and backlink marketplace connecting advertisers', $home);
        $this->assertStringNotContainsString('About SEOLinkBuildings', $home);
        $this->assertStringNotContainsString('on-page', $home);
        $this->assertStringNotContainsString('actionnables', $home);
        $this->assertStringNotContainsString('Storytelling', $home);
        $this->assertStringNotContainsString('booster votre SEO', $home);

        $buy = $this->get('/fr/acheter-guest-post')->assertOk()->getContent();
        $this->assertStringContainsString('Acheter un guest post en France', $buy);
        $this->assertStringContainsString('Acheter des guest posts en France | SEOLinkBuildings', $buy);
        $this->assertStringContainsString('SIRET', $buy);
        $this->assertStringNotContainsString('meilleure plateforme', $buy);
        $this->assertStringNotContainsString('checkout', strtolower($buy));
        $this->assertStringNotContainsString('listing', strtolower($buy));
        $this->assertStringNotContainsString('Semrush', $buy);

        $agences = $this->get('/fr/agences')->assertOk()->getContent();
        $this->assertStringNotContainsString('off-page', $agences);
        $this->assertStringNotContainsString('outreach', strtolower($agences));

        $blade = (string) file_get_contents(resource_path('views/pages/french-money-lander.blade.php'));
        $this->assertStringNotContainsString('checkout', strtolower($blade));
        $this->assertStringNotContainsString('listing', strtolower($blade));

        $marche = $this->get('/fr/marche')->assertOk()->getContent();
        $this->assertStringContainsString('Catalogue de médias et d’éditeurs en France', $marche);
        $this->assertStringContainsString('/fr/acheter-guest-post', $marche);
        $this->assertStringNotContainsString('median advertiser prices', $marche);
        $this->assertStringNotContainsString('EU guest-post price index', $marche);

        $tarifs = $this->get('/fr/tarifs')->assertOk()->getContent();
        $this->assertStringContainsString('Combien coûte un guest post en France', $tarifs);
        $this->assertStringContainsString('Prix des guest posts en France | SEOLinkBuildings', $tarifs);
        $this->assertStringNotContainsString('au checkout', $tarifs);
        $this->assertStringNotContainsString('selon le listing', $tarifs);
        $this->assertStringNotContainsString('package Digital PR managé', $tarifs);
        $this->assertStringNotContainsString('outreach', $tarifs);

        $how = $this->get('/fr/comment-ca-marche')->assertOk()->getContent();
        $this->assertStringNotContainsString('Recharger le wallet', $how);
        $this->assertStringNotContainsString('au checkout', $how);
        $this->assertStringNotContainsString('paiements wallet', $how);
        $this->assertStringContainsString('portefeuille', $how);

        $about = $this->get('/fr/a-propos')->assertOk()->getContent();
        $this->assertStringContainsString('italien', $about);
        $this->assertStringContainsString('roumain', $about);
        $this->assertStringNotContainsString('EN/DE/FR/NL', $about);
        $this->assertStringNotContainsString('FR/EN/DE/NL', $about);

        $faq = $this->get('/fr/faq')->assertOk()->getContent();
        $this->assertStringContainsString('portugais', $faq);
        $this->assertStringNotContainsString('tableau de bord SaaS', $faq);
        $this->assertStringNotContainsString('Aide & feedback', $faq);

        $refund = $this->get('/fr/remboursement')->assertOk()->getContent();
        $this->assertStringNotContainsString('en cash', $refund);
        $this->assertStringNotContainsString('bank/Wise', $refund);
        $this->assertStringNotContainsString('Clawbacks', $refund);

        $contact = $this->get('/fr/contact')->assertOk()->getContent();
        $this->assertStringNotContainsString('mieux se classer', $contact);
        $this->assertStringNotContainsString('bons classements', $contact);

        $buyGuide = (string) file_get_contents(app_path('Support/AcheterGuestPostsFrBlogPost.php'));
        $this->assertStringNotContainsString('/marketplace', $buyGuide);
        $this->assertStringNotContainsString('/how-it-works', $buyGuide);
        $this->assertStringNotContainsString('Au checkout', $buyGuide);
        $this->assertStringContainsString('/fr/marche', $buyGuide);
    }

    public function test_sitemap_fr_includes_money_landers_and_not_aliases(): void
    {
        $xml = $this->get('/sitemap-fr.xml')->assertOk()->getContent();
        foreach (FrenchMoneyLanders::slugs() as $slug) {
            $this->assertStringContainsString('/fr/'.$slug, $xml, $slug);
        }
        $this->assertStringContainsString('/fr/marche', $xml);
        $this->assertStringContainsString('/fr/tarifs', $xml);
        $this->assertStringNotContainsString('/fr/prix', $xml);
        $this->assertStringNotContainsString('/fr/medias-francais', $xml);
        $this->assertStringNotContainsString('/fr/guest-post-france', $xml);
    }

    public function test_language_switcher_on_french_money_page(): void
    {
        $request = Request::create('/fr/acheter-guest-post', 'GET');
        $this->assertSame(url('/fr/acheter-guest-post'), PublicI18n::switchUrl($request, 'fr'));
        $this->assertSame(url('/it'), PublicI18n::switchUrl($request, 'it'));

        $shared = Request::create('/fr/digital-pr', 'GET');
        $this->assertSame(url('/it/digital-pr'), PublicI18n::switchUrl($shared, 'it'));
        $this->assertSame(url('/fr/digital-pr'), PublicI18n::switchUrl($shared, 'fr'));
        $this->assertSame(url('/de/digital-pr'), PublicI18n::switchUrl($shared, 'de'));
    }

    public function test_france_english_lander_links_to_french_money_page(): void
    {
        $this->get('/guest-posts-france')
            ->assertOk()
            ->assertSee('/fr/acheter-guest-post', false)
            ->assertSee('Acheter un guest post en français', false);
    }

    public function test_copy_redirects_and_teaser_countries(): void
    {
        $this->assertSame(['fr'], FrenchMoneyLanders::copyRedirectLocales());
        $this->assertSame(['fr'], PublicI18n::catalogTeaserCountries('fr'));
        $this->assertSame(['fr'], PublicI18n::moneyLanderLocales('acheter-guest-post'));
        $this->assertSame('fr', PublicI18n::moneyLanderXDefault('acheter-guest-post'));
        $this->assertSame('fr', PublicI18n::moneyLanderXDefault('netlinking'));
        $digitalPr = PublicI18n::moneyLanderLocales('digital-pr');
        foreach (['it', 'de', 'at', 'ch', 'es', 'pt', 'ro', 'fr', 'nl', 'dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl'] as $code) {
            $this->assertContains($code, $digitalPr, $code);
        }
        $this->assertSame('it', PublicI18n::moneyLanderXDefault('digital-pr'));
    }

    public function test_niche_edits_page_does_not_sell_a_fake_sku(): void
    {
        $html = $this->get('/fr/niche-edits')->assertOk()->getContent();
        $this->assertStringContainsString('SKU', $html);
        $this->assertStringContainsString('/fr/acheter-guest-post', $html);
    }

    public function test_guide_is_one_page_not_thin_children(): void
    {
        $this->get('/fr/guide')->assertOk();
        $this->get('/fr/guide/comment-acheter-des-backlinks')->assertNotFound();
        $this->get('/fr/guide/quest-ce-que-le-netlinking')->assertNotFound();
    }
}
