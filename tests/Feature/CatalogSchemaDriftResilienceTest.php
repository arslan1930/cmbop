<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Catalog\CatalogCountryInventory;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogSchemaDriftResilienceTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $advRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advRole->id,
        ]);
        $this->advertiser->roles()->attach($advRole->id);

        $pubRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $pubRole->id,
        ]);
        $this->publisher->roles()->attach($pubRole->id);

        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Drift Fixture',
            'site_url' => 'https://drift-fixture.example',
            'domain' => 'drift-fixture.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 8000,
            'country' => 'de',
            'countries' => ['de'],
            'language' => 'de',
            'languages' => ['de'],
            'category' => 'Technology',
            'categories' => ['Technology'],
            'price' => 90,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Schema drift catalog fixture listing description.',
            'verified' => true,
            'active' => true,
            'bulk_discount_enabled' => true,
            'bulk_discount_percent' => 15,
        ]);
    }

    public function test_catalog_loads_when_sites_countries_json_column_is_missing(): void
    {
        $this->dropSitesColumnIfPresent('countries');
        CatalogCountryInventory::forget();

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertDontSee('Unknown column')
            ->assertDontSee('SQLSTATE');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['country' => 'de']))
            ->assertOk()
            ->assertDontSee('Unknown column');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results', ['country' => 'de']))
            ->assertOk()
            ->assertDontSee('Unknown column');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', ['country' => 'is']))
            ->assertOk()
            ->assertDontSee('Unknown column');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals', ['country' => 'de']))
            ->assertOk();

        $this->assertSame(1, app(CatalogCountryInventory::class)->counts()['de'] ?? 0);
    }

    public function test_catalog_loads_when_sites_languages_and_categories_json_missing(): void
    {
        $this->dropSitesColumnIfPresent('languages');
        $this->dropSitesColumnIfPresent('categories');
        CatalogCountryInventory::forget();

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog', [
                'language' => 'de',
                'category' => 'Technology',
                'search' => 'Technology',
            ]))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    public function test_catalog_loads_when_content_submission_orderable_columns_missing(): void
    {
        foreach (['archived_at', 'country', 'language'] as $column) {
            if (Schema::hasColumn('content_submissions', $column)) {
                try {
                    Schema::table('content_submissions', function (Blueprint $table) use ($column) {
                        $table->dropColumn($column);
                    });
                } catch (\Throwable) {
                    // SQLite may refuse some drops; skip that column.
                }
            }
        }

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong');
    }

    public function test_catalog_repairs_missing_homepage_placement_prices_column(): void
    {
        $this->dropSitesColumnIfPresent('homepage_placement_prices');
        $this->dropSitesColumnIfPresent('social_promotion');
        $this->assertFalse(Schema::hasColumn('sites', 'homepage_placement_prices'));

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong');

        $this->assertTrue(Schema::hasColumn('sites', 'homepage_placement_prices'));
        $this->assertTrue(Schema::hasColumn('sites', 'social_promotion'));
        $this->assertSame(0, Site::countWithHomepagePlacement());
    }

    public function test_catalog_loads_when_order_items_and_featured_columns_are_missing(): void
    {
        $this->dropSitesColumnIfPresent('featured_until');
        $this->dropSitesColumnIfPresent('screenshot_path');
        Schema::dropIfExists('order_items');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->assertDontSee('Something went wrong')
            ->assertDontSee('Unknown column')
            ->assertDontSee('SQLSTATE');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.results'))
            ->assertOk()
            ->assertDontSee('Unknown column');

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.bulk-deals'))
            ->assertOk()
            ->assertDontSee('Unknown column');
    }

    private function dropSitesColumnIfPresent(string $column): void
    {
        if (! Schema::hasColumn('sites', $column)) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) use ($column) {
            $table->dropColumn($column);
        });
    }
}
