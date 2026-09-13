<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogUrlQuery;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogDefaultVerifiedFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config(['catalog.default_verified' => true]);
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
        $domain = $overrides['domain'] ?? ('verified-default-'.uniqid('', true).'.test');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Verified Default Site',
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

    public function test_verified_is_url_allowlisted(): void
    {
        $this->assertContains('verified', CatalogUrlQuery::KEYS);
    }

    public function test_default_browse_hides_unverified_when_flag_on(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'site_name' => 'Verified Live Row',
            'domain' => 'verified-live.test',
            'verified' => true,
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Unverified Live Row',
            'domain' => 'unverified-live.test',
            'verified' => false,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Verified Live Row', false)
            ->assertDontSee('Unverified Live Row', false)
            ->assertSee('TXT Verified only', false);
    }

    public function test_verified_zero_shows_unverified_rows(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'site_name' => 'Unverified After Clear',
            'domain' => 'unverified-clear.test',
            'verified' => false,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['verified' => 0]))
            ->assertOk()
            ->assertSee('Unverified After Clear', false);
    }

    public function test_flag_off_keeps_unverified_in_default_browse(): void
    {
        config(['catalog.default_verified' => false]);
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'site_name' => 'Unverified When Flag Off',
            'domain' => 'unverified-flag-off.test',
            'verified' => false,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Unverified When Flag Off', false);
    }
}
