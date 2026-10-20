<?php

namespace Tests\Feature;

use App\Mail\PartnerThreadMessageMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerAutoApproveRule;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerConnection;
use App\Models\PartnerImportRow;
use App\Models\PartnerLedgerTransaction;
use App\Models\PartnerOrderCorrection;
use App\Models\PartnerOrderDispute;
use App\Models\PartnerPayoutModeChange;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LiveUrlHealthChecker;
use App\Services\Partners\JsonPartnerPullAdapter;
use App\Services\Partners\PartnerAccountService;
use App\Services\Partners\PartnerApiKeyService;
use App\Services\Partners\PartnerApiSerializer;
use App\Services\Partners\PartnerAutoApproveService;
use App\Services\Partners\PartnerChannel;
use App\Services\Partners\PartnerCompleteService;
use App\Services\Partners\PartnerConfidentiality;
use App\Services\Partners\PartnerContactMasker;
use App\Services\Partners\PartnerCorrectionService;
use App\Services\Partners\PartnerInvalidTransitionException;
use App\Services\Partners\PartnerLedgerService;
use App\Services\Partners\PartnerLinkCheckFailedException;
use App\Services\Partners\PartnerLinkMonitorService;
use App\Services\Partners\PartnerOrderStatus;
use App\Services\Partners\PartnerPayoutModeService;
use App\Services\Partners\PartnerPriceFreeze;
use App\Services\Partners\PartnerSettlement;
use App\Services\Partners\PartnerThreadService;
use App\Services\Partners\PartnerWebhookService;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\CountriesTableSeeder;
use Database\Seeders\LanguagesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerFullChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partnerUser;

    private Partner $partner;

    private User $advertiser;

    private Site $site;

    private User $normalPublisher;

    private Site $normalSite;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $this->admin->roles()->attach($adminRole->id);

        $this->partnerUser = User::factory()->create([
            'name' => 'PlaCla Test',
            'email' => 'partner-full@example.com',
            'password' => 'secret-pass-12',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->partnerUser->roles()->attach($publisherRole->id);
        $this->partner = app(PartnerAccountService::class)->mark($this->partnerUser, $this->admin, [
            'legal_name' => 'PlaCla Test s.r.o.',
        ]);

        $this->advertiser = User::factory()->create([
            'name' => 'Secret Buyer LLC',
            'email' => 'secretbuyer@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->site = Site::create([
            'publisher_id' => $this->partnerUser->id,
            'site_name' => 'Garden Today',
            'site_url' => 'https://garden-today.example',
            'domain' => 'garden-today.example',
            'da' => 30,
            'dr' => 32,
            'traffic' => 4000,
            'country' => 'cz',
            'language' => 'cs',
            'countries' => ['cz'],
            'languages' => ['cs'],
            'category' => 'home',
            'price' => 40,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Partner full-channel test site',
            'verified' => true,
            'active' => true,
        ]);

        $this->normalPublisher = User::factory()->create([
            'email' => 'normal-pub@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->normalPublisher->roles()->attach($publisherRole->id);
        $this->normalSite = Site::create([
            'publisher_id' => $this->normalPublisher->id,
            'site_name' => 'Normal Pub',
            'site_url' => 'https://normal-pub.example',
            'domain' => 'normal-pub.example',
            'da' => 20,
            'dr' => 22,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'countries' => ['de'],
            'languages' => ['de'],
            'category' => 'home',
            'price' => 50,
            'publication_time' => '5 days',
            'link_type' => 'dofollow',
            'description' => 'Ordinary publisher site',
            'verified' => true,
            'active' => true,
        ]);

        $this->swap(LiveUrlHealthChecker::class, new class extends LiveUrlHealthChecker
        {
            public function check(string $url): array
            {
                if (str_contains($url, 'bad-link')) {
                    return ['ok' => false, 'status' => 404, 'checked_at' => now(), 'message' => 'Not found'];
                }

                return ['ok' => true, 'status' => 200, 'checked_at' => now(), 'message' => 'OK'];
            }
        });
    }

    private function partnerUrl(string $path = '/'): string
    {
        return 'http://partners.seolinkbuildings.com'.$path;
    }

    private function makeItem(array $overrides = [], ?Site $site = null, ?User $buyer = null): OrderItem
    {
        $site ??= $this->site;
        $buyer ??= $this->advertiser;
        $order = Order::create([
            'user_id' => $buyer->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 127.50,
            'tax' => 0,
            'total_amount' => 127.50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => $overrides['order_status'] ?? 'pending',
            'paid_at' => now(),
        ]);
        unset($overrides['order_status']);

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article',
            'anchor_text' => 'garden tools',
            'target_url' => 'https://shop.example/tools',
            'price' => 127.50,
            'advertiser_eur_amount' => 127.50,
            'publisher_amount' => $site->id === $this->normalSite->id ? 50.00 : 40.00,
            'publisher_currency' => 'EUR',
            'publisher_status' => 'pending',
        ], $overrides));
    }

    public function test_partner_prices_are_frozen_at_order_time(): void
    {
        $item = $this->makeItem();
        $item->refresh();
        $this->assertEquals(40.00, (float) $item->partner_payout_eur);
        $this->assertEquals(127.50, (float) $item->slb_price_eur_internal);
        $item->publisher_amount = 99;
        $item->price = 200;
        $item->save();
        app(PartnerPriceFreeze::class)->freeze($item->fresh());
        $this->assertEquals(40.00, (float) $item->fresh()->partner_payout_eur);
        $this->assertEquals(127.50, (float) $item->fresh()->slb_price_eur_internal);
    }

    public function test_complete_rejects_bad_state_and_bad_link_then_holds_earnings(): void
    {
        $item = $this->makeItem();
        $complete = app(PartnerCompleteService::class);
        try {
            $complete->complete($item, 'https://garden-today.example/post');
            $this->fail('pending order should 409');
        } catch (PartnerInvalidTransitionException) {
            $this->assertTrue(true);
        }

        $item->publisher_status = 'accepted';
        $item->accepted_at = now();
        $item->save();
        $item->order->update(['status' => 'processing']);

        try {
            $complete->complete($item->fresh(), 'https://garden-today.example/bad-link');
            $this->fail('bad link should 422');
        } catch (PartnerLinkCheckFailedException $e) {
            $this->assertSame('accepted', $item->fresh()->publisher_status);
            $this->assertNull($item->fresh()->live_url);
        }

        $result = $complete->complete($item->fresh(), 'https://garden-today.example/live-post');
        $this->assertSame('submitted', $result['status']);
        $this->assertSame('awaiting_approval', $item->fresh()->partner_earnings_status);
        $this->assertNotNull($item->fresh()->hold_until);
        $this->assertSame('held', $item->fresh()->payout_mode_at_submission);

        app(PartnerSettlement::class)->settleOnApproval($item->fresh());
        $this->assertSame('on_hold', $item->fresh()->partner_earnings_status);
        $this->assertSame(4000, app(PartnerLedgerService::class)->pendingMinor($this->partner));
        $this->assertSame(0, app(PartnerLedgerService::class)->availableMinor($this->partner));

        $item->hold_until = now()->subMinute();
        $item->save();
        $this->assertFalse(app(PartnerLedgerService::class)->holdIsReleasable($item->fresh()));
        $item->order->update(['status' => 'completed']);
        app(PartnerLedgerService::class)->releaseHold($item->fresh(), $this->partner);
        $this->assertSame(0, app(PartnerLedgerService::class)->pendingMinor($this->partner));
        $this->assertSame(4000, app(PartnerLedgerService::class)->availableMinor($this->partner));
        $tx = PartnerLedgerTransaction::query()->latest('id')->first();
        $this->assertTrue(app(PartnerLedgerService::class)->transactionIsBalanced($tx));
    }

    public function test_instant_posts_available_and_over_cap_falls_back_to_held(): void
    {
        app(PartnerPayoutModeService::class)->storeKeepLiveAcceptance($this->partner, $this->partnerUser, '127.0.0.1');
        app(PartnerPayoutModeService::class)->switchToInstant($this->partner, $this->admin, 'trusted partner');
        $this->partner->instant_exposure_cap_minor = 5000;
        $this->partner->save();

        $first = $this->makeItem();
        $first->publisher_status = 'accepted';
        $first->accepted_at = now();
        $first->save();
        $first->order->update(['status' => 'processing']);
        app(PartnerCompleteService::class)->complete($first->fresh(), 'https://garden-today.example/one');
        $this->assertSame('available', $first->fresh()->partner_earnings_status);
        $this->assertSame(4000, app(PartnerLedgerService::class)->availableMinor($this->partner));

        $second = $this->makeItem();
        $second->publisher_status = 'accepted';
        $second->accepted_at = now();
        $second->save();
        $second->order->update(['status' => 'processing']);
        app(PartnerCompleteService::class)->complete($second->fresh(), 'https://garden-today.example/two');
        $this->assertSame('held', $second->fresh()->payout_mode_at_submission);
        $this->assertSame('awaiting_approval', $second->fresh()->partner_earnings_status);
    }

    public function test_third_correction_opens_a_dispute_and_second_is_relayed(): void
    {
        $item = $this->makeItem(['order_status' => 'review', 'live_url' => 'https://garden-today.example/live']);
        $item->order->update(['status' => 'review']);
        $svc = app(PartnerCorrectionService::class);
        $first = $svc->buyerRequest($item, 'Please move the link into the first paragraph of the article.');
        $this->assertSame('pending_relay', $first['kind']);
        $this->assertTrue((bool) $item->fresh()->partner_awaiting_relay);
        $this->assertNotSame('yes', $item->fresh()->modification_requested);

        $show = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/orders/'.$item->id));
        $show->assertOk();
        $this->assertStringNotContainsString('Please move the link', $show->getContent());

        $svc->relay($first['correction'], $this->admin);
        $this->assertSame('yes', $item->fresh()->modification_requested);

        $second = $svc->buyerRequest($item->fresh(), 'Please also change the anchor to garden tools plus.');
        $this->assertSame('pending_relay', $second['kind']);
        $third = $svc->buyerRequest($item->fresh(), 'Third change: add another paragraph about shipping.');
        $this->assertSame('dispute', $third['kind']);
        $this->assertSame('open', $item->fresh()->partner_dispute_status);
        $this->assertSame(1, PartnerOrderDispute::query()->where('order_item_id', $item->id)->count());
        $this->assertSame('disputed', PartnerOrderStatus::fromItem($item->fresh()));
    }

    public function test_thread_masks_contact_details_and_emails_use_one_click_links(): void
    {
        $item = $this->makeItem();
        $thread = app(PartnerThreadService::class);
        $held = $thread->post($item, 'buyer', $this->advertiser->id, 'Call me at +420777111222 or secretbuyer@example.com thanks', [
            'hold' => true,
        ]);
        $this->assertTrue($held->masked);
        $this->assertSame('held_for_review', $held->relay_status);
        $this->assertStringContainsString('[email removed]', $held->body_masked);
        $this->assertStringContainsString('[phone removed]', $held->body_masked);

        $hidden = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/orders/'.$item->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('secretbuyer@example.com', $hidden);
        $this->assertStringNotContainsString('+420777111222', $hidden);

        $thread->release($held, $this->admin);
        Mail::assertQueued(PartnerThreadMessageMail::class);

        $html = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/orders/'.$item->id))->assertOk()->getContent();
        $this->assertStringContainsString('[email removed]', $html);
        $this->assertStringNotContainsString('secretbuyer@example.com', $html);
        $this->assertNoConfidentialLeak($html);
        $this->assertSame([], app(PartnerContactMasker::class)->mask('ok text')['rules']);
    }

    public function test_api_orders_sites_and_webhooks_never_leak_slb_price(): void
    {
        $issued = app(PartnerApiKeyService::class)->issue($this->partner, 'test', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $item = $this->makeItem();
        PartnerCatalogItem::query()->create([
            'partner_id' => $this->partner->id,
            'listing_status' => PartnerCatalogItem::STATUS_LIVE,
            'domain' => 'garden-today.example',
            'site_url' => 'https://garden-today.example',
            'site_name' => 'Garden Today',
            'partner_rate_currency' => 'EUR',
            'partner_rate_publish' => 40,
            'partner_rate_publish_eur' => 40,
            'slb_price_publish_eur' => 127.50,
        ]);

        $me = $this->withPartnerKey($issued['plain'])->getJson('/api/partner/v1/me');
        $me->assertOk();
        $this->assertNoConfidentialLeak($me->getContent());

        $orders = $this->withPartnerKey($issued['plain'])->getJson('/api/partner/v1/orders');
        $orders->assertOk();
        $this->assertNoConfidentialLeak($orders->getContent());
        $this->assertStringContainsString('"your_earnings"', $orders->getContent());
        $this->assertStringNotContainsString('127.50', $orders->getContent());
        $this->assertStringNotContainsString('Secret Buyer', $orders->getContent());

        $sites = $this->withPartnerKey($issued['plain'])->getJson('/api/partner/v1/sites');
        $sites->assertOk();
        $this->assertNoConfidentialLeak($sites->getContent());
        $payload = app(PartnerApiSerializer::class)->order($this->partnerUser, $item);
        $this->assertSame([], app(PartnerConfidentiality::class)->scan($payload));
    }

    public function test_money_page_shows_held_available_and_statements(): void
    {
        $item = $this->makeItem();
        $item->publisher_status = 'accepted';
        $item->accepted_at = now();
        $item->save();
        $item->order->update(['status' => 'processing']);
        app(PartnerCompleteService::class)->complete($item->fresh(), 'https://garden-today.example/live-post');
        app(PartnerSettlement::class)->settleOnApproval($item->fresh());
        $statement = app(PartnerLedgerService::class)->statement($this->partner, now()->format('Y-m'));
        $this->assertArrayNotHasKey('slb_revenue', $statement->totals);
        $this->assertSame([], app(PartnerConfidentiality::class)->scan($statement->lines));
        $this->assertSame([], app(PartnerConfidentiality::class)->scan($statement->totals));

        $html = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/money'))->assertOk()->getContent();
        $this->assertStringContainsString('Held', $html);
        $this->assertStringContainsString('Available to withdraw', $html);
        $this->assertStringContainsString('Statements', $html);
        $this->assertStringContainsString('No fees', $html);
        $this->assertNoConfidentialLeak($html);
    }

    public function test_settings_can_issue_keys_and_webhooks(): void
    {
        $this->actingAs($this->partnerUser)->post($this->partnerUrl('/settings/keys'), [
            'name' => 'Dashboard key',
            'mode' => 'live',
        ])->assertRedirect();
        $this->assertNotNull(session('plain_api_key'));
        $this->actingAs($this->partnerUser)->post($this->partnerUrl('/settings/webhooks'), [
            'url' => 'https://hooks.example/slb',
        ])->assertRedirect();
        $html = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/settings'))->assertOk()->getContent();
        $this->assertStringContainsString('API keys', $html);
        $this->assertStringContainsString('Webhooks', $html);
        $this->assertStringContainsString('hooks.example/slb', $html);
        $this->assertNoConfidentialLeak($html);
    }

    public function test_admin_relay_and_payout_screens(): void
    {
        $this->actingAs($this->admin)->get(route('admin.partners.relay'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.partners.payouts'))->assertOk()
            ->assertSee('Held')
            ->assertSee('Available');
        $this->actingAs($this->admin)->get(route('admin.partners.show', $this->partner))
            ->assertOk()
            ->assertSee('Payout mode')
            ->assertSee('Pull adapter');
    }

    public function test_auto_approve_dry_run_preview_and_trusted_only_go_live(): void
    {
        $rule = PartnerAutoApproveRule::query()->create([
            'name' => 'Dry',
            'priority' => 1,
            'min_dr' => 1,
            'action' => PartnerAutoApproveRule::ACTION_LIVE,
            'active' => true,
            'require_sample_post' => false,
            'reject_sensitive_only' => false,
        ]);
        PartnerCatalogItem::query()->create([
            'partner_id' => $this->partner->id,
            'listing_status' => PartnerCatalogItem::STATUS_UNUSED,
            'domain' => 'dry-run.example',
            'site_url' => 'https://dry-run.example',
            'site_name' => 'Dry',
            'dr' => 40,
            'partner_rate_publish_eur' => 40,
        ]);
        $dry = app(PartnerAutoApproveService::class)->dryRun($rule);
        $this->assertSame(1, $dry['count']);
        $this->assertArrayHasKey('would_send_to_review', $dry);
        $this->assertSame(1, $dry['would_send_to_review']);
        $this->assertSame(0, $dry['would_go_live']);
        $this->partner->trusted = true;
        $this->partner->save();
        $dryTrusted = app(PartnerAutoApproveService::class)->dryRun($rule);
        $this->assertSame(1, $dryTrusted['would_go_live']);
    }

    public function test_json_pull_adapter_imports_into_pipeline_and_blocks_casino(): void
    {
        $this->seed(CountriesTableSeeder::class);
        $this->seed(LanguagesTableSeeder::class);
        $this->seed(CategoriesTableSeeder::class);
        Http::fake([
            'https://partner-api.example/*' => Http::response([
                'data' => [
                    [
                        'url' => 'https://garden-pull.example',
                        'name' => 'Garden Pull',
                        'price' => 40,
                        'currency' => 'EUR',
                        'country' => 'cz',
                        'language' => 'cs',
                        'da' => 30,
                        'dr' => 40,
                        'traffic' => 5000,
                        'niches' => 'Home & Garden',
                        'description' => str_repeat('Quality editorial site for guest posts. ', 4),
                        'sample_url' => 'https://garden-pull.example/sample',
                        'link_type' => 'dofollow',
                        'turnaround' => '5',
                        'publication' => 'permanent',
                        'contact_email' => 'partner-full@example.com',
                        'contact_name' => 'PlaCla Test',
                        'sponsored_tag' => 'never',
                    ],
                    [
                        'url' => 'https://casino-pull.example',
                        'name' => 'Casino Pull',
                        'price' => 40,
                        'currency' => 'EUR',
                        'niches' => 'casino',
                        'description' => 'Online casino gambling',
                    ],
                ],
            ], 200),
        ]);
        $connection = PartnerConnection::query()->create([
            'partner_id' => $this->partner->id,
            'type' => PartnerConnection::TYPE_API_PULL,
            'status' => PartnerConnection::STATUS_ACTIVE,
            'api_base_url' => 'https://partner-api.example/v1/sites',
            'items_path' => 'data',
            'pagination' => ['page_param' => 'page', 'start_page' => 1, 'max_pages' => 1, 'page_size' => 50],
        ]);
        $adapter = app(JsonPartnerPullAdapter::class);
        $fetched = $adapter->fetchRows($connection, 'https://partner-api.example/v1/sites');
        $this->assertNotEmpty($fetched);
        $this->assertSame('https://garden-pull.example', $fetched[0]['site_url'] ?? null);
        $job = $adapter->sync($this->partner, $connection, ['skip_reachability' => true]);
        $this->assertNotSame('failed', $job->status, (string) $job->error);
        $ok = PartnerCatalogItem::query()->where('domain', 'garden-pull.example')->first();
        $this->assertNotNull($ok, json_encode([
            'status' => $job->status,
            'error' => $job->error,
            'counts' => $job->counts,
            'rows' => PartnerImportRow::query()->where('job_id', $job->id)->get(['domain', 'result', 'reason_code', 'message']),
        ]));
        $this->assertSame(PartnerCatalogItem::STATUS_UNUSED, $ok->listing_status);
        $blocked = PartnerCatalogItem::query()->where('domain', 'casino-pull.example')->first();
        if ($blocked) {
            $this->assertSame(PartnerCatalogItem::STATUS_BLOCKED, $blocked->listing_status);
        }
    }

    public function test_normal_publisher_wallet_is_unchanged(): void
    {
        $item = $this->makeItem([], $this->normalSite);
        $this->assertFalse(PartnerChannel::itemIsPartner($item));
        $this->assertFalse(app(PartnerSettlement::class)->settleOnApproval($item));
        $wallet = Wallet::lockOrCreateForRole((int) $this->normalPublisher->id, (int) Wallet::publisherRoleId(), 'EUR');
        $before = (float) $wallet->balance;
        $wallet->credit(50);
        $this->assertEquals($before + 50, (float) $wallet->fresh()->balance);
        $this->assertSame(0, app(PartnerLedgerService::class)->availableMinor($this->partner));
    }

    public function test_webhook_signature_is_v1_hmac(): void
    {
        $sig = app(PartnerWebhookService::class)->sign('secret', '123', '{"a":1}');
        $this->assertSame('v1='.hash_hmac('sha256', '123.{"a":1}', 'secret'), $sig);
    }

    public function test_resubmit_after_relay_resets_hold_and_third_party_order_is_404(): void
    {
        $item = $this->makeItem();
        $item->publisher_status = 'accepted';
        $item->accepted_at = now();
        $item->save();
        $item->order->update(['status' => 'processing']);
        app(PartnerCompleteService::class)->complete($item->fresh(), 'https://garden-today.example/first');
        $firstHold = $item->fresh()->hold_until;
        $this->assertNotNull($firstHold);

        $svc = app(PartnerCorrectionService::class);
        $first = $svc->buyerRequest($item->fresh(), 'Please move the link into the first paragraph.');
        $svc->relay($first['correction'], $this->admin);
        $this->assertSame('awaiting_correction', PartnerOrderStatus::fromItem($item->fresh()));

        $this->travel(2)->days();
        app(PartnerCompleteService::class)->complete($item->fresh(), 'https://garden-today.example/second');
        $this->assertSame('submitted', PartnerOrderStatus::fromItem($item->fresh()));
        $this->assertTrue($item->fresh()->hold_until->greaterThan($firstHold));

        $issued = app(PartnerApiKeyService::class)->issue($this->partner, 'scope', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $otherUser = User::factory()->create([
            'email' => 'other-partner@example.com',
            'email_verified_at' => now(),
            'active_role_id' => Role::firstOrCreate(['name' => 'publisher'])->id,
        ]);
        $otherUser->roles()->attach(Role::firstOrCreate(['name' => 'publisher'])->id);
        $other = app(PartnerAccountService::class)->mark($otherUser, $this->admin, ['legal_name' => 'Other Partner']);
        $otherKey = app(PartnerApiKeyService::class)->issue($other, 'other', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $this->withPartnerKey($otherKey['plain'])->getJson('/api/partner/v1/orders/'.$item->id)->assertNotFound();
        $rates = $this->withPartnerKey($issued['plain'])->getJson('/api/partner/v1/rates');
        $rates->assertOk();
        $this->assertNoConfidentialLeak($rates->getContent());
        $this->withPartnerKey($issued['plain'])->patchJson('/api/partner/v1/me', ['payout_mode' => 'instant'])->assertForbidden();
        $this->assertSame('held', $this->partner->fresh()->payout_mode ?: 'held');
    }

    public function test_declining_correction_opens_dispute_and_messages_pref_skips_mail(): void
    {
        $item = $this->makeItem(['order_status' => 'review', 'live_url' => 'https://garden-today.example/live']);
        $item->order->update(['status' => 'review']);
        $svc = app(PartnerCorrectionService::class);
        $first = $svc->buyerRequest($item, 'Please add a second paragraph about shipping times.');
        $svc->relay($first['correction'], $this->admin);
        $row = PartnerOrderCorrection::query()->where('order_item_id', $item->id)->firstOrFail();
        $dispute = $svc->decline($item->fresh(), $row, 'The layout cannot take another paragraph.');
        $this->assertSame('open', $item->fresh()->partner_dispute_status);
        $this->assertNotNull($dispute->id);

        $this->partner->notification_preferences = ['messages' => false, 'new_order' => true, 'correction' => true];
        $this->partner->save();
        Mail::fake();
        app(PartnerThreadService::class)->post($item->fresh(), 'admin', $this->admin->id, 'Status update with no contact details');
        Mail::assertNotQueued(PartnerThreadMessageMail::class);

        $held = app(PartnerThreadService::class)->post($item->fresh(), 'buyer', $this->advertiser->id, 'Please discuss commission on WhatsApp https://wa.me/420777111222');
        $this->assertTrue($held->masked);
        $this->assertSame('held_for_review', $held->relay_status);
        $this->assertStringContainsString('[contact removed]', $held->body_masked);
        $attachment = app(PartnerThreadService::class)->storeAttachment(
            $item->fresh(),
            UploadedFile::fake()->create('secretbuyer@example.com.pdf', 10, 'application/pdf'),
            $this->advertiser
        );
        $this->assertStringContainsString('[email removed]', $attachment->filename_masked);
        $this->assertSame('secretbuyer@example.com.pdf', $attachment->filename_original);
    }

    public function test_instant_clawback_offsets_future_earnings_and_two_takedowns_switch_to_held(): void
    {
        app(PartnerPayoutModeService::class)->storeKeepLiveAcceptance($this->partner, $this->partnerUser, '127.0.0.1');
        app(PartnerPayoutModeService::class)->switchToInstant($this->partner, $this->admin, 'trusted partner');
        $first = $this->makeItem();
        $first->publisher_status = 'accepted';
        $first->accepted_at = now();
        $first->save();
        $first->order->update(['status' => 'processing']);
        app(PartnerCompleteService::class)->complete($first->fresh(), 'https://garden-today.example/one');
        $this->assertSame(4000, app(PartnerLedgerService::class)->availableMinor($this->partner));
        app(PartnerLedgerService::class)->debitAvailableForPayout($this->partner, 4000, 9001);
        app(PartnerLedgerService::class)->clawback($first->fresh(), $this->partner, 'keep_live_breach');
        $this->assertSame(-4000, app(PartnerLedgerService::class)->availableMinor($this->partner));

        $second = $this->makeItem();
        $second->publisher_status = 'accepted';
        $second->accepted_at = now();
        $second->save();
        $second->order->update(['status' => 'processing']);
        app(PartnerCompleteService::class)->complete($second->fresh(), 'https://garden-today.example/two');
        $this->assertSame(0, app(PartnerLedgerService::class)->availableMinor($this->partner));

        app(PartnerLinkMonitorService::class)->afterTakedown($this->partner->fresh());
        $this->assertSame('instant', $this->partner->fresh()->payout_mode);
        app(PartnerLedgerService::class)->clawback($second->fresh(), $this->partner, 'keep_live_breach');
        app(PartnerLinkMonitorService::class)->afterTakedown($this->partner->fresh());
        $this->assertSame('held', $this->partner->fresh()->payout_mode);
        $this->assertSame(1, PartnerPayoutModeChange::query()->where('to_mode', 'held')->count());
    }

    public function test_api_taxonomy_and_earnings_never_leak(): void
    {
        $issued = app(PartnerApiKeyService::class)->issue($this->partner, 'tax', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $tax = $this->withPartnerKey($issued['plain'])->getJson('/api/partner/v1/taxonomy');
        $tax->assertOk();
        $casino = collect($tax->json('data.categories'))->first(
            fn ($row) => str_contains(strtolower((string) ($row['name'] ?? '')), 'casino')
        );
        $this->assertNotNull($casino);
        $this->assertFalse($casino['marketplace_allowed']);
        $earn = $this->withPartnerKey($issued['plain'])->getJson('/api/partner/v1/earnings');
        $earn->assertOk();
        $this->assertNoConfidentialLeak($earn->getContent());
        $this->assertNoConfidentialLeak($tax->getContent());
    }

    private function withPartnerKey(string $plain)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain]);
    }

    private function assertNoConfidentialLeak(string $html): void
    {
        $scan = app(PartnerConfidentiality::class);
        $this->assertSame([], $scan->scanText($html));
        $this->assertSame([], $scan->scanAmounts($html, [127.50]));
        $this->assertStringNotContainsString('127.50', $html);
        $this->assertStringNotContainsString('Secret Buyer', $html);
        $this->assertStringNotContainsString('secretbuyer@example.com', $html);
    }
}
