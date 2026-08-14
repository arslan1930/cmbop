<?php

namespace Tests\Feature;

use App\Mail\BulkSiteRequestItemRejected;
use App\Mail\BulkSitesSeededNotification;
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
use App\Support\MarketingOpsQueues;
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
        $this->assertStringContainsString('data-bulk-advanced-seed', $html);
        $this->assertStringContainsString('Legacy request', $html);
        $this->assertStringContainsString('Seed draft sites?', $html);
    }

    public function test_advanced_seed_is_hidden_when_url_price_rows_exist(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://use-done.example',
            'domain' => 'use-done.example',
            'price' => 40,
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertDontSee('data-bulk-advanced-seed', false)
            ->assertDontSee('Advanced: seed with per-row metrics', false)
            ->assertSee('Done — add sites', false)
            ->assertSee('name="items['.$bulk->items()->first()->id.'][country]"', false);
    }

    public function test_seed_is_rejected_when_url_price_rows_exist(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://no-paste-seed.example',
            'domain' => 'no-paste-seed.example',
            'price' => 40,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => "https://no-paste-seed.example,99,40,45,12000,{$language},{$country},Should Fail",
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('error', 'Use Done for submitted URL + price rows. Advanced Seed is only for legacy requests without that list.');

        $this->assertDatabaseMissing('sites', ['domain' => 'no-paste-seed.example']);
        $this->assertTrue($bulk->items()->first()->isPending());
        Mail::assertNothingOutgoing();
    }

    public function test_closed_legacy_request_hides_seed_and_rejects_post(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();

        $completed = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 2,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $completed))
            ->assertOk()
            ->assertSee('Legacy request', false)
            ->assertDontSee('Use Advanced Seed below', false)
            ->assertDontSee('data-bulk-advanced-seed', false);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $completed))
            ->post(route('marketing.bulk-site-requests.seed', $completed), [
                'rows' => "https://closed-legacy.example,99,40,45,12000,{$country},{$language},Closed",
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $completed))
            ->assertSessionHas('error', 'This request is closed. Advanced Seed is only for open legacy requests.');

        $this->assertDatabaseMissing('sites', ['domain' => 'closed-legacy.example']);
        Mail::assertNothingOutgoing();
    }

    public function test_legacy_seed_validation_error_stays_on_the_seed_box(): void
    {
        $bulk = $this->makeBulkRequest();

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.seed', $bulk), [
                'rows' => '',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors('rows');

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertDontSee('Finish the boxes first.', false)
            ->getContent();

        $this->assertStringContainsString('data-bulk-advanced-seed', $html);
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

    public function test_done_niches_error_marks_the_visible_picker(): void
    {
        [$country, $language] = $this->marketplaceCodes();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://mkt-niches-mark.example',
            'domain' => 'mkt-niches-mark.example',
            'price' => 55,
        ]);

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => '',
                    ],
                ],
            ])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/multi-select-input\s+is-invalid[^>]*id="categoryInput-done'.$item->id.'"/',
            $html
        );
        $this->assertStringContainsString('Finish this field, or clear the row', $html);
        $this->assertDatabaseMissing('sites', ['domain' => 'mkt-niches-mark.example']);
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

    public function test_done_skips_stale_item_ids_and_still_adds_pending_rows(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $already = $this->seedDraft($bulk, 'already-done.example');
        $stale = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $already->site_url,
            'domain' => $already->domain,
            'price' => 40,
            'site_id' => $already->id,
        ]);
        $pending = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://still-pending.example',
            'domain' => 'still-pending.example',
            'price' => 55,
        ]);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $stale->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                    $pending->id => [
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
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sites', ['domain' => 'still-pending.example']);
        $this->assertNotNull($pending->fresh()->site_id);
        $this->assertSame($already->id, (int) $stale->fresh()->site_id);
    }

    public function test_done_all_stale_ids_asks_to_refresh(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $already = $this->seedDraft($bulk, 'all-stale.example');
        $stale = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $already->site_url,
            'domain' => $already->domain,
            'price' => 40,
            'site_id' => $already->id,
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://still-empty.example',
            'domain' => 'still-empty.example',
            'price' => 55,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $stale->id => [
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
            ->assertSessionHas('error', fn ($message) => is_string($message) && str_contains($message, 'Refresh'));

        $this->assertSame($already->id, (int) $stale->fresh()->site_id);
        $this->assertSame(1, Site::query()->where('bulk_site_request_id', $bulk->id)->count());
    }

    public function test_done_non_scalar_fields_do_not_500(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-array-fields.example',
            'domain' => 'done-array-fields.example',
            'price' => 55,
        ]);

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => [$language],
                        'country' => [$country],
                        'da' => [20],
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertOk()
            ->getContent();

        $this->assertTrue($item->fresh()->isPending());
        $this->assertStringContainsString('Finish this field', $html);
        $this->assertStringNotContainsString('TypeError', $html);
    }

    public function test_done_object_category_values_do_not_500(): void
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
            'site_url' => 'https://done-object-cats.example',
            'domain' => 'done-object-cats.example',
            'price' => 55,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => [['Business'], ['Finance']],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($item->fresh()->isPending());
        $this->assertDatabaseMissing('sites', ['domain' => 'done-object-cats.example']);
    }

    public function test_done_domain_already_registered_keeps_the_boxes(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $this->seedDraft($this->makeBulkRequest(), 'taken-host.example');

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://taken-host.example',
            'domain' => 'taken-host.example',
            'price' => 55,
        ]);

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertOk()
            ->getContent();

        $this->assertTrue($item->fresh()->isPending());
        $this->assertStringContainsString('Domain already registered', $html);
        $this->assertStringContainsString('value="'.$country.'"', $html);
        $this->assertStringContainsString('selected', $html);
    }

    public function test_done_unique_race_on_domain_does_not_500_and_keeps_the_boxes(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-unique-race.example',
            'domain' => 'done-unique-race.example',
            'price' => 55,
        ]);

        Site::creating(function (Site $site) {
            static $injected = false;
            if ($injected || $site->domain !== 'done-unique-race.example') {
                return;
            }
            $injected = true;
            Site::withoutEvents(function () use ($site) {
                $winner = $site->replicate();
                $winner->site_name = 'Race winner';
                $winner->save();
            });
        });

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $item->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                ],
            ])
            ->assertOk()
            ->getContent();

        $this->assertTrue($item->fresh()->isPending());
        $this->assertStringContainsString('Domain already registered', $html);
        $this->assertStringContainsString('value="'.$country.'"', $html);
    }

    public function test_done_deletes_orphan_site_when_row_stops_being_pending(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $lost = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-orphan-lost.example',
            'domain' => 'done-orphan-lost.example',
            'price' => 40,
        ]);
        $kept = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-orphan-kept.example',
            'domain' => 'done-orphan-kept.example',
            'price' => 55,
        ]);

        Site::saving(function (Site $site) use ($lost) {
            if ($site->domain !== 'done-orphan-lost.example' || $lost->fresh()?->rejected_at) {
                return;
            }
            $lost->forceFill([
                'rejected_at' => now(),
                'rejected_by' => $lost->id,
                'reject_reason' => 'taken mid-flight',
            ])->save();
        });

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), [
                'items' => [
                    $lost->id => [
                        'language' => $language,
                        'country' => $country,
                        'da' => 20,
                        'dr' => 25,
                        'traffic' => 1000,
                        'categories' => $category->name,
                    ],
                    $kept->id => [
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

        $this->assertDatabaseMissing('sites', ['domain' => 'done-orphan-lost.example']);
        $this->assertDatabaseHas('sites', ['domain' => 'done-orphan-kept.example']);
        $this->assertTrue($lost->fresh()->isRejected());
        $this->assertNotNull($kept->fresh()->site_id);
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
        $this->assertStringContainsString('data-bulk-progress', $html);
        $this->assertStringContainsString('Still to Done', $html);
        $this->assertStringContainsString('Reject this site only. The rest of the batch stays open.', $html);
        $this->assertStringContainsString("title: 'Done — add draft sites?'", $html);
        $this->assertStringContainsString("confirmText: 'Done'", $html);
        $this->assertStringNotContainsString("title: 'Seed draft sites?'", $html);
        $this->assertStringContainsString('This removes the wrong draft.', $html);
        $this->assertStringContainsString('The publisher will see your reason.', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString('Note for publisher', $html);
        $this->assertStringContainsString('data-bulk-done-density', $html);
        $this->assertStringContainsString('function isRejectControl', $html);
        $this->assertStringContainsString('const fields =', $html);
        $this->assertStringNotContainsString('function fields()', $html);
        $this->assertStringContainsString('safeItemId(row.getAttribute(\'data-item-id\'))', $html);
        $this->assertStringContainsString('data-bulk-done-clear', $html);
        $this->assertStringContainsString('function clearRow', $html);
        $this->assertStringContainsString('function markRequiredField', $html);
        $this->assertStringContainsString('function safeItemId', $html);
        $this->assertStringContainsString('or click Clear', $html);
        $controller = file_get_contents(app_path('Http/Controllers/Admin/BulkSiteRequestController.php'));
        $this->assertStringContainsString('function attachCreatedSiteToBulkItem', $controller);
        $this->assertStringContainsString('whereKey($itemId)', $controller);
        $this->assertStringContainsString('UniqueConstraintViolationException', $controller);
        $this->assertStringContainsString('lockForUpdate()', $controller);
        $this->assertStringContainsString('$site->delete()', $controller);
        $this->assertStringContainsString('Could not attach this website. Try again.', $controller);
        $this->assertStringContainsString('cancelledDuringWrite', $controller);
        $this->assertStringContainsString('Those websites were already added or rejected. Refresh and try again.', $controller);
        $this->assertStringContainsString("str_contains(\$itemsError, 'already added')", $controller);
        $this->assertStringContainsString('$skippedStale', $controller);
        $this->assertStringContainsString("return 'cancelled'", $controller);
        $this->assertStringContainsString('Same lock order as Done', $controller);
        $this->assertStringContainsString('sealedItemIds', $html);
        $this->assertStringContainsString('doneConfirmOpen', $html);
        $this->assertStringContainsString('collectBulkDraftDeleteReason', $html);
        $this->assertStringContainsString('JSON.stringify({ reason: reason })', $html);
        $this->assertStringContainsString('bulkDeleteBusy', $html);
        $this->assertStringContainsString('typeof confirmFn.then', $html);
        $this->assertStringContainsString('is_scalar', $controller);
        $this->assertStringContainsString('applyDensity(readStoredDensity(), false)', $html);
        $this->assertStringContainsString('data-bulk-reject-error', $html);
        $this->assertStringContainsString('name="reject_item_id"', $html);
        $this->assertStringContainsString('reject_note', $html);
        $this->assertStringContainsString('function restoreRejectNote', $html);
        $this->assertStringContainsString('id="bulk-cancel-reason"', $html);
        $this->assertStringContainsString('data-bulk-advanced-seed', $html);
        $this->assertStringContainsString('Seed these pasted rows as drafts and notify the publisher?', $html);
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

    public function test_marketer_delete_draft_without_reason_is_rejected(): void
    {
        $site = $this->seedDraft($this->makeBulkRequest(), 'needs-reason.example');

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $site->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_marketer_can_delete_awaiting_details_draft_and_history_remains(): void
    {
        $bulk = $this->makeBulkRequest();
        $site = $this->seedDraft($bulk, 'oops-wrong.example');

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $site->id), [
                'reason' => 'Wrong domain seeded in this batch.',
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

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Deleted pending site')
            ->assertDontSee('>site.deleted_by_marketing<', false)
            ->assertSee('oops-wrong.example');
    }

    public function test_deleting_a_done_draft_returns_the_row_so_it_can_be_doned_again(): void
    {
        Mail::fake();
        [$country, $language] = $this->marketplaceCodes();
        $category = Category::query()->firstOrFail();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://done-then-delete.example',
            'domain' => 'done-then-delete.example',
            'price' => 55,
        ]);

        $payload = [
            'items' => [
                $item->id => [
                    'language' => $language,
                    'country' => $country,
                    'da' => 20,
                    'dr' => 25,
                    'traffic' => 1000,
                    'categories' => $category->name,
                ],
            ],
        ];

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $siteId = (int) $item->fresh()->site_id;
        $this->assertGreaterThan(0, $siteId);

        $this->actingAs($this->marketer)
            ->deleteJson(route('marketing.sites.destroy', $siteId), [
                'reason' => 'Wrong draft added for this URL.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($item->fresh()->isPending());
        $this->assertDatabaseMissing('sites', ['id' => $siteId]);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('name="items['.$item->id.'][country]"', false);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.done', $bulk), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($item->fresh()->site_id);
        $this->assertDatabaseHas('sites', ['domain' => 'done-then-delete.example']);
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
                'reason' => 'Publisher withdrew this pending listing.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
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
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => 'Publisher asked to stop this batch.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.index'));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.cancelled',
            'user_id' => $this->marketer->id,
        ]);
        $cancelled = $bulk->fresh();
        $this->assertSame(BulkSiteRequest::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Publisher asked to stop this batch.', $cancelled->cancel_reason);

        $log = ActivityLog::query()->where('action', 'bulk_request.cancelled')->latest('id')->first();
        $this->assertSame('Publisher asked to stop this batch.', $log?->properties['reason'] ?? null);

        $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('data-bulk-cancel-reason', false)
            ->assertSee('Publisher asked to stop this batch.', false);

        $this->actingAs($this->marketer)
            ->get(route('marketing.history'))
            ->assertOk()
            ->assertSee('Cancelled bulk request', false)
            ->assertSee('Publisher asked to stop this batch.', false);
    }

    public function test_cancelled_request_hides_done_cards(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_CANCELLED,
            'estimated_count' => 1,
            'cancel_reason' => 'Publisher asked to stop this batch.',
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://left-pending.example',
            'domain' => 'left-pending.example',
            'price' => 40,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('data-bulk-done-closed', false)
            ->assertSee('This request is cancelled.', false)
            ->assertDontSee('id="bulkDoneForm"', false)
            ->assertDontSee('name="items['.$bulk->items()->first()->id.'][country]"', false)
            ->getContent();

        $this->assertStringContainsString('Publisher asked to stop this batch.', $html);
        $this->assertTrue($bulk->items()->first()->isPending());
    }

    public function test_cancel_requires_a_reason(): void
    {
        $bulk = $this->makeBulkRequest();
        $bulk->update(['status' => BulkSiteRequest::STATUS_REQUESTED]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.cancel', $bulk), [
                'reason' => '',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors('reason');

        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $bulk->fresh()->status);
        $this->assertNull($bulk->fresh()->cancel_reason);
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

    public function test_marketer_can_reject_one_pending_site(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 2,
        ]);
        $keep = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://keep-this.example',
            'domain' => 'keep-this.example',
            'price' => 40,
        ]);
        $drop = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://drop-this.example',
            'domain' => 'drop-this.example',
            'price' => 55,
        ]);

        $html = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('data-bulk-progress', false)
            ->assertSee('Added 0', false)
            ->assertSee('Rejected 0', false)
            ->assertSee('Still to Done 2', false)
            ->assertSee('Publisher filling 0', false)
            ->assertSee('Ready 0', false)
            ->assertSee('Reject this site only. The rest of the batch stays open.', false)
            ->getContent();

        $this->assertStringContainsString(
            staff_route('bulk-site-requests.items.reject', [$bulk->id, $drop->id], false),
            $html
        );

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.items.reject', [$bulk->id, $drop->id]), [
                'reason' => 'Wrong URL / duplicate listing.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('success');

        $drop->refresh();
        $this->assertNotNull($drop->rejected_at);
        $this->assertSame($this->marketer->id, (int) $drop->rejected_by);
        $this->assertSame('Wrong URL / duplicate listing.', $drop->reject_reason);
        $this->assertNull($drop->site_id);
        $this->assertTrue($keep->fresh()->isPending());
        $this->assertSame(1, $bulk->fresh()->pendingItemsCount());
        $this->assertSame(BulkSiteRequest::STATUS_REQUESTED, $bulk->fresh()->status);
        $this->assertTrue(MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists());

        Mail::assertQueued(BulkSiteRequestItemRejected::class, function ($mail) {
            return $mail->hasTo($this->publisher->email)
                && $mail->item->domain === 'drop-this.example'
                && $mail->reason === 'Wrong URL / duplicate listing.';
        });
        Mail::assertNotQueued(BulkSitesSeededNotification::class);
        $this->assertDatabaseMissing('sites', ['domain' => 'drop-this.example']);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_request.item_rejected',
            'user_id' => $this->marketer->id,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $this->publisher->id,
            'audience' => InAppNotification::AUDIENCE_PUBLISHER,
        ]);
        $bell = InAppNotification::query()->where('user_id', $this->publisher->id)->latest('id')->first();
        $this->assertStringContainsString('drop-this.example', (string) $bell->message);
        $this->assertStringContainsString('Wrong URL / duplicate listing.', (string) $bell->message);

        $after = $this->actingAs($this->marketer)
            ->get(route('marketing.bulk-site-requests.show', $bulk))
            ->assertOk()
            ->assertSee('Rejected 1', false)
            ->assertSee('Still to Done 1', false)
            ->assertSee('https://drop-this.example', false)
            ->assertSee('Wrong URL / duplicate listing.', false)
            ->assertSee('Rejected site from bulk', false)
            ->getContent();

        $this->assertStringNotContainsString('name="items['.$drop->id.'][country]"', $after);
        $this->assertStringContainsString('name="items['.$keep->id.'][country]"', $after);
        $this->assertStringContainsString('Note for publisher', $html);
        $this->assertStringContainsString('data-bulk-done-density', $html);
        $this->assertStringContainsString('bulk-done-card', $html);
        $this->assertStringNotContainsString('placeholder="Reason"', $html);
    }

    public function test_reject_reason_error_does_not_look_like_a_done_box_error(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://short-note.example',
            'domain' => 'short-note.example',
            'price' => 40,
        ]);

        $other = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://other-pending.example',
            'domain' => 'other-pending.example',
            'price' => 55,
        ]);

        $unscoped = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.items.reject', [$bulk->id, $item->id]), [
                'reason' => 'no',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Add a note for the publisher.', $unscoped);
        $this->assertSame(0, preg_match_all('/id="reject-note-\d+"[^>]*>no</', $unscoped));

        $html = $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->followingRedirects()
            ->post(route('marketing.bulk-site-requests.items.reject', [$bulk->id, $item->id]), [
                'reason' => 'no',
                'reject_item_id' => $item->id,
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Add a note for the publisher.', $html);
        $this->assertStringContainsString('Give a short reason for rejecting this website.', $html);
        $this->assertStringNotContainsString('Finish the boxes first.', $html);
        $this->assertStringNotContainsString('Give a short reason for cancelling this request.', $html);
        $this->assertStringContainsString('name="reject_item_id"', $html);
        $this->assertStringContainsString('id="reject-note-'.$item->id.'"', $html);
        $this->assertSame(1, preg_match_all('/id="reject-note-\d+"[^>]*>no</', $html));
        $this->assertMatchesRegularExpression(
            '/id="reject-note-'.$item->id.'"[^>]*>no</',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/id="reject-note-'.$other->id.'"[^>]*>no</',
            $html
        );
        $this->assertTrue($item->fresh()->isPending());
        $this->assertTrue($other->fresh()->isPending());
    }

    public function test_reject_requires_a_reason_and_skips_added_rows(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://need-reason.example',
            'domain' => 'need-reason.example',
            'price' => 40,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.items.reject', [$bulk->id, $item->id]), [
                'reason' => '',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHasErrors('reason');

        $this->assertTrue($item->fresh()->isPending());

        $draft = $this->seedDraft($bulk, 'already-added.example');
        $added = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => $draft->site_url,
            'domain' => $draft->domain,
            'price' => 40,
            'site_id' => $draft->id,
        ]);

        $this->actingAs($this->marketer)
            ->from(route('marketing.bulk-site-requests.show', $bulk))
            ->post(route('marketing.bulk-site-requests.items.reject', [$bulk->id, $added->id]), [
                'reason' => 'Too late, already drafted.',
            ])
            ->assertRedirect(route('marketing.bulk-site-requests.show', $bulk))
            ->assertSessionHas('error', 'That website is already added or rejected.');

        $this->assertNull($added->fresh()->rejected_at);
    }

    public function test_rejecting_every_pending_row_completes_the_batch(): void
    {
        Mail::fake();

        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_REQUESTED,
            'estimated_count' => 1,
        ]);
        $item = BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://only-row.example',
            'domain' => 'only-row.example',
            'price' => 40,
        ]);

        $this->actingAs($this->marketer)
            ->post(route('marketing.bulk-site-requests.items.reject', [$bulk->id, $item->id]), [
                'reason' => 'Publisher asked to drop this URL.',
            ])
            ->assertRedirect();

        $this->assertSame(BulkSiteRequest::STATUS_COMPLETED, $bulk->fresh()->status);
        $this->assertFalse(MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists());
        $this->assertSame(0, $bulk->fresh()->pendingItemsCount());
        $this->assertSame(1, $bulk->fresh()->rejectedItemsCount());
        $this->assertDatabaseMissing('sites', ['domain' => 'only-row.example']);
    }

    public function test_rejected_rows_are_not_counted_as_pending(): void
    {
        $bulk = BulkSiteRequest::create([
            'publisher_id' => $this->publisher->id,
            'status' => BulkSiteRequest::STATUS_COMPLETED,
            'estimated_count' => 1,
            'completed_at' => now(),
        ]);
        BulkSiteRequestItem::create([
            'bulk_site_request_id' => $bulk->id,
            'site_url' => 'https://already-rejected.example',
            'domain' => 'already-rejected.example',
            'price' => 40,
            'rejected_at' => now(),
            'rejected_by' => $this->marketer->id,
            'reject_reason' => 'Out of niche.',
        ]);

        $this->assertSame(0, $bulk->pendingItemsCount());
        $this->assertSame(1, $bulk->rejectedItemsCount());
        $this->assertFalse($bulk->items()->first()->isPending());
        $this->assertTrue($bulk->items()->first()->isRejected());
        $this->assertFalse(MarketingOpsQueues::bulkWaitingOnMarketer()->whereKey($bulk->id)->exists());
    }
}
