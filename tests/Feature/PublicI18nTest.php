<?php

namespace Tests\Feature;

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

    public function test_locale_login_redirects_to_english_auth(): void
    {
        $this->get('/de/login')
            ->assertRedirect('/login');

        $this->get('/fr/register')
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
        foreach (['', '/de', '/fr', '/nl'] as $prefix) {
            foreach (['/pricing', '/marketplace', '/faq', '/about', '/cookie-policy', '/refund-policy'] as $path) {
                $this->get($prefix.$path)->assertOk();
            }
        }
    }

    public function test_locale_sitemaps_are_available(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('sitemap-de.xml', false);

        $this->get('/sitemap-de.xml')
            ->assertOk()
            ->assertSee('/de/marketplace', false)
            ->assertSee('hreflang="fr"', false);
    }

    public function test_browser_language_suggestion_banner_appears_on_english_home(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->get('/')
            ->assertOk()
            ->assertSee('localeSuggestBanner', false)
            ->assertSee('Deutsch', false);
    }

    public function test_about_and_contact_titles_do_not_collide(): void
    {
        $this->get('/de/about')
            ->assertOk()
            ->assertSee('Gebaut für moderne Linkbuilding-Teams', false);

        $this->get('/de/contact')
            ->assertOk()
            ->assertSee('Über SEOLinkBuildings', false)
            ->assertDontSee('Gebaut für moderne Linkbuilding-Teams', false);
    }
}
