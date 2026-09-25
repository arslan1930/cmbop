<?php

namespace Tests\Feature;

use App\Support\CountryLander;
use App\Support\PublicI18n;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolishLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_polish_home_switcher_and_hreflang(): void
    {
        $html = $this->get('/pl')->assertOk()->getContent();

        $this->assertStringContainsString('lang="pl-PL"', $html);
        $this->assertStringContainsString('hreflang="pl-PL"', $html);
        $this->assertStringContainsString('Polski', $html);
        $this->assertStringContainsString('Rynek wpisów gościnnych', $html);
        $this->assertStringContainsString('navbar-lang-menu', $html);
        $this->assertStringContainsString('max-height: min(70vh, 28rem)', $html);

        $this->get('/pl/o-nas')->assertOk();
        $this->get('/pl/about')->assertRedirect('/pl/o-nas');

        $locales = get_available_locales();
        $this->assertArrayHasKey('pl', $locales);
        $this->assertArrayHasKey('ch', $locales);
        $this->assertArrayHasKey('ro', $locales);
        $this->assertArrayHasKey('hu', $locales);
        $this->assertSame('pl-PL', PublicI18n::hreflang('pl'));
        $this->assertSame(['pl'], PublicI18n::catalogTeaserCountries('pl'));
    }

    public function test_poland_lander_stays_english_only_on_dot_com(): void
    {
        $this->assertContains('guest-posts-poland', CountryLander::slugs());

        $this->get('/guest-posts-poland')
            ->assertOk()
            ->assertSee('Guest posts in Poland', false)
            ->assertSee('hreflang="en-GB"', false)
            ->assertDontSee('advertiser/catalog', false);

        $this->get('/pl/guest-posts-poland')
            ->assertRedirect('/guest-posts-poland');
    }
}
