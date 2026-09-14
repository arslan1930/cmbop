<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherDashboardChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_publisher_layout_yields_title_and_loads_dashboard_css_before_hover(): void
    {
        $layout = (string) file_get_contents(resource_path('views/publisher/layouts/app.blade.php'));

        $this->assertStringContainsString("@yield('title', 'Publisher')", $layout);
        $this->assertStringContainsString('publisher-dashboard.css', $layout);
        $this->assertStringNotContainsString('<title>Publisher Dashboard</title>', $layout);

        preg_match_all('/<link[^>]+assets\/css\/([a-z-]+)\.css/', $layout, $matches);
        $this->assertContains('publisher-dashboard', $matches[1]);
        $this->assertSame('hover-system', end($matches[1]));
    }

    public function test_dashboard_blade_has_no_inline_style_and_pins_chart_js(): void
    {
        $blade = (string) file_get_contents(resource_path('views/publisher/dashboard.blade.php'));
        $css = (string) file_get_contents(public_path('assets/css/publisher-dashboard.css'));

        $this->assertStringNotContainsString('<style>', $blade);
        $this->assertStringContainsString("@section('title', 'Dashboard')", $blade);
        $this->assertStringContainsString('chart.js@4.4.6', $blade);
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/chart.js"', $blade);
        $this->assertStringContainsString('No orders yet', $blade);
        $this->assertStringContainsString('No paid orders yet', $blade);
        $this->assertStringContainsString('We could not refresh every number', $blade);
        $this->assertStringContainsString('clawbacks appear on the reversal day', $blade);
        $this->assertStringContainsString('listed — add another niche or market.', $blade);
        $this->assertStringContainsString('<h2 class="mb-1 fw-semibold">Dashboard</h2>', $blade);
        $this->assertStringContainsString('Welcome back, {{ $publisherName }}', $blade);
        $this->assertStringNotContainsString('sites live', $blade);
        $this->assertStringNotContainsString('Open Rate', $blade);
        $this->assertStringNotContainsString('style="color:#0b6266', $blade);
        $this->assertStringContainsString('.publisher-primary-cta', $css);
        $this->assertStringContainsString('.publisher-chart-empty', $css);
        $this->assertStringContainsString('.publisher-cta-eyebrow', $css);
        $this->assertStringContainsString('.publisher-dashboard-failed', $css);
    }

    public function test_tasks_and_dashboard_use_distinct_document_titles(): void
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->actingAs($user)
            ->get(route('publisher.dashboard'))
            ->assertOk()
            ->assertSee('<title>Dashboard — SEOLinkBuildings</title>', false);

        $this->actingAs($user)
            ->get(route('publisher.tasks'))
            ->assertOk()
            ->assertSee('<title>My Tasks — SEOLinkBuildings</title>', false)
            ->assertDontSee('<title>Publisher Dashboard</title>', false);
    }
}
