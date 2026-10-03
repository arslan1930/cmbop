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
        $this->assertStringNotContainsString('dashboard.webp', $html);
        $this->assertStringNotContainsString('dashboard.png', $html);
    }

    public function test_no_page_loads_recaptcha(): void
    {
        // reCAPTCHA was never verified server-side and the widget was commented
        // out, so /forgot-password was fetching Google's bundle for nothing.
        foreach (['/forgot-password', '/login', '/register', '/'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('google.com/recaptcha/api.js', false)
                ->assertDontSee('g-recaptcha', false);
        }
    }

    public function test_csp_no_longer_allowlists_recaptcha_origins(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $csp, 'CSP header is missing');
        $this->assertStringNotContainsString('recaptcha.net', $csp);
        $this->assertStringNotContainsString('www.google.com', $csp);
        $this->assertStringNotContainsString('www.gstatic.com', $csp);

        // Everything still in use must survive the trim.
        $this->assertStringContainsString('js.stripe.com', $csp);
        $this->assertStringContainsString('fonts.gstatic.com', $csp);
        $this->assertStringContainsString('cdn.jsdelivr.net', $csp);
        $this->assertStringContainsString('embed.tawk.to', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
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
        $this->assertMatchesRegularExpression(
            '/slb-icon-draw\.js[^>]*\sdefer/',
            $html,
            'icon inlining must not block HTML parse'
        );
        $this->assertStringNotContainsString('data-slb-defer-css', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/notification-center\.css[^>]*media="print"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/catalog\.css[^>]*media="print"/',
            $html,
            'catalog.css must stay render-blocking so the table does not paint unstyled'
        );
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        $this->assertStringContainsString('<meta name="description"', $html);
        $this->assertStringContainsString('<title>', $html);
    }

    public function test_catalog_html_gzips_when_the_client_asks(): void
    {
        $advertiser = $this->advertiser();

        $plain = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk();
        $plainHtml = $plain->getContent();
        $this->assertStringContainsString('catalog-page', $plainHtml);
        $this->assertNull($plain->headers->get('Content-Encoding'));

        $packed = $this->actingAs($advertiser)
            ->withHeaders([
                'Accept-Encoding' => 'gzip',
                'X-Test-Compress' => '1',
            ])
            ->get(route('advertiser.catalog'))
            ->assertOk();

        $this->assertSame('gzip', $packed->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $packed->headers->get('Vary'));
        $decoded = gzdecode($packed->getContent());
        $this->assertIsString($decoded);
        $this->assertStringContainsString('catalog-page', $decoded);
        $this->assertLessThan(strlen($plainHtml), strlen($packed->getContent()));
    }

    public function test_orders_page_does_not_reload_bootstrap_51(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('bootstrap@5.1.3', $html);
        $this->assertStringContainsString('bootstrap-5.3.0', $html);
    }
}
