<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageFirstImpressionTest extends TestCase
{
    public function test_guest_homepage_is_marketplace_first_without_raw_keys_or_auth_catalog(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('messages.pricing_card_1_title', $html);
        $this->assertStringNotContainsString('advertiser/catalog', $html);
        $this->assertStringNotContainsString('Free SEO Audit', $html);
        $this->assertStringNotContainsString('background-color: #5bc4c7', $html);
        $this->assertStringNotContainsString('background-color: #4ECDCB', $html);
        $this->assertStringNotContainsString('#667eea', $html);
        $this->assertStringNotContainsString('#764ba2', $html);
        $this->assertStringNotContainsString('rgba(78, 205, 203', $html);
        $this->assertStringContainsString('--grad-hero', file_get_contents(public_path('assets/css/brand-colors.css')));
        $this->assertStringContainsString('var(--grad-hero', file_get_contents(resource_path('views/components/hero.blade.php')));

        $response->assertSee('Verified publisher catalog', false);
        $response->assertSee('Wallet checkout', false);
        $response->assertSee('Track to live URL', false);
        $response->assertSee('Starter Package', false);
        $response->assertSee('Talk to sales', false);
        $response->assertSee('Create free account', false);
        $response->assertSee('slb-bottom-cta', false);
        $response->assertSee('btn btn-primary', false);

        // Hero uses the static multi-country catalog artwork (no live inventory table).
        $this->assertStringContainsString('dashboard.png', $html);
        $this->assertStringNotContainsString('Marketplace catalog', $html);
        $this->assertStringNotContainsString('Live publisher catalog', $html);
    }

    public function test_managed_package_ctas_point_to_contact(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Package CTAs should be contact, not register (self-serve still has register).
        $this->assertMatchesRegularExpression(
            '/pricing-packages[\s\S]*href="[^"]*contact[^"]*"[\\s\\S]*Talk to sales/i',
            $html
        );
        $this->assertStringNotContainsString('Start with marketplace', $html);
    }

    public function test_login_has_no_fabricated_zero_metrics(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('0+', $html);
        $this->assertStringNotContainsString('0/5', $html);
        $this->assertStringNotContainsString('Links Delivered', $html);
        $this->assertStringNotContainsString('Clients Rating', $html);
    }
}
