<?php

namespace Tests\Feature;

use App\Jobs\CaptureSiteScreenshotJob;
use App\Mail\AgencySiteImportSubmitted;
use App\Models\ActivityLog;
use App\Models\AgencySiteImport;
use App\Models\AgencySiteImportFailure;
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
use Illuminate\Support\Facades\Mail;
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

    public function test_submitting_import_notifies_admins_and_exposes_admin_detail(): void
    {
        Mail::fake();
        Bus::fake();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);
        $adminB = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $adminB->roles()->attach($adminRole->id);

        $this->uploadCsv([
            $this->validRow('notify-me.example', 'Notify Blog'),
            $this->validRow('taken-in-file.example', 'Dup A'),
            $this->validRow('taken-in-file.example', 'Dup B'),
        ])->assertRedirect();

        $import = AgencySiteImport::query()->first();
        $this->assertNotNull($import);
        $this->assertSame(AgencySiteImport::STATUS_PARTIAL, $import->status);

        Mail::assertQueued(AgencySiteImportSubmitted::class, 2);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $admin->id,
            'title' => 'Agency CSV import ready for review',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.agency-imports.show', $import))
            ->assertOk()
            ->assertSee('Import #'.$import->id, false)
            ->assertSee('Failed rows', false)
            ->assertSee('CSV metrics — spot-check', false)
            ->assertSee('Duplicate domain in this file', false);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_agency_imports', 1);
    }

    public function test_admin_can_bulk_verify_and_activate_import_sites(): void
    {
        Mail::fake();
        Bus::fake();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->uploadCsv([
            $this->validRow('bulk-a.example', 'Bulk A'),
            $this->validRow('bulk-b.example', 'Bulk B'),
        ])->assertRedirect();

        $import = AgencySiteImport::query()->firstOrFail();
        $ids = Site::query()->where('agency_site_import_id', $import->id)->pluck('id')->all();
        $this->assertCount(2, $ids);

        $this->actingAs($admin)
            ->postJson(route('admin.agency-imports.bulk-action', $import), [
                'action' => 'verify',
                'site_ids' => $ids,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'updated' => 2]);

        foreach ($ids as $id) {
            $this->assertTrue((bool) Site::find($id)->verified);
        }

        $this->actingAs($admin)
            ->postJson(route('admin.agency-imports.bulk-action', $import), [
                'action' => 'activate',
                'site_ids' => $ids,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'updated' => 2]);

        foreach ($ids as $id) {
            $this->assertTrue((bool) Site::find($id)->active);
        }

        $import->refresh();
        $this->assertSame(AgencySiteImport::STATUS_REVIEWED, $import->status);

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_agency_imports', 0);
    }

    public function test_marketing_cannot_bulk_action_agency_import(): void
    {
        Bus::fake();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        // Ensure import exists via publisher upload first.
        $this->uploadCsv([
            $this->validRow('mkt-block.example', 'Marketing Block'),
        ])->assertRedirect();
        $import = AgencySiteImport::query()->firstOrFail();
        $siteId = Site::query()->where('agency_site_import_id', $import->id)->value('id');

        $marketingRole = Role::firstOrCreate(['name' => 'marketing']);
        $marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $marketer->roles()->attach($marketingRole->id);

        $this->actingAs($marketer)
            ->postJson(route('admin.agency-imports.bulk-action', $import), [
                'action' => 'verify',
                'site_ids' => [$siteId],
            ])
            ->assertForbidden();
    }
}
