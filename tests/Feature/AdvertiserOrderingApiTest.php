<?php

namespace Tests\Feature;

use App\Models\AdvertiserApiToken;
use App\Models\AdvertiserWebhookEndpoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Advertiser\AdvertiserApiKeyService;
use App\Services\Advertiser\AdvertiserWebhookService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class AdvertiserOrderingApiTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $advertiser;

    private User $otherAdvertiser;

    private User $publisher;

    private Site $site;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this suite.');
        }

        parent::setUp();
        Mail::fake();
        Http::fake();
        $this->seed(RolesTableSeeder::class);

        $advertiserRole = Role::where('name', 'advertiser')->firstOrFail();
        $publisherRole = Role::where('name', 'publisher')->firstOrFail();

        $this->advertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->advertiser->roles()->attach($advertiserRole->id);

        $this->otherAdvertiser = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiserRole->id,
        ]);
        $this->otherAdvertiser->roles()->attach($advertiserRole->id);

        $this->publisher = User::factory()->create([
            'email' => 'publisher-secret@example.com',
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $this->publisher->roles()->attach($publisherRole->id);

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Oslo Rentals',
            'site_url' => 'https://oslo-rentals.no',
            'domain' => 'oslo-rentals.no',
            'example_url' => 'https://oslo-rentals.no/sample',
            'da' => 35,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'no',
            'language' => 'no',
            'price' => 90,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Do not pitch casino or gambling. Max one dofollow link. Editorial tone only.',
            'verified' => true,
            'active' => 1,
            'writing_enabled' => true,
            'writing_fee' => 25,
        ]);
        $this->site->forceFill([
            'slb_price_publish_eur' => 41.00,
            'partner_rate_version' => 3,
        ])->save();

        $this->fundAdvertiserWallet($this->advertiser, 5000);
    }

    public function test_docs_page_is_public(): void
    {
        $this->get('/api/docs/advertiser')
            ->assertOk()
            ->assertSee('Advertiser Ordering API')
            ->assertSee('Idempotency-Key')
            ->assertSee('SLB-Signature')
            ->assertSee('sandbox');
    }

    public function test_legacy_catalog_key_cannot_create_orders(): void
    {
        $issued = AdvertiserApiToken::issue($this->advertiser, 'Catalog only');

        $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'garden tools',
            'target_url' => 'https://example.com/tools',
            'content' => '<p>Hello</p>',
        ], ['Idempotency-Key' => 'k-1'])->assertForbidden();
    }

    public function test_site_details_are_advertiser_safe(): void
    {
        $issued = $this->issueLive();
        $response = $this->withKey($issued['plain'])->getJson('/api/v1/sites/'.$this->site->id);

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.id', $this->site->id)
            ->assertJsonPath('data.price.currency', 'EUR')
            ->assertJsonPath('data.turnaround', '7 days')
            ->assertJsonPath('data.writing.offered', true);
        $this->assertNotEmpty($response->json('data.rules.description'));
        $this->assertBuyerSafe($response->getContent());
        $this->assertGreaterThan(0, (float) $response->json('data.price_eur'));
    }

    public function test_wallet_returns_advertiser_eur_only(): void
    {
        $issued = $this->issueLive();
        $this->withKey($issued['plain'])->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('spendable', 5000)
            ->assertJsonMissingPath('publisher_wallets');
    }

    public function test_live_order_debits_wallet_and_hides_partner_rates(): void
    {
        $issued = $this->issueLive();
        $before = (float) Wallet::query()->where('user_id', $this->advertiser->id)->value('balance');

        $response = $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'garden tools',
            'target_url' => 'https://example.com/tools',
            'content' => '<p>A long enough article about garden tools for a family audience.</p>',
            'content_format' => 'html',
        ], ['Idempotency-Key' => 'ord-1']);

        $response->assertCreated()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.sandbox', false)
            ->assertJsonPath('data.anchor', 'garden tools');
        $this->assertBuyerSafe($response->getContent());

        $order = Order::query()->find($response->json('data.id'));
        $this->assertNotNull($order);
        $this->assertSame('wallet', $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
        $this->assertFalse((bool) $order->is_sandbox);
        $this->assertGreaterThan(0, (float) $order->total_amount);

        $after = (float) Wallet::query()->where('user_id', $this->advertiser->id)->value('balance');
        $this->assertEqualsWithDelta($before - (float) $order->total_amount, $after, 0.011);
    }

    public function test_insufficient_funds_returns_402(): void
    {
        Wallet::query()->where('user_id', $this->advertiser->id)->update([
            'balance' => 1,
            'bonus_balance' => 0,
        ]);
        $issued = $this->issueLive();

        $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'garden tools',
            'target_url' => 'https://example.com/tools',
            'content' => '<p>Article</p>',
        ], ['Idempotency-Key' => 'ord-402'])
            ->assertStatus(402)
            ->assertJsonPath('error', 'insufficient_funds');

        $this->assertSame(0, Order::query()->where('user_id', $this->advertiser->id)->count());
    }

    public function test_writing_option_orders_without_content(): void
    {
        $issued = $this->issueLive();
        $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'garden tools',
            'target_url' => 'https://example.com/tools',
            'writing' => true,
        ], ['Idempotency-Key' => 'ord-write'])
            ->assertCreated()
            ->assertJsonPath('data.writing', true);
    }

    public function test_idempotency_replays_and_conflicts(): void
    {
        $issued = $this->issueLive();
        $payload = [
            'site_id' => $this->site->id,
            'anchor' => 'garden tools',
            'target_url' => 'https://example.com/tools',
            'content' => '<p>Article</p>',
        ];
        $first = $this->withKey($issued['plain'])->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'same-key']);
        $first->assertCreated();
        $replay = $this->withKey($issued['plain'])->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => 'same-key']);
        $replay->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Order::query()->where('user_id', $this->advertiser->id)->count());

        $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'other anchor',
            'target_url' => 'https://example.com/other',
            'content' => '<p>Other</p>',
        ], ['Idempotency-Key' => 'same-key'])->assertStatus(409);
    }

    public function test_orders_are_isolated_and_cancellable_until_accepted(): void
    {
        $issued = $this->issueLive();
        $created = $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'garden tools',
            'target_url' => 'https://example.com/tools',
            'content' => '<p>Article</p>',
        ], ['Idempotency-Key' => 'ord-can']);
        $created->assertCreated();
        $id = $created->json('data.id');

        $other = $this->issueFor($this->otherAdvertiser, 'live');
        $this->withKey($other['plain'])->getJson('/api/v1/orders/'.$id)->assertNotFound();

        $this->withKey($issued['plain'])->getJson('/api/v1/orders/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.cancellable', true);

        $this->withKey($issued['plain'])->postJson('/api/v1/orders/'.$id.'/cancel', [], [
            'Idempotency-Key' => 'cancel-1',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', Order::query()->find($id)?->status);
    }

    public function test_cannot_cancel_after_publisher_accepts(): void
    {
        $issued = $this->issueLive();
        $created = $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'garden tools',
            'target_url' => 'https://example.com/tools',
            'content' => '<p>Article</p>',
        ], ['Idempotency-Key' => 'ord-acc']);
        $id = $created->json('data.id');
        $item = OrderItem::query()->where('order_id', $id)->first();
        $item->forceFill(['publisher_status' => 'accepted', 'accepted_at' => now()])->save();
        Order::query()->whereKey($id)->update(['status' => 'processing']);

        $this->withKey($issued['plain'])->postJson('/api/v1/orders/'.$id.'/cancel', [], [
            'Idempotency-Key' => 'cancel-acc',
        ])->assertStatus(422)->assertJsonPath('error', 'cannot_cancel');
    }

    public function test_sandbox_order_skips_wallet_and_publisher_mail(): void
    {
        $issued = $this->issueFor($this->advertiser, 'test');
        $before = (float) Wallet::query()->where('user_id', $this->advertiser->id)->value('balance');

        $response = $this->withKey($issued['plain'])->postJson('/api/v1/orders', [
            'site_id' => $this->site->id,
            'anchor' => 'sandbox link',
            'target_url' => 'https://example.com/sandbox',
            'content' => '<p>Sandbox article</p>',
        ], ['Idempotency-Key' => 'sbx-1']);

        $response->assertCreated()
            ->assertJsonPath('data.sandbox', true)
            ->assertJsonPath('data.livemode', false);
        $order = Order::query()->find($response->json('data.id'));
        $this->assertTrue((bool) $order->is_sandbox);
        $this->assertSame('sandbox', $order->payment_method);
        $this->assertSame($before, (float) Wallet::query()->where('user_id', $this->advertiser->id)->value('balance'));
        Mail::assertNothingSent();

        $live = $this->issueLive();
        $this->withKey($live['plain'])->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_webhook_crud_returns_secret_once_and_sends_signed_test_event(): void
    {
        $issued = $this->issueLive();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'hooks.example')) {
                $timestamp = $request->header('SLB-Webhook-Timestamp')[0] ?? '';
                $signature = $request->header('SLB-Signature')[0] ?? '';
                $expected = app(AdvertiserWebhookService::class)->sign(
                    AdvertiserWebhookEndpoint::query()->first()->plainSecret(),
                    $timestamp,
                    $request->body()
                );
                $this->assertSame($expected, $signature);
                $payload = json_decode($request->body(), true);
                $this->assertSame('webhook.test', $payload['type'] ?? null);
                $this->assertFalse($payload['livemode'] ?? true);

                return Http::response('ok', 200);
            }

            return Http::response('ok', 200);
        });

        $create = $this->withKey($issued['plain'])->postJson('/api/v1/webhooks', [
            'url' => 'https://hooks.example/slb',
            'events' => ['order.*', 'webhook.test'],
        ]);
        $create->assertCreated();
        $secret = $create->json('data.secret');
        $this->assertNotEmpty($secret);
        $this->assertStringStartsWith('whsec_', $secret);
        $id = $create->json('data.id');

        $list = $this->withKey($issued['plain'])->getJson('/api/v1/webhooks');
        $list->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());

        $this->withKey($issued['plain'])->postJson('/api/v1/webhooks/'.$id.'/test')
            ->assertOk()
            ->assertJsonPath('data.type', 'webhook.test');

        $this->withKey($issued['plain'])->deleteJson('/api/v1/webhooks/'.$id)->assertOk();
        $this->assertSame(0, AdvertiserWebhookEndpoint::query()->count());
    }

    public function test_dashboard_can_issue_test_key_and_manage_webhooks(): void
    {
        $this->actingAs($this->advertiser)
            ->get(route('advertiser.api.index'))
            ->assertOk()
            ->assertSee('/api/docs/advertiser')
            ->assertSee('Test (sandbox');

        $this->actingAs($this->advertiser)
            ->post(route('advertiser.api.store'), [
                'name' => 'Agency live',
                'mode' => 'live',
                'scopes' => AdvertiserApiKeyService::SCOPES,
            ])->assertRedirect(route('advertiser.api.index'));

        $this->actingAs($this->advertiser)
            ->post(route('advertiser.api.webhooks.store'), [
                'url' => 'https://hooks.example/dash',
                'events' => ['*'],
            ])->assertRedirect(route('advertiser.api.index'));

        $this->assertSame(1, AdvertiserWebhookEndpoint::query()->count());
    }

    /**
     * @return array{plain: string, token: AdvertiserApiToken}
     */
    private function issueLive(): array
    {
        return $this->issueFor($this->advertiser, 'live');
    }

    /**
     * @return array{plain: string, token: AdvertiserApiToken}
     */
    private function issueFor(User $user, string $mode): array
    {
        return app(AdvertiserApiKeyService::class)->issue(
            $user,
            $mode.'-key',
            AdvertiserApiKeyService::SCOPES,
            $mode
        );
    }

    private function withKey(string $plain)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$plain]);
    }

    private function assertBuyerSafe(string $json): void
    {
        $lower = strtolower($json);
        foreach ([
            'publisher_price', 'platform_fee', 'slb_price', 'partner_rate',
            'markup', 'margin', 'publisher_id', 'publisher-secret@example.com',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $lower, 'Leaked '.$needle);
        }
    }
}
