<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_loads(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_contact_page_loads(): void
    {
        $this->get('/contact')->assertOk();
    }

    public function test_privacy_policy_page_loads(): void
    {
        $this->get('/privacy-policy')->assertOk();
    }

    public function test_terms_page_loads(): void
    {
        $this->get('/terms-of-services')->assertOk();
    }

    public function test_health_endpoint_loads(): void
    {
        $this->get('/up')->assertOk();
    }
}
