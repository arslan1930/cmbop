<?php

namespace Tests\Feature;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Models\ActivityLog;
use App\Models\AgencySiteImport;
use App\Models\AgencySiteImportFailure;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgencyCsvBulkImportTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private string $categoryName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $role = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $this->publisher->roles()->attach($role->id);

        $this->categoryName = Category::query()->value('name') ?: 'Business & Finance';
    }

    private function csvContents(array $rows): string
    {
        $headers = [
            'site_name', 'site_url', 'example_url', 'da', 'dr', 'traffic',
            'country', 'language', 'categories', 'price', 'turnaround_time',
            'publication_time', 'link_type', 'description',
            'sponsored', 'partner_material', 'as_you_prefer',
        ];

        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(function ($v) {
                $v = (string) $v;
                if (str_contains($v, ',') || str_contains($v, '"')) {
                    return '"'.str_replace('"', '""', $v).'"';
                }

                return $v;
            }, $row));
        }

        return implode("\n", $lines)."\n";
    }

    private function validRow(string $domain, string $name = 'Agency Blog'): array
    {
        return [
            $name,
            'https://'.$domain,
            'https://'.$domain.'/sample-post',
            '45',
            '40',
            '15000',
            'de',
            'de',
            $this->categoryName,
            '120',
            '3days',
            'permanent',
            'dofollow',
            'High-quality editorial site covering business and technology topics for professional audiences.',
            '0',
            '0',
            '0',
        ];
    }

    private function uploadCsv(array $rows, bool $dryRun = false)
    {
        Storage::fake('local');
        $csv = $this->csvContents($rows);
        $file = UploadedFile::fake()->createWithContent('agency-sites.csv', $csv);

        $payload = ['csv_file' => $file];
        if ($dryRun) {
            $payload['dry_run'] = 1;
        }

        return $this->actingAs($this->publisher)
            ->post(route('publisher.sites.bulk-import'), $payload);
    }

    public function test_happy_path_creates_import_batch_and_stamps_acceptance(): void
    {
        Bus::fake();
        config(['site_enrichment.enabled' => true]);

        $this->uploadCsv([
            $this->validRow('agency-one.example', 'Agency One'),
            $this->validRow('agency-two.example', 'Agency Two'),
        ])->assertRedirect();

        $import = AgencySiteImport::query()->first();
        $this->assertNotNull($import);
        $this->assertSame(AgencySiteImport::STATUS_SUBMITTED, $import->status);
        $this->assertSame(2, (int) $import->created_count);
        $this->assertSame(0, (int) $import->failed_count);
        $this->assertSame($this->publisher->id, (int) $import->publisher_id);

        $sites = Site::query()->where('agency_site_import_id', $import->id)->get();
        $this->assertCount(2, $sites);
        foreach ($sites as $site) {
            $this->assertNotNull($site->publisher_accepted_at);
            $this->assertTrue((bool) $site->metrics_manual);
            $this->assertFalse((bool) $site->verified);
            $this->assertFalse((bool) $site->active);
            $this->assertTrue($site->isFromAgencyCsvImport());
        }

        $this->assertTrue(
            ActivityLog::query()->where('action', 'site.bulk_imported')->count() >= 2
        );
        $this->assertTrue(
            ActivityLog::query()->where('action', 'agency_import.submitted')->exists()
        );

        Bus::assertDispatched(CaptureSiteScreenshotJob::class, 2);
    }

    public function test_dry_run_does_not_persist_import_or_sites(): void
    {
        $this->uploadCsv([
            $this->validRow('dry-run.example'),
        ], dryRun: true)->assertRedirect();

        $this->assertSame(0, AgencySiteImport::query()->count());
        $this->assertSame(0, Site::query()->count());
    }

    public function test_dry_run_does_not_release_cancelled_bulk_leftover(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
        ]);
        $leftover = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Cancelled Leftover',
            'site_url' => 'https://dry-cancel.example',
            'domain' => 'dry-cancel.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => $this->categoryName,
            'price' => 50,
            'publication_time' => 'permanent',
            'description' => str_repeat('Cancelled leftover description text. ', 4),
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => false,
            'archived_at' => now(),
            'bulk_site_request_id' => $bulk->id,
        ]);

        $this->uploadCsv([
            $this->validRow('dry-cancel.example'),
        ], dryRun: true)->assertRedirect();

        $this->assertSame(0, AgencySiteImport::query()->count());
        $this->assertDatabaseHas('sites', [
            'id' => $leftover->id,
            'domain' => 'dry-cancel.example',
        ]);
    }

    public function test_partial_failures_are_persisted_on_the_import(): void
    {
        Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Existing',
            'site_url' => 'https://taken.example',
            'domain' => 'taken.example',
            'da' => 10,
            'dr' => 10,
            'traffic' => 100,
            'country' => 'de',
            'language' => 'de',
            'category' => $this->categoryName,
            'price' => 50,
            'publication_time' => 'permanent',
            'description' => str_repeat('Existing site description text. ', 4),
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        $this->uploadCsv([
            $this->validRow('fresh.example', 'Fresh Site'),
            $this->validRow('taken.example', 'Already Taken'),
        ])->assertRedirect();

        $import = AgencySiteImport::query()->first();
        $this->assertNotNull($import);
        $this->assertSame(AgencySiteImport::STATUS_PARTIAL, $import->status);
        $this->assertSame(1, (int) $import->created_count);
        $this->assertSame(1, (int) $import->failed_count);

        $this->assertSame(1, Site::query()->where('agency_site_import_id', $import->id)->count());
        $this->assertSame(1, AgencySiteImportFailure::query()->where('agency_site_import_id', $import->id)->count());
        $failure = AgencySiteImportFailure::query()->first();
        $this->assertStringContainsString('already registered', implode(' ', $failure->errors));
    }

    public function test_import_rejects_domain_pending_on_open_bulk(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://agency-pending.example',
            'domain' => 'agency-pending.example',
            'price' => 40,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://agency-pending-b.example',
            'domain' => 'agency-pending-b.example',
            'price' => 45,
        ]);

        $this->uploadCsv([
            $this->validRow('agency-pending.example', 'Pending Clash'),
        ])->assertRedirect();

        $this->assertDatabaseMissing('sites', ['domain' => 'agency-pending.example']);
        $failure = AgencySiteImportFailure::query()->first();
        $this->assertNotNull($failure);
        $this->assertStringContainsString(
            'Already in an open bulk request: agency-pending.example',
            implode(' ', $failure->errors)
        );
    }

    public function test_overflow_price_is_rejected_without_creating_a_site(): void
    {
        $row = $this->validRow('overflow-agency.example', 'Overflow Agency');
        $row[9] = '100000000000';

        $this->uploadCsv([$row])->assertRedirect();

        $this->assertNull(Site::where('domain', 'overflow-agency.example')->first());

        $import = AgencySiteImport::query()->first();
        $this->assertNotNull($import);
        $this->assertSame(0, (int) $import->created_count);
        $this->assertSame(1, (int) $import->failed_count);

        $failure = AgencySiteImportFailure::query()->first();
        $this->assertNotNull($failure);
        $this->assertMatchesRegularExpression('/price|99999999/i', implode(' ', $failure->errors));
    }

    public function test_admin_site_list_marks_csv_metrics_for_spot_check(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->uploadCsv([
            $this->validRow('spot-check.example', 'Spot Check Blog'),
        ])->assertRedirect();

        $site = Site::query()->where('domain', 'spot-check.example')->firstOrFail();

        $this->actingAs($admin)
            ->getJson(route('admin.users.sites', $this->publisher->id))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $site->id,
                'csv_metrics_spot_check' => true,
                'agency_site_import_id' => (int) $site->agency_site_import_id,
            ]);
    }
}
