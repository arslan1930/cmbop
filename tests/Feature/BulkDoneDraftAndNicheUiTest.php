<?php

namespace Tests\Feature;

use App\Mail\BulkSiteRequestSubmitted;
use App\Mail\BulkSitesSeededNotification;
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
        $this->assertStringContainsString('Keep the session draft until the next successful render', $html);
        $this->assertStringContainsString('Do not restore Delete marks from sessionStorage', $html);
        $this->assertStringNotContainsString('pruneDraftForItemIds(submittedIds)', $html);
        $this->assertStringNotContainsString('(draft.rejected || []).forEach', $html);
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
        $this->assertStringContainsString('overflow-wrap: anywhere', $staffCss);
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

    public function test_notes_and_seed_errors_do_not_show_done_boxes_alert(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://notes-alert.example',
            'domain' => 'notes-alert.example',
            'price' => 40,
        ]);

        $notesHtml = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.notes', $bulk), [
                'admin_notes' => str_repeat('n', 20001),
            ])
            ->assertOk()
            ->assertSee('is-invalid', false)
            ->getContent();

        $this->assertStringNotContainsString('Finish the boxes first.', $notesHtml);
        $this->assertStringContainsString('name="admin_notes"', $notesHtml);

        $seedHtml = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => '',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Finish the boxes first.', $seedHtml);
        $this->assertStringContainsString('$hasDoneFormErrors', file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php')));
    }

    public function test_sheet_sent_does_not_overwrite_admin_notes(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
            'admin_notes' => 'Keep these internal notes',
        ]);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.sheet-sent', $bulk), [
                'admin_notes' => ['wiped'],
            ])
            ->assertRedirect();

        $fresh = $bulk->fresh();
        $this->assertSame(BulkSiteRequest::STATUS_SHEET_SENT, $fresh->status);
        $this->assertSame('Keep these internal notes', $fresh->admin_notes);
    }

    public function test_bulk_index_renders_when_handler_is_missing(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.index'))
            ->assertOk()
            ->assertSee((string) $bulk->id, false)
            ->assertSee($this->publisher->name, false)
            ->assertDontSee('Attempt to read property', false);

        $indexBlade = file_get_contents(resource_path('views/admin/bulk-site-requests/index.blade.php'));
        $this->assertStringContainsString('$req->handler?->name', $indexBlade);
        $this->assertStringContainsString('$req->publisher?->name', $indexBlade);
    }

    public function test_show_does_not_link_javascript_site_urls(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'javascript:alert(1)',
            'domain' => 'xss-bulk.example',
            'price' => 40,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('javascript:alert(1)', false)
            ->getContent();

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString("href='javascript:", $html);
        $this->assertStringContainsString('safe_external_url($item->site_url)', file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php')));
    }

    public function test_done_empty_domain_does_not_attach_sibling_pending_rows(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $empty = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://empty-domain.example',
            'domain' => '',
            'price' => 40,
        ]);
        $sibling = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://keep-pending.example',
            'domain' => 'keep-pending.example',
            'price' => 55,
        ]);

        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $empty->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 40,
                        'dr' => 45,
                        'traffic' => 12000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('seed_failures');

        $this->assertNull($empty->fresh()->site_id);
        $this->assertNull($sibling->fresh()->site_id);
        $this->assertDatabaseMissing('sites', ['domain' => '']);
        $this->assertDatabaseMissing('sites', ['domain' => 'keep-pending.example']);
    }

    public function test_seed_does_not_skip_allowlist_when_pending_domains_are_empty(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://blank-domain.example',
            'domain' => '',
            'price' => 40,
        ]);

        [$country, $language] = $this->marketplaceCodes();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => "https://off-list-blank.example,90,40,45,8000,{$country},{$language},Off List",
            ])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('seed_failures');

        $this->assertDatabaseMissing('sites', ['domain' => 'off-list-blank.example']);
    }

    public function test_done_does_not_attach_www_sibling_pending_row(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $www = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://www.twin-bulk.example',
            'domain' => 'www.twin-bulk.example',
            'price' => 40,
        ]);
        $apex = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://twin-bulk.example',
            'domain' => 'twin-bulk.example',
            'price' => 55,
        ]);

        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $www->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 40,
                        'dr' => 45,
                        'traffic' => 12000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($www->fresh()->site_id);
        $this->assertNull($apex->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame('twin-bulk.example', Site::query()->where('bulk_site_request_id', $bulk->id)->value('domain'));
    }

    public function test_done_rewrites_javascript_site_url_to_https_domain(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'javascript:alert(1)',
            'domain' => 'xss-done.example',
            'price' => 40,
        ]);

        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 40,
                        'dr' => 45,
                        'traffic' => 12000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $site = Site::query()->where('bulk_site_request_id', $bulk->id)->first();
        $this->assertNotNull($site);
        $this->assertSame('xss-done.example', $site->domain);
        $this->assertSame('https://xss-done.example', $site->site_url);
        $this->assertSame('https://xss-done.example', $site->example_url);
        $this->assertSame($site->id, $item->fresh()->site_id);
    }

    public function test_seed_rejects_ftp_and_javascript_urls(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 3,
            'sheet_sent_at' => now(),
        ]);

        [$country, $language] = $this->marketplaceCodes();
        $rows = implode("\n", [
            "javascript:alert(1),99,40,45,12000,{$country},{$language},Js",
            "ftp://ftp-seed.example,99,40,45,12000,{$country},{$language},Ftp",
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), ['rows' => $rows])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('seed_failures');

        $this->assertDatabaseMissing('sites', ['domain' => 'javascript']);
        $this->assertDatabaseMissing('sites', ['domain' => 'ftp']);
        $this->assertDatabaseMissing('sites', ['domain' => 'ftp-seed.example']);
        $this->assertSame(0, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
    }

    public function test_done_occupying_failure_keeps_typed_metrics(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://keep-metrics.example',
            'domain' => 'keep-metrics.example',
            'price' => 40,
        ]);

        $taken = new Site;
        $taken->applyMarketplaceListing([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Already Taken',
            'site_url' => 'https://keep-metrics.example',
            'domain' => 'keep-metrics.example',
            'example_url' => 'https://keep-metrics.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 12000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'News',
            'price' => 40,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Existing listing description. ', 3),
            'verified' => true,
            'active' => true,
        ]);
        $taken->save();

        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 41,
                        'dr' => 46,
                        'traffic' => 13000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionHas('seed_failures')
            ->assertSessionHasInput('items.'.$item->id.'.da', '41')
            ->assertSessionHasInput('items.'.$item->id.'.dr', '46')
            ->assertSessionHasInput('items.'.$item->id.'.traffic', '13000');

        $this->assertNull($item->fresh()->site_id);
        $controller = file_get_contents(app_path('Http/Controllers/Admin/BulkSiteRequestController.php'));
        $this->assertStringContainsString('lockForUpdate()', $controller);
        $this->assertStringContainsString('$abortedCancelled', $controller);
        $this->assertStringContainsString('notArchived()->lockForUpdate()', $controller);
        $this->assertStringContainsString('listingUrlForDomain', $controller);
        $this->assertStringContainsString("->update(['site_id' => \$site->id])", $controller);
        $this->assertStringContainsString('$attached < 1', $controller);
        $this->assertStringContainsString("'rows' => 'required|string|min:3|max:200000'", $controller);
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

    public function test_done_rewrites_mismatched_host_url_to_https_domain(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://evil-host.example/click?to=safe-host.example',
            'domain' => 'safe-host.example',
            'price' => 40,
        ]);

        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 40,
                        'dr' => 45,
                        'traffic' => 12000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $site = Site::query()->where('bulk_site_request_id', $bulk->id)->first();
        $this->assertNotNull($site);
        $this->assertSame('safe-host.example', $site->domain);
        $this->assertSame('https://safe-host.example', $site->site_url);
        $this->assertSame('https://safe-host.example', $site->example_url);
        $this->assertSame($site->id, $item->fresh()->site_id);
    }

    public function test_seed_rejects_oversized_paste(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 3,
            'sheet_sent_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => str_repeat('a', 200001),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rows');

        $this->assertSame(0, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
    }

    public function test_refresh_progress_does_not_uncancel_a_stale_in_memory_request(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 8,
        ]);
        BulkSiteRequest::query()->whereKey($bulk->id)->update([
            'status' => BulkSiteRequest::STATUS_CANCELLED,
        ]);

        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $bulk->status);
        $bulk->refreshProgressStatus();

        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->status);
        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Cancelled', false);

        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);

        $model = file_get_contents(app_path('Models/BulkSiteRequest.php'));
        $this->assertStringContainsString('lockForUpdate()->find($id)', $model);
        $this->assertStringContainsString('applyProgressStatus', $model);
        $this->assertStringContainsString('stale in-memory status', $model);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/BulkSiteRequestController.php'));
        $this->assertStringContainsString('lockForUpdate()->findOrFail($id)', $controller);
        $this->assertStringContainsString('$blocked', $controller);
    }

    public function test_done_does_not_notify_after_a_post_commit_cancel(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/BulkSiteRequestController.php'));
        $this->assertStringContainsString('if (! $fresh || $fresh->isCancelled())', $controller);
        $this->assertStringContainsString('This bulk request is no longer available.', $controller);

        $publisherController = file_get_contents(app_path('Http/Controllers/Publisher/BulkSiteRequestController.php'));
        $this->assertStringContainsString('whereKey($site->id)->lockForUpdate()', $publisherController);
        $this->assertStringContainsString('$freshSite = $site->fresh()', $publisherController);
        $this->assertStringContainsString('$blockedCancelled', $publisherController);

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $bulk->setRelation('publisher', null);

        $seeded = (new BulkSitesSeededNotification($bulk, 1, $this->publisher, ['mail-null-pub.example']))->render();
        $this->assertStringContainsString($this->publisher->name, $seeded);

        $submitted = (new BulkSiteRequestSubmitted($bulk, route('admin.bulk-site-requests.show', $bulk), $this->marketer))->render();
        $this->assertStringContainsString('Unknown', $submitted);
        $this->assertStringContainsString('publisher?->name', file_get_contents(app_path('Mail/BulkSiteRequestSubmitted.php')));
        $this->assertStringContainsString('publisher?->name', file_get_contents(app_path('Mail/BulkSitesSeededNotification.php')));
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
