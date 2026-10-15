<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\MarketplacePicker;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminSiteEditMarketplacePickerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketer = $this->userWithRole('marketing');
        $this->publisher = $this->userWithRole('publisher');
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

    private function site(array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Picker Site',
            'site_url' => 'https://picker-site.example',
            'domain' => 'picker-site.example',
            'da' => 40,
            'dr' => 42,
            'traffic' => 15000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'News',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Marketplace picker edit page description. ', 3),
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    /**
     * @param  list<string>|array<string, string>  $countries
     * @param  list<string>  $languages
     */
    private function poisonPickerCache(array $countries, array $languages = ['de', 'en']): void
    {
        Cache::flush();
        Cache::put(MarketplacePicker::LEGACY_COUNTRIES_CACHE_KEY, $countries, 300);
        Cache::put(MarketplacePicker::COUNTRIES_CACHE_KEY, $countries, 300);
        Cache::put(MarketplacePicker::LEGACY_LANGUAGES_CACHE_KEY, $languages, 300);
        Cache::put(MarketplacePicker::LANGUAGES_CACHE_KEY, $languages, 300);
    }

    private function assertCountryPickerHtml(string $html): void
    {
        $this->assertStringContainsString('value="de"', $html);
        $this->assertStringContainsString('Germany', $html);
        $this->assertStringNotContainsString('Attempt to read property', $html);
    }

    public function test_admin_site_edit_renders_country_and_language_options(): void
    {
        $site = $this->site();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->assertSee('Picker Site', false)
            ->getContent();

        $this->assertCountryPickerHtml($html);
        $this->assertStringContainsString('value="de"', $html);
        $this->assertStringContainsString('German', $html);
        $this->assertNotEmpty(Cache::get(MarketplacePicker::COUNTRIES_CACHE_KEY));
    }

    public function test_every_admin_site_edit_page_survives_string_cache_payload(): void
    {
        $this->poisonPickerCache(['de', 'fr', 'uk', 'United Kingdom']);

        $first = $this->site(['site_name' => 'First Edit', 'site_url' => 'https://first-edit.example', 'domain' => 'first-edit.example']);
        $second = $this->site(['site_name' => 'Second Edit', 'site_url' => 'https://second-edit.example', 'domain' => 'second-edit.example']);

        foreach ([$first, $second] as $site) {
            $html = $this->actingAs($this->admin)
                ->get(route('admin.sites.edit', $site->id))
                ->assertOk()
                ->getContent();
            $this->assertCountryPickerHtml($html);
            $this->assertStringContainsString($site->site_name, $html);
        }
    }

    public function test_admin_site_edit_tolerates_code_name_map_and_attribute_arrays(): void
    {
        $this->poisonPickerCache(marketplace_countries(), ['de', 'en', 'es']);

        $site = $this->site();
        $this->assertCountryPickerHtml(
            $this->actingAs($this->admin)
                ->get(route('admin.sites.edit', $site->id))
                ->assertOk()
                ->getContent()
        );

        Cache::flush();
        Cache::put(MarketplacePicker::COUNTRIES_CACHE_KEY, [
            ['id' => 1, 'code' => 'de', 'name' => 'Germany'],
            ['id' => 2, 'code' => 'es', 'name' => 'Spain'],
        ], 300);
        Cache::put(MarketplacePicker::LANGUAGES_CACHE_KEY, [
            ['code' => 'de', 'name' => 'German'],
            ['code' => 'en', 'name' => 'English'],
        ], 300);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->getContent();
        $this->assertCountryPickerHtml($html);
        $this->assertStringContainsString('value="es"', $html);
    }

    public function test_warm_cache_second_request_keeps_working(): void
    {
        $site = $this->site();

        $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk();

        $warm = Cache::get(MarketplacePicker::COUNTRIES_CACHE_KEY);
        $this->assertIsArray($warm);
        $this->assertArrayHasKey('code', $warm[0] ?? []);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.edit', $site->id))
            ->assertOk()
            ->getContent();
        $this->assertCountryPickerHtml($html);
        $this->assertSame($warm, Cache::get(MarketplacePicker::COUNTRIES_CACHE_KEY));
    }

    public function test_admin_site_create_survives_poisoned_cache(): void
    {
        $this->poisonPickerCache(['Germany', 'France', 'de']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.create', ['publisher' => $this->publisher->id]))
            ->assertOk()
            ->getContent();

        $this->assertCountryPickerHtml($html);
    }

    public function test_marketing_site_edit_survives_poisoned_cache(): void
    {
        $this->poisonPickerCache(['de', 'it']);
        $site = $this->site([
            'verified' => false,
            'active' => false,
            'site_name' => 'Marketer Edit',
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.sites.edit', $site->id))
            ->assertOk()
            ->getContent();

        $this->assertCountryPickerHtml($html);
        $this->assertStringContainsString('Marketer Edit', $html);
    }

    public function test_publisher_websites_renders_country_options(): void
    {
        $html = $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->getContent();

        $this->assertCountryPickerHtml($html);
        $this->assertStringContainsString('data-value="de"', $html);
    }

    public function test_staff_sites_list_filters_survive_poisoned_cache(): void
    {
        $this->poisonPickerCache(['de', 'nl']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.sites.index', ['all' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertCountryPickerHtml($html);
    }
}
