<?php

namespace Tests\Feature;

use Tests\TestCase;

class VerifiedTrafficPreviewTest extends TestCase
{
    public function test_preview_hub_renders_in_testing(): void
    {
        $this->get('/preview/verified-traffic')
            ->assertOk()
            ->assertSee('Verified traffic (draft)')
            ->assertSee('DRAFT PREVIEW')
            ->assertSee('demo data only');
    }

    public function test_publisher_connect_picker_and_connected_states(): void
    {
        $this->get('/preview/verified-traffic/publisher?state=connect')
            ->assertOk()
            ->assertSee('Connect Google Analytics / Search Console')
            ->assertSee('analytics.readonly');

        $this->get('/preview/verified-traffic/publisher?state=picker')
            ->assertOk()
            ->assertSee('Choose properties')
            ->assertSee('sc-domain:healthinsights.example')
            ->assertSee('Hostname does not match');

        $this->get('/preview/verified-traffic/publisher?state=connected')
            ->assertOk()
            ->assertSee('Verified traffic is live')
            ->assertSee('Last sync')
            ->assertSee('maria@publisher.example');
    }

    public function test_catalog_shows_verified_badge_and_manual_row_without_publisher_price(): void
    {
        $html = $this->get('/preview/verified-traffic/catalog')
            ->assertOk()
            ->assertSee('Verified traffic')
            ->assertSee('via GA4')
            ->assertSee('Health Insights')
            ->assertSee('Local Notes')
            ->assertSee('Manual')
            ->assertSee('€89')
            ->assertDontSee('markup')
            ->getContent();

        $this->assertStringNotContainsString('maria@publisher.example', $html);
    }

    public function test_catalog_detail_has_badge_but_no_last_sync(): void
    {
        $html = $this->get('/preview/verified-traffic/catalog/detail')
            ->assertOk()
            ->assertSee('Verified traffic')
            ->assertSee('via GA4')
            ->getContent();

        $this->assertStringNotContainsString('Last sync', $html);
        $this->assertStringNotContainsString('maria@publisher.example', $html);
    }

    public function test_admin_shows_source_last_sync_and_override(): void
    {
        $this->get('/preview/verified-traffic/admin')
            ->assertOk()
            ->assertSee('Verified traffic (Google)')
            ->assertSee('Admin override')
            ->assertSee('Last sync')
            ->assertSee('maria@publisher.example')
            ->assertSee('Manual lock skips API providers');
    }

    public function test_preview_is_404_in_production_without_flag(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production', 'app.verified_traffic_preview' => false]);

        $this->get('/preview/verified-traffic')->assertNotFound();
        $this->get('/preview/verified-traffic/publisher')->assertNotFound();
        $this->get('/preview/verified-traffic/catalog')->assertNotFound();
        $this->get('/preview/verified-traffic/admin')->assertNotFound();
    }

    public function test_preview_can_be_enabled_on_staging_via_config(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production', 'app.verified_traffic_preview' => true]);

        $this->get('/preview/verified-traffic')->assertOk();
    }
}
