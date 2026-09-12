<?php

namespace Tests\Feature;

use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_german_home_is_localized_with_hreflang(): void
    {
        $this->get('/de')
            ->assertOk()
            ->assertSee('lang="de"', false)
            ->assertSee('hreflang="de"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertSee('Marktplatz', false)
            ->assertSee('Registrieren', false);
    }

    public function test_german_home_targets_gastbeitrag_marketplace(): void
    {
        $html = $this->get('/de')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Gastbeiträge kaufen — Gastbeitrag-Marktplatz | SEOLinkBuildings', $html);
        $this->assertStringContainsString('Gastbeitrag-Marktplatz für geprüfte Publisher-Seiten.', $html);
        $this->assertStringNotContainsString('Guest-Post-Marktplatz für SEO-Backlinks', $html);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $html);
    }

    public function test_locale_homes_target_native_buy_guest_post_queries(): void
    {
        $pages = [
            '/de' => [
                'Gastbeiträge kaufen — Gastbeitrag-Marktplatz | SEOLinkBuildings',
                'Gastbeitrag-Marktplatz für geprüfte Publisher-Seiten.',
            ],
            '/fr' => [
                'Acheter des guest posts chez des éditeurs vérifiés | SEOLinkBuildings',
                'Acheter des guest posts sur des sites d’éditeurs vérifiés.',
            ],
            '/it' => [
                'Comprare guest post da editori verificati | SEOLinkBuildings',
                'Comprare guest post su siti di editori verificati.',
            ],
            '/es' => [
                'Comprar guest posts de editores verificados | SEOLinkBuildings',
                'Comprar guest posts en sitios de editores verificados.',
            ],
            '/nl' => [
                'Guest posts kopen bij gecontroleerde publishers | SEOLinkBuildings',
                'Guest posts kopen op gecontroleerde publisher-sites.',
            ],
        ];

        foreach ($pages as $path => [$title, $h1]) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString($title, $html, $path.' title');
            $this->assertStringContainsString($h1, $html, $path.' h1');
            $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $html, $path);
        }
    }

    public function test_german_publisher_page_targets_website_vermarkten_gastbeitrag(): void
    {
        $html = $this->get(LocalizedPublicPath::publicPath('become-a-publisher', 'de'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Website mit Gastbeiträgen vermarkten | Publisher werden', $html);
        $this->assertStringContainsString('Website mit Gastbeiträgen vermarkten', $html);
        $this->assertStringNotContainsString('Guest Posts verkaufen und verdienen', $html);
        $this->assertStringNotContainsString('Monetarisieren Sie Ihr redaktionelles Inventar', $html);
    }

    public function test_english_publisher_page_targets_become_a_publisher_guest_posts(): void
    {
        $html = $this->get('/become-a-publisher')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Become a Publisher for Guest Posts | SEOLinkBuildings', $html);
        $this->assertStringContainsString('List your site and sell guest posts', $html);
        $this->assertStringNotContainsString('Sell Guest Posts and Earn', $html);
        $this->assertStringNotContainsString('Monetize your editorial inventory', $html);
    }

    public function test_english_pricing_page_targets_digital_pr_marketplace(): void
    {
        $html = $this->get('/pricing')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Digital PR Marketplace Pricing | SEOLinkBuildings', $html);
        $this->assertStringContainsString('Digital PR marketplace pricing', $html);
        $this->assertStringNotContainsString('Guest Post and Digital PR Pricing', $html);
        $this->assertStringNotContainsString('Transparent pricing for every campaign', $html);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $html);
    }

    public function test_locale_login_redirects_to_english_auth(): void
    {
        $this->get('/de/login')
            ->assertRedirect('/login');

        $this->get('/fr/register')
            ->assertRedirect('/register');

        $this->get('/es/login')
            ->assertRedirect('/login');

        $this->get('/us/register')
            ->assertRedirect('/register');
    }

    public function test_english_login_has_no_language_switcher(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('id="languageDropdown"', false)
            ->assertSee('Continue with Google', false);
    }

    public function test_public_marketing_pages_exist_for_each_locale(): void
    {
        foreach (PublicI18n::supported() as $locale) {
            foreach (['pricing', 'marketplace', 'faq', 'about', 'blog', 'cookie-policy', 'refund-policy'] as $page) {
                $this->get(LocalizedPublicPath::publicPath($page, $locale))->assertOk();
            }
        }
    }

    public function test_prefixed_english_page_paths_redirect_to_localized_slugs(): void
    {
        $this->get('/de/about')
            ->assertRedirect('/de/ueber-uns');
        $this->get('/de/marketplace')
            ->assertRedirect('/de/marktplatz');
        $this->get('/fr/how-it-works')
            ->assertRedirect('/fr/comment-ca-marche');
        $this->get('/nl/become-a-publisher')
            ->assertRedirect('/nl/publisher-worden');
        $this->get('/es/pricing')
            ->assertRedirect('/es/precios');
        $this->get('/it/contact')
            ->assertRedirect('/it/contatto');

        $this->get('/us/about')->assertOk();
        $this->get('/de/blog')->assertOk();
    }

    public function test_language_switcher_uses_localized_page_paths(): void
    {
        $this->get(LocalizedPublicPath::publicPath('about', 'de'))
            ->assertOk()
            ->assertSee(url(LocalizedPublicPath::publicPath('about', 'fr')), false)
            ->assertSee(url(LocalizedPublicPath::publicPath('marketplace', 'de')), false)
            ->assertDontSee(url('/de/about'), false)
            ->assertDontSee(url('/de/marketplace'), false);
    }

    public function test_locale_sitemaps_are_available(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('sitemap-de.xml', false)
            ->assertSee('sitemap-es.xml', false)
            ->assertSee('sitemap-it.xml', false)
            ->assertSee('sitemap-us.xml', false);

        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertSee('/de/marktplatz', false)
            ->assertSee('/de/ueber-uns', false)
            ->assertSee('/fr/a-propos', false)
            ->assertSee('hreflang="fr"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="en-US"', false);

        $this->get('/sitemap-us.xml')
            ->assertOk()
            ->assertSee('/us/marketplace', false);
    }

    public function test_browser_language_suggestion_banner_appears_on_english_home(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->get('/')
            ->assertOk()
            ->assertSee('localeSuggestBanner', false)
            ->assertSee('Deutsch', false);
    }

    public function test_uk_english_home_uses_en_gb_tags_and_uk_label(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('lang="en-GB"', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertSee('hreflang="en-US"', false)
            ->assertSee('hreflang="es"', false)
            ->assertSee('hreflang="it"', false)
            ->assertSee('og:locale" content="en_GB"', false)
            ->assertSee('English (UK)', false)
            ->assertSee('English (US)', false)
            ->assertSee('Español', false)
            ->assertSee('Italiano', false);
    }

    public function test_us_spanish_and_italian_homes_are_routed(): void
    {
        $this->get('/us')
            ->assertOk()
            ->assertSee('lang="en-US"', false)
            ->assertSee('og:locale" content="en_US"', false)
            ->assertSee('English (US)', false);

        $this->get('/es')
            ->assertOk()
            ->assertSee('lang="es"', false)
            ->assertSee('Español', false);

        $this->get('/it')
            ->assertOk()
            ->assertSee('lang="it"', false)
            ->assertSee('Italiano', false);
    }

    public function test_spanish_and_italian_homes_use_translated_copy(): void
    {
        $this->get('/es')
            ->assertOk()
            ->assertSee('Iniciar sesión', false)
            ->assertSee('Registrarse', false)
            ->assertSee('El marketplace global de link building', false)
            ->assertSee('Cómo funciona', false);

        $this->get('/it')
            ->assertOk()
            ->assertSee('Accedi', false)
            ->assertSee('Registrati', false)
            ->assertSee('Il marketplace globale di link building', false)
            ->assertSee('Come funziona', false);
    }

    public function test_us_and_uk_schema_use_bcp47_language_tags(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('"inLanguage":"en-GB"', false);

        $this->get('/us/about')
            ->assertOk()
            ->assertSee('"inLanguage":"en-US"', false)
            ->assertDontSee('"inLanguage":"us"', false);
    }

    public function test_us_english_browser_language_suggests_us_locale(): void
    {
        $this->withHeader('Accept-Language', 'en-US,en;q=0.8')
            ->get('/')
            ->assertOk()
            ->assertSee('localeSuggestBanner', false)
            ->assertSee('It looks like you prefer English (US)', false);
    }

    public function test_about_and_contact_titles_do_not_collide(): void
    {
        $this->get(LocalizedPublicPath::publicPath('about', 'de'))
            ->assertOk()
            ->assertSee('Der Guest-Post-Marktplatz für Europa', false);

        $this->get(LocalizedPublicPath::publicPath('contact', 'de'))
            ->assertOk()
            ->assertSee('Über SEOLinkBuildings', false)
            ->assertDontSee('Der Guest-Post-Marktplatz für Europa', false);
    }
}
