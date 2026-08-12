<?php

namespace Tests\Feature;

use App\Mail\AgencySiteImportSubmitted;
use App\Models\AgencySiteImport;
use App\Models\Category;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgencyCsvRouteWiringTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $admin;

    private User $marketer;

    private string $categoryName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $marketingRole = Role::firstOrCreate(['name' => 'marketing']);
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->categoryName = Category::query()->value('name') ?: 'Business & Finance';
    }

    public function test_all_agency_csv_named_routes_are_registered(): void
    {
        foreach ([
            'admin.agency-imports.index',
            'admin.agency-imports.show',
            'admin.agency-imports.bulk-action',
            'marketing.agency-imports.index',
            'marketing.agency-imports.show',
            'marketing.agency-imports.bulk-action',
            'publisher.sites.bulk-import',
            'publisher.sites.bulk-template',
            'admin.dashboard.queue-counts',
            'admin.sites.verify',
            'admin.sites.active',
            'marketing.sites.active',
            'admin.sites.index',
            'marketing.sites.index',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing named route: {$name}");
        }
    }

    public function test_admin_and_marketing_agency_import_pages_wire_together(): void
    {
        Bus::fake();
        Mail::fake();

        $import = $this->submitOneImport();

        $this->actingAs($this->admin)
            ->get(route('admin.agency-imports.index'))
            ->assertOk()
            ->assertSee('Agency CSV imports', false)
            ->assertSee(route('admin.agency-imports.show', $import, false), false);

        $adminShow = $this->actingAs($this->admin)
            ->get(route('admin.agency-imports.show', $import))
            ->assertOk()
            ->assertSee('Import #'.$import->id, false)
            ->assertSee(route('admin.agency-imports.index', [], false), false)
            ->assertSee('bulkUrl', false)
            ->assertSee('bulkVerifyBtn', false)
            ->assertSee('needs_review=1', false)
            ->assertSee('publisher='.$import->publisher_id, false);

        $this->assertStringContainsString(
            'agency-imports/'.$import->id.'/bulk-action',
            html_entity_decode(str_replace('\\/', '/', $adminShow->getContent()))
        );

        $this->assertStringContainsString('Agency CSV', $adminShow->getContent());

        $this->actingAs($this->marketer)
            ->get(route('marketing.agency-imports.index'))
            ->assertOk()
            ->assertSee('Agency CSV imports', false)
            ->assertSee(route('marketing.agency-imports.show', $import, false), false);

        $mktShow = $this->actingAs($this->marketer)
            ->get(route('marketing.agency-imports.show', $import))
            ->assertOk()
            ->assertSee('Import #'.$import->id, false)
            ->assertSee(route('marketing.agency-imports.index', [], false), false)
            ->assertSee('needs_review=1', false)
            ->assertSee('publisher='.$import->publisher_id, false);

        // Marketing can browse but must not get bulk-action controls / admin bulk URL.
        $this->assertStringNotContainsString('/admin/agency-imports/'.$import->id.'/bulk-action', $mktShow->getContent());
        $this->assertStringNotContainsString('id="bulkVerifyBtn"', $mktShow->getContent());
        // Marketing show uses staff_route → marketing bulk URL is present only for admins;
        // marketers should not see the bulk script block at all.
        $this->assertStringNotContainsString('bulkUrl', $mktShow->getContent());

        $this->actingAs($this->marketer)
            ->get(route('marketing.dashboard'))
            ->assertOk()
            ->assertSee(route('marketing.agency-imports.index', [], false), false)
            ->assertSee('Agency CSV', false);

        $this->actingAs($this->marketer)
            ->get(route('admin.agency-imports.show', $import))
            ->assertRedirect(route('marketing.agency-imports.show', $import));
    }

    public function test_publisher_bulk_routes_and_admin_notify_deep_link(): void
    {
        Bus::fake();
        Mail::fake();

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.bulk-template'))
            ->assertOk();

        $this->actingAs($this->publisher)
            ->get(route('publisher.sites.index'))
            ->assertOk()
            ->assertSee(route('publisher.sites.bulk-import', [], false), false)
            ->assertSee(route('publisher.sites.bulk-template', [], false), false);

        $import = $this->submitOneImport();

        Mail::assertQueued(AgencySiteImportSubmitted::class, function (AgencySiteImportSubmitted $mail) use ($import) {
            $built = $mail->build();
            $url = $built->viewData['adminUrl'] ?? null;

            return $url === route('admin.agency-imports.show', $import)
                && $mail->hasTo($this->admin->email);
        });

        $note = InAppNotification::query()
            ->where('user_id', $this->admin->id)
            ->where('title', 'Agency CSV import ready for review')
            ->first();
        $this->assertNotNull($note);
        $this->assertSame(route('admin.agency-imports.show', $import, false), $note->action_url);

        $this->actingAs($this->admin)
            ->getJson(route('admin.dashboard.queue-counts'))
            ->assertOk()
            ->assertJsonPath('pending_agency_imports', 1);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.agency-imports.index', [], false), false);
    }

    private function submitOneImport(): AgencySiteImport
    {
        Storage::fake('local');
        $desc = 'High-quality editorial site covering business and technology topics for professional audiences.';
        $csv = implode("\n", [
            'site_name,site_url,example_url,da,dr,traffic,country,language,categories,price,turnaround_time,publication_time,link_type,description,sponsored,partner_material,as_you_prefer',
            'Wire Blog,https://route-wire.example,https://route-wire.example/post,45,40,15000,de,de,"'.$this->categoryName.'",120,3days,permanent,dofollow,"'.$desc.'",0,0,0',
        ])."\n";

        $this->actingAs($this->publisher)
            ->post(route('publisher.sites.bulk-import'), [
                'csv_file' => UploadedFile::fake()->createWithContent('agency-sites.csv', $csv),
            ])
            ->assertRedirect();

        return AgencySiteImport::query()->latest('id')->firstOrFail();
    }
}
