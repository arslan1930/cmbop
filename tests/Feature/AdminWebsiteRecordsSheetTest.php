<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Country;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWebsiteRecordsSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
    }

    private function userWithRoles(array $roleNames, ?string $active = null): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $ids = [];
        foreach ($roleNames as $name) {
            $ids[$name] = Role::where('name', $name)->value('id');
            $user->roles()->attach($ids[$name]);
        }
        $activeName = $active ?? $roleNames[0];
        $user->active_role_id = $ids[$activeName];
        $user->save();

        return $user->fresh(['roles']);
    }

    private function makeSite(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Records Sheet Site',
            'site_url' => 'https://records-sheet.example',
            'domain' => 'records-sheet.example',
            'da' => 20,
            'dr' => 25,
            'traffic' => 1000,
            'country' => 'de',
            'countries' => ['de', 'at'],
            'language' => 'de',
            'category' => 'Technology',
            'categories' => ['Technology', 'Business & Finance'],
            'price' => 99,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Website records sheet test description.',
            'verified' => false,
            'active' => false,
        ], $overrides));
    }

    public function test_admin_can_view_websites_records_sheet(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher);

        $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk()
            ->assertSee('Websites records sheet', false)
            ->assertSee('Live from database', false)
            ->assertSee('Filter by country', false)
            ->assertSee('Search countries', false)
            ->assertSee('recordsCountrySearch', false)
            ->assertDontSee('>Apply<', false)
            ->assertSee('https://records-sheet.example', false)
            ->assertSee('de|at', false)
            ->assertSee('Technology|Business &amp; Finance', false)
            ->assertSee('Search sites', false)
            ->assertSee('Verify selected', false)
            ->assertSee('Activate selected', false)
            ->assertSee('Duplicate domains', false)
            ->assertSee(route('admin.sites.duplicates'), false)
            ->assertDontSee('€99', false);
    }

    public function test_records_sheet_exposes_live_country_counts(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_url' => 'https://german-records.example',
            'domain' => 'german-records.example',
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://austria-records.example',
            'domain' => 'austria-records.example',
            'country' => 'de',
            'countries' => ['de', 'at'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://french-records.example',
            'domain' => 'french-records.example',
            'country' => 'fr',
            'countries' => ['fr'],
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk()
            ->getContent();

        // COUNTRIES JSON embedded for the combobox — DE includes both DE-only and DE+AT sites.
        $this->assertMatchesRegularExpression('/"code"\s*:\s*"de"[^}]*"count"\s*:\s*2/i', $html);
        $this->assertMatchesRegularExpression('/"code"\s*:\s*"at"[^}]*"count"\s*:\s*1/i', $html);
        $this->assertMatchesRegularExpression('/"code"\s*:\s*"fr"[^}]*"count"\s*:\s*1/i', $html);
        $this->assertStringContainsString('TOTAL_SITES = 3', $html);
    }

    public function test_records_sheet_partial_json_filters_live(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_url' => 'https://german-records.example',
            'domain' => 'german-records.example',
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://french-records.example',
            'domain' => 'french-records.example',
            'country' => 'fr',
            'countries' => ['fr'],
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['country' => 'fr', 'partial' => 1]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('selected_country', 'fr')
            ->assertJsonPath('total', 1);

        $html = (string) $response->json('table_html');
        $this->assertStringContainsString('https://french-records.example', $html);
        $this->assertStringNotContainsString('https://german-records.example', $html);
        $this->assertStringContainsString('country=fr', (string) $response->json('export_url'));
    }

    public function test_records_sheet_array_country_does_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_url' => 'https://german-records.example',
            'domain' => 'german-records.example',
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://french-records.example',
            'domain' => 'french-records.example',
            'country' => 'fr',
            'countries' => ['fr'],
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['country' => ['fr'], 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('selected_country', 'fr')
            ->assertJsonPath('total', 1);

        $export = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['country' => ['fr']]));

        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('https://french-records.example', $csv);
        $this->assertStringNotContainsString('https://german-records.example', $csv);
    }

    public function test_admin_can_filter_records_sheet_by_country(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_name' => 'German Site',
            'site_url' => 'https://german-records.example',
            'domain' => 'german-records.example',
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'French Site',
            'site_url' => 'https://french-records.example',
            'domain' => 'french-records.example',
            'country' => 'fr',
            'countries' => ['fr'],
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Multi AT Site',
            'site_url' => 'https://austria-records.example',
            'domain' => 'austria-records.example',
            'country' => 'de',
            'countries' => ['de', 'at'],
        ]);

        $this->assertTrue(Country::marketplace()->where('code', 'de')->exists());

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['country' => 'fr']))
            ->assertOk()
            ->assertSee('https://french-records.example', false)
            ->assertDontSee('https://german-records.example', false)
            ->assertDontSee('https://austria-records.example', false);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['country' => 'at']))
            ->assertOk()
            ->assertSee('https://austria-records.example', false)
            ->assertDontSee('https://french-records.example', false)
            ->assertDontSee('https://german-records.example', false);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['country' => 'de']))
            ->assertOk()
            ->assertSee('https://german-records.example', false)
            ->assertSee('https://austria-records.example', false)
            ->assertDontSee('https://french-records.example', false);
    }

    public function test_admin_csv_export_respects_country_filter(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_url' => 'https://german-records.example',
            'domain' => 'german-records.example',
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://french-records.example',
            'domain' => 'french-records.example',
            'country' => 'fr',
            'countries' => ['fr'],
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['country' => 'fr']));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('https://french-records.example', $csv);
        $this->assertStringNotContainsString('https://german-records.example', $csv);
    }

    public function test_admin_can_download_websites_records_csv(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher);

        $response = $this->actingAs($admin)
            ->get(route('admin.sites.records.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('url,countries,categories', $csv);
        $this->assertStringContainsString('https://records-sheet.example', $csv);
        $this->assertStringContainsString('de|at', $csv);
        $this->assertStringContainsString('Technology|Business & Finance', $csv);
        $this->assertStringNotContainsString('Records Sheet Site', $csv);

        $log = ActivityLog::query()->where('action', 'sites.records_exported')->first();
        $this->assertNotNull($log);
        $this->assertGreaterThanOrEqual(1, (int) data_get($log->properties, 'rows_exported'));
        $this->assertFalse((bool) data_get($log->properties, 'missing_market'));
    }

    public function test_non_admin_cannot_access_websites_records_sheet(): void
    {
        $marketer = $this->userWithRoles(['marketing'], 'marketing');
        $advertiser = $this->userWithRoles(['advertiser'], 'advertiser');

        $this->actingAs($marketer)
            ->get(route('admin.sites.records'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($marketer)
            ->get(route('admin.sites.records.export'))
            ->assertRedirect(route('marketing.dashboard'));

        $this->actingAs($marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee('Marketing workspace', false);

        $this->actingAs($advertiser)
            ->get(route('admin.sites.records'))
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->get(route('admin.sites.records.export'))
            ->assertForbidden();
    }

    public function test_admin_sites_index_links_to_records_sheet(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');

        $this->actingAs($admin)
            ->get(route('admin.sites.index'))
            ->assertOk()
            ->assertSee('Websites records sheet', false)
            ->assertSee(route('admin.sites.records'), false);
    }

    public function test_records_search_filters_by_domain(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_name' => 'Needle Records Site',
            'site_url' => 'https://needle-records.example',
            'domain' => 'needle-records.example',
        ]);
        $this->makeSite($publisher, [
            'site_name' => 'Other Records Site',
            'site_url' => 'https://other-records.example',
            'domain' => 'other-records.example',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['q' => 'needle-records']))
            ->assertOk()
            ->assertSee('https://needle-records.example', false)
            ->assertDontSee('https://other-records.example', false);
    }

    public function test_records_bulk_verify_and_activate_use_existing_site_actions(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_name' => 'Bulk Verify Site',
            'site_url' => 'https://bulk-verify.example',
            'domain' => 'bulk-verify.example',
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.records.bulk-verify'), ['ids' => [$site->id]])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated', 1);

        $this->assertTrue((bool) $site->fresh()->verified);

        $this->actingAs($admin)
            ->postJson(route('admin.sites.records.bulk-activate'), ['ids' => [$site->id]])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated', 1);

        $this->assertTrue((bool) $site->fresh()->active);
    }
}
