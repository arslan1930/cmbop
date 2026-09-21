<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\AdvertiserAnalyticsService;
use App\Services\AgencySiteImportService;
use App\Services\ContentModeration\ContentModerationService;
use App\Services\InAppNotificationService;
use App\Services\Marketplace\CountryLanguagePairs;
use App\Services\SiteFileVerificationService;
use App\Services\Wallet\WalletOverviewService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebsiteLeftoverErrorHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $publisher->id,
            'site_name' => 'Leftover News Daily',
            'site_url' => 'https://leftover-news.example',
            'domain' => 'leftover-news.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'A publisher site for leftover error tests',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ], $overrides));
    }

    /**
     * @param  TestResponse  $response
     */
    private function assertSafeJsonFailure($response): void
    {
        $response->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);

        $message = (string) ($response->json('message') ?: $response->json('error'));
        $this->assertNotSame('', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('Unknown column', $message);
    }

    public function test_claim_submit_returns_json_when_claims_table_is_gone(): void
    {
        $owner = $this->userWithRole('publisher');
        $claimer = $this->userWithRole('publisher');
        $this->siteFor($owner);

        Schema::dropIfExists('site_claims');

        $this->assertSafeJsonFailure(
            $this->actingAs($claimer)->postJson(route('publisher.sites.claim'), [
                'website_url' => 'https://www.leftover-news.example',
                'website_name' => 'Leftover News Daily',
                'proof_message' => 'I own this domain via registrar account and CMS admin access.',
                'contact_email' => $claimer->email,
            ])
        );
    }

    public function test_admin_records_partial_returns_json_when_sites_table_is_gone(): void
    {
        $admin = $this->userWithRole('admin');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($admin)->getJson(route('admin.sites.records', ['partial' => 1]))
        );
    }

    public function test_marketing_queue_counts_return_json_when_sites_table_is_gone(): void
    {
        $marketer = $this->userWithRole('marketing');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($marketer)->getJson(route('marketing.dashboard.queue-counts'));

        $this->assertSafeJsonFailure($response);
        $response->assertJsonPath('ready_sites', 0)
            ->assertJsonPath('bulk_waiting', 0)
            ->assertJsonPath('sites_waiting_on_publisher', 0)
            ->assertJsonPath('bulk_waiting_on_publisher', 0)
            ->assertJsonPath('my_tasks_today', 0)
            ->assertJsonPath('my_tasks_total', 0);
    }

    public function test_marketing_dashboard_still_renders_when_sites_table_is_gone(): void
    {
        $marketer = $this->userWithRole('marketing');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($marketer)->get(route('marketing.dashboard'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_marketing_dashboard_still_renders_when_activity_logs_table_is_gone(): void
    {
        $marketer = $this->userWithRole('marketing');
        Schema::dropIfExists('activity_logs');

        $response = $this->actingAs($marketer)->get(route('marketing.dashboard'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));

        $this->assertSafeJsonFailure(
            $this->actingAs($marketer)->getJson(route('marketing.dashboard.queue-counts'))
        );
    }

    public function test_order_timeline_returns_json_when_activities_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LEFT-1',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/draft-article',
            'anchor_text' => 'best seo tools',
            'target_url' => 'https://advertiser.example',
            'publisher_status' => 'pending',
        ]);

        Schema::dropIfExists('order_activities');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->getJson(route('notifications.order-timeline', $order->id))
        );
    }

    public function test_content_moderation_scan_returns_json_when_scanner_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->mock(ContentModerationService::class, function ($mock) {
            $mock->shouldReceive('scan')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: scan boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.content-moderation.scan'), [
                'url' => 'https://docs.google.com/document/d/leftover-scan/edit',
            ])
        );
    }

    public function test_publisher_archive_returns_json_when_save_throws(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->failNextSiteUpdate();

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->postJson(route('publisher.sites.archive', $site->id))
        );
    }

    public function test_publisher_unarchive_returns_json_when_save_throws(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, ['archived_at' => now()]);
        $this->failNextSiteUpdate();

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->postJson(route('publisher.sites.unarchive', $site->id))
        );
    }

    private function failNextSiteUpdate(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger used to force a website save failure');
        }

        DB::unprepared(
            'CREATE TRIGGER leftover_fail_site_update BEFORE UPDATE ON sites
             BEGIN SELECT RAISE(ABORT, \'SQLSTATE[HY000]: General error: disk full\'); END'
        );
    }

    public function test_verification_start_returns_json_when_service_throws(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, [
            'verified' => false,
            'active' => false,
            'onboarding_status' => Site::ONBOARDING_READY_FOR_REVIEW,
        ]);

        $this->mock(SiteFileVerificationService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: verify boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->postJson(route('publisher.sites.verification.start', $site->id))
        );
    }

    public function test_save_favorites_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.favorites.save'), [
                'favorites' => [1],
            ])
        );
    }

    public function test_save_blacklist_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.blacklist.save'), [
                'blacklist' => [1],
            ])
        );
    }

    public function test_saved_sites_still_render_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)->get(route('advertiser.saved-sites'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_saved_sites_remove_favorite_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.saved-sites.favorites.remove'), [
                'site_id' => 1,
            ])
        );
    }

    public function test_saved_sites_remove_blacklist_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.saved-sites.blacklist.remove'), [
                'site_id' => 1,
            ])
        );
    }

    public function test_saved_sites_move_to_blacklist_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.saved-sites.move.blacklist'), [
                'site_id' => 1,
            ])
        );
    }

    public function test_saved_sites_move_to_favorites_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.saved-sites.move.favorites'), [
                'site_id' => 1,
            ])
        );
    }

    public function test_catalog_suggest_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->getJson(route('advertiser.catalog.suggest', ['q' => 'news']))
        );
    }

    public function test_catalog_visit_redirects_safely_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)->get(route('advertiser.catalog.visit', 1));

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect(route('advertiser.catalog'));
        $response->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_catalog_reveal_url_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.catalog.reveal-url', 1))
        );
    }

    public function test_catalog_hide_url_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.catalog.hide-url', 1))
        );
    }

    public function test_website_suggestion_returns_json_when_suggestions_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('website_suggestions');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.website-suggestions.store'), [
                'website_name' => 'Leftover Suggest Daily',
                'website_url' => 'https://leftover-suggest.example',
            ])
        );
    }

    public function test_site_rating_returns_json_when_items_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('order_items');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.ratings.store'), [
                'order_item_id' => 1,
                'rating' => 5,
            ])
        );
    }

    public function test_site_rating_batch_returns_json_when_items_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('order_items');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.ratings.batch'), [
                'ratings' => [
                    ['order_item_id' => 1, 'rating' => 5],
                ],
            ])
        );
    }

    public function test_get_cart_returns_json_when_submissions_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('content_submissions');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)
                ->withSession([
                    'cart' => [[
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'quantity' => 1,
                        'language' => 'en',
                    ]],
                ])
                ->getJson(route('advertiser.cart.get'))
        );
    }

    public function test_get_cart_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)
                ->withSession([
                    'cart' => [[
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'quantity' => 1,
                        'language' => 'en',
                    ]],
                ])
                ->getJson(route('advertiser.cart.get'))
        );
    }

    public function test_cart_count_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'language' => 'en',
                    'price' => 80,
                ]],
            ])
            ->getJson(route('advertiser.cart.count'));

        $this->assertSafeJsonFailure($response);
        $response->assertJsonPath('count', 0)
            ->assertJsonPath('cart_total', 0);
    }

    public function test_assign_cart_article_returns_json_when_submissions_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('content_submissions');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)
                ->withSession([
                    'cart' => [[
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'quantity' => 1,
                        'language' => 'en',
                    ]],
                ])
                ->postJson(route('advertiser.cart.assign-article'), [
                    'id' => $site->id,
                    'content_submission_id' => 99,
                ])
        );
    }

    public function test_configure_cart_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)
                ->withSession([
                    'cart' => [[
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'quantity' => 1,
                        'language' => 'en',
                    ]],
                ])
                ->postJson(route('advertiser.cart.configure'), [
                    'id' => $site->id,
                    'homepage_days' => 'none',
                    'new_homepage_days' => 7,
                ])
        );
    }

    public function test_add_to_cart_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.cart.add'), ['id' => 1])
        );
    }

    public function test_save_cart_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.cart.save'), [
                'cart' => [['id' => 1, 'quantity' => 1]],
            ])
        );
    }

    public function test_save_empty_cart_stays_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => 1,
                    'name' => 'Leftover News Daily',
                    'quantity' => 1,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.cart.save'), [
                'cart' => [],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart', [])
            ->assertJsonPath('cart_count', 0)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);
        $this->assertSame([], session('cart', []));
    }

    public function test_update_cart_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)
                ->withSession([
                    'cart' => [[
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'quantity' => 1,
                        'language' => 'en',
                    ]],
                ])
                ->postJson(route('advertiser.cart.update'), [
                    'id' => $site->id,
                    'quantity' => 2,
                ])
        );
    }

    public function test_update_last_cart_line_to_zero_stays_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.cart.update'), [
                'id' => $site->id,
                'quantity' => 0,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart', [])
            ->assertJsonPath('cart_count', 0)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);
        $this->assertSame([], session('cart', []));
    }

    public function test_remove_from_cart_stays_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.cart.remove'), ['id' => $site->id]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart', [])
            ->assertJsonPath('cart_count', 0)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);
        $this->assertSame([], session('cart', []));
    }

    public function test_remove_remaining_cart_line_returns_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $keep = $this->siteFor($publisher);
        $drop = $this->siteFor($publisher, [
            'site_name' => 'Leftover Second Daily',
            'site_url' => 'https://leftover-second.example',
            'domain' => 'leftover-second.example',
        ]);

        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)
                ->withSession([
                    'cart' => [
                        [
                            'id' => $keep->id,
                            'name' => $keep->site_name,
                            'quantity' => 1,
                            'language' => 'en',
                        ],
                        [
                            'id' => $drop->id,
                            'name' => $drop->site_name,
                            'quantity' => 1,
                            'language' => 'en',
                        ],
                    ],
                ])
                ->postJson(route('advertiser.cart.remove'), ['id' => $drop->id])
        );
    }

    public function test_clear_cart_stays_json_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'language' => 'en',
                ]],
            ])
            ->postJson(route('advertiser.cart.clear'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart', [])
            ->assertJsonPath('cart_count', 0)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('<html', false);
        $this->assertSame([], session('cart', []));
    }

    public function test_checkout_cancel_does_not_crash_when_orders_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);

        Schema::dropIfExists('orders');

        $response = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'language' => 'en',
                    'price' => 80,
                ]],
            ])
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'LEFTREF1']));

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect(route('advertiser.catalog'));
        $response->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_mark_all_read_returns_json_when_service_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->mock(InAppNotificationService::class, function ($mock) {
            $mock->shouldReceive('markAllRead')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: bell boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('notifications.read-all'))
        );
    }

    public function test_edit_data_returns_json_when_sites_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $siteId = $site->id;

        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('publisher.sites.edit-data', $siteId))
        );
    }

    public function test_bulk_join_returns_json_when_save_throws(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $this->failNextSiteUpdate();

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->postJson(route('publisher.sites.bulk-join', $site->id), [
                'percent' => 15,
            ])
        );
    }

    public function test_problem_report_returns_json_when_table_is_gone(): void
    {
        Schema::dropIfExists('problem_reports');

        $this->assertSafeJsonFailure(
            $this->postJson(route('feedback.problem'), [
                'name' => 'Guest Reporter',
                'email' => 'guest@example.com',
                'subject' => 'Checkout failed after leftover cart',
                'message' => 'The leftover websites disappeared from my cart after I paid.',
            ])
        );
    }

    public function test_publisher_websites_still_renders_when_sites_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($publisher)->get(route('publisher.websites'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_dashboard_still_renders_when_sites_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($publisher)->get(route('publisher.dashboard'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_destroy_redirects_safely_when_sites_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher, ['verified' => false, 'active' => false]);
        $siteId = $site->id;

        Schema::dropIfExists('sites');

        $response = $this->actingAs($publisher)->delete(route('publisher.sites.destroy', $siteId));

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect();
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_bulk_import_redirects_when_service_throws(): void
    {
        $publisher = $this->userWithRole('publisher');

        $this->mock(AgencySiteImportService::class, function ($mock) {
            $mock->shouldReceive('importFromUpload')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: import boom'));
        });

        $response = $this->actingAs($publisher)->post(route('publisher.sites.bulk-import'), [
            'csv_file' => UploadedFile::fake()->create('sites.csv', 20, 'text/csv'),
        ]);

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect();
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_bulk_request_redirects_when_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('bulk_site_requests');

        $response = $this->actingAs($publisher)->post(route('publisher.bulk-sites.request'), [
            'sites' => [
                ['url' => 'https://one-leftover.example', 'price' => 40],
                ['url' => 'https://two-leftover.example', 'price' => 50],
            ],
        ]);

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect(route('publisher.websites'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_claims_index_returns_json_when_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('site_claims');

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('site-claims.index'))
        );
    }

    public function test_publisher_locate_task_returns_json_when_items_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('order_items');

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('publisher.orders.locate', ['order_id' => 1]))
        );
    }

    public function test_publisher_wallet_summary_returns_json_when_wallets_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('wallets');

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('publisher.promotions.wallet'))
        );
    }

    public function test_publisher_country_languages_returns_json_when_lookup_fails(): void
    {
        $publisher = $this->userWithRole('publisher');

        $this->mock(CountryLanguagePairs::class, function ($mock) {
            $mock->shouldReceive('mapWithNames')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: pair boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('publisher.countries.languages', 'us'))
        );
    }

    public function test_content_revision_options_return_json_when_submissions_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site);

        Schema::dropIfExists('content_submissions');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->getJson(route('advertiser.orders.content-revision-options', $order->id))
        );
    }

    public function test_recheck_live_url_returns_json_when_items_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $site = $this->siteFor($publisher);
        $order = $this->paidOrder($advertiser, $site);

        Schema::dropIfExists('order_items');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->postJson(route('advertiser.orders.recheck-live-url', $order->id))
        );
    }

    public function test_advertiser_transactions_return_json_when_overview_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->mock(WalletOverviewService::class, function ($mock) {
            $mock->shouldReceive('activity')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: tx boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->getJson(route('advertiser.balance.transactions'))
        );
    }

    public function test_advertiser_analytics_return_json_when_overview_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->mock(WalletOverviewService::class, function ($mock) {
            $mock->shouldReceive('analytics')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: analytics boom'));
        });

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->getJson(route('advertiser.balance.analytics'))
        );
    }

    public function test_advertiser_export_redirects_when_overview_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->mock(WalletOverviewService::class, function ($mock) {
            $mock->shouldReceive('exportRows')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: export boom'));
        });

        $response = $this->actingAs($advertiser)->get(route('advertiser.balance.export'));

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect(route('advertiser.add-funds'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_profile_update_redirects_when_save_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $this->failNextUserUpdate();

        $response = $this->actingAs($advertiser)
            ->from(route('profile'))
            ->post(route('profile.update'), [
                'name' => 'Leftover Name',
                'phone' => '+123456789',
            ]);

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect(route('profile'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_blog_index_still_renders_when_blogs_table_is_gone(): void
    {
        Schema::dropIfExists('blogs');

        $response = $this->get(route('blog.index'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_blog_show_redirects_when_blogs_table_is_gone(): void
    {
        Schema::dropIfExists('blogs');

        $response = $this->get(route('blog.show', 'leftover-post'));

        $this->assertNotSame(500, $response->status());
        $response->assertRedirect(route('blog.index'));
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_balance_still_renders_when_wallets_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('wallets');

        $response = $this->actingAs($publisher)->get(route('publisher.balance'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_withdraw_still_renders_when_wallets_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('wallets');

        $response = $this->actingAs($publisher)->get(route('publisher.withdraw'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_withdrawal_history_returns_json_when_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('withdrawals');

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('publisher.withdrawals.history'))
        );
    }

    public function test_publisher_content_download_is_safe_when_items_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $submission = ContentSubmission::create([
            'user_id' => $advertiser->id,
            'title' => 'Leftover article',
            'original_filename' => 'leftover.docx',
            'disk' => 'local',
            'path' => 'content-uploads/leftover.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'size_bytes' => 13,
            'moderation_status' => ContentSubmission::STATUS_APPROVED,
        ]);

        Schema::dropIfExists('order_items');

        $response = $this->actingAs($publisher)->get(route('publisher.content.download', $submission));

        $this->assertSame(503, $response->status());
        $response->assertDontSee('SQLSTATE');
    }

    public function test_chat_messages_return_json_when_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        $publisher = $this->userWithRole('publisher');
        $order = $this->paidOrder($advertiser, $this->siteFor($publisher));

        Schema::dropIfExists('order_chat_messages');

        $this->assertSafeJsonFailure(
            $this->actingAs($advertiser)->getJson(route('chat.messages', $order->id))
        );
    }

    public function test_publisher_recent_orders_return_json_when_items_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        $this->siteFor($publisher);
        Schema::dropIfExists('order_items');

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('publisher.dashboard.recent'))
        );
    }

    public function test_catalog_still_renders_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)->get(route('advertiser.catalog'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_catalog_results_still_render_when_sites_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('sites');

        $response = $this->actingAs($advertiser)->get(route('advertiser.catalog.results'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
    }

    public function test_add_funds_still_renders_when_wallets_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('wallets');

        $response = $this->actingAs($advertiser)->get(route('advertiser.add-funds'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_notifications_inbox_still_renders_when_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('in_app_notifications');

        $response = $this->actingAs($advertiser)->get(route('notifications.all'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_analytics_still_renders_when_service_throws(): void
    {
        $advertiser = $this->userWithRole('advertiser');

        $this->mock(AdvertiserAnalyticsService::class, function ($mock) {
            $mock->shouldReceive('build')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: analytics boom'));
        });

        $response = $this->actingAs($advertiser)->get(route('advertiser.analytics'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_advertiser_billing_still_renders_when_invoices_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('invoices');

        $response = $this->actingAs($advertiser)->get(route('advertiser.billing.index'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_billing_still_renders_when_invoices_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        Schema::dropIfExists('invoices');

        $response = $this->actingAs($publisher)->get(route('publisher.billing.index'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_missing_billing_invoice_html_is_leftover_safe(): void
    {
        config(['app.debug' => true]);
        $advertiser = $this->userWithRole('advertiser');

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.show', 999999))
            ->assertNotFound()
            ->assertSee('Page not found', false)
            ->assertDontSee('App\\Models', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('No query results', false);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.billing.show', 999999))
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('App\\Models');
    }

    public function test_advertiser_billing_show_survives_missing_invoices_table(): void
    {
        config(['app.debug' => true]);
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('invoices');

        $html = $this->actingAs($advertiser)->get(route('advertiser.billing.show', 1));
        $this->assertContains($html->status(), [404, 500]);
        $html->assertDontSee('SQLSTATE', false)
            ->assertDontSee('App\\Models', false)
            ->assertDontSee('No query results', false);
        if ($html->status() === 404) {
            $html->assertSee('Page not found', false);
        } else {
            $html->assertSee('Something went wrong', false);
        }

        $json = $this->actingAs($advertiser)->getJson(route('advertiser.billing.show', 1));
        $this->assertContains($json->status(), [404, 503, 500]);
        $json->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_wizard_market_still_renders_when_countries_table_is_gone(): void
    {
        $advertiser = $this->userWithRole('advertiser');
        Schema::dropIfExists('countries');

        $response = $this->actingAs($advertiser)->get(route('advertiser.wizard.market'));

        $this->assertNotSame(500, $response->status());
        $response->assertOk()->assertDontSee('SQLSTATE');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_publisher_weekly_earnings_return_json_when_sites_table_is_gone(): void
    {
        $publisher = $this->userWithRole('publisher');
        $this->siteFor($publisher);
        Schema::dropIfExists('sites');

        $this->assertSafeJsonFailure(
            $this->actingAs($publisher)->getJson(route('publisher.dashboard.weekly-earnings'))
        );
    }

    private function paidOrder(User $advertiser, Site $site): Order
    {
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LEFT-'.uniqid(),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 80,
            'content_link' => 'https://example.com/draft-article',
            'anchor_text' => 'best seo tools',
            'target_url' => 'https://advertiser.example',
            'publisher_status' => 'pending',
            'live_url' => 'https://leftover-news.example/live-post',
        ]);

        return $order;
    }

    private function failNextUserUpdate(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite trigger used to force a profile save failure');
        }

        DB::unprepared(
            'CREATE TRIGGER leftover_fail_user_update BEFORE UPDATE ON users
             BEGIN SELECT RAISE(ABORT, \'SQLSTATE[HY000]: General error: disk full\'); END'
        );
    }
}
