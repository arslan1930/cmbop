<?php

namespace Tests\Feature;

use App\Support\LocalizedPublicPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyQueryUrlMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_p1_money_queries_use_one_url_each_and_do_not_fight(): void
    {
        $home = $this->get('/')->assertOk()->getContent();
        $market = $this->get('/marketplace')->assertOk()->getContent();
        $publisher = $this->get('/become-a-publisher')->assertOk()->getContent();
        $pricing = $this->get('/pricing')->assertOk()->getContent();
        $germany = $this->get('/guest-posts-germany')->assertOk()->getContent();
        $de = $this->get('/de')->assertOk()->getContent();

        $this->assertStringContainsString('Guest Post Marketplace for SEO Backlinks', $home);
        $this->assertStringContainsString('Earn powerful backlinks from trusted websites.', $home);
        $this->assertStringNotContainsString('Buy guest posts from verified publishers', $home);
        $this->assertStringNotContainsString('Become a Publisher and Sell Guest Posts', $home);

        $this->assertStringContainsString('Browse Publisher Sites and Buy Guest Posts', $market);
        $this->assertStringContainsString('Buy guest posts from verified publishers', $market);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $market);
        $this->assertStringNotContainsString('Guest Post Cost in Germany', $market);

        $this->assertStringContainsString('Become a Publisher and Sell Guest Posts', $publisher);
        $this->assertStringContainsString('List your site and sell guest posts', $publisher);
        $this->assertStringNotContainsString('Guest Post Marketplace for SEO Backlinks', $publisher);

        $this->assertStringContainsString('Digital PR Marketplace | Guest Posts and Packages', $pricing);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $pricing);

        $this->assertStringContainsString('Guest Post Cost in Germany — DACH Publishers, EUR', $germany);
        $this->assertStringContainsString('Guest post cost in Germany', $germany);
        $this->assertStringNotContainsString('Buy guest posts from verified publishers', $germany);

        $this->assertStringContainsString('Gastbeiträge kaufen — Gastbeitrag-Marktplatz', $de);
        $this->assertStringContainsString('Gastbeitrag-Marktplatz für geprüfte Publisher.', $de);
        $this->assertStringNotContainsString('/de/guest-posts-germany', $de);

        $index = $this->get('/guest-post-prices-europe')->assertOk()->getContent();
        $this->assertStringContainsString('Guest Post Prices in Europe — Median by Country', $index);
        $this->assertStringContainsString('EU guest-post price index', $index);
        $this->assertStringNotContainsString('The guest post marketplace for verified publisher sites.', $index);
        $this->assertStringNotContainsString('Buy guest posts from verified publishers', $index);
        $this->assertStringNotContainsString('Guest Post Cost in Germany', $index);
        $this->get(LocalizedPublicPath::publicPath('become-a-publisher', 'de'))
            ->assertOk()
            ->assertSee('Ihre Website mit Gastbeiträgen vermarkten', false);
    }

    public function test_gsc_verification_meta_is_absent_until_configured(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('google-site-verification', false);

        config(['services.google.site_verification' => 'gsc-token-fixture']);

        $this->get('/')
            ->assertOk()
            ->assertSee('name="google-site-verification" content="gsc-token-fixture"', false);
    }
}
