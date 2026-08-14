<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\Category;
use App\Models\Country;
use App\Models\InAppNotification;
use App\Models\Language;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MarketingBulkSiteOpsTest extends TestCase
{
    use RefreshDatabase;

    private User $publisher;

    private User $marketer;

    private User $admin;

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

        $marketingRole = Role::where('name', 'marketing')->firstOrFail();
        $this->marketer = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $marketingRole->id,
            'name' => 'Marketer Casey',
        ]);
        $this->marketer->roles()->attach($marketingRole->id);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);
    }

    private function marketplaceCodes(): array
    {
        $country = Country::marketplace()->where('code', 'de')->first()
            ?? Country::marketplace()->firstOrFail();
        $language = Language::marketplace()->where('code', 'de')->first()
            ?? Language::marketplace()->firstOrFail();

        return [strtolower($country->code), strtolower($language->code)];
    }

    private function makeBulkRequest(): BulkSiteRequest
    {
        return BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_SHEET_SENT,
            'estimated_count' => 10,
            'sheet_sent_at' => now(),
        ]);
    }

    private function seedDraft(BulkSiteRequest $bulk, string $domain = 'wrong-seed.example'): Site
    {
        [$country, $language] = $this->marketplaceCodes();

        $site = new Site;
        $site->applyMarketplaceListing([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Wrong Seed',
            'site_url' => 'https://'.$domain,
            'domain' => $domain,
            'example_url' => 'https://'.$domain,
            'da' => 10,
            'dr' => 12,
            'traffic' => 1000,
            'metrics_manual' => true,
            'country' => $country,
            'countries' => [$country],
            'language' => $language,
            'languages' => [$language],
            'category' => 'Pending',
            'price' => 50,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Placeholder description text. ', 3),
            'verified' => false,
            'active' => false,
            'as_you_prefer' => true,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);
        $site->save();

        return $site->fresh();
    }

    public function test_marketer_can_seed_and_history_is_logged(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $bulk = $this->makeBulkRequest();

        $rows = "https://seed-mkt.example,99,40,45,12000,{$language},{$country},Seed Mkt";

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.seed', $bulk), ['rows' => $rows])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => is_string($message) && str_starts_with($message, 'Seed —'));

        $this->assertDatabaseHas('sites', [
            'domain' => 'seed-mkt.example',
            'bulk_site_request_id' => $bulk->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.seeded',
            'user_id' => $this->marketer->id,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('Cannot be deleted')
            ->assertSee('<div class="fw-semibold">Seed</div>', false)
            ->assertDontSee('<div class="fw-semibold">Done</div>', false)
            ->assertDontSee('>bulk_request.seeded<', false)
            ->getContent();

        $this->assertStringContainsString('Append-only', $html);
        $this->assertStringContainsString('Done — add sites', $html);
    }

    public function test_marketer_done_rejects_da_or_dr_above_100(): void
    {
        [$country, $language] = $this->marketplaceCodes();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-da-cap.example',
            'domain' => 'mkt-da-cap.example',
            'price' => 55,
        ]);
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 150,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors('items.'.$item->id.'.da');

        $this->assertDatabaseMissing('sites', ['domain' => 'mkt-da-cap.example']);
    }

    public function test_bulk_done_form_clamps_da_dr_in_the_browser(): void
    {
        $html = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));

        $this->assertStringContainsString('function clampScoreInput', $html);
        $this->assertStringContainsString('data-score-clamp="100"', $html);
        $this->assertStringContainsString('data-traffic-input', $html);
        $this->assertStringContainsString('max="4294967295"', $html);
        $this->assertStringContainsString('hasAttribute(\'data-traffic-input\')', $html);
        // Traffic must not share the DA/DR 0–100 score clamp.
        $this->assertDoesNotMatchRegularExpression(
            '/name="items\[\{\{\s*\$item->id\s*\}\}\]\[traffic\]"[^>]*max="100"/',
            $html
        );
    }

    public function test_marketer_done_accepts_billion_scale_traffic(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-traffic-billion.example',
            'domain' => 'mkt-traffic-billion.example',
            'price' => 55,
        ]);
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 40,
                        'dr' => 45,
                        'traffic' => 1_500_000_000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sites', [
            'domain' => 'mkt-traffic-billion.example',
            'traffic' => 1_500_000_000,
        ]);
    }

    public function test_marketer_can_done_one_block_at_a_time(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $itemA = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-block-a.example',
            'domain' => 'mkt-block-a.example',
            'price' => 55,
        ]);
        $itemB = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-block-b.example',
            'domain' => 'mkt-block-b.example',
            'price' => 66,
        ]);
        $category = Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemA->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sites', ['domain' => 'mkt-block-a.example']);
        $this->assertDatabaseMissing('sites', ['domain' => 'mkt-block-b.example']);
        $this->assertNull($itemB->fresh()->site_id);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemB->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 22,
                        'dr' => 28,
                        'traffic' => 2000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sites', ['domain' => 'mkt-block-b.example']);
        $this->assertNotNull($itemB->fresh()->site_id);
    }

    public function test_done_stays_available_when_completed_but_pending_items_remain(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 2,
            'completed_at' => now(),
        ]);

        // Already seeded draft the publisher finished (bulk marked completed).
        $seeded = Site::create([
            'publisher_id' => $this->publisher->id,
            'bulk_site_request_id' => $bulk->id,
            'site_name' => 'Already Seeded',
            'site_url' => 'https://done-stuck-seeded.example',
            'domain' => 'done-stuck-seeded.example',
            'example_url' => 'https://done-stuck-seeded.example/post',
            'da' => 10,
            'dr' => 12,
            'traffic' => 500,
            'country' => $country,
            'language' => $language,
            'category' => $category->name,
            'categories' => [$category->name],
            'price' => 40,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Seeded site description for stuck bulk. ', 3),
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'publisher_accepted_at' => now(),
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $seeded->site_url,
            'domain' => $seeded->domain,
            'price' => 40,
            'site_id' => $seeded->id,
        ]);

        $pending = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-stuck-pending.example',
            'domain' => 'done-stuck-pending.example',
            'price' => 55,
        ]);

        $this->assertTrue($bulk->fresh()->canAddDraftSites());
        $this->assertFalse($bulk->fresh()->isOpen());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('id="bulkDoneSubmit"', false)
            ->assertSee('data-open="1"', false)
            ->getContent();

        $this->assertStringContainsString('done-stuck-pending.example', $html);
        // Opening the page heals completed → seeded when pending rows remain.
        $this->assertSame(BulkSiteRequest::STATUS_SEEDED, $bulk->fresh()->status);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $pending->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 21,
                        'dr' => 22,
                        'traffic' => 1500,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sites', [
            'domain' => 'done-stuck-pending.example',
            'bulk_site_request_id' => $bulk->id,
        ]);
        $this->assertNotNull($pending->fresh()->site_id);
    }

    public function test_marketer_can_done_full_200_site_batch_matching_publisher_limit(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $max = BulkSiteRequest::MAX_SITES_PER_REQUEST;
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => $max,
        ]);

        $now = now();
        $rows = [];
        for ($i = 1; $i <= $max; $i++) {
            $rows[] = [
                'bulk_site_request_id' => $bulk->id,
                'site_url' => "https://mkt-full-{$i}.example",
                'domain' => "mkt-full-{$i}.example",
                'price' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        BulkSiteRequestItem::insert($rows);

        $items = BulkSiteRequestItem::query()
            ->where('bulk_site_request_id', $bulk->id)
            ->orderBy('id')
            ->get();
        $this->assertCount($max, $items);

        $payload = [];
        foreach ($items as $item) {
            $payload[$item->id] = [
                'language' => $language,
                'country' => $country,
                'da' => 30,
                'dr' => 35,
                'traffic' => 15000,
                'categories' => $category->name,
            ];
        }

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($max, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
        $this->assertSame(0, $bulk->items()->whereNull('site_id')->count());
        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
    }

    public function test_bulk_done_form_supports_partial_block_submit_ui(): void
    {
        $html = file_get_contents(resource_path('views/admin/bulk-site-requests/show.blade.php'));

        $this->assertStringContainsString('function completeRows', $html);
        $this->assertStringContainsString('one row, several, or all at once', $html);
        $this->assertStringContainsString('unfinished row(s) will stay pending', $html);
        $this->assertStringContainsString('MAX_SITES_PER_REQUEST', $html);
        $this->assertStringContainsString('-site batch limit', $html);
    }

    public function test_marketer_done_from_items_creates_drafts_and_notifies(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $itemA = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-done-a.example',
            'domain' => 'mkt-done-a.example',
            'price' => 55,
        ]);
        $itemB = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-done-b.example',
            'domain' => 'mkt-done-b.example',
            'price' => 66,
        ]);

        $category = Category::query()->where('name', 'Business & Finance')->first()
            ?? Category::query()->firstOrFail();

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $itemA->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                    $itemB->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 22,
                        'dr' => 28,
                        'traffic' => 2000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => is_string($message) && str_starts_with($message, 'Done —'));

        $this->assertDatabaseHas('sites', [
            'domain' => 'mkt-done-a.example',
            'publisher_id' => $this->publisher->id,
            'onboarding_status' => Site::ONBOARDING_AWAITING_DETAILS,
            'active' => 0,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->publisher->id,
        ]);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('title', 'like', '%Pending sites%')
                ->exists()
        );

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.done',
            'user_id' => $this->marketer->id,
        ]);
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'bulk_request.seeded',
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<div class="fw-semibold">Done</div>', $html);
        $this->assertStringNotContainsString('<div class="fw-semibold">Seed</div>', $html);
        $this->assertStringNotContainsString('>bulk_request.done<', $html);
    }

    public function test_marketer_can_delete_awaiting_details_draft_and_history_remains(): void
    {
        $bulk = $this->makeBulkRequest();
        $site = $this->seedDraft($bulk, 'oops-wrong.example');

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $site->id), [
                'reason' => 'Wrong domain submitted by the publisher.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'site.deleted_by_marketing',
            'user_id' => $this->marketer->id,
        ]);

        $log = ActivityLog::where('action', 'site.deleted_by_marketing')->first();
        $this->assertNotNull($log);
        $this->assertSame($bulk->id, (int) ($log->properties['bulk_site_request_id'] ?? 0));
        $this->assertSame($this->publisher->id, (int) ($log->properties['publisher_id'] ?? 0));
        $this->assertSame('oops-wrong.example', $log->properties['domain'] ?? null);
        $this->assertSame('Wrong domain submitted by the publisher.', $log->properties['reason'] ?? null);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Deleted pending site')
            ->assertDontSee('>site.deleted_by_marketing<', false)
            ->assertSee('oops-wrong.example');
    }

    public function test_marketer_can_delete_ready_for_review_pending_site(): void
    {
        $bulk = $this->makeBulkRequest();
        $site = $this->seedDraft($bulk, 'pending-ready.example');
        $site->update([
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
            'verified' => false,
            'active' => false,
        ]);

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $site->id), [
                'reason' => 'Metrics do not meet the marketplace quality bar.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_marketer_delete_requires_reject_reason(): void
    {
        $bulk = $this->makeBulkRequest();
        $site = $this->seedDraft($bulk, 'needs-reason.example');

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $site->id), [
                'reason' => 'too short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_marketer_cannot_delete_verified_or_active_site(): void
    {
        $bulk = $this->makeBulkRequest();
        $verified = $this->seedDraft($bulk, 'verified-live.example');
        $verified->update([
            'onboarding_status' => null,
            'verified' => true,
            'active' => false,
        ]);
        $active = $this->seedDraft($bulk, 'active-live.example');
        $active->update([
            'onboarding_status' => null,
            'verified' => false,
            'active' => true,
        ]);

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $verified->id))
            ->assertStatus(403);
        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $active->id))
            ->assertStatus(403);

        $this->assertDatabaseHas('sites', ['id' => $verified->id]);
        $this->assertDatabaseHas('sites', ['id' => $active->id]);
    }

    public function test_activity_history_has_no_delete_route(): void
    {
        $hasDelete = collect(Route::getRoutes())->contains(function ($route) {
            $uri = $route->uri();
            $methods = $route->methods();

            return str_contains($uri, 'activity-log')
                && (in_array('DELETE', $methods, true) || in_array('delete', $methods, true));
        });

        $this->assertFalse($hasDelete, 'Activity history must remain immutable (no DELETE route)');
    }

    public function test_sheet_sent_and_cancel_are_logged(): void
    {
        $bulk = $this->makeBulkRequest();
        $bulk->update(['status' => BulkSiteRequest::STATUS_REQUESTED, 'sheet_sent_at' => null]);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.sheet-sent', $bulk))
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.sheet_sent',
            'user_id' => $this->marketer->id,
        ]);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.cancel', $bulk))
            ->assertRedirect(route('marketing.bulk-site-requests.index'));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.cancelled',
            'user_id' => $this->marketer->id,
        ]);
        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $bulk->fresh()->status);
    }

    public function test_sheet_sent_cannot_rewind_a_live_batch(): void
    {
        $bulk = $this->makeBulkRequest();
        $bulk->update(['status' => BulkSiteRequest::STATUS_AWAITING_PUBLISHER]);
        $this->seedDraft($bulk, 'sheet-rewind.example');

        $this->assertFalse($bulk->fresh()->canMarkSheetSent());

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Mark sheet emailed (optional)', $html);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.sheet-sent', $bulk))
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('error', 'Sheet emailed can only be marked before drafts are added.');

        $this->assertSame(BulkSiteRequest::STATUS_AWAITING_PUBLISHER, $bulk->fresh()->status);
    }
}
