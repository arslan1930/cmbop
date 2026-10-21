<?php

namespace Tests\Feature;

use App\Mail\LinkGuaranteeRestoreMail;
use App\Models\InAppNotification;
use App\Models\LinkCheck;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PublisherReliabilityStat;
use App\Models\Role;
use App\Models\SavedSearch;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CartPricingService;
use App\Services\Marketplace\LinkGuaranteeService;
use App\Services\Marketplace\PublisherReliabilityService;
use App\Services\Marketplace\WritingFeeService;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class MarketplaceCatalogAddOnsTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['content_moderation.enabled' => false]);
        Mail::fake();

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        Role::firstOrCreate(['name' => 'admin']);

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        Wallet::create([
            'user_id' => $this->advertiser->id,
            'role_id' => $advertiserRole->id,
            'balance' => 2000,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function makeSite(string $slug, float $price = 100, array $overrides = []): Site
    {
        return Site::create(array_merge([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'AddOn '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 50,
            'traffic' => 8000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => 'Marketplace add-on listing.',
            'verified' => true,
            'active' => true,
            'writing_enabled' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     */
    private function makePaidOrder(Site $site, array $orderOverrides = [], array $itemOverrides = []): Order
    {
        $paidAt = $orderOverrides['paid_at'] ?? now()->subDays(3);
        $acceptedAt = $paidAt->copy()->addHours(4);
        $doneAt = $acceptedAt->copy()->addHours(20);

        $order = Order::create(array_merge([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => $paidAt,
            'completed_at' => $doneAt,
        ], $orderOverrides));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'publisher_price' => 100,
            'publisher_amount' => 100,
            'platform_fee_amount' => 15,
            'accepted_at' => $acceptedAt,
            'completed_at' => $doneAt,
            'live_url' => 'https://'.$site->domain.'/live-post',
            'live_url_submitted_at' => $doneAt,
            'target_url' => 'https://advertiser.example/offer',
        ], $itemOverrides));

        return $order->fresh(['items']);
    }

    public function test_checkout_page_shows_writing_option_and_guarantee_terms(): void
    {
        $site = $this->makeSite('checkout-terms');
        $sub = $this->createApprovedSubmission($this->advertiser, null, 0, 'terms', 'https://advertiser.example/terms');

        $html = $this->actingAs($this->advertiser)
            ->withSession(['cart' => [[
                'id' => $site->id,
                'name' => $site->site_name,
                'quantity' => 1,
                'content_submission_id' => $sub->id,
            ]]])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('I supply the article', $html);
        $this->assertStringContainsString('Publisher writes the article', $html);
        $this->assertStringContainsString('12-month link guarantee', $html);
        $this->assertStringContainsString('7 days', $html);
        $this->assertStringContainsString('not a ranking', $html);
        $this->assertStringContainsString('12 months or permanent', strtolower($html));
        $this->assertStringNotContainsString('casino', strtolower($html));
    }

    public function test_checkout_hides_guarantee_when_publication_period_is_short(): void
    {
        $site = $this->makeSite('six-month', 100, ['publication_time' => '6months']);
        $sub = $this->createApprovedSubmission($this->advertiser, null, 0, 'short', 'https://advertiser.example/short');

        $html = $this->actingAs($this->advertiser)
            ->withSession(['cart' => [[
                'id' => $site->id,
                'name' => $site->site_name,
                'quantity' => 1,
                'content_submission_id' => $sub->id,
            ]]])
            ->get(route('advertiser.checkout'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="linkGuarantee"', $html);
        $this->assertStringContainsString('publication period is shorter than 12 months', $html);
    }

    public function test_wallet_checkout_ignores_guarantee_on_six_month_listing(): void
    {
        $site = $this->makeSite('no-guarantee', 100, ['publication_time' => '6months']);
        $sub = $this->createApprovedSubmission($this->advertiser, null, 0, 'noguarantee', 'https://advertiser.example/noguarantee');

        $response = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                ]],
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'NOG1',
                'publication_mode' => 'immediate',
                'link_guarantee' => 1,
                'content_source' => [$site->id => WritingFeeService::SOURCE_BUYER],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::query()->where('reference_code', 'NOG1')->firstOrFail();
        $this->assertFalse((bool) $order->link_guarantee);
        $this->assertEquals(0.0, (float) $order->link_guarantee_fee);
    }

    public function test_wallet_checkout_charges_writing_fee_and_guarantee(): void
    {
        $site = $this->makeSite('write-guarantee');
        $priced = app(CartPricingService::class)->priceForAdvertiser($site);
        $writing = app(WritingFeeService::class)->resolvedAdvertiserWritingFee($site);
        $placement = round((float) $priced['total'] + $writing, 2);
        $guarantee = round($placement * 0.10, 2);
        $expected = round($placement + $guarantee, 2);

        $response = $this->actingAs($this->advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                ]],
                'checkout_schedule' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'ADDON1',
                'publication_mode' => 'immediate',
                'link_guarantee' => 1,
                'content_source' => [$site->id => WritingFeeService::SOURCE_PUBLISHER],
                'writing_brief' => [$site->id => [
                    'target_url' => 'https://advertiser.example/offer',
                    'anchor_text' => 'guest tools',
                ]],
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $order = Order::query()->where('reference_code', 'ADDON1')->firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertTrue((bool) $order->link_guarantee);
        $this->assertEquals($guarantee, (float) $order->link_guarantee_fee);
        $this->assertEquals($placement, (float) $order->link_guarantee_covered_amount);
        $this->assertSame('pending', $order->link_guarantee_status);
        $this->assertEquals($expected, (float) $order->total_amount);
        $this->assertSame(WritingFeeService::SOURCE_PUBLISHER, $item->content_source);
        $this->assertEquals($writing, (float) $item->writing_fee);
        $this->assertSame('https://advertiser.example/offer', $item->target_url);
        $this->assertSame('guest tools', $item->anchor_text);
        $this->assertGreaterThan((float) $item->writing_fee, (float) $item->price);
    }

    public function test_saved_search_can_be_listed_and_deleted(): void
    {
        $this->actingAs($this->advertiser)
            ->post(route('advertiser.saved-searches.store'), [
                'name' => 'DE tech DR 40+',
                'notify' => 1,
                'country' => 'de',
                'dr_min' => '40',
                'page' => '3',
            ])
            ->assertRedirect(route('advertiser.saved-searches'));

        $search = SavedSearch::query()->where('user_id', $this->advertiser->id)->firstOrFail();
        $this->assertSame('DE tech DR 40+', $search->name);
        $this->assertTrue($search->notify);
        $this->assertSame('de', $search->catalogQuery()['country'] ?? null);
        $this->assertArrayNotHasKey('page', $search->catalogQuery());

        $this->actingAs($this->advertiser)
            ->get(route('advertiser.saved-searches'))
            ->assertOk()
            ->assertSee('DE tech DR 40+', false)
            ->assertSee('Daily', false);

        $this->actingAs($this->advertiser)
            ->delete(route('advertiser.saved-searches.destroy', $search))
            ->assertRedirect(route('advertiser.saved-searches'));

        $this->assertDatabaseMissing('saved_searches', ['id' => $search->id]);
    }

    public function test_saved_search_cannot_be_deleted_by_another_advertiser(): void
    {
        $search = SavedSearch::query()->create([
            'user_id' => $this->advertiser->id,
            'name' => 'Private search',
            'query_json' => ['country' => 'us'],
            'notify' => false,
        ]);

        $otherRole = Role::firstOrCreate(['name' => 'advertiser']);
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $otherRole->id,
        ]);
        $other->roles()->attach($otherRole->id);

        $this->actingAs($other)
            ->delete(route('advertiser.saved-searches.destroy', $search))
            ->assertForbidden();

        $this->assertDatabaseHas('saved_searches', ['id' => $search->id]);
    }

    public function test_catalog_csv_export_includes_visible_fields_and_omits_url_in_hide_mode(): void
    {
        $site = $this->makeSite('csv-open', 150, [
            'site_name' => 'Csv Open Brand',
            'domain' => 'csv-open.example',
            'site_url' => 'https://csv-open.example',
        ]);

        $open = $this->actingAs($this->advertiser)
            ->get(route('advertiser.catalog.export'))
            ->assertOk();
        $open->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $openCsv = $open->streamedContent();
        $this->assertStringContainsString('site,url,country', $openCsv);
        $this->assertStringContainsString('csv-open.example', $openCsv);
        $this->assertStringNotContainsString('publisher_amount', $openCsv);
        $this->assertStringNotContainsString('markup', strtolower($openCsv));

        $this->advertiser->forceFill([
            'catalog_copy_strike_count' => 2,
            'catalog_hide_until' => now()->addDay(),
        ])->save();

        $hidden = $this->actingAs($this->advertiser->fresh())
            ->get(route('advertiser.catalog.export'))
            ->assertOk()
            ->streamedContent();

        $header = strtolower(strtok($hidden, "\n") ?: '');
        $this->assertStringStartsWith('site,country', $header);
        $this->assertStringNotContainsString('url', $header);
        $this->assertStringNotContainsString('https://csv-open.example', $hidden);
    }

    public function test_reliability_hides_below_sample_and_badges_only_with_thresholds(): void
    {
        $site = $this->makeSite('reliable');
        $service = app(PublisherReliabilityService::class);

        for ($i = 0; $i < 4; $i++) {
            $this->makePaidOrder($site, [
                'order_number' => 'REL-LOW-'.$i,
                'paid_at' => now()->subDays(10 + $i),
            ]);
        }
        $service->refreshPublisher((int) $this->publisher->id);
        PublisherReliabilityStat::forgetRequestCache();
        $hidden = PublisherReliabilityStat::presentationFor((int) $this->publisher->id);
        $this->assertNotNull($hidden);
        $this->assertFalse($hidden['visible']);
        $this->assertFalse($hidden['badge']);
        $this->assertNull($hidden['completed_bucket']);

        $this->makePaidOrder($site, ['order_number' => 'REL-FIVE']);
        $service->refreshPublisher((int) $this->publisher->id);
        PublisherReliabilityStat::forgetRequestCache();
        $visible = PublisherReliabilityStat::presentationFor((int) $this->publisher->id);
        $this->assertTrue($visible['visible']);
        $this->assertFalse($visible['badge']);
        $this->assertSame('5+', $visible['completed_bucket']);
        $this->assertStringNotContainsString('5 completed', json_encode($visible));

        for ($i = 0; $i < 5; $i++) {
            $this->makePaidOrder($site, ['order_number' => 'REL-BADGE-'.$i]);
        }
        $row = $service->refreshPublisher((int) $this->publisher->id);
        $this->assertGreaterThanOrEqual(10, (int) $row->completed_orders);
        PublisherReliabilityStat::forgetRequestCache();
        $badge = PublisherReliabilityStat::presentationFor((int) $this->publisher->id);
        $this->assertTrue($badge['visible']);
        $this->assertTrue($badge['badge']);
        $this->assertSame('10+', $badge['completed_bucket']);
    }

    public function test_link_guarantee_http_error_does_not_start_restore_window(): void
    {
        $site = $this->makeSite('guarantee-error');
        $order = $this->makePaidOrder($site, [
            'link_guarantee' => true,
            'link_guarantee_fee' => 11.5,
            'link_guarantee_covered_amount' => 115,
            'link_guarantee_status' => LinkGuaranteeService::STATUS_ACTIVE,
            'link_guarantee_starts_at' => now()->subMonth(),
            'link_guarantee_ends_at' => now()->addMonths(11),
        ]);

        Http::fake([
            'https://'.$site->domain.'/live-post' => Http::response('server error', 500),
        ]);

        $result = app(LinkGuaranteeService::class)->runDueChecks(10);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['restoreNotified']);
        $this->assertSame(0, $result['refunded']);
        $this->assertSame(LinkGuaranteeService::STATUS_ACTIVE, $order->fresh()->link_guarantee_status);
        $this->assertSame(LinkCheck::STATUS_ERROR, LinkCheck::query()->value('status'));
    }

    public function test_link_guarantee_missing_href_notifies_then_refunds_covered_amount(): void
    {
        $site = $this->makeSite('guarantee-missing');
        $publisherWallet = Wallet::create([
            'user_id' => $this->publisher->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $advertiserWallet = Wallet::query()
            ->where('user_id', $this->advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->firstOrFail();

        $order = $this->makePaidOrder($site, [
            'link_guarantee' => true,
            'link_guarantee_fee' => 11.5,
            'link_guarantee_covered_amount' => 115,
            'link_guarantee_status' => LinkGuaranteeService::STATUS_ACTIVE,
            'link_guarantee_starts_at' => now()->subMonth(),
            'link_guarantee_ends_at' => now()->addMonths(11),
        ]);

        Http::fake([
            'https://'.$site->domain.'/live-post' => Http::response('<html><body><p>gone</p></body></html>', 200),
        ]);

        $first = app(LinkGuaranteeService::class)->runDueChecks(10);
        $this->assertSame(1, $first['restoreNotified']);
        $this->assertSame(0, $first['refunded']);

        $order->refresh();
        $this->assertSame(LinkGuaranteeService::STATUS_RESTORE_DUE, $order->link_guarantee_status);
        Mail::assertQueued(LinkGuaranteeRestoreMail::class);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('title', 'like', 'Restore the live link%')
                ->exists()
        );

        $order->update(['link_guarantee_restore_due_at' => now()->subHour()]);
        $second = app(LinkGuaranteeService::class)->runDueChecks(10);
        $this->assertSame(1, $second['refunded']);

        $order->refresh();
        $this->assertSame(LinkGuaranteeService::STATUS_REFUNDED, $order->link_guarantee_status);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertEquals(2115.0, (float) $advertiserWallet->fresh()->balance);
        $this->assertEquals(0.0, (float) $publisherWallet->fresh()->withdrawableBalance());
        $this->assertSame(LinkCheck::STATUS_REFUNDED, LinkCheck::query()->orderByDesc('id')->value('status'));
    }

    public function test_admin_can_view_link_guarantees(): void
    {
        $site = $this->makeSite('admin-guarantee');
        $this->makePaidOrder($site, [
            'order_number' => 'ORD-ADMIN-G',
            'link_guarantee' => true,
            'link_guarantee_fee' => 11.5,
            'link_guarantee_covered_amount' => 115,
            'link_guarantee_status' => LinkGuaranteeService::STATUS_ACTIVE,
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $this->actingAs($admin)
            ->get(route('admin.link-guarantees.index'))
            ->assertOk()
            ->assertSee('ORD-ADMIN-G', false)
            ->assertSee('Link guarantees', false);
    }

    public function test_catalog_and_email_config_include_new_templates(): void
    {
        $this->assertArrayHasKey('link_guarantee_restore', EmailCatalog::all());
        $this->assertArrayHasKey('saved_search_alert', EmailCatalog::all());
        $this->assertArrayHasKey('article_ready_for_approval', EmailCatalog::all());
        $this->assertArrayHasKey('link_guarantee_restore', config('email_notifications.types'));
        $this->assertArrayHasKey('saved_search_alert', config('email_notifications.types'));
        $this->assertNotNull(EmailCatalog::makeMailable('link_guarantee_restore'));
        $this->assertNotNull(EmailCatalog::makeMailable('saved_search_alert'));
        $this->assertNotNull(EmailCatalog::makeMailable('article_ready_for_approval'));
    }

    public function test_terms_and_refund_pages_state_guarantee_eligibility(): void
    {
        $terms = $this->get(localized_url('terms-of-services'))->assertOk()->getContent();
        $this->assertStringContainsString('Optional 12-month link guarantee', $terms);
        $this->assertStringContainsString('at least 12 months or Permanent', $terms);
        $this->assertStringContainsString('not available on 6-month listings', $terms);
        $this->assertStringContainsString('SEOLinkBuildings', $terms);

        $refund = $this->get(localized_url('refund-policy'))->assertOk()->getContent();
        $this->assertStringContainsString('Optional 12-month link guarantee', $refund);
        $this->assertStringContainsString('never on 6-month listings', $refund);
        $this->assertStringContainsString('SEOLinkBuildings', $refund);
    }

    public function test_scheduler_commands_are_registered(): void
    {
        $this->artisan('orders:check-link-guarantees', ['--limit' => 1])->assertSuccessful();
        $this->artisan('publishers:refresh-reliability-stats')->assertSuccessful();
        $this->artisan('emails:send-saved-search-alerts', ['--limit' => 1])->assertSuccessful();
    }
}
