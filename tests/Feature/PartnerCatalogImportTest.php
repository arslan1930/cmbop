<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerAutoApproveRule;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerFxRate;
use App\Models\PartnerImportJob;
use App\Models\PartnerPricingRule;
use App\Models\PartnerSiteRateChange;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CartPricingService;
use App\Services\Catalog\CatalogAdvertiserPriceFilter;
use App\Services\Partners\PartnerAccountService;
use App\Services\Partners\PartnerAutoApproveService;
use App\Services\Partners\PartnerCatalogGoLiveService;
use App\Services\Partners\PartnerConfidentiality;
use App\Services\Partners\PartnerImportPipeline;
use App\Services\Partners\PartnerPricingService;
use App\Services\Partners\PartnerRateChangeService;
use App\Services\PlatformFeeService;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartnerCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $publisher;

    private Partner $partner;

    private string $niche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $publisherRole = Role::where('name', 'publisher')->firstOrFail();
        $this->publisher = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->partner = app(PartnerAccountService::class)->mark($this->publisher, $this->admin, [
            'legal_name' => 'PlaCla Test s.r.o.',
            'agreement_signed' => true,
        ]);

        PartnerAutoApproveRule::query()->update(['active' => false]);
        PartnerPricingRule::query()->where('is_default', true)->update(['jitter_percent' => 0]);

        PartnerFxRate::query()->updateOrCreate(
            ['rate_date' => now()->toDateString(), 'quote' => 'CZK'],
            ['rate' => 25, 'source' => 'ECB']
        );
        PartnerFxRate::query()->updateOrCreate(
            ['rate_date' => now()->toDateString(), 'quote' => 'PLN'],
            ['rate' => 4.3, 'source' => 'ECB']
        );

        $this->niche = Category::query()->where('name', 'Technology & Gadgets')->value('name')
            ?: Category::query()->value('name');
    }

    public function test_ac_p1_import_five_hundred_rows_records_every_result_and_leaves_new_items_unused(): void
    {
        $csv = $this->csvHeader()."\n";
        for ($i = 1; $i <= 499; $i++) {
            $csv .= $this->csvRow("site-{$i}.example", 40, 'EUR', $this->niche)."\n";
        }
        $csv .= $this->csvRow('casino-hall.example', 40, 'EUR', 'casino', 'Best online casino bonuses')."\n";

        $started = microtime(true);
        $job = app(PartnerImportPipeline::class)->importUpload($this->partner, 'feed.csv', $csv, [
            'actor' => $this->admin,
            'skip_reachability' => true,
        ]);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(300, $elapsed, 'AC-P1: 500-row import must finish in under 5 minutes');
        $this->assertSame(PartnerImportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(500, $job->rows()->count());
        $this->assertSame(0, $job->rows()->whereNull('result')->count());
        $this->assertSame(499, PartnerCatalogItem::query()->where('partner_id', $this->partner->id)->where('casino', false)->count());
        $this->assertTrue(
            PartnerCatalogItem::query()->where('partner_id', $this->partner->id)->where('casino', false)
                ->get()
                ->every(fn (PartnerCatalogItem $item) => $item->listing_status === PartnerCatalogItem::STATUS_UNUSED)
        );
        $casino = PartnerCatalogItem::query()->where('domain', 'casino-hall.example')->first();
        $this->assertNotNull($casino);
        $this->assertTrue($casino->casino);
        $this->assertSame(PartnerCatalogItem::STATUS_BLOCKED, $casino->listing_status);
        $this->assertSame('casino_not_allowed', $casino->block_reason);
        $this->assertSame(1, (int) ($job->counts['blocked'] ?? 0));
    }

    public function test_casino_row_cannot_go_live(): void
    {
        $job = $this->importCsv($this->csvHeader()."\n".$this->csvRow('spin-casino.example', 40, 'EUR', 'casino', 'Casino news'));
        $item = PartnerCatalogItem::query()->where('domain', 'spin-casino.example')->firstOrFail();
        $this->assertSame(PartnerCatalogItem::STATUS_BLOCKED, $item->listing_status);

        $result = app(PartnerCatalogGoLiveService::class)->goLiveItems([$item->id], $this->admin);
        $this->assertSame([], $result['live']);
        $this->assertSame('blocked', $result['refused'][0]['reason'] ?? null);
        $this->assertNull(Site::query()->where('domain', 'spin-casino.example')->first());
        $this->assertNotNull($job->id);
    }

    public function test_ac_p2_feed_drop_and_disappear_do_not_pause_without_confirmation(): void
    {
        $first = $this->csvHeader()."\n"
            .$this->csvRow('keep-one.example', 40)."\n"
            .$this->csvRow('keep-two.example', 40)."\n"
            .$this->csvRow('drop-me.example', 40)."\n";
        $this->importCsv($first);
        $ids = PartnerCatalogItem::query()->where('partner_id', $this->partner->id)->pluck('id')->all();
        $live = app(PartnerCatalogGoLiveService::class)->goLiveItems($ids, $this->admin);
        $this->assertCount(3, $live['live']);

        $shrunk = $this->csvHeader()."\n".$this->csvRow('keep-one.example', 40)."\n".$this->csvRow('keep-two.example', 40)."\n";
        $dropJob = $this->importCsv($shrunk);
        $this->assertSame(PartnerImportJob::STATUS_HELD, $dropJob->status);
        $this->assertNotEmpty($dropJob->pending_removals);
        $dropped = PartnerCatalogItem::query()->where('domain', 'drop-me.example')->firstOrFail();
        $this->assertSame(PartnerCatalogItem::STATUS_LIVE, $dropped->listing_status);
        $this->assertTrue((bool) Site::query()->find($dropped->site_id)?->active);

        $emptyJob = app(PartnerImportPipeline::class)->importString($this->partner, 'feed_url', $this->csvHeader()."\n", [
            'actor' => $this->admin,
            'skip_reachability' => true,
        ]);
        $this->assertSame(PartnerImportJob::STATUS_HELD, $emptyJob->status);
        $this->assertSame(PartnerCatalogItem::STATUS_LIVE, $dropped->fresh()->listing_status);

        app(PartnerImportPipeline::class)->confirmPendingRemovals($emptyJob, $this->admin);
        $this->assertSame(PartnerCatalogItem::STATUS_PAUSED, $dropped->fresh()->listing_status);
        $this->assertFalse((bool) Site::query()->find($dropped->site_id)?->active);
    }

    public function test_ac_p3_admin_filters_bulk_go_live_and_hard_refusals(): void
    {
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('garden-live.example', 40)."\n");
        $ok = PartnerCatalogItem::query()->where('domain', 'garden-live.example')->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.partners.catalog', ['partner_id' => $this->partner->id, 'q' => 'garden-live']))
            ->assertOk()
            ->assertSee('garden-live.example', false)
            ->assertSee('Partner €', false);

        $this->actingAs($this->admin)->post(route('admin.partners.catalog.bulk'), [
            'action' => 'go_live',
            'ids' => [$ok->id],
        ])->assertRedirect();
        $this->assertSame(PartnerCatalogItem::STATUS_LIVE, $ok->fresh()->listing_status);
        $site = Site::query()->find($ok->fresh()->site_id);
        $this->assertNotNull($site);
        $this->assertSame($this->publisher->id, (int) $site->publisher_id);
        $this->assertEqualsWithDelta(40.0, (float) $site->price, 0.001);
        $this->assertGreaterThan(40.0, (float) $site->slb_price_publish_eur);

        $blocked = PartnerCatalogItem::query()->create($this->itemAttrs([
            'domain' => 'blocked.example',
            'site_url' => 'https://blocked.example',
            'casino' => true,
            'listing_status' => PartnerCatalogItem::STATUS_BLOCKED,
            'block_reason' => 'casino_not_allowed',
        ]));
        $missingPrice = PartnerCatalogItem::query()->create($this->itemAttrs([
            'domain' => 'noprice.example',
            'site_url' => 'https://noprice.example',
            'slb_price_publish_eur' => null,
            'slb_price_overridden' => true,
        ]));
        $thin = PartnerCatalogItem::query()->create($this->itemAttrs([
            'domain' => 'thin-margin.example',
            'site_url' => 'https://thin-margin.example',
            'partner_rate_publish_eur' => 40,
            'slb_price_publish_eur' => 45,
            'slb_price_overridden' => true,
            'below_margin_floor' => true,
        ]));
        $otherPub = User::factory()->create(['email_verified_at' => now()]);
        $otherPub->roles()->attach(Role::where('name', 'publisher')->firstOrFail()->id);
        Site::create([
            'publisher_id' => $otherPub->id,
            'site_name' => 'Taken',
            'site_url' => 'https://taken-domain.example',
            'domain' => 'taken-domain.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'price' => 50,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Taken domain listing. ', 4),
            'verified' => true,
            'active' => true,
        ]);
        $dup = PartnerCatalogItem::query()->create($this->itemAttrs([
            'domain' => 'taken-domain.example',
            'site_url' => 'https://taken-domain.example',
            'duplicate' => true,
        ]));

        $go = app(PartnerCatalogGoLiveService::class);
        $this->assertSame('blocked', $go->goLiveItems([$blocked->id], $this->admin)['refused'][0]['reason']);
        $this->assertSame('missing_slb_price', $go->goLiveItems([$missingPrice->id], $this->admin)['refused'][0]['reason']);
        $this->assertSame('below_margin_floor', $go->goLiveItems([$thin->id], $this->admin)['refused'][0]['reason']);
        $this->assertSame('duplicate', $go->goLiveItems([$dup->id], $this->admin)['refused'][0]['reason']);

        $overridden = $go->goLiveItems([$thin->id], $this->admin, ['override_margin' => true, 'override_reason' => 'owner']);
        $this->assertContains($thin->id, $overridden['live']);
    }

    public function test_ac_p4_rate_rise_below_floor_queues_and_open_orders_stay_frozen(): void
    {
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('rate-change.example', 40)."\n");
        $item = PartnerCatalogItem::query()->where('domain', 'rate-change.example')->firstOrFail();
        app(PartnerCatalogGoLiveService::class)->goLiveItems([$item->id], $this->admin);
        $item = $item->fresh();
        $site = Site::query()->findOrFail($item->site_id);
        $frozenPartner = (float) $item->partner_rate_publish_eur;
        $frozenSlb = (float) $item->slb_price_publish_eur;

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $advertiser->roles()->attach($advertiserRole->id);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'P-1001',
            'reference_code' => 'REF-P1',
            'subtotal' => $frozenSlb,
            'tax' => 0,
            'total_amount' => $frozenSlb,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article',
            'price' => $frozenSlb,
            'publisher_amount' => $frozenPartner,
            'advertiser_eur_amount' => $frozenSlb,
            'publisher_status' => 'pending',
        ]);

        $this->importCsv($this->csvHeader()."\n".$this->csvRow('rate-change.example', 80)."\n");
        $item = $item->fresh();
        $this->assertEqualsWithDelta($frozenPartner, (float) $item->partner_rate_publish_eur, 0.001);
        $change = PartnerSiteRateChange::query()->where('item_id', $item->id)->where('status', PartnerSiteRateChange::STATUS_PENDING)->first();
        $this->assertNotNull($change);

        $this->actingAs($this->admin)
            ->get(route('admin.partners.rate-changes.index'))
            ->assertOk()
            ->assertSee('rate-change.example', false);

        $this->assertEqualsWithDelta($frozenPartner, (float) $orderItem->fresh()->publisherPayoutAmount(), 0.001);
        $this->assertEqualsWithDelta($frozenSlb, (float) $orderItem->fresh()->advertiserEurAmount(), 0.001);

        app(PartnerRateChangeService::class)->approve($change, $this->admin, true);
        $this->assertEqualsWithDelta(80.0, (float) $item->fresh()->partner_rate_publish_eur, 0.001);
        $this->assertEqualsWithDelta($frozenPartner, (float) $orderItem->fresh()->publisherPayoutAmount(), 0.001);
        $this->assertEqualsWithDelta($frozenSlb, (float) $orderItem->fresh()->advertiserEurAmount(), 0.001);
    }

    public function test_czk_converts_via_cached_ecb_rate_and_stores_original(): void
    {
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('koruna.example', 1000, 'CZK')."\n");
        $item = PartnerCatalogItem::query()->where('domain', 'koruna.example')->firstOrFail();
        $this->assertSame('CZK', $item->partner_rate_currency);
        $this->assertEqualsWithDelta(1000.0, (float) $item->partner_rate_publish, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $item->partner_rate_publish_eur, 0.001);
        $this->assertEqualsWithDelta(25.0, (float) $item->fx_rate, 0.001);
    }

    public function test_json_and_xml_feeds_import(): void
    {
        $row = [
            'email' => 'partner@example.com',
            'name' => 'Partner',
            'url' => 'https://json-feed.example',
            'price' => '40',
            'currency' => 'EUR',
            'country' => 'de',
            'language' => 'de',
            'da' => '30',
            'dr' => '40',
            'traffic' => '5000',
            'site_name' => 'JSON Feed',
            'example_url' => 'https://json-feed.example/sample',
            'turnaround' => '5',
            'publication' => 'permanent',
            'link_type' => 'dofollow',
            'tag' => 'never',
            'niches' => $this->niche,
            'description' => str_repeat('JSON feed listing description. ', 4),
        ];
        $jsonJob = app(PartnerImportPipeline::class)->importUpload($this->partner, 'feed.json', json_encode([$row]), [
            'skip_reachability' => true,
        ]);
        $this->assertSame(PartnerImportJob::STATUS_COMPLETED, $jsonJob->status);
        $this->assertNotNull(PartnerCatalogItem::query()->where('domain', 'json-feed.example')->first());

        $xml = '<sites><site>'.implode('', array_map(
            fn ($k, $v) => "<{$k}>".htmlspecialchars((string) $v)."</{$k}>",
            array_keys($row),
            $row
        )).'</site></sites>';
        $xml = str_replace('json-feed.example', 'xml-feed.example', $xml);
        $xmlJob = app(PartnerImportPipeline::class)->importUpload($this->partner, 'feed.xml', $xml, [
            'skip_reachability' => true,
        ]);
        $this->assertNotNull(PartnerCatalogItem::query()->where('domain', 'xml-feed.example')->first());
        $this->assertContains($xmlJob->status, [
            PartnerImportJob::STATUS_COMPLETED,
            PartnerImportJob::STATUS_HELD,
        ]);
    }

    public function test_auto_approve_sends_to_review_and_trusted_may_go_live(): void
    {
        $rule = PartnerAutoApproveRule::query()->create([
            'name' => 'Test DR30',
            'priority' => 1,
            'min_dr' => 30,
            'min_monthly_traffic' => 2000,
            'link_types' => ['dofollow'],
            'require_sample_post' => true,
            'reject_sensitive_only' => true,
            'action' => PartnerAutoApproveRule::ACTION_REVIEW,
            'active' => true,
        ]);
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('auto-review.example', 40, 'EUR', $this->niche, null, 40, 8000)."\n");
        $item = PartnerCatalogItem::query()->where('domain', 'auto-review.example')->firstOrFail();
        $this->assertSame(PartnerCatalogItem::STATUS_IN_REVIEW, $item->listing_status);
        $this->assertSame($rule->id, $item->matched_rule_id);

        $dry = app(PartnerAutoApproveService::class)->dryRun($rule);
        $this->assertIsArray($dry['ids']);

        $this->partner->trusted = true;
        $this->partner->save();
        $rule->action = PartnerAutoApproveRule::ACTION_LIVE;
        $rule->save();
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('auto-live.example', 40, 'EUR', $this->niche, null, 40, 8000)."\n");
        $live = PartnerCatalogItem::query()->where('domain', 'auto-live.example')->firstOrFail();
        $this->assertSame(PartnerCatalogItem::STATUS_LIVE, $live->listing_status);
        $this->assertNotNull($live->site_id);
    }

    public function test_suggested_slb_price_ends_in_nine_and_holds_floor(): void
    {
        $quote = app(PartnerPricingService::class)->quote(40, PartnerPricingRule::defaultRule(), 0);
        $this->assertSame(9, ((int) $quote['amount']) % 10);
        $this->assertTrue(app(PartnerPricingService::class)->holdsFloor($quote['amount'], 40));
        $this->assertGreaterThanOrEqual(25, $quote['amount'] - 40);
    }

    public function test_advertiser_catalog_uses_stored_slb_price_for_partner_sites_only(): void
    {
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('retail-price.example', 40)."\n");
        $item = PartnerCatalogItem::query()->where('domain', 'retail-price.example')->firstOrFail();
        app(PartnerCatalogGoLiveService::class)->goLiveItems([$item->id], $this->admin);
        $partnerSite = Site::query()->findOrFail($item->fresh()->site_id);

        $normal = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Hand listed',
            'site_url' => 'https://hand-listed.example',
            'domain' => 'hand-listed.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 5000,
            'country' => 'de',
            'language' => 'de',
            'price' => 40,
            'listing_currency' => 'EUR',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Hand listed publisher site. ', 4),
            'verified' => true,
            'active' => true,
        ]);

        $cart = app(CartPricingService::class);
        $partnerPay = $cart->priceForAdvertiser($partnerSite);
        $normalPay = $cart->priceForAdvertiser($normal);
        $this->assertEqualsWithDelta((float) $partnerSite->slb_price_publish_eur, $partnerPay['base'], 0.001);
        $this->assertEqualsWithDelta(40.0, $partnerPay['publisher_price'], 0.001);
        $expectedNormal = app(PlatformFeeService::class)->advertiserBase(40);
        $this->assertEqualsWithDelta($expectedNormal, $normalPay['base'], 0.001);
        $this->assertNotEquals($partnerPay['base'], $normalPay['base']);

        $filter = app(CatalogAdvertiserPriceFilter::class);
        $sql = $filter->advertiserFacingSql();
        $rows = Site::query()
            ->select('id')
            ->selectRaw($sql.' as adv')
            ->whereIn('id', [$partnerSite->id, $normal->id])
            ->get()
            ->keyBy('id');
        $this->assertEqualsWithDelta($partnerPay['base'], (float) $rows[$partnerSite->id]->adv, 0.001);
        $this->assertEqualsWithDelta($normalPay['base'], (float) $rows[$normal->id]->adv, 0.001);
        $this->assertEqualsWithDelta($partnerPay['base'], $filter->phpBuyerPay($partnerSite->fresh(), 'EUR'), 0.001);
    }

    public function test_partner_and_publisher_surfaces_never_leak_slb_price(): void
    {
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('secret-price.example', 40)."\n");
        $item = PartnerCatalogItem::query()->where('domain', 'secret-price.example')->firstOrFail();
        app(PartnerCatalogGoLiveService::class)->goLiveItems([$item->id], $this->admin);
        $site = Site::query()->findOrFail($item->fresh()->site_id);
        $slb = (float) $site->getAttribute('slb_price_publish_eur');
        $this->assertGreaterThan(40, $slb);

        $scan = app(PartnerConfidentiality::class);
        $this->assertArrayHasKey('slb_price_publish_eur', $site->getAttributes());
        $this->assertArrayNotHasKey('slb_price_publish_eur', $site->toArray());
        $this->assertArrayNotHasKey('slb_price_publish_eur', $item->fresh()->toArray());
        $this->assertSame([], $scan->scan($site->toArray()));
        $this->assertSame([], $scan->scan($item->fresh()->toArray()));

        $ajax = $this->actingAs($this->publisher)
            ->get(route('publisher.sites.ajax', ['status' => 'all']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('slb_price', $ajax);
        $this->assertStringNotContainsString('commission', strtolower($ajax));
        $this->assertStringNotContainsString('markup', strtolower($ajax));
        $this->assertSame([], $scan->scanAmounts($ajax, [$slb]));
        $this->assertStringContainsString('40', $ajax);

        $edit = $this->actingAs($this->publisher)
            ->getJson(route('publisher.sites.edit-data', $site->id))
            ->assertOk()
            ->json();
        $this->assertSame([], $scan->scan($edit));
        $this->assertEqualsWithDelta(40.0, (float) data_get($edit, 'site.price'), 0.001);
        $encoded = json_encode($edit) ?: '';
        $this->assertSame([], $scan->scanAmounts($encoded, [$slb]));
        $this->assertStringNotContainsString('slb_price', $encoded);
        $this->assertStringNotContainsString('commission', strtolower($encoded));
        $this->assertStringNotContainsString('markup', strtolower($encoded));
    }

    public function test_partner_dashboard_cannot_overwrite_the_imported_rate(): void
    {
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('locked-rate.example', 40)."\n");
        $item = PartnerCatalogItem::query()->where('domain', 'locked-rate.example')->firstOrFail();
        app(PartnerCatalogGoLiveService::class)->goLiveItems([$item->id], $this->admin);
        $site = Site::query()->findOrFail($item->fresh()->site_id);

        $this->actingAs($this->publisher)->put(route('publisher.sites.update', $site->id), [
            'exampleUrl' => $site->example_url,
            'da' => $site->da,
            'dr' => $site->dr,
            'traffic' => $site->traffic,
            'country' => $site->country,
            'language' => $site->language,
            'categories' => $site->categories,
            'price' => 999,
            'turnaround_time' => $site->turnaround_time,
            'publicationTime' => $site->publication_time,
            'link_type' => $site->link_type,
            'siteDescription' => $site->description,
        ]);

        $site = $site->fresh();
        $this->assertEqualsWithDelta(40.0, (float) $site->price, 0.001);
        $this->assertGreaterThan(40.0, (float) $site->getAttribute('slb_price_publish_eur'));
    }

    public function test_normal_publisher_hand_listing_is_unchanged(): void
    {
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => Role::where('name', 'publisher')->value('id'),
        ]);
        $other->roles()->attach(Role::where('name', 'publisher')->firstOrFail()->id);
        $this->assertFalse($other->isSupplyPartner());

        $site = Site::create([
            'publisher_id' => $other->id,
            'site_name' => 'Classic Publisher',
            'site_url' => 'https://classic-publisher.example',
            'domain' => 'classic-publisher.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 3000,
            'country' => 'de',
            'language' => 'de',
            'price' => 120,
            'listing_currency' => 'EUR',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Classic hand listed publisher. ', 4),
            'verified' => true,
            'active' => true,
        ]);
        $this->assertFalse($site->usesPartnerRetailPrice());
        $pay = app(CartPricingService::class)->priceForAdvertiser($site);
        $this->assertEqualsWithDelta(app(PlatformFeeService::class)->advertiserBase(120), $pay['base'], 0.001);
        $this->assertEqualsWithDelta(120.0, $pay['publisher_price'], 0.001);
        $this->assertNull($site->partner_catalog_item_id);
    }

    public function test_admin_partner_screens_render(): void
    {
        $this->actingAs($this->admin)->get(route('admin.partners.index'))->assertOk()->assertSee('Catalog partners', false);
        $this->actingAs($this->admin)->get(route('admin.partners.show', $this->partner))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.partners.imports'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.partners.pricing-rules.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.partners.pricing-rules.preview'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.partners.auto-approve-rules.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.partners.rate-changes.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.publishers.onboard'))->assertOk()->assertSee('Catalog partner', false);
    }

    public function test_failed_feed_fetch_keeps_live_sites(): void
    {
        $this->importCsv($this->csvHeader()."\n".$this->csvRow('stay-live.example', 40)."\n");
        $item = PartnerCatalogItem::query()->where('domain', 'stay-live.example')->firstOrFail();
        app(PartnerCatalogGoLiveService::class)->goLiveItems([$item->id], $this->admin);
        $this->partner->feedConnection()->create([
            'type' => 'feed_url',
            'feed_url' => 'https://feeds.example/missing.csv',
            'status' => 'active',
            'schedule' => 'daily',
        ]);
        Http::fake(fn () => Http::response('nope', 404));
        $job = app(PartnerImportPipeline::class)->importFeed($this->partner, $this->partner->fresh()->feedConnection, [
            'skip_reachability' => true,
        ]);
        $this->assertSame(PartnerImportJob::STATUS_FAILED, $job->status);
        $this->assertSame(PartnerCatalogItem::STATUS_LIVE, $item->fresh()->listing_status);
    }

    private function importCsv(string $csv): PartnerImportJob
    {
        return app(PartnerImportPipeline::class)->importUpload($this->partner, 'feed.csv', $csv, [
            'actor' => $this->admin,
            'skip_reachability' => true,
        ]);
    }

    private function csvHeader(): string
    {
        return 'email,name,url,price,currency,country,language,da,dr,traffic,site_name,example_url,turnaround,publication,link_type,tag,niches,description';
    }

    private function csvRow(
        string $domain,
        float $price,
        string $currency = 'EUR',
        ?string $niche = null,
        ?string $description = null,
        int $dr = 40,
        int $traffic = 5000,
    ): string {
        $niche ??= $this->niche;
        $description ??= str_repeat('Quality editorial site for guest posts. ', 4);
        $fields = [
            'partner@example.com',
            'Partner',
            'https://'.$domain,
            (string) $price,
            $currency,
            'de',
            'de',
            '30',
            (string) $dr,
            (string) $traffic,
            $domain,
            'https://'.$domain.'/sample',
            '5',
            'permanent',
            'dofollow',
            'never',
            $niche,
            $description,
        ];

        return implode(',', array_map(function ($value) {
            return '"'.str_replace('"', '""', $value).'"';
        }, $fields));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemAttrs(array $overrides): array
    {
        return array_merge([
            'partner_id' => $this->partner->id,
            'listing_status' => PartnerCatalogItem::STATUS_UNUSED,
            'site_name' => 'Fixture',
            'description' => str_repeat('Fixture partner catalog item. ', 4),
            'country' => 'de',
            'language' => 'de',
            'niches' => [$this->niche],
            'da' => 30,
            'dr' => 40,
            'traffic' => 5000,
            'turnaround_time' => '5days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'example_url' => 'https://'.$overrides['domain'].'/sample',
            'partner_rate_currency' => 'EUR',
            'partner_rate_publish' => 40,
            'partner_rate_publish_eur' => 40,
            'slb_price_publish_eur' => 79,
            'partner_rate_version' => 1,
        ], $overrides);
    }
}
