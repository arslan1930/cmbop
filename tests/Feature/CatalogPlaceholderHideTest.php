<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\CatalogPlaceholderListing;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPlaceholderHideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        config(['catalog.hide_placeholders' => true]);
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
        $domain = $overrides['domain'] ?? ('hide-'.uniqid('', true).'.test');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Hide Placeholder Site',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'example_url' => 'https://'.$domain.'/sample',
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

    public function test_default_catalog_hides_placeholder_rows(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/guest',
            'site_name' => 'Demo Eighty Six',
        ]);
        $this->makeSite($publisher, [
            'domain' => 'real-hide.test',
            'site_url' => 'https://real-hide.test',
            'site_name' => 'Real Hide Listing',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Real Hide Listing', false)
            ->assertDontSee('Demo Eighty Six', false)
            ->assertDontSee('demo86.com', false);
    }

    public function test_host_search_and_site_id_still_show_placeholder(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $placeholder = $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/guest',
            'site_name' => 'Demo Eighty Six',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['search' => 'demo86.com']))
            ->assertOk()
            ->assertSee('Demo Eighty Six', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', ['site' => $placeholder->id]))
            ->assertOk()
            ->assertSee('Demo Eighty Six', false);
    }

    public function test_add_to_cart_refuses_hidden_placeholder(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $placeholder = $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/guest',
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.cart.add'), ['id' => $placeholder->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', CatalogPlaceholderListing::CART_BLOCK_REASON);
    }

    public function test_null_example_url_is_not_treated_as_placeholder(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'domain' => 'null-example.test',
            'site_url' => 'https://null-example.test',
            'example_url' => null,
            'site_name' => 'Null Example Url',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Null Example Url', false);
    }

    public function test_flag_off_keeps_placeholder_in_browse(): void
    {
        config(['catalog.hide_placeholders' => false]);
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/guest',
            'site_name' => 'Demo Eighty Six',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertSee('Demo Eighty Six', false);
    }
}
