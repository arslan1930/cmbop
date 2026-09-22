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
use Illuminate\Support\Facades\Schema;
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

    private function dropSitesColumn(string $column): void
    {
        $this->dropTableColumn('sites', $column);
    }

    private function dropTableColumn(string $table, string $column): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];
            if (! in_array($column, $columns, true)) {
                continue;
            }
            $name = $index['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            try {
                Schema::table($table, function ($blueprint) use ($name) {
                    $blueprint->dropIndex($name);
                });
            } catch (\Throwable) {
                // SQLite leftover composite indexes; keep dropping the rest.
            }
        }

        Schema::table($table, function ($blueprint) use ($column) {
            $blueprint->dropColumn($column);
        });
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

    public function test_records_sheet_array_live_does_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');

        $this->makeSite($publisher, [
            'site_url' => 'https://live-array.example',
            'domain' => 'live-array.example',
            'active' => true,
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://off-array.example',
            'domain' => 'off-array.example',
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => ['1']]))
            ->assertOk()
            ->assertSee('https://live-array.example', false)
            ->assertDontSee('https://off-array.example', false)
            ->assertDontSee('SQLSTATE', false);

        $partial = $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => ['1'], 'partial' => ['1']]));
        $partial->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true);
        $this->assertStringContainsString('https://live-array.example', (string) $partial->json('table_html'));
        $this->assertStringNotContainsString('https://off-array.example', (string) $partial->json('table_html'));

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['live' => ['1']]));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('https://live-array.example', $body);
        $this->assertStringNotContainsString('https://off-array.example', $body);
    }

    public function test_junk_live_query_shows_all_records(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://junk-live-off.example',
            'domain' => 'junk-live-off.example',
            'active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => ['not-json']]))
            ->assertOk()
            ->assertSee('https://junk-live-off.example', false)
            ->assertDontSee('No live websites.', false);
    }

    public function test_array_missing_market_does_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://missing-market-array.example',
            'domain' => 'missing-market-array.example',
            'active' => true,
            'country' => '',
            'countries' => [],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://has-market-array.example',
            'domain' => 'has-market-array.example',
            'active' => true,
            'country' => 'de',
            'countries' => ['de'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['missing_market' => ['1']]))
            ->assertOk()
            ->assertSee('https://missing-market-array.example', false)
            ->assertDontSee('https://has-market-array.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_live_filter_survives_missing_bulk_table(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-bulk.example',
            'domain' => 'live-no-bulk.example',
            'active' => true,
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://off-no-bulk.example',
            'domain' => 'off-no-bulk.example',
            'active' => false,
        ]);

        Schema::dropIfExists('bulk_site_requests');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://live-no-bulk.example', false)
            ->assertDontSee('https://off-no-bulk.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true);

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['live' => 1]));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('https://live-no-bulk.example', $body);
        $this->assertStringNotContainsString('https://off-no-bulk.example', $body);
    }

    public function test_records_sheet_array_page_does_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://page-array.example',
            'domain' => 'page-array.example',
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1, 'page' => ['2']]))
            ->assertOk()
            ->assertSee('https://page-array.example', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('must be of type', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', [
                'live' => ['1'],
                'partial' => ['1'],
                'page' => ['2'],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true);
    }

    public function test_records_sheet_array_health_does_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://below-array.example',
            'domain' => 'below-array.example',
            'active' => true,
            'verified' => true,
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'countries' => ['de'],
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://clean-array.example',
            'domain' => 'clean-array.example',
            'active' => true,
            'verified' => true,
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'countries' => ['de'],
            'site_image' => 'sites/cover.webp',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => ['below_quality']]))
            ->assertOk()
            ->assertSee('https://below-array.example', false)
            ->assertDontSee('https://clean-array.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_health_filter_survives_missing_bulk_table(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://below-no-bulk.example',
            'domain' => 'below-no-bulk.example',
            'active' => true,
            'verified' => true,
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'countries' => ['de'],
        ]);

        Schema::dropIfExists('bulk_site_requests');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'below_quality']))
            ->assertOk()
            ->assertSee('https://below-no-bulk.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['health' => 'below_quality', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('health', 'below_quality');
    }

    public function test_live_filter_survives_missing_countries_table(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-countries.example',
            'domain' => 'live-no-countries.example',
            'active' => true,
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://off-no-countries.example',
            'domain' => 'off-no-countries.example',
            'active' => false,
        ]);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('countries');
        Schema::enableForeignKeyConstraints();

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://live-no-countries.example', false)
            ->assertDontSee('https://off-no-countries.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true);
    }

    public function test_records_leftover_junk_queries_do_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://junk-combo.example',
            'domain' => 'junk-combo.example',
            'active' => true,
        ]);

        $queries = [
            ['live' => 1, 'health' => ['not-json'], 'page' => 'not-json'],
            ['live' => ['on'], 'country' => ['not-json']],
            ['partial' => ['yes'], 'live' => ['1'], 'country' => [['de']]],
            ['missing_market' => ['false'], 'live' => 1],
        ];

        foreach ($queries as $query) {
            $this->actingAs($admin)
                ->get(route('admin.sites.records', $query))
                ->assertOk()
                ->assertDontSee('SQLSTATE', false)
                ->assertDontSee('must be of type', false);
        }
    }

    public function test_live_csv_survives_missing_countries_table(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-csv-no-countries.example',
            'domain' => 'live-csv-no-countries.example',
            'active' => true,
        ]);
        $this->makeSite($publisher, [
            'site_url' => 'https://off-csv-no-countries.example',
            'domain' => 'off-csv-no-countries.example',
            'active' => false,
        ]);

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('countries');
        Schema::enableForeignKeyConstraints();

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['live' => ['1']]));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('https://live-csv-no-countries.example', $body);
        $this->assertStringNotContainsString('https://off-csv-no-countries.example', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_below_quality_survives_missing_da_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://below-no-da.example',
            'domain' => 'below-no-da.example',
            'active' => true,
            'verified' => true,
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'countries' => ['de'],
        ]);

        Schema::table('sites', function ($table) {
            $table->dropColumn('da');
        });

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'below_quality']))
            ->assertOk()
            ->assertSee('https://below-no-da.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['health' => 'below_quality', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_leftover_invalid_utf8_url_does_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_url' => 'https://utf8-records.example',
            'domain' => 'utf8-records.example',
            'active' => true,
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'site_url' => "https://utf8-records.example/\xB1",
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertDontSee('SQLSTATE', false)
            ->assertSee('Open in admin', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_live_filter_survives_missing_active_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-active-col.example',
            'domain' => 'live-no-active-col.example',
            'active' => true,
        ]);

        $this->dropSitesColumn('active');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://live-no-active-col.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true);

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['live' => 1]));
        $csv->assertOk();
        $this->assertStringContainsString('https://live-no-active-col.example', $csv->streamedContent());
    }

    public function test_missing_market_survives_missing_active_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://missing-market-no-active.example',
            'domain' => 'missing-market-no-active.example',
            'active' => true,
            'country' => '',
            'countries' => [],
        ]);

        $this->dropSitesColumn('active');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['missing_market' => 1]))
            ->assertOk()
            ->assertSee('https://missing-market-no-active.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_placeholder_queue_survives_missing_description_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://demo86.com/guest',
            'domain' => 'demo86.com',
            'active' => true,
            'verified' => true,
            'description' => 'Lorem ipsum leftover placeholder.',
        ]);

        $this->dropSitesColumn('description');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'placeholder']))
            ->assertOk()
            ->assertSee('https://demo86.com/guest', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['health' => 'placeholder', 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('health', 'placeholder');
    }

    public function test_unverified_queue_survives_missing_verified_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://unverified-no-col.example',
            'domain' => 'unverified-no-col.example',
            'active' => true,
            'verified' => false,
        ]);

        $this->dropSitesColumn('verified');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'unverified']))
            ->assertOk()
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('must be of type', false);
    }

    public function test_live_filter_survives_missing_country_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-country-col.example',
            'domain' => 'live-no-country-col.example',
            'active' => true,
            'country' => 'de',
            'countries' => ['de'],
        ]);

        $this->dropSitesColumn('country');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1, 'country' => 'de']))
            ->assertOk()
            ->assertSee('https://live-no-country-col.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true);
    }

    public function test_leftover_nested_countries_json_does_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_url' => 'https://nested-countries.example',
            'domain' => 'nested-countries.example',
            'active' => true,
            'country' => 'de',
            'countries' => ['de'],
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'countries' => json_encode([['de'], ['at']]),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk()
            ->assertSee('https://nested-countries.example', false)
            ->assertSee('de|at', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('must be of type', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_leftover_not_json_countries_and_categories_do_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_url' => 'https://not-json-records.example',
            'domain' => 'not-json-records.example',
            'active' => true,
            'country' => 'de',
            'countries' => ['de'],
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'countries' => 'not-json',
            'categories' => 'not-json',
            'languages' => 'not-json',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1, 'country' => 'de']))
            ->assertOk()
            ->assertSee('https://not-json-records.example', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('must be of type', false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['live' => 1, 'country' => 'de']));
        $csv->assertOk();
        $this->assertStringContainsString('https://not-json-records.example', $csv->streamedContent());
        $this->assertStringNotContainsString('SQLSTATE', $csv->streamedContent());
    }

    public function test_live_filter_survives_missing_site_url_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-url-col.example',
            'domain' => 'live-no-url-col.example',
            'active' => true,
        ]);

        $this->dropSitesColumn('site_url');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('live-no-url-col.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_records_sheet_survives_missing_categories_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://no-categories-col.example',
            'domain' => 'no-categories-col.example',
            'active' => true,
        ]);

        $this->dropSitesColumn('categories');

        $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk()
            ->assertSee('https://no-categories-col.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_missing_market_survives_missing_country_columns(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://no-country-cols.example',
            'domain' => 'no-country-cols.example',
            'active' => true,
            'country' => '',
            'countries' => [],
        ]);

        $this->dropSitesColumn('country');
        $this->dropSitesColumn('countries');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['missing_market' => 1]))
            ->assertOk()
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('must be of type', false);
    }

    public function test_below_quality_survives_missing_all_metric_columns(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://no-metrics-cols.example',
            'domain' => 'no-metrics-cols.example',
            'active' => true,
            'verified' => true,
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
        ]);

        $this->dropSitesColumn('da');
        $this->dropSitesColumn('dr');
        $this->dropSitesColumn('traffic');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'below_quality']))
            ->assertOk()
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('must be of type', false);
    }

    public function test_missing_cover_survives_missing_site_image_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://no-cover-col.example',
            'domain' => 'no-cover-col.example',
            'active' => true,
            'verified' => true,
            'site_image' => null,
            'screenshot_path' => null,
            'screenshot_thumb_path' => null,
        ]);

        $this->dropSitesColumn('site_image');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'missing_cover']))
            ->assertOk()
            ->assertSee('https://no-cover-col.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_leftover_invalid_utf8_categories_do_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_url' => 'https://utf8-categories.example',
            'domain' => 'utf8-categories.example',
            'active' => true,
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'categories' => "Tech\xB1",
            'category' => "News\xB1",
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records'))
            ->assertOk()
            ->assertSee('https://utf8-categories.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_live_filter_survives_missing_bulk_status_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-bulk-status.example',
            'domain' => 'live-no-bulk-status.example',
            'active' => true,
        ]);

        $this->dropTableColumn('bulk_site_requests', 'status');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://live-no-bulk-status.example', false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($admin)
            ->getJson(route('admin.sites.records', ['live' => 1, 'partial' => 1]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('live', true);
    }

    public function test_live_filter_survives_missing_domain_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-domain-col.example',
            'domain' => 'live-no-domain-col.example',
            'active' => true,
        ]);

        $this->dropSitesColumn('domain');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://live-no-domain-col.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_live_filter_survives_missing_archived_at_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-archived-col.example',
            'domain' => 'live-no-archived-col.example',
            'active' => true,
        ]);

        $this->dropSitesColumn('archived_at');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://live-no-archived-col.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_live_filter_survives_missing_bulk_site_request_id_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-no-bulk-id.example',
            'domain' => 'live-no-bulk-id.example',
            'active' => true,
        ]);

        $this->dropSitesColumn('bulk_site_request_id');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://live-no-bulk-id.example', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_placeholder_queue_survives_missing_example_url_column(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://demo86.com/guest',
            'domain' => 'demo86.com',
            'active' => true,
            'verified' => true,
            'example_url' => 'https://demo86.com/sample',
        ]);

        $this->dropSitesColumn('example_url');

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['health' => 'placeholder']))
            ->assertOk()
            ->assertSee('https://demo86.com/guest', false)
            ->assertDontSee('SQLSTATE', false);
    }

    public function test_live_csv_survives_missing_activity_logs_table(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $this->makeSite($publisher, [
            'site_url' => 'https://live-csv-no-logs.example',
            'domain' => 'live-csv-no-logs.example',
            'active' => true,
        ]);

        Schema::dropIfExists('activity_logs');

        $csv = $this->actingAs($admin)
            ->get(route('admin.sites.records.export', ['live' => 1]));
        $csv->assertOk();
        $this->assertStringContainsString('https://live-csv-no-logs.example', $csv->streamedContent());
        $this->assertStringNotContainsString('SQLSTATE', $csv->streamedContent());
    }

    public function test_leftover_unparseable_dates_do_not_500(): void
    {
        $admin = $this->userWithRoles(['admin'], 'admin');
        $publisher = $this->userWithRoles(['publisher'], 'publisher');
        $site = $this->makeSite($publisher, [
            'site_url' => 'https://leftover-dates.example',
            'domain' => 'leftover-dates.example',
            'active' => true,
        ]);
        DB::table('sites')->where('id', $site->id)->update([
            'archived_at' => 'not-a-date',
            'featured_until' => 'not-a-date',
            'custom_discount_starts_at' => 'not-a-date',
            'custom_discount_ends_at' => 'not-a-date',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sites.records', ['live' => 1]))
            ->assertOk()
            ->assertSee('https://leftover-dates.example', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('must be of type', false);
    }
}
