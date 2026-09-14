<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDuplicateDomainReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
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
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Dup Site',
            'site_url' => 'https://dup.example',
            'domain' => 'dup.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 2000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'en',
            'category' => 'Technology',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Duplicate domain fixture.',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_duplicate_domain_report_groups_www_and_bare_host(): void
    {
        $admin = $this->userWithRole('admin');
        $first = $this->userWithRole('publisher');
        $second = $this->userWithRole('publisher');
        $this->makeSite($first, [
            'site_name' => 'Bare Host Listing',
            'site_url' => 'https://twin-domain.example',
            'domain' => 'twin-domain.example',
        ]);
        $this->makeSite($second, [
            'site_name' => 'Www Host Listing',
            'site_url' => 'https://www.twin-domain.example',
            'domain' => 'www.twin-domain.example',
        ]);
        $this->makeSite($first, [
            'site_name' => 'Unique Listing',
            'site_url' => 'https://only-once.example',
            'domain' => 'only-once.example',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.duplicates'))
            ->assertOk()
            ->assertSee('Duplicate domains', false)
            ->assertSee('twin-domain.example', false)
            ->assertSee('Bare Host Listing', false)
            ->assertSee('Www Host Listing', false)
            ->assertDontSee('Unique Listing', false)
            ->assertSee('2 listings', false);
    }
}
