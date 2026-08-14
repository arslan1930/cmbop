<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CatalogVisibleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $roleModel->id,
        ]);
        $user->roles()->attach($roleModel->id);

        return $user->fresh();
    }

    private function site(User $publisher, array $attrs = []): Site
    {
        $slug = $attrs['domain'] ?? ('vis-'.uniqid());

        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Visible '.$slug,
            'site_url' => 'https://'.$slug,
            'domain' => $slug,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 150,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Catalog visibility fixture.',
            'verified' => true,
            'active' => true,
        ], $attrs));
    }

    public function test_catalog_lists_only_active_verified_not_archived_sites(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');

        $live = $this->site($publisher, [
            'site_name' => 'Live Catalog Site',
            'domain' => 'live-catalog.example',
            'site_url' => 'https://live-catalog.example',
        ]);
        $this->site($publisher, [
            'site_name' => 'Unverified Catalog Site',
            'domain' => 'unverified-catalog.example',
            'site_url' => 'https://unverified-catalog.example',
            'verified' => false,
        ]);
        $this->site($publisher, [
            'site_name' => 'Inactive Catalog Site',
            'domain' => 'inactive-catalog.example',
            'site_url' => 'https://inactive-catalog.example',
            'active' => false,
        ]);
        $this->site($publisher, [
            'site_name' => 'Archived Catalog Site',
            'domain' => 'archived-catalog.example',
            'site_url' => 'https://archived-catalog.example',
            'active' => false,
            'archived_at' => now(),
        ]);

        $this->assertTrue($live->isCatalogVisible());
        $this->assertSame(1, Site::query()->catalogVisible()->count());

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Live Catalog Site', $html);
        $this->assertStringNotContainsString('Unverified Catalog Site', $html);
        $this->assertStringNotContainsString('Inactive Catalog Site', $html);
        $this->assertStringNotContainsString('Archived Catalog Site', $html);
    }
}
