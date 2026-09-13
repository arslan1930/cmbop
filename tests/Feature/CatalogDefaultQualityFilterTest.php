<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogDefaultQualityFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config(['catalog.default_quality' => true]);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        $domain = $overrides['domain'] ?? ('quality-default-'.uniqid('', true).'.test');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Quality Default Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Guest posts on a German finance magazine for founders.',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    public function test_quality_is_url_allowlisted(): void
    {
        $this->assertContains('quality', CatalogUrlQuery::KEYS);
    }

    public function test_shipped_quality_default_stays_off(): void
    {
        $this->assertStringContainsString(
            "env('CATALOG_DEFAULT_QUALITY', false)",
            (string) file_get_contents(config_path('catalog.php'))
        );
    }

    public function test_default_browse_hides_below_bar_when_flag_on(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'site_name' => 'Strong Quality Row',
            'domain' => 'strong-quality.test',
            'da' => 40,
            'dr' => 50,
            'traffic' => 15000,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Weak Quality Row',
            'domain' => 'weak-quality.test',
            'da' => 10,
            'dr' => 10,
            'traffic' => 500,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Strong Quality Row', false)
            ->assertDontSee('Weak Quality Row', false)
            ->assertSee('Quality bar (DA/DR/traffic)', false);
    }

    public function test_quality_zero_shows_below_bar_rows(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'site_name' => 'Weak After Clear',
            'domain' => 'weak-clear.test',
            'da' => 5,
            'dr' => 5,
            'traffic' => 100,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['quality' => 0]))
            ->assertOk()
            ->assertSee('Weak After Clear', false);
    }

    public function test_flag_off_keeps_below_bar_in_default_browse(): void
    {
        config(['catalog.default_quality' => false]);
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'site_name' => 'Weak When Flag Off',
            'domain' => 'weak-flag-off.test',
            'da' => 5,
            'dr' => 5,
            'traffic' => 100,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Weak When Flag Off', false);
    }
}
