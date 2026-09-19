<?php

namespace Tests\Feature;

use App\Support\CountryHost;
use App\Support\CountryLander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountryHostRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_cc_tlds_permanent_redirect_to_apex_locale(): void
    {
        $this->get('https://seolinkbuildings.ch/')
            ->assertRedirect('https://seolinkbuildings.com/ch');
        $this->assertSame(301, $this->get('https://seolinkbuildings.ch/')->status());

        $this->get('https://seolinkbuildings.at/about')
            ->assertRedirect('https://seolinkbuildings.com/at/ueber-uns');

        $this->get('https://www.seolinkbuildings.pl/')
            ->assertRedirect('https://seolinkbuildings.com/pl');

        $this->get('https://seolinkbuildings.pl/about')
            ->assertRedirect('https://seolinkbuildings.com/pl/o-nas');

        $this->get('https://seolinkbuildings.se/login')
            ->assertRedirect('https://seolinkbuildings.com/login');

        $this->get('https://seolinkbuildings.ch/guest-posts-switzerland')
            ->assertRedirect('https://seolinkbuildings.com/guest-posts-switzerland');

        $this->get('https://seolinkbuildings.co.uk/about')
            ->assertRedirect('https://seolinkbuildings.com/about');
    }

    public function test_apex_com_is_not_redirected_to_itself(): void
    {
        $this->get('https://seolinkbuildings.com/')
            ->assertOk();
        $this->get('https://seolinkbuildings.com/ch')
            ->assertOk();
    }

    public function test_locale_for_host_ignores_the_primary_com_domain(): void
    {
        $this->assertNull(CountryHost::localeForHost('seolinkbuildings.com'));
        $this->assertNull(CountryHost::localeForHost('www.seolinkbuildings.com'));
        $this->assertSame('ch', CountryHost::localeForHost('seolinkbuildings.ch'));
        $this->assertSame('ch', CountryHost::localeForHost('www.seolinkbuildings.ch'));
        $this->assertSame('pl', CountryHost::localeForHost('seolinkbuildings.pl'));
        $this->assertSame('en', CountryHost::localeForHost('seolinkbuildings.co.uk'));
    }

    public function test_english_sitemap_includes_poland_lander_not_country_hosts(): void
    {
        $this->assertContains('guest-posts-poland', CountryLander::slugs());

        $sitemap = $this->get('/sitemap-en.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/guest-posts-poland', $sitemap);
        $this->assertStringNotContainsString('seolinkbuildings.pl', $sitemap);
        $this->assertStringNotContainsString('seolinkbuildings.ch', $sitemap);
    }
}
