<?php

namespace Tests\Feature;

use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogSiteReportThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function siteFor(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Owned News Daily',
            'site_url' => 'https://owned-news.example',
            'domain' => 'owned-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'A publisher site for catalog report tests',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_advertiser_can_submit_a_catalog_site_report(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.site-report', $site), [
                'message' => 'The traffic number looks inflated compared with the live site.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reported', true);

        $this->assertSame(1, ProblemReport::query()->count());
    }

    public function test_catalog_browsing_does_not_block_a_listing_report(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));

        // Numeric throttle:N,M used to share one per-user key. Ten copy-track
        // hits would then make the 10/min report route return 429.
        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($advertiser)
                ->postJson(route('advertiser.catalog.copy-track'), [
                    'text' => 'https://owned-news.example/post-'.$i,
                    'site_id' => $site->id,
                ])
                ->assertOk();
        }

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results', ['search' => 'owned']))
            ->assertOk();

        // Other numeric throttle:N,M routes still share one per-user key.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($advertiser)
                ->getJson(route('advertiser.website-suggestions.check', [
                    'url' => 'https://fresh-'.$i.'.example',
                ]))
                ->assertOk();
        }

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.catalog.site-report', $site), [
                'message' => 'Metrics on this listing do not match the live homepage.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_catalog_site_report_is_isolated_after_ten_reports(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $site = $this->siteFor($this->userWithRole('publisher'));
        $url = route('advertiser.catalog.site-report', $site);
        $payload = ['message' => 'The listed category does not match the live site.'];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($advertiser)
                ->postJson($url, $payload)
                ->assertOk()
                ->assertJsonPath('success', true);
        }

        $this->actingAs($advertiser)
            ->postJson($url, $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'You sent several reports just now. Wait a minute and try again.'
            );
    }

    public function test_catalog_site_report_routes_use_named_limiter(): void
    {
        foreach (['advertiser.catalog.site-report', 'advertiser.catalog.site-report.delete'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('throttle:catalog-site-report', $route->gatherMiddleware(), $name);
        }
    }
}
