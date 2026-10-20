<?php

namespace Tests\Feature;

use App\Mail\OrderAccepted;
use App\Mail\PartnerNewOrderMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerLedgerTransaction;
use App\Models\PartnerWebhookEndpoint;
use App\Models\PartnerWebhookEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Partners\PartnerAccountService;
use App\Services\Partners\PartnerAlertService;
use App\Services\Partners\PartnerApiKeyService;
use App\Services\Partners\PartnerCompleteService;
use App\Services\Partners\PartnerLedgerService;
use App\Services\Partners\PartnerLiveLinkChecker;
use App\Services\Partners\PartnerWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartnerApiGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partnerUser;

    private Partner $partner;

    private User $advertiser;

    private Site $site;

    private PartnerCatalogItem $catalogItem;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'hooks.example') || str_contains($url, 'webhook.example')) {
                return Http::response('ok', 200);
            }
            if (str_contains($url, 'bad-link')) {
                return Http::response('not found', 404);
            }
            if (str_contains($url, 'missing-href')) {
                return Http::response('<html><body><p>no links</p></body></html>', 200);
            }
            if (str_contains($url, 'nofollow-page')) {
                return Http::response(
                    '<html><body><a href="https://shop.example/tools" rel="nofollow sponsored">garden tools</a></body></html>',
                    200
                );
            }
            if (str_contains($url, 'wrong-anchor')) {
                return Http::response(
                    '<html><body><a href="https://shop.example/tools">other words</a></body></html>',
                    200
                );
            }

            return Http::response(
                '<html><body><a href="https://shop.example/tools">garden tools</a></body></html>',
                200
            );
        });

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
            'email' => 'partner-gaps@example.com',
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
            'email' => 'secretbuyer-gaps@example.com',
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
            'description' => 'Partner API gaps test site',
            'verified' => true,
            'active' => true,
        ]);
        $this->catalogItem = PartnerCatalogItem::query()->create([
            'partner_id' => $this->partner->id,
            'site_id' => $this->site->id,
            'listing_status' => PartnerCatalogItem::STATUS_LIVE,
            'domain' => 'garden-today.example',
            'site_url' => 'https://garden-today.example',
            'site_name' => 'Garden Today',
            'partner_rate_currency' => 'EUR',
            'partner_rate_publish' => 40,
            'partner_rate_publish_eur' => 40,
            'slb_price_publish_eur' => 127.50,
        ]);
    }

    public function test_webhook_api_crud_returns_secret_once_and_sends_signed_test_event(): void
    {
        $issued = app(PartnerApiKeyService::class)->issue($this->partner, 'live', PartnerApiKeyService::SCOPES, 'live', $this->admin);

        $create = $this->withPartnerKey($issued['plain'])->postJson('/api/partner/v1/webhooks', [
            'url' => 'https://hooks.example/slb',
            'events' => ['order.*', 'webhook.test'],
        ]);
        $create->assertCreated();
        $create->assertJsonPath('data.url', 'https://hooks.example/slb');
        $secret = $create->json('data.secret');
        $this->assertNotEmpty($secret);
        $this->assertStringStartsWith('whsec_', $secret);
        $id = $create->json('data.id');

        $list = $this->withPartnerKey($issued['plain'])->getJson('/api/partner/v1/webhooks');
        $list->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertSame($id, $list->json('data.0.id'));

        $this->withPartnerKey($issued['plain'])->patchJson('/api/partner/v1/webhooks/'.$id, [
            'events' => ['*'],
        ])->assertOk()->assertJsonPath('data.events.0', '*');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'hooks.example')) {
                $timestamp = $request->header('SLB-Webhook-Timestamp')[0] ?? '';
                $signature = $request->header('SLB-Signature')[0] ?? '';
                $expected = app(PartnerWebhookService::class)->sign(
                    PartnerWebhookEndpoint::query()->first()->plainSecret(),
                    $timestamp,
                    $request->body()
                );
                $this->assertSame($expected, $signature);
                $payload = json_decode($request->body(), true);
                $this->assertSame('webhook.test', $payload['type'] ?? null);
                $this->assertFalse($payload['livemode'] ?? true);

                return Http::response('ok', 200);
            }

            return Http::response('<html><body><a href="https://shop.example/tools">garden tools</a></body></html>', 200);
        });

        $this->withPartnerKey($issued['plain'])->postJson('/api/partner/v1/webhooks/'.$id.'/test')
            ->assertOk()
            ->assertJsonPath('data.type', 'webhook.test')
            ->assertJsonPath('data.livemode', false);

        $this->withPartnerKey($issued['plain'])->deleteJson('/api/partner/v1/webhooks/'.$id)
            ->assertOk();
        $this->assertSame(0, PartnerWebhookEndpoint::query()->count());
    }

    public function test_dashboard_settings_can_send_a_test_webhook_event(): void
    {
        $this->actingAs($this->partnerUser)->post($this->partnerUrl('/settings/webhooks'), [
            'url' => 'https://hooks.example/dash',
        ])->assertRedirect();
        $endpoint = PartnerWebhookEndpoint::query()->firstOrFail();
        $this->actingAs($this->partnerUser)
            ->post($this->partnerUrl('/settings/webhooks/'.$endpoint->id.'/test'))
            ->assertRedirect();
        $this->assertSame(1, PartnerWebhookEvent::query()->where('type', 'webhook.test')->count());
        $event = PartnerWebhookEvent::query()->where('type', 'webhook.test')->first();
        $this->assertFalse((bool) ($event->payload['livemode'] ?? true));
    }

    public function test_order_created_webhook_fires_when_new_order_email_is_off(): void
    {
        $this->partner->notification_preferences = ['new_order' => false];
        $this->partner->save();
        $item = $this->makeLiveItem();
        app(PartnerAlertService::class)->notifyNewOrder($this->partnerUser, $item);
        Mail::assertNotQueued(PartnerNewOrderMail::class);
        $this->assertSame(1, PartnerWebhookEvent::query()->where('type', 'order.created')->count());
        $payload = PartnerWebhookEvent::query()->where('type', 'order.created')->first()->payload;
        $this->assertTrue($payload['livemode'] ?? false);
        $this->assertStringNotContainsString('127.50', json_encode($payload));
    }

    public function test_accept_and_reject_fire_webhooks_from_api_and_dashboard(): void
    {
        $issued = app(PartnerApiKeyService::class)->issue($this->partner, 'live', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $item = $this->makeLiveItem();
        $this->withPartnerKey($issued['plain'])->postJson('/api/partner/v1/orders/'.$item->id.'/accept')->assertOk();
        $this->assertSame(1, PartnerWebhookEvent::query()->where('type', 'order.accepted')->count());

        $apiReject = $this->makeLiveItem();
        $this->withPartnerKey($issued['plain'])->postJson('/api/partner/v1/orders/'.$apiReject->id.'/reject', [
            'reason' => 'The topic is not a fit for this site.',
        ])->assertOk();
        $this->assertSame(1, PartnerWebhookEvent::query()->where('type', 'order.rejected')->count());

        $second = $this->makeLiveItem();
        $this->actingAs($this->partnerUser)
            ->post($this->partnerUrl('/orders/'.$second->id.'/accept'))
            ->assertRedirect();
        $this->assertSame(2, PartnerWebhookEvent::query()->where('type', 'order.accepted')->count());

        $third = $this->makeLiveItem();
        $this->actingAs($this->partnerUser)
            ->post($this->partnerUrl('/orders/'.$third->id.'/reject'), [
                'reason' => 'The topic is not a fit for this site.',
            ])
            ->assertRedirect();
        $this->assertSame(2, PartnerWebhookEvent::query()->where('type', 'order.rejected')->count());
        Mail::assertQueued(OrderAccepted::class);
    }

    public function test_complete_stores_real_rel_and_anchor_match_and_rejects_unlinked_pages(): void
    {
        $item = $this->makeLiveItem();
        $item->publisher_status = 'accepted';
        $item->accepted_at = now();
        $item->save();
        $item->order->update(['status' => 'processing']);

        $issued = app(PartnerApiKeyService::class)->issue($this->partner, 'live', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $this->withPartnerKey($issued['plain'])->postJson('/api/partner/v1/orders/'.$item->id.'/complete', [
            'live_url' => 'https://garden-today.example/missing-href',
        ])->assertStatus(422)->assertJsonPath('error', 'link_check_failed');
        $this->assertNull($item->fresh()->live_url);

        $ok = $this->withPartnerKey($issued['plain'])->postJson('/api/partner/v1/orders/'.$item->id.'/complete', [
            'live_url' => 'https://garden-today.example/nofollow-page',
        ]);
        $ok->assertOk();
        $ok->assertJsonPath('link_check.rel', 'nofollow sponsored');
        $ok->assertJsonPath('link_check.anchor_match', true);
        $ok->assertJsonPath('link_check.href_found', true);
        $fresh = $item->fresh();
        $this->assertSame('nofollow sponsored', $fresh->live_url_rel);
        $this->assertTrue((bool) $fresh->live_url_anchor_match);
        $this->assertTrue((bool) $fresh->live_url_href_found);
        $this->assertSame(200, (int) $fresh->live_url_http_status);

        $mismatch = $this->makeLiveItem();
        $mismatch->publisher_status = 'accepted';
        $mismatch->accepted_at = now();
        $mismatch->save();
        $mismatch->order->update(['status' => 'processing']);
        $result = app(PartnerCompleteService::class)->complete($mismatch->fresh(), 'https://garden-today.example/wrong-anchor');
        $this->assertFalse($result['link_check']['anchor_match']);
        $this->assertSame('dofollow', $result['link_check']['rel']);
    }

    public function test_live_link_checker_reports_rel_tokens(): void
    {
        $checker = app(PartnerLiveLinkChecker::class);
        $html = '<a href="https://shop.example/tools" rel="UGC nofollow">Garden Tools</a>';
        $match = $checker->inspectAnchors($html, 'https://shop.example/tools', 'garden tools');
        $this->assertTrue($match['anchor_match']);
        $this->assertSame('nofollow ugc', $match['rel']);
    }

    public function test_sandbox_orders_are_isolated_and_move_no_money(): void
    {
        $liveKey = app(PartnerApiKeyService::class)->issue($this->partner, 'live', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $testKey = app(PartnerApiKeyService::class)->issue($this->partner, 'test', PartnerApiKeyService::SCOPES, 'test', $this->admin);
        $liveItem = $this->makeLiveItem();

        $this->withPartnerKey($liveKey['plain'])->postJson('/api/partner/v1/sandbox/orders', [
            'site_id' => 'site_'.$this->catalogItem->id,
        ])->assertForbidden();

        Mail::fake();
        $created = $this->withPartnerKey($testKey['plain'])->postJson('/api/partner/v1/sandbox/orders', [
            'site_id' => 'site_'.$this->catalogItem->id,
            'anchor_text' => 'garden tools',
            'target_url' => 'https://shop.example/tools',
        ]);
        $created->assertCreated();
        $created->assertJsonPath('livemode', false);
        $created->assertJsonPath('data.is_sandbox', true);
        $createdEvent = PartnerWebhookEvent::query()->where('type', 'order.created')->first();
        $this->assertNotNull($createdEvent);
        $this->assertFalse((bool) ($createdEvent->payload['livemode'] ?? true));
        $orderId = $created->json('data.id');
        $numeric = (int) str_replace('ord_', '', $orderId);
        $sandboxItem = OrderItem::query()->findOrFail($numeric);
        $this->assertTrue($sandboxItem->isSandbox());
        $this->assertTrue($sandboxItem->order->isSandbox());
        $this->assertSame(0.0, (float) $sandboxItem->order->total_amount);
        if (Invoice::tableAvailable()) {
            $this->assertSame(0, Invoice::query()->where('order_id', $sandboxItem->order_id)->count());
        }
        Mail::assertNothingQueued();

        $liveList = $this->withPartnerKey($liveKey['plain'])->getJson('/api/partner/v1/orders');
        $liveList->assertOk();
        $this->assertSame(1, $liveList->json('meta.total'));
        $this->assertSame('ord_'.$liveItem->id, $liveList->json('data.0.id'));

        $testList = $this->withPartnerKey($testKey['plain'])->getJson('/api/partner/v1/orders');
        $testList->assertOk();
        $this->assertSame(1, $testList->json('meta.total'));
        $this->assertSame($orderId, $testList->json('data.0.id'));

        $this->withPartnerKey($liveKey['plain'])->getJson('/api/partner/v1/orders/'.$numeric)->assertNotFound();
        $this->withPartnerKey($testKey['plain'])->getJson('/api/partner/v1/orders/'.$liveItem->id)->assertNotFound();

        $this->withPartnerKey($testKey['plain'])->postJson('/api/partner/v1/orders/'.$numeric.'/accept')->assertOk();
        $this->assertSame('accepted', $sandboxItem->fresh()->publisher_status);
        $this->assertSame(1, PartnerWebhookEvent::query()->where('type', 'order.accepted')->count());
        $this->assertFalse((bool) PartnerWebhookEvent::query()->where('type', 'order.accepted')->first()->payload['livemode']);

        $this->withPartnerKey($testKey['plain'])->postJson('/api/partner/v1/orders/'.$numeric.'/complete', [
            'live_url' => 'https://garden-today.example/sandbox-live',
        ])->assertOk();

        $rejectCreated = $this->withPartnerKey($testKey['plain'])->postJson('/api/partner/v1/sandbox/orders', [
            'site_id' => 'site_'.$this->catalogItem->id,
            'anchor_text' => 'garden tools',
            'target_url' => 'https://shop.example/tools',
        ]);
        $rejectCreated->assertCreated();
        $rejectNumeric = (int) str_replace('ord_', '', (string) $rejectCreated->json('data.id'));
        $this->withPartnerKey($testKey['plain'])->postJson('/api/partner/v1/orders/'.$rejectNumeric.'/reject', [
            'reason' => 'Sandbox reject path must work end to end.',
        ])->assertOk();
        $this->assertSame('rejected', OrderItem::query()->findOrFail($rejectNumeric)->publisher_status);
        $this->assertFalse((bool) PartnerWebhookEvent::query()->where('type', 'order.rejected')->first()?->payload['livemode']);

        $this->assertSame(0, PartnerLedgerTransaction::query()->count());
        $this->assertSame(0, app(PartnerLedgerService::class)->availableMinor($this->partner));
        $this->assertSame(0, app(PartnerLedgerService::class)->pendingMinor($this->partner));

        $this->assertSame(1, Order::query()->notSandbox()->where('payment_status', 'paid')->count());
        $this->assertEquals(127.50, (float) Order::query()->notSandbox()->where('payment_status', 'paid')->sum('total_amount'));
        $this->assertSame(2, Order::query()->sandboxOnly()->count());

        $dash = $this->actingAs($this->partnerUser)->get($this->partnerUrl('/orders'))->assertOk()->getContent();
        $this->assertStringNotContainsString((string) $sandboxItem->order->order_number, $dash);
        $this->assertStringContainsString((string) $liveItem->order->order_number, $dash);
        $this->assertStringNotContainsString('127.50', $created->getContent());
    }

    public function test_me_reflects_test_mode_for_test_keys(): void
    {
        $liveKey = app(PartnerApiKeyService::class)->issue($this->partner, 'live', PartnerApiKeyService::SCOPES, 'live', $this->admin);
        $testKey = app(PartnerApiKeyService::class)->issue($this->partner, 'test', PartnerApiKeyService::SCOPES, 'test', $this->admin);
        $this->withPartnerKey($liveKey['plain'])->getJson('/api/partner/v1/me')
            ->assertOk()
            ->assertJsonPath('data.test_mode', false)
            ->assertJsonPath('data.livemode', true);
        $this->withPartnerKey($testKey['plain'])->getJson('/api/partner/v1/me')
            ->assertOk()
            ->assertJsonPath('data.test_mode', true)
            ->assertJsonPath('data.livemode', false);
        $this->withPartnerKey($testKey['plain'])->postJson('/api/partner/v1/payouts', ['amount' => 2000])
            ->assertForbidden();
    }

    private function partnerUrl(string $path = '/'): string
    {
        return 'http://partners.seolinkbuildings.com'.$path;
    }

    private function withPartnerKey(string $plain)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain]);
    }

    private function makeLiveItem(): OrderItem
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 127.50,
            'tax' => 0,
            'total_amount' => 127.50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article',
            'anchor_text' => 'garden tools',
            'target_url' => 'https://shop.example/tools',
            'price' => 127.50,
            'advertiser_eur_amount' => 127.50,
            'publisher_amount' => 40.00,
            'publisher_currency' => 'EUR',
            'publisher_status' => 'pending',
        ]);
    }
}
