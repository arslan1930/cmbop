<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\Country;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('Live on portal', false)
            ->assertSee('All records', false)
            ->assertSee('Filter by country', false)
            ->assertSee('Search countries', false)
            ->assertSee('recordsCountrySearch', false)
            ->assertDontSee('>Apply<', false)
            ->assertSee('https://records-sheet.example', false)
            ->assertSee('Open in admin', false)
            ->assertSee('>Off</span>', false)
            ->assertSee('de|at', false)
            ->assertSee('Technology|Business &amp; Finance', false)
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
        $this->assertStringContainsString('listing_state', $csv);
        $this->assertStringContainsString('https://records-sheet.example', $csv);
        $this->assertStringContainsString('de|at', $csv);
        $this->assertStringContainsString('Technology|Business & Finance', $csv);
        $this->assertStringContainsString('not_live', $csv);
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

    public function test_live_filter_keeps_catalog_visible_sites_only(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_url' => 'https://live-portal.example',
            'domain' => 'live-portal.example',
            'active' => true,
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://off-portal.example',
            'domain' => 'off-portal.example',
            'active' => false,
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://archived-portal.example',
            'domain' => 'archived-portal.example',
            'active' => true,
            'archived_at' => now(),
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://cancelled-bulk-portal.example',
            'domain' => 'cancelled-bulk-portal.example',
            'active' => true,
            'bulk_site_request_id' => $bulk->id,
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://live-fr-portal.example',
            'domain' => 'live-fr-portal.example',
            'active' => true,
            'country' => 'fr',
            'countries' => ['fr'],
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('Live on portal', false)
            ->assertSee('https://live-portal.example', false)
            ->assertSee('https://live-fr-portal.example', false)
            ->assertDontSee('https://off-portal.example', false)
            ->assertDontSee('https://archived-portal.example', false)
            ->assertDontSee('https://cancelled-bulk-portal.example', false)
            ->assertSee('>Live</span>', false)
            ->getContent();

        $this->assertStringContainsString(route('admin.sites.edit', Site::query()->where('domain', 'live-portal.example')->value('id')), $html);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1, 'country' => 'de']))
            ->assertOk()
            ->assertSee('https://live-portal.example', false)
            ->assertDontSee('https://live-fr-portal.example', false)
            ->assertDontSee('https://off-portal.example', false);

        $partial = $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'country' => 'de', 'partial' => 1]));
        $partial->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true)
            ->assertJsonPath('selected_country', 'de')
            ->assertJsonPath('total', 1);
        $this->assertStringContainsString('live=1', (string) $partial->json('export_url'));
        $this->assertStringContainsString('country=de', (string) $partial->json('export_url'));

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['live' => 1, 'country' => 'de']));
        $csv->assertOk();
        $this->assertStringContainsString('live', (string) $csv->headers->get('Content-Disposition'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('https://live-portal.example', $body);
        $this->assertStringContainsString(',live', $body);
        $this->assertStringNotContainsString('https://live-fr-portal.example', $body);
        $this->assertStringNotContainsString('https://off-portal.example', $body);
    }

    public function test_leftover_hostile_records_url_is_not_linked(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_url' => 'https://leftover-records.example',
            'domain' => 'leftover-records.example',
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'site_url' => 'javascript:alert(1)',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk()
            ->assertSee('Open in admin', false)
            ->getContent();

        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringContainsString(route('admin.sites.edit', $site->id), $html);
    }

    public function test_live_filter_empty_state(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, ['active' => false]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('No live websites.', false)
            ->assertDontSee('https://records-sheet.example', false);
    }
}
