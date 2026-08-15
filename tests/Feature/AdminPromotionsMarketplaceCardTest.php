<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminPromotionsMarketplaceCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_lists_featured_and_on_discount_sites(): void
    {
        $this->seed(RolesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $featured = $this->makeSite($publisher, 'Featured Promo Site');
        if (Schema::hasColumn('sites', 'featured_until')) {
            $featured->update(['featured_until' => now()->addDays(3)]);
        }

        $sale = $this->makeSite($publisher, 'On Sale Promo Site');
        if (Schema::hasColumn('sites', 'custom_discount_percent')) {
            $sale->update([
                'custom_discount_percent' => 15,
                'custom_discount_starts_at' => now()->subDay(),
                'custom_discount_ends_at' => now()->addDays(5),
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertSee('Marketplace promotions', false)
            ->assertSee('Featured Promo Site', false)
            ->assertSee('On Sale Promo Site', false);
    }

    public function test_hub_hides_featured_sites_that_are_not_in_the_catalog(): void
    {
        $this->seed(RolesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $publisher->roles()->attach($publisherRole->id);

        $hidden = $this->makeSite($publisher, 'Inactive Featured Site');
        $payload = ['active' => false];
        if (Schema::hasColumn('sites', 'featured_until')) {
            $payload['featured_until'] = now()->addDays(3);
        }
        $hidden->update($payload);

        $this->actingAs($admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertDontSee('Inactive Featured Site', false);
    }

    public function test_hub_ok_when_promo_columns_missing(): void
    {
        $this->seed(RolesTableSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->actingAs($admin)
            ->get(route('admin.promotions.index'))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    private function makeSite(User $publisher, string $name): Site
    {
        $slug = strtolower(str_replace(' ', '-', $name));

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'Marketplace promo test site',
            'link_type' => 'dofollow',
            'active' => true,
            'verified' => true,
        ]);
    }
}
