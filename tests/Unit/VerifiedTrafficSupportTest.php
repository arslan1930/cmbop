<?php

namespace Tests\Unit;

use App\Support\VerifiedTraffic;
use Tests\TestCase;

class VerifiedTrafficSupportTest extends TestCase
{
    public function test_hosts_match_normalises_www_scheme_and_gsc_prefix(): void
    {
        $this->assertTrue(VerifiedTraffic::hostsMatch('example.com', 'https://www.example.com/'));
        $this->assertTrue(VerifiedTraffic::hostsMatch('www.example.com', 'sc-domain:example.com'));
        $this->assertFalse(VerifiedTraffic::hostsMatch('example.com', 'other.example.com'));
        $this->assertFalse(VerifiedTraffic::hostsMatch('example.com', ''));
    }

    public function test_oauth_configured_ignores_empty_and_placeholder_clients(): void
    {
        config(['services.google_traffic.client_id' => '', 'services.google_traffic.client_secret' => '']);
        $this->assertFalse(VerifiedTraffic::oauthConfigured());

        config([
            'services.google_traffic.client_id' => 'your-id',
            'services.google_traffic.client_secret' => 'secret-value',
        ]);
        $this->assertFalse(VerifiedTraffic::oauthConfigured());

        config([
            'services.google_traffic.client_id' => '123.apps.googleusercontent.com',
            'services.google_traffic.client_secret' => 'real-secret',
        ]);
        $this->assertTrue(VerifiedTraffic::oauthConfigured());
    }
}
