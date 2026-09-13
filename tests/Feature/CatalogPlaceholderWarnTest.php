<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\CatalogPlaceholderListing;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogPlaceholderWarnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
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
        $domain = $overrides['domain'] ?? ('warn-'.uniqid('', true).'.test');

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Placeholder Warn Site',
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
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ], $overrides));
    }

    public function test_catalog_details_warn_on_placeholder_listing(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/guest',
            'description' => 'Lorem Ipsum is simply dummy text for testing purposes.',
        ]);
        $this->makeSite($publisher, [
            'domain' => 'real-warn.test',
            'site_url' => 'https://real-warn.test',
        ]);

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(CatalogPlaceholderListing::BUYER_CAPTION, $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'catalog-placeholder-warning'));
    }

    public function test_catalog_details_omit_warning_on_real_listing(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $this->makeSite($publisher, [
            'domain' => 'only-real-warn.test',
            'site_url' => 'https://only-real-warn.test',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee(CatalogPlaceholderListing::BUYER_CAPTION, false);
    }

    public function test_activate_is_blocked_for_placeholder_listing(): void
    {
        $admin = $this->userWithRole('admin');
        $publisher = $this->userWithRole('publisher');
        $site = $this->makeSite($publisher, [
            'domain' => 'demo86.com',
            'site_url' => 'https://demo86.com/guest',
            'description' => 'Lorem Ipsum is simply dummy text for testing purposes.',
            'active' => false,
            'verified' => true,
        ]);

        $this->assertSame(
            CatalogPlaceholderListing::ACTIVATE_BLOCK_REASON,
            $site->staffGoLiveBlockReason()
        );

        $this->actingAs($admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', CatalogPlaceholderListing::ACTIVATE_BLOCK_REASON);

        $this->assertFalse((bool) $site->fresh()->active);
    }
}
