<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 — catalog niche picker loads from the categories table only.
 */
class CatalogCategoryDbSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
        Category::flushNicheLookupCache();

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);
    }

    public function test_catalog_page_options_match_db_and_include_comma_niches(): void
    {
        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $dbNames = Category::query()->orderBy('name')->pluck('name')->all();
        $this->assertNotEmpty($dbNames);

        foreach ($dbNames as $name) {
            $this->assertStringContainsString(
                'value="'.e($name).'"',
                $html,
                "Missing picker option for DB niche: {$name}"
            );
        }

        foreach (Category::NICHES_CONTAINING_COMMA as $niche) {
            $this->assertStringContainsString('value="'.e($niche).'"', $html);
        }
    }

    public function test_controller_no_longer_embeds_hardcoded_niche_list(): void
    {
        $src = (string) file_get_contents(
            app_path('Http/Controllers/Advertiser/CatalogController.php')
        );

        $this->assertStringContainsString('Category::catalogPickerRows()', $src);
        $this->assertStringContainsString('Category::catalogPickerNames()', $src);
        $this->assertStringNotContainsString(
            "['name' => 'Marketing, PR & Advertising', 'group' => 'Business & Finance']",
            $src
        );
        $this->assertStringNotContainsString(
            "['name' => 'Events, Conferences & Trade Fairs'",
            $src
        );
    }

    public function test_picker_rows_follow_db_insert_without_controller_edit(): void
    {
        Category::query()->create([
            'name' => 'Phase5 Drift Probe Niche',
            'group' => 'Other',
        ]);
        Category::flushNicheLookupCache();

        $names = Category::catalogPickerNames();
        $this->assertContains('Phase5 Drift Probe Niche', $names);

        $html = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="Phase5 Drift Probe Niche"', $html);
    }

    public function test_seeder_and_picker_share_the_same_name_set(): void
    {
        $fromSeederPath = Category::query()->orderBy('name')->pluck('name')->all();
        $fromPicker = Category::catalogPickerNames();
        sort($fromPicker);

        $this->assertSame($fromSeederPath, $fromPicker);
        $this->assertSameSize($fromSeederPath, Category::catalogPickerRows());
    }
}
