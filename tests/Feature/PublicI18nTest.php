<?php

namespace Tests\Feature;

use App\Support\LocalizedPublicPath;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_austria_and_switzerland_reuse_german_copy(): void
    {
        foreach (['/at', '/ch'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('Der Publisher-Marktplatz für Gastbeiträge, Backlinks und Linkbuilding.', $html, $path);
            $this->assertStringContainsString('Marktplatz', $html, $path);
            $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $html, $path);
        }

        $this->get('/at')->assertSee('lang="de-AT"', false);
        $this->get('/ch')->assertSee('lang="de-CH"', false);
        $this->get('/ro')
            ->assertOk()
            ->assertSee('Cumpără guest posturi de la publisheri verificați', false)
            ->assertSee('lang="ro"', false);
    }

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

        $this->assertStringContainsString('Publisher-Marktplatz für Gastbeiträge | SEOLinkBuildings', $html);
        $this->assertStringContainsString('Der Publisher-Marktplatz für Gastbeiträge, Backlinks und Linkbuilding.', $html);
        $this->assertStringNotContainsString('Guest-Post-Marktplatz für SEO-Backlinks', $html);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $html);
    }

    public function test_locale_homes_target_native_buy_guest_post_queries(): void
    {
        $pages = [
            '/de' => [
                'Publisher-Marktplatz für Gastbeiträge | SEOLinkBuildings',
                'Der Publisher-Marktplatz für Gastbeiträge, Backlinks und Linkbuilding.',
            ],
            '/fr' => [
                'Acheter des guest posts chez des éditeurs vérifiés | SEOLinkBuildings',
                'Achetez des guest posts sur des sites d’éditeurs vérifiés.',
            ],
            '/it' => [
                'Marketplace guest post e link building | SEOLinkBuildings',
                'Il marketplace di guest post e link building per l’Italia.',
            ],
            '/es' => [
                'Comprar guest posts de editores verificados | SEOLinkBuildings',
                'Compre guest posts en sitios de editores verificados.',
            ],
            '/nl' => [
                'Guest posts kopen bij geverifieerde publishers | SEOLinkBuildings',
                'Guest posts kopen bij geverifieerde publishers.',
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

        $this->assertStringContainsString('Ihre Website mit Gastbeiträgen vermarkten | Publisher werden', $html);
        $this->assertStringContainsString('Ihre Website mit Gastbeiträgen vermarkten', $html);
        $this->assertStringNotContainsString('Guest Posts verkaufen und verdienen', $html);
        $this->assertStringNotContainsString('Monetarisieren Sie Ihr redaktionelles Inventar', $html);
    }

    public function test_english_publisher_page_targets_become_a_publisher_guest_posts(): void
    {
        $html = $this->get('/become-a-publisher')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Become a Publisher and Sell Guest Posts | SEOLinkBuildings', $html);
        $this->assertStringContainsString('List your site and sell guest posts', $html);
        $this->assertStringNotContainsString('Become a publisher for guest posts', $html);
        $this->assertStringNotContainsString('Sell Guest Posts and Earn', $html);
        $this->assertStringNotContainsString('Monetize your editorial inventory', $html);
    }

    public function test_locale_publisher_pages_use_native_titles_not_thin_calques(): void
    {
        $pages = [
            LocalizedPublicPath::publicPath('become-a-publisher', 'fr') => [
                'Devenir éditeur et vendre des guest posts | SEOLinkBuildings',
                'Proposez votre site et vendez des guest posts',
            ],
            LocalizedPublicPath::publicPath('become-a-publisher', 'it') => [
                'Diventare publisher e vendere guest post | SEOLinkBuildings',
                'Mettete il vostro sito e vendete guest post',
            ],
            LocalizedPublicPath::publicPath('become-a-publisher', 'es') => [
                'Hágase editor y venda guest posts | SEOLinkBuildings',
                'Anuncie su sitio y venda guest posts',
            ],
            LocalizedPublicPath::publicPath('become-a-publisher', 'nl') => [
                'Publisher worden en guestposts verkopen | SEOLinkBuildings',
                'Plaats uw site en verkoop guestposts',
            ],
        ];

        foreach ($pages as $path => [$title, $h1]) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString($title, $html, $path.' title');
            $this->assertStringContainsString($h1, $html, $path.' h1');
            $this->assertStringNotContainsString('Monétisez votre inventaire éditorial', $html, $path);
            $this->assertStringNotContainsString('Monetizza il tuo inventario editoriale', $html, $path);
            $this->assertStringNotContainsString('Monetice su inventario editorial', $html, $path);
            $this->assertStringNotContainsString('Monetiseer uw redactionele inventaris', $html, $path);
            $this->assertStringContainsString('hreflang="de"', $html, $path);
            $this->assertStringContainsString('hreflang="fr"', $html, $path);
            $this->assertStringContainsString(url('/register'), $html, $path);
        }
    }

    public function test_locale_homes_keep_hreflang_without_thin_lander_copies(): void
    {
        foreach (['/de', '/fr', '/it', '/es', '/nl'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('hreflang="de"', $html, $path);
            $this->assertStringContainsString('hreflang="it"', $html, $path);
            $this->assertStringContainsString('hreflang="es"', $html, $path);
            $this->assertStringContainsString('hreflang="fr"', $html, $path);
            $this->assertStringContainsString('hreflang="nl"', $html, $path);
            $this->assertStringContainsString(url('/register'), $html, $path);
            $this->assertStringNotContainsString('/de/guest-posts-germany', $html, $path);
        }
    }

    public function test_english_pricing_page_targets_digital_pr_marketplace(): void
    {
        $html = $this->get('/pricing')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Digital PR Marketplace | Guest Posts and Packages | SEOLinkBuildings', $html);
        $this->assertStringContainsString('The digital PR marketplace for guest posts and packages', $html);
        $this->assertStringNotContainsString('Marketplace placements and Digital PR packages', $html);
        $this->assertStringNotContainsString('Guest Post and Digital PR Pricing', $html);
        $this->assertStringNotContainsString('Transparent pricing for every campaign', $html);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $html);
        $this->assertStringNotContainsString('slb-section-kicker', $html);
        $this->assertStringContainsString('marketing-kicker', $html);

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Marketplace placements and Digital PR packages', $home);
        $this->assertStringContainsString('The guest post marketplace for verified publisher sites.', $home);
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

        $this->get('/at/login')
            ->assertRedirect('/login');

        $this->get('/ro/register')
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
        $this->get('/at/about')->assertRedirect('/at/ueber-uns');
        $this->get('/ch/marketplace')->assertRedirect('/ch/marktplatz');
        $this->get('/ro/about')->assertRedirect('/ro/despre-noi');
        $this->get('/se/pricing')->assertRedirect('/se/priser');
        $this->get('/pl/about')->assertRedirect('/pl/o-nas');
        $this->get('/pl/marketplace')->assertRedirect('/pl/rynek');
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
            ->assertSee('sitemap-us.xml', false)
            ->assertSee('sitemap-pl.xml', false);

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
            ->assertSee('Piattaforma self-service: catalogo di editori', false)
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
