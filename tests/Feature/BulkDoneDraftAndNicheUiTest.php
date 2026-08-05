<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkDoneDraftAndNicheUiTest extends TestCase
{
    use RefreshDatabase;

    private User $marketer;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);
    }

    public function test_done_page_wires_draft_storage_and_niche_dropdown_hooks(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://draft-ui.example',
            'domain' => 'draft-ui.example',
            'price' => 55,
        ]);

        $category = Category::query()->first();
        $this->assertNotNull($category);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('id="bulkDoneForm"', false)
            ->assertSee('bulk-done-table-wrap', false)
            ->assertSee('bulkDoneDraft:'.$bulk->id.':'.$this->marketer->id, false)
            ->assertSee('sessionStorage', false)
            ->assertSee('restoreDraftIfNeeded', false)
            ->assertSee('categoryWrapper-done'.$bulk->items()->first()->id, false)
            ->getContent();

        $this->assertStringContainsString('Select niches', $html);
        $this->assertStringContainsString('bulk-done-niches-cell', $html);
        $this->assertStringNotContainsString('table-responsive mb-3', $html);

        // The fixed grid layout now lives in the shared stylesheet, not inline.
        $this->assertStringContainsString('staff-sites.css', $html);
        $this->assertStringContainsString('bulk-done-grid', $html);
        $staffCss = file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('.bulk-done-grid', $staffCss);
        $this->assertStringContainsString('table-layout: fixed', $staffCss);

        $js = file_get_contents(public_path('js/multi-select.js'));
        $this->assertStringContainsString('multi-select-dropdown--fixed', $js);
        $this->assertStringContainsString('position: \'fixed\'', $js);
        $this->assertStringContainsString('getBoundingClientRect', $js);
        // Selected niches must wrap inside .multi-select-tags (not as loose flex children).
        $this->assertStringContainsString('class="multi-select-tags"', $js);
        // Niche picks must fire a native bubbling change so bulk Done draft autosave hears them.
        $this->assertStringContainsString("dispatchEvent(new Event('change', { bubbles: true }))", $js);
        $this->assertStringContainsString('categories: categories ? categories.value : \'\'', $html);
        $this->assertStringContainsString('multiSelects[itemId].setSelectedItems(nicheValues, nicheValues)', $html);

        $css = file_get_contents(public_path('assets/css/multi-select.css'));
        $this->assertStringContainsString('.multi-select-dropdown.multi-select-dropdown--fixed', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);
        $this->assertStringContainsString('max-height: 4.75rem', $css);
        $cssDup = file_get_contents(public_path('assets/css/multi-select.css'));
        $this->assertStringContainsString('.multi-select-dropdown.multi-select-dropdown--fixed', $cssDup);
        $this->assertStringContainsString('max-height: 4.75rem', $cssDup);
    }

    public function test_marketing_layout_sidebar_collapse_uses_shell_tokens(): void
    {
        $layout = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));
        $this->assertStringContainsString('--shell-sidebar-width: 230px', $layout);
        $this->assertStringContainsString('--shell-sidebar-collapsed: 70px', $layout);
        $this->assertStringContainsString('max-width: var(--shell-sidebar-collapsed)', $layout);
        $this->assertStringContainsString('syncSidebarForViewport', $layout);
        $this->assertStringContainsString('isDesktopNav', $layout);
        $this->assertStringContainsString('class="nav-label"', $layout);
        $this->assertStringNotContainsString('transition: all 0.3s ease-in-out', $layout);
    }
}
