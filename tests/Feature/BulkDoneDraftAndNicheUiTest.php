<?php

namespace Tests\Feature;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
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
            ->assertSee('data-bulk-reject-row', false)
            ->assertSee('name="rejection_note"', false)
            ->assertSee('bulk-done-table-wrap', false)
            ->assertSee('data-bulk-done-row', false)
            ->assertSee('bulkDoneDraft:'.$bulk->id.':'.$this->marketer->id, false)
            ->assertSee('sessionStorage', false)
            ->assertSee('restoreDraftIfNeeded', false)
            ->assertSee('categoryWrapper-done'.$bulk->items()->first()->id, false)
            ->getContent();

        $this->assertStringContainsString('Select niches', $html);
        $this->assertStringContainsString('bulk-done-niches-cell', $html);
        $this->assertStringContainsString('bulk-done-row__summary', $html);
        $this->assertStringContainsString('bulk-done-row__body', $html);
        $this->assertStringContainsString('data-bulk-clear-row', $html);
        $this->assertStringContainsString('data-bulk-copy-above', $html);
        $this->assertStringContainsString('data-bulk-done-chip', $html);
        $this->assertStringContainsString('function expandBulkDoneRow', $html);
        $this->assertStringContainsString('function clearBulkDoneRow', $html);
        $this->assertStringContainsString('function copyBulkDoneRowFromAbove', $html);
        $this->assertStringContainsString('function updateBulkDoneChip', $html);
        $this->assertStringContainsString('function refreshBulkDoneQuality', $html);
        $this->assertStringContainsString('function focusFirstInvalidDoneField', $html);
        $this->assertStringContainsString('row.open = true', $html);
        $this->assertStringContainsString('[data-bulk-clear-row]', $html);
        $this->assertStringContainsString('[data-bulk-copy-above]', $html);
        $this->assertStringContainsString('data-bulk-quality-warn', $html);
        $this->assertStringContainsString('data-bulk-quality-chip', $html);
        $this->assertStringContainsString('data-min-da="'.Site::GOOD_MIN_DA.'"', $html);
        $this->assertStringContainsString('data-min-dr="'.Site::GOOD_MIN_DR.'"', $html);
        $this->assertStringContainsString('data-min-traffic="'.Site::GOOD_MIN_TRAFFIC.'"', $html);
        $this->assertStringContainsString('Done below this is allowed', $html);
        $this->assertStringContainsString('You can still Done this row', $html);
        $this->assertStringContainsString('No categories found', $html);
        $this->assertStringContainsString('Type to search niches', $html);
        $this->assertStringContainsString("emptyId: 'categoryEmpty-", $html);
        $this->assertStringNotContainsString('table-responsive mb-3', $html);

        $this->assertStringContainsString('staff-sites.css', $html);
        $this->assertStringContainsString('bulk-done-list', $html);
        $this->assertStringContainsString('bulk-request-show', $html);
        $this->assertStringContainsString('bulk-request-layout', $html);
        $this->assertStringContainsString('align-items-start', $html);
        $this->assertStringContainsString('bulk-request-sidebar', $html);
        $this->assertStringContainsString('bulk-request-main', $html);
        $this->assertStringContainsString('bulk-request-done', $html);
        $this->assertStringNotContainsString('max-height: 28rem', $html);
        $mainPos = strpos($html, 'bulk-request-main');
        $donePos = strpos($html, 'bulk-request-done');
        $this->assertNotFalse($mainPos);
        $this->assertNotFalse($donePos);
        $this->assertGreaterThan($mainPos, $donePos);
        $this->assertStringNotContainsString('id="bulkDoneForm"', substr($html, $mainPos, $donePos - $mainPos));
        $staffCss = file_get_contents(public_path('assets/css/staff-sites.css'));
        $this->assertStringContainsString('.bulk-request-show', $staffCss);
        $this->assertStringContainsString('.bulk-request-sidebar', $staffCss);
        $this->assertStringContainsString('.bulk-request-show > .bulk-request-done', $staffCss);
        $this->assertStringContainsString('.bulk-request-show .bulk-history-list', $staffCss);
        $this->assertStringContainsString('align-items: flex-start', $staffCss);
        $this->assertStringContainsString('.bulk-done-panel', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__fields', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__chip.is-empty', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__chip.is-partial', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__chip.is-ready', $staffCss);
        $this->assertStringContainsString('.bulk-done-row__chip.is-below-bar', $staffCss);
        $this->assertStringNotContainsString('table-layout: fixed', $staffCss);

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
        $this->assertStringContainsString('rejected: rejectedIds()', $html);
        $this->assertStringContainsString('rejection_note:', $html);
        $this->assertStringContainsString('applyRejectedState', $html);
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

    public function test_admin_bulk_request_show_defines_below_quality_and_renders(): void
    {
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://admin-below-quality.example',
            'domain' => 'admin-below-quality.example',
            'price' => 40,
        ]);

        $blade = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString('$belowQuality = $metricsFilled', $blade);

        $this->actingAs($admin)
            ->get(route('admin.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('admin-below-quality.example', false)
            ->assertSee('data-bulk-quality-chip', false)
            ->assertSee('data-bulk-quality-warn', false)
            ->assertSee('Below bar', false)
            ->assertDontSee('Undefined variable $belowQuality', false);
    }

    public function test_bulk_notes_reject_non_string_payload(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.notes', $bulk), [
                'admin_notes' => ['injected'],
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors('admin_notes');

        $this->assertNull($bulk->fresh()->admin_notes);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.notes', $bulk), [
                'admin_notes' => 'Keep this note',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Keep this note', $bulk->fresh()->admin_notes);
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

    public function test_done_row_error_prefix_does_not_open_sibling_item_ids(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
        ]);

        $this->insertDoneItem($bulk->id, 1, 'https://first.example', 'first.example', 40);
        $this->insertDoneItem($bulk->id, 2, 'https://prefix.example', 'prefix.example', 50);
        $this->insertDoneItem($bulk->id, 21, 'https://long.example', 'long.example', 60);

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    21 => [
                        'da' => 10,
                    ],
                ],
            ])
            ->assertOk()
            ->assertSee('Finish the boxes first.', false)
            ->getContent();

        $this->assertFalse(
            $this->doneRowIsOpen($html, 'prefix.example'),
            'items.21.country must not mark item 2 as having errors'
        );
        $this->assertTrue(
            $this->doneRowIsOpen($html, 'long.example'),
            'The row that actually has errors should stay expanded'
        );

        $blade = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));
        $this->assertStringContainsString('$itemErrorPrefix = \'items.\'.$item->id.\'.\'', $blade);
        $this->assertStringNotContainsString(
            "str_starts_with((string) \$key, 'items.'.\$item->id)",
            $blade
        );
    }

    public function test_first_empty_done_row_opens_when_an_earlier_row_already_has_input(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 3,
        ]);

        $this->insertDoneItem($bulk->id, 1, 'https://filled.example', 'filled.example', 40);
        $this->insertDoneItem($bulk->id, 2, 'https://empty-first.example', 'empty-first.example', 50);
        $this->insertDoneItem($bulk->id, 3, 'https://empty-later.example', 'empty-later.example', 60);

        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    1 => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 150,
                        'dr' => 35,
                        'traffic' => 15000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertOk()
            ->assertSee('Finish the boxes first.', false)
            ->getContent();

        $this->assertTrue($this->doneRowIsOpen($html, 'filled.example'));
        $this->assertTrue(
            $this->doneRowIsOpen($html, 'empty-first.example'),
            'The first empty row should open so the marketer can keep filling'
        );
        $this->assertFalse(
            $this->doneRowIsOpen($html, 'empty-later.example'),
            'Later empty rows stay collapsed'
        );
        $this->assertStringContainsString('function focusFirstInvalidDoneField', $html);
    }

    public function test_quality_hint_warns_below_the_bar_without_blocking_done(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $this->insertDoneItem($bulk->id, 11, 'https://below-bar.example', 'below-bar.example', 55);

        [$country, $language] = $this->marketplaceCodes();

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    11 => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 10,
                        'dr' => 12,
                        'traffic' => 100,
                    ],
                ],
            ])
            ->assertOk()
            ->assertSee('Finish the boxes first.', false)
            ->getContent();

        $this->assertTrue($this->doneRowIsOpen($html, 'below-bar.example'));
        $row = $this->doneRowHtml($html, 'below-bar.example');
        $this->assertStringContainsString('data-bulk-quality-warn', $row);
        $this->assertStringContainsString('You can still Done this row', $row);
        $this->assertStringContainsString('>Below bar<', $row);
        $this->assertStringNotContainsString('mb-0 d-none', $row);
        $this->assertStringNotContainsString('is-below-bar d-none', $row);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/BulkSiteRequestController.php'));
        $this->assertStringNotContainsString('GOOD_MIN_DA', $controller);
        $this->assertStringNotContainsString('hasGoodMetrics', $controller);
    }

    public function test_completed_batch_with_awaiting_details_blocks_and_heals(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 1,
            'completed_at' => now(),
        ]);
        Site::create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Undo Left Awaiting',
            'site_url' => 'https://undo-left-awaiting.example',
            'domain' => 'undo-left-awaiting.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Pending',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Unverify leftover still needs publisher details. ', 3),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->assertTrue($bulk->needsProgressHeal());
        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://should-not-open.example', 'price' => 40],
                    ['url' => 'https://should-not-open-b.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites'))
            ->assertSessionHas(
                'error',
                'Finish your pending sites under Complete details before submitting another bulk request.'
            );

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Waiting on publisher', false);

        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
        $this->assertDatabaseMissing('bulk_site_request_items', ['domain' => 'should-not-open.example']);
    }

    public function test_publisher_submit_heals_stale_awaiting_and_allows_new_bulk(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER,
            'estimated_count' => 1,
        ]);
        Site::create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Stale Awaiting',
            'site_url' => 'https://stale-awaiting-bulk.example',
            'domain' => 'stale-awaiting-bulk.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Stale awaiting bulk leftover description. ', 3),
            'verified' => true,
            'active' => true,
            'onboarding_status' => null,
        ]);

        $this->assertTrue(
            BulkSiteRequest::query()->whereKey($bulk->id)->blockingPublisher()->exists()
        );

        $this->actingAs($this->publisher)
            ->from(route('publisher.websites'))
            ->post(route('publisher.bulk-sites.request'), [
                'sites' => [
                    ['url' => 'https://heal-new-a.example', 'price' => 40],
                    ['url' => 'https://heal-new-b.example', 'price' => 50],
                ],
            ])
            ->assertRedirect(route('publisher.websites', ['status' => 'pending']))
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertDatabaseHas('bulk_site_requests', [
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $this->assertDatabaseHas('bulk_site_request_items', ['domain' => 'heal-new-a.example']);
    }

    private function marketplaceCodes(): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        return [strtolower((string) $country->code), strtolower((string) $language->code)];
    }

    private function insertDoneItem(int $bulkId, int $id, string $url, string $domain, float $price): void
    {
        $item = new BulkSiteRequestItem([
            'bulk_site_request_id' => $bulkId,
            'site_url' => $url,
            'domain' => $domain,
            'price' => $price,
        ]);
        $item->id = $id;
        $item->save();
    }

    private function doneRowHtml(string $html, string $domain): string
    {
        preg_match_all(
            '/<details class="bulk-done-row" data-bulk-done-row([^>]*)>(.*?)<\/details>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            if (str_contains($match[2], $domain)) {
                return $match[0];
            }
        }

        $this->fail('Done row for '.$domain.' was not rendered');
    }

    private function doneRowIsOpen(string $html, string $domain): bool
    {
        $row = $this->doneRowHtml($html, $domain);

        return (bool) preg_match('/<details class="bulk-done-row" data-bulk-done-row[^>]*\sopen(?:\s|>|$)/', $row);
    }
}
