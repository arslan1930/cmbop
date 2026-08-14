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
            ->assertSee('bulk-done-list', false)
            ->assertSee('bulk-done-card', false)
            ->assertSee('data-bulk-done-density', false)
            ->assertSee('Note for publisher', false)
            ->assertSee('bulkDoneDraft:'.$bulk->id.':'.$this->marketer->id, false)
            ->assertSee('sessionStorage', false)
            ->assertSee('bulkDoneDensity', false)
            ->assertSee('restoreDraftIfNeeded', false)
            ->assertSee('categoryWrapper-done'.$bulk->items()->first()->id, false)
            ->getContent();

        $this->assertStringContainsString('Select niches', $html);
        $this->assertStringContainsString('bulk-done-niches-cell', $html);
        $this->assertStringContainsString('No categories found', $html);
        $this->assertStringContainsString('Type to search niches', $html);
        $this->assertStringContainsString("emptyId: 'categoryEmpty-", $html);
        $this->assertStringNotContainsString('table-responsive mb-3', $html);

        // The fixed grid layout now lives in the shared stylesheet, not inline.
        $this->assertStringContainsString('staff-sites.css', $html);
        $this->assertStringContainsString('bulk-done-card-fields', $html);
        $item = $bulk->items()->first();
        $this->assertStringContainsString('form="reject-item-'.$item->id.'"', $html);
        $this->assertStringContainsString('name="reject_item_id"', $html);
        $this->assertStringContainsString('data-item-id="'.$item->id.'"', $html);
        $this->assertStringContainsString('id="done-niches-label-done'.$item->id.'"', $html);
        $this->assertStringContainsString('aria-labelledby="done-niches-label-done'.$item->id.'"', $html);
        $this->assertStringContainsString('data-bulk-reject-note', $html);
        $this->assertStringContainsString('reject_note', $html);
        $this->assertStringContainsString('function restoreRejectNote', $html);
        $this->assertStringContainsString('function isRejectControl', $html);
        $this->assertStringContainsString('data-bulk-done-clear', $html);
        $this->assertStringContainsString('function clearRow', $html);
        $this->assertStringContainsString('function markRequiredField', $html);
        $this->assertStringContainsString('function unmarkFilledField', $html);
        $this->assertStringContainsString('function safeItemId', $html);
        $this->assertStringContainsString('const fields =', $html);
        $this->assertStringNotContainsString('function fields()', $html);
        $this->assertStringContainsString('sealedItemIds', $html);
        $this->assertStringContainsString('doneConfirmOpen', $html);
        $this->assertStringContainsString('collectBulkDraftDeleteReason', $html);
        $this->assertStringContainsString('JSON.stringify({ reason: reason })', $html);
        $this->assertStringContainsString("input: 'textarea'", $html);
        $this->assertStringContainsString('if (!/^\\d+$/.test(id)) return;', $html);
        $this->assertStringContainsString('safeItemId(row.getAttribute(\'data-item-id\'))', $html);
        $this->assertStringContainsString('/^\\d+$/', $html);
        $this->assertStringContainsString("indexOf('[categories]')", $html);
        $this->assertStringContainsString('serverOldItemIds', $html);
        $blade = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString("is_array(old('items'))", $blade);
        $this->assertStringContainsString("is_scalar(\$old['country']", $blade);
        $this->assertStringContainsString('function markRequiredField', $blade);
        $this->assertStringNotContainsString('data-bulk-done-closed', $html);
        $this->assertStringNotContainsString('for="categoryInput-done'.$item->id.'"', $html);
        $this->assertStringContainsString('readStoredDensity', $html);
        $this->assertStringContainsString('sessionStorage.getItem(storageKey)', $html);
        $this->assertStringContainsString('applyDensity(readStoredDensity(), false)', $html);
        $this->assertStringContainsString("addEventListener('pagehide'", $html);
        $this->assertStringNotContainsString('placeholder="Reason"', $html);
        $staffCss = file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('.bulk-done-card', $staffCss);
        $this->assertStringContainsString('.bulk-done-card-head-meta', $staffCss);
        $this->assertStringContainsString('.bulk-done-list.is-compact', $staffCss);
        $this->assertStringContainsString('grid-column: 1 / -1', $staffCss);

        $js = file_get_contents(public_path('js/multi-select.js'));
        $this->assertStringContainsString('multi-select-dropdown--fixed', $js);
        $this->assertStringContainsString('position: \'fixed\'', $js);
        $this->assertStringContainsString('getBoundingClientRect', $js);
        // Selected niches must wrap inside .multi-select-tags (not as loose flex children).
        $this->assertStringContainsString('class="multi-select-tags"', $js);
        // Niche picks must fire a native bubbling change so bulk Done draft autosave hears them.
        $this->assertStringContainsString("dispatchEvent(new Event('change', { bubbles: true }))", $js);
        // Catalog-parity keyboard: Enter adds sole/focused match; Backspace peels last chip.
        $this->assertStringContainsString('selectSoleOrFocused', $js);
        $this->assertStringContainsString("e.key === 'Enter'", $js);
        $this->assertStringContainsString("e.key === 'Backspace'", $js);
        $this->assertStringContainsString('removeLast', $js);
        $this->assertStringContainsString('categories: categories ? categories.value : \'\'', $html);
        $this->assertStringContainsString('multiSelects[itemId].setSelectedItems(nicheValues, nicheValues)', $html);
        $this->assertStringContainsString('Category::catalogPickerNames()', file_get_contents(app_path('Http/Controllers/Admin/BulkSiteRequestController.php')));

        $css = file_get_contents(public_path('assets/css/multi-select.css'));
        $this->assertStringContainsString('.multi-select-dropdown.multi-select-dropdown--fixed', $css);
        $this->assertStringContainsString('flex-wrap: wrap', $css);
        $this->assertStringContainsString('max-height: 4.75rem', $css);
        $this->assertStringContainsString('.multi-select-empty', $css);
        $cssDup = file_get_contents(public_path('assets/css/multi-select.css'));
        $this->assertStringContainsString('.multi-select-dropdown.multi-select-dropdown--fixed', $cssDup);
        $this->assertStringContainsString('max-height: 4.75rem', $cssDup);
    }

    public function test_marketing_layout_sidebar_collapse_uses_shell_tokens(): void
    {
        $layout = file_get_contents(resource_path('views/marketing/layouts/app.blade.php'));
        $this->assertStringContainsString('marketing-shell.css', $layout);
        $this->assertStringContainsString('syncSidebarForViewport', $layout);
        $this->assertStringContainsString('isDesktopNav', $layout);
        $this->assertStringContainsString('class="nav-label"', $layout);
        $this->assertStringNotContainsString('<style>', $layout);
        $this->assertStringNotContainsString('transition: all 0.3s ease-in-out', $layout);

        $shell = file_get_contents(public_path('assets/css/marketing-shell.css'));
        $this->assertStringContainsString('--shell-sidebar-width: 230px', $shell);
        $this->assertStringContainsString('--shell-sidebar-collapsed: 70px', $shell);

        $appShell = file_get_contents(public_path('assets/css/app-shell.css'));
        $this->assertStringContainsString('max-width: var(--shell-sidebar-collapsed)', $appShell);
    }
}
