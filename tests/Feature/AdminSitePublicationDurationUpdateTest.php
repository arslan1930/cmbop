<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\PublicationDuration;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSitePublicationDurationUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);
    }

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Duration Site',
            'site_url' => 'https://duration-site.example',
            'domain' => 'duration-site.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'News',
            'price' => 80,
            'publication_time' => PublicationDuration::PERMANENT,
            'link_type' => 'dofollow',
            'description' => str_repeat('Publication duration listing description. ', 3),
            'verified' => true,
            'active' => false,
        ], $overrides));
    }

    public function test_admin_can_update_and_activate_site_with_publication_time(): void
    {
        $site = $this->site(['example_url' => 'https://duration-site.example/sample']);

        $this->actingAs($this->admin)
            ->putJson(route('admin.sites.update', $site->id), [
                'site_name' => 'Duration Site',
                'site_url' => 'https://duration-site.example',
                'example_url' => 'https://duration-site.example/sample',
                'da' => 40,
                'dr' => 42,
                'traffic' => 15000,
                'country' => 'de',
                'language' => 'de',
                'price' => 80,
                'publication_time' => PublicationDuration::TWO_YEARS,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(PublicationDuration::TWO_YEARS, $site->fresh()->publication_time);

        $this->actingAs($this->admin)
            ->postJson(route('admin.sites.active', $site->id), ['active' => 1])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('active', true);

        $fresh = $site->fresh();
        $this->assertTrue((bool) $fresh->active);
        $this->assertSame(PublicationDuration::TWO_YEARS, $fresh->publication_time);
    }
}
