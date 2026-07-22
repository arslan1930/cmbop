<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceUiWeightTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_homepage_does_not_load_global_recaptcha(): void
    {
        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('google.com/recaptcha/api.js', $html);
        $this->assertStringContainsString('rel="preconnect"', $html);
        $this->assertStringContainsString('dashboard.webp', $html);
        $this->assertStringContainsString('width="1200"', $html);
        $this->assertStringContainsString('height="518"', $html);
    }

    public function test_forgot_password_still_loads_recaptcha(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('google.com/recaptcha/api.js', false)
            ->assertSee('g-recaptcha', false);
    }

    public function test_catalog_uses_external_assets_and_deferred_previews(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/css/catalog.css', $html);
        $this->assertStringContainsString('/js/catalog.js', $html);
        $this->assertStringContainsString('window.CatalogConfig', $html);
        $this->assertStringContainsString('pulse-badge.js', $html);
        $this->assertMatchesRegularExpression('/pulse-badge\.js[^>]*>/', $html);
        // defer attribute on pulse-badge
        $this->assertStringContainsString('pulse-badge.js', $html);
        $this->assertTrue(
            str_contains($html, 'defer') && str_contains($html, 'pulse-badge.js'),
            'pulse-badge.js should be deferred'
        );
    }

    public function test_orders_page_does_not_reload_bootstrap_51(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('bootstrap@5.1.3', $html);
        $this->assertStringContainsString('bootstrap@5.3.0', $html);
    }
}
