<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Country;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyLiveMultiSiteTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

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
    }

    /**
     * @return array<string, mixed>
     */
    private function sitePayload(string $domain, array $overrides = []): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();
        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        return array_merge([
            'siteName' => 'Agency '.$domain,
            'siteUrl' => 'https://'.$domain,
            'exampleUrl' => 'https://'.$domain.'/sample-post',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => strtolower($country->code),
            'language' => strtolower($language->code),
            'categories' => [$category->name],
            'price' => 99,
            'turnaround_time' => '3days',
            'publicationTime' => 'permanent',
            'link_type' => 'dofollow',
            'siteDescription' => str_repeat('Quality editorial site for guest posts. ', 4),
            'site_tag' => 'as_you_prefer',
        ], $overrides);
    }

    public function test_live_bulk_creates_pending_sites(): void
    {
        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.bulk-store'), [
            'sites' => [
                $this->sitePayload('live-bulk-one.example'),
                $this->sitePayload('live-bulk-two.example', [
                    'categories' => [Category::query()->where('name', 'Technology & Gadgets')->value('name') ?: 'Technology & Gadgets'],
                    'dr' => 55,
                ]),
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHas('bulk_import_created', 2);

        $one = Site::where('domain', 'live-bulk-one.example')->first();
        $two = Site::where('domain', 'live-bulk-two.example')->first();

        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertFalse((bool) $one->verified);
        $this->assertFalse((bool) $one->active);
        $this->assertFalse((bool) $two->verified);
        $this->assertFalse((bool) $two->active);
        $this->assertSame(45, (int) $one->dr);
        $this->assertSame(55, (int) $two->dr);
        $this->assertContains('Business & Finance', $one->categories ?? []);
    }

    public function test_live_bulk_rejects_unknown_category(): void
    {
        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.bulk-store'), [
            'sites' => [
                $this->sitePayload('bad-cat.example', [
                    'categories' => ['Technology'],
                ]),
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $failures = session('bulk_import_failures');
        $this->assertIsArray($failures);
        $this->assertNotEmpty($failures);
        $this->assertTrue(
            collect($failures)->contains(fn ($f) => collect($f['errors'] ?? [])->contains(fn ($e) => str_contains($e, 'Unknown category')))
        );
        $this->assertNull(Site::where('domain', 'bad-cat.example')->first());
    }

    public function test_live_bulk_rejects_more_than_25_sites(): void
    {
        $sites = [];
        for ($i = 1; $i <= 26; $i++) {
            $sites[] = $this->sitePayload("batch-{$i}.example");
        }

        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.bulk-store'), [
            'sites' => $sites,
        ]);

        $response->assertSessionHasErrors('sites');
        $this->assertSame(0, Site::where('domain', 'like', 'batch-%')->count());
    }

    public function test_live_bulk_duplicate_domain_in_batch_fails_second_row(): void
    {
        $response = $this->actingAs($this->publisher)->post(route('publisher.sites.bulk-store'), [
            'sites' => [
                $this->sitePayload('same-domain.example'),
                $this->sitePayload('same-domain.example', ['siteName' => 'Copy']),
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('bulk_import_created', 1);
        $failures = session('bulk_import_failures');
        $this->assertCount(1, $failures);
        $this->assertSame(1, Site::where('domain', 'same-domain.example')->count());
    }

    public function test_websites_page_shows_live_bulk_form(): void
    {
        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('Add many websites', false)
            ->assertSee('liveBulkForm', false)
            ->assertSee('live-bulk-table', false)
            ->assertSee('liveBulkFill25Btn', false)
            ->assertSee('Fill every column for each site in one table', false)
            ->assertSee('Advanced: CSV import', false)
            ->assertSee(route('publisher.sites.bulk-store'), false)
            ->assertDontSee('Previous sites collapse', false);
    }

    public function test_websites_page_script_is_valid_javascript(): void
    {
        $blade = file_get_contents(resource_path('views/publisher/websites.blade.php'));

        // A bare "for CREATE …" line (missing //) is a SyntaxError that kills the
        // whole page script — Add New Website and Add many websites both break.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*for CREATE/m',
            $blade,
            'websites.blade.php must not contain an un-commented "for CREATE" line'
        );
        $this->assertStringContainsString('// Toggle form for CREATE', $blade);
        $this->assertStringContainsString("$('#addSiteForm').submit", $blade);
        $this->assertStringContainsString("$('#showFormBtn')", $blade);
        $this->assertStringContainsString('liveBulkForm', $blade);

        $this->actingAs($this->publisher)
            ->get(route('publisher.websites'))
            ->assertOk()
            ->assertSee('showFormBtn', false)
            ->assertSee('addSiteForm', false)
            ->assertSee('// Toggle form for CREATE', false);
    }
}
