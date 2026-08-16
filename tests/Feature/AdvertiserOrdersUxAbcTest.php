<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertiserOrdersUxAbcTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function siteFor(User $publisher, string $name = 'Orders UX Site'): Site
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?: 'orders-ux');

        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => $name,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeOrder(User $advertiser, Site $site, array $orderAttrs = [], array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-UX-'.uniqid(),
            'reference_code' => 'REF-UX-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ], $orderAttrs));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article.docx',
        ], $itemAttrs));

        return $order->fresh('items');
    }

    public function test_orders_page_uses_compact_columns_and_split_pending_filters(): void
    {
        $advertiser = $this->advertiser();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.orders'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="awaiting_payment"', $html);
        $this->assertStringContainsString('value="awaiting_publisher"', $html);
        $this->assertStringContainsString('Awaiting payment', $html);
        $this->assertStringContainsString('Awaiting publisher', $html);
        $this->assertStringNotContainsString('>Waiting for payment</option>', $html);
        preg_match('/id="statusFilter"[^>]*>(.*?)<\/select>/s', $html, $statusSelect);
        $this->assertNotEmpty($statusSelect[1] ?? null);
        $this->assertStringNotContainsString('value="pending"', $statusSelect[1]);
        $this->assertStringContainsString('value="awaiting_payment"', $statusSelect[1]);
        $this->assertStringContainsString('value="awaiting_publisher"', $statusSelect[1]);

        $this->assertStringContainsString('<th>Total</th>', $html);
        $this->assertStringContainsString('<th>Payment</th>', $html);
        $this->assertStringContainsString('<th width="180">Actions</th>', $html);
        $this->assertStringNotContainsString('<th>Sensitive Price</th>', $html);
        $this->assertStringNotContainsString('<th>Reference Code</th>', $html);
        $this->assertStringNotContainsString('<th>Content Link</th>', $html);
        $this->assertStringNotContainsString('<th>Live URL</th>', $html);

        $this->assertStringContainsString('orderDetailsActions', $html);
        $this->assertStringContainsString('At least 10 characters', $html);
        $this->assertStringContainsString('AdvertiserOrdersConfig', $html);
        $this->assertStringContainsString('assets/js/advertiser-orders.js', $html);
        $this->assertStringContainsString('assets/css/advertiser-orders.css', $html);
        $this->assertStringContainsString('type="search"', $html);
        $this->assertStringContainsString('id="ordersSearchStatus"', $html);
        $this->assertStringContainsString('id="ordersSearchClear"', $html);
        $this->assertStringContainsString('id="ordersResultsCard"', $html);
        $this->assertStringContainsString('Results update as you type.', $html);
        $this->assertStringContainsString('data-orders-live-search="1"', $html);
        $this->assertStringContainsString('id="ordersSearchHint"', $html);
        // Live list API must be same-origin relative (Hostinger APP_URL mismatches break fetch).
        $this->assertTrue(
            str_contains($html, 'list: "/advertiser/orders/list"')
            || str_contains($html, 'list: "\/advertiser\/orders\/list"'),
            'orders list route should be a relative /advertiser/orders/list path'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/list:\s*["\']https?:/',
            $html
        );

        $js = file_get_contents(public_path('assets/js/advertiser-orders.js'));
        $this->assertIsString($js);
        $this->assertStringContainsString('Please provide at least 10 characters', $js);
        $this->assertStringContainsString('No matching orders', $js);
        $this->assertStringContainsString('payment-refunded', $js);
        $this->assertStringContainsString('paginationPageWindow', $js);
        $this->assertStringContainsString('ordersPageHref', $js);
        $this->assertStringContainsString('if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;', $js);
        $this->assertStringContainsString('catalog-pagination__meta', $js);
        $this->assertStringContainsString('catalog-pagination__links', $js);
        $this->assertStringContainsString('if (!pagination || !pagination.total)', $js);
        $this->assertStringNotContainsString('pagination.last_page <= 1', $js);
        $this->assertStringContainsString('popstate', $js);
        $this->assertStringContainsString('window.viewOrder', $js);
        $this->assertStringContainsString('+${moreCount} more', $js);
        $this->assertStringContainsString('ORDERS_SEARCH_LIVE_MS', $js);
        $this->assertStringContainsString('ORDERS_SEARCH_MIN_CHARS', $js);
        $this->assertStringContainsString('AbortController', $js);
        $this->assertStringContainsString('scheduleOrdersLiveSearch', $js);
        $this->assertStringContainsString('runOrdersLiveFetch', $js);
        $this->assertStringContainsString('bootAdvertiserOrdersPage', $js);
        $this->assertStringContainsString('isAwaitingScheduledRelease', $js);
        $this->assertStringContainsString("credentials: 'same-origin'", $js);
        // Live search must not depend on OrderChat succeeding first.
        $earlyFetchAssign = strpos($js, 'window.fetchOrders = fetchOrders');
        $orderChatInit = strpos($js, 'new window.OrderChat');
        $this->assertNotFalse($earlyFetchAssign);
        $this->assertNotFalse($orderChatInit);
        $this->assertLessThan($orderChatInit, $earlyFetchAssign);
        $this->assertStringContainsString('replaceState', $js);
        // Row actions stay View/Chat/Pay again — Approve is modal-only markup.
        $this->assertStringContainsString('onclick="approveOrder(${order.id})"', $js);
        preg_match('/function renderOrders\(orders, pagination\) \{(.*?)\n    \}/s', $js, $renderOrdersFn);
        $this->assertNotEmpty($renderOrdersFn[1] ?? null, 'renderOrders function should be present');
        $this->assertStringContainsString('action-buttons', $renderOrdersFn[1]);
        $this->assertStringContainsString('Pay again', $renderOrdersFn[1]);
        $this->assertStringContainsString('viewOrder', $renderOrdersFn[1]);
        $this->assertStringContainsString('openChat', $renderOrdersFn[1]);
        $this->assertStringNotContainsString('approveOrder', $renderOrdersFn[1]);
        $this->assertStringNotContainsString('requestModification', $renderOrdersFn[1]);
        $this->assertStringNotContainsString('reportLinkRemoved', $renderOrdersFn[1]);
        $this->assertStringContainsString('window.reportLinkRemoved = function(orderId, itemId)', $js);
        $this->assertStringContainsString('payload.order_item_id', $js);
        $this->assertStringContainsString('window.recheckLiveUrl = function(orderId, itemId)', $js);
    }

    public function test_awaiting_payment_and_awaiting_publisher_filters_split_pending(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $awaitingPayment = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-PAY-1',
            'payment_status' => 'pending',
            'status' => 'pending',
            'paid_at' => null,
        ]);
        $awaitingPublisher = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-PUB-1',
            'payment_status' => 'paid',
            'status' => 'pending',
        ]);
        $processing = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-PROC-1',
            'status' => 'processing',
        ]);

        $paymentOnly = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['status' => 'awaiting_payment']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('orders');

        $this->assertCount(1, $paymentOnly);
        $this->assertSame($awaitingPayment->id, $paymentOnly[0]['id']);

        $publisherOnly = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['status' => 'awaiting_publisher']))
            ->assertOk()
            ->json('orders');

        $this->assertCount(1, $publisherOnly);
        $this->assertSame($awaitingPublisher->id, $publisherOnly[0]['id']);

        $processingOnly = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['status' => 'processing']))
            ->assertOk()
            ->json('orders');

        $this->assertCount(1, $processingOnly);
        $this->assertSame($processing->id, $processingOnly[0]['id']);
    }

    public function test_search_matches_reference_code_and_live_url(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Searchable Site');

        $byRef = $this->makeOrder($advertiser, $site, [
            'reference_code' => 'REF-UNIQUE-ALPHA',
            'order_number' => 'ORD-REF-1',
        ]);
        $byLive = $this->makeOrder($advertiser, $site, [
            'reference_code' => 'REF-OTHER',
            'order_number' => 'ORD-LIVE-1',
        ], [
            'live_url' => 'https://live-unique.example/guest-post-xyz',
        ]);
        $this->makeOrder($advertiser, $site, [
            'reference_code' => 'REF-NOISE',
            'order_number' => 'ORD-NOISE',
        ]);

        $refHits = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['search' => 'UNIQUE-ALPHA']))
            ->assertOk()
            ->json('orders');
        $this->assertCount(1, $refHits);
        $this->assertSame($byRef->id, $refHits[0]['id']);

        $liveHits = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['search' => 'guest-post-xyz']))
            ->assertOk()
            ->json('orders');
        $this->assertCount(1, $liveHits);
        $this->assertSame($byLive->id, $liveHits[0]['id']);
    }

    public function test_search_requires_every_token_and_matches_site_url_host(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Alpha Bravo Site');

        $match = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-AND-1',
            'reference_code' => 'REF-AND-1',
        ], [
            'site_name' => 'Alpha Bravo Site',
            'site_url' => 'https://www.alpha-bravo.example/blog',
        ]);
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-AND-2',
            'reference_code' => 'REF-AND-2',
        ], [
            'site_name' => 'Alpha Only Site',
            'site_url' => 'https://alpha-only.example',
        ]);

        $andHits = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['search' => 'Alpha Bravo']))
            ->assertOk()
            ->json('orders');
        $this->assertCount(1, $andHits);
        $this->assertSame($match->id, $andHits[0]['id']);

        $hostHits = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['search' => 'https://www.alpha-bravo.example/path']))
            ->assertOk()
            ->json('orders');
        $this->assertCount(1, $hostHits);
        $this->assertSame($match->id, $hostHits[0]['id']);
    }

    public function test_search_like_wildcards_are_neutralized(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Wildcard Site');

        $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-WILD-1',
            'reference_code' => 'REF-LITERAL-PERCENT',
        ]);

        $hits = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['search' => '%']))
            ->assertOk()
            ->json('orders');

        // "%" is stripped from the LIKE needle; bare empty needle should not match everything.
        $this->assertCount(0, $hits);
    }

    public function test_list_and_detail_include_items_count_for_multi_item_orders(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->siteFor($publisher, 'Multi A');
        $siteB = $this->siteFor($publisher, 'Multi B');

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-MULTI-1',
            'reference_code' => 'REF-MULTI-1',
            'subtotal' => 100,
            'tax' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        foreach ([$siteA, $siteB] as $site) {
            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 50,
                'content_link' => 'https://example.com/article.docx',
            ]);
        }

        $list = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->json('orders');

        $row = collect($list)->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['items_count']);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('order');

        $this->assertSame(2, $detail['items_count']);
        $this->assertCount(2, $detail['items']);
    }

    public function test_order_list_and_show_omit_stripe_payload_and_are_not_cached(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->makeOrder($advertiser, $this->siteFor($this->publisher()), [
            'payment_method' => 'card',
            'stripe_session_id' => 'cs_test_orders_secret_session',
            'stripe_payment_intent_id' => 'pi_test_orders_secret_intent',
            'stripe_response' => [
                'id' => 'cs_test_orders_secret_session',
                'client_secret' => 'orders_secret_xyz',
            ],
        ]);

        $list = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertStringContainsString('no-store', (string) $list->headers->get('Cache-Control'));
        $row = collect($list->json('orders'))->firstWhere('id', $order->id);
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('stripe_session_id', $row);
        $this->assertArrayNotHasKey('stripe_payment_intent_id', $row);
        $this->assertArrayNotHasKey('stripe_response', $row);
        $this->assertStringNotContainsString('cs_test_orders_secret_session', $list->getContent());
        $this->assertStringNotContainsString('pi_test_orders_secret_intent', $list->getContent());
        $this->assertStringNotContainsString('orders_secret_xyz', $list->getContent());

        $show = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.id', $order->id);

        $this->assertStringContainsString('no-store', (string) $show->headers->get('Cache-Control'));
        $payload = $show->json('order');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('stripe_session_id', $payload);
        $this->assertArrayNotHasKey('stripe_payment_intent_id', $payload);
        $this->assertArrayNotHasKey('stripe_response', $payload);
        $this->assertStringNotContainsString('cs_test_orders_secret_session', $show->getContent());
        $this->assertStringNotContainsString('pi_test_orders_secret_intent', $show->getContent());
        $this->assertStringNotContainsString('orders_secret_xyz', $show->getContent());
    }

    public function test_pagination_payload_includes_from_to_for_results_count(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        $this->makeOrder($advertiser, $site);

        $response = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonStructure([
            'pagination' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ]);
        $this->assertSame(1, $response->json('pagination.from'));
        $this->assertSame(1, $response->json('pagination.to'));
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertSame(1, $response->json('pagination.last_page'));
    }

    public function test_page_two_keeps_search_and_status_filters(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);

        for ($i = 1; $i <= 21; $i++) {
            $this->makeOrder($advertiser, $site, [
                'order_number' => sprintf('ORD-PAGE-%02d', $i),
                'status' => 'processing',
                'payment_status' => 'paid',
            ]);
        }

        $response = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', [
                'page' => 2,
                'search' => 'ORD-PAGE',
                'status' => 'processing',
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(2, $response->json('pagination.current_page'));
        $this->assertSame(2, $response->json('pagination.last_page'));
        $this->assertSame(21, $response->json('pagination.from'));
        $this->assertSame(21, $response->json('pagination.to'));
        $this->assertSame(21, $response->json('pagination.total'));
        $this->assertCount(1, $response->json('orders'));
        $this->assertStringContainsString('ORD-PAGE', $response->json('orders.0.order_number'));
    }

    public function test_list_puts_review_above_active_and_sinks_completed_and_cancelled(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Queue Site');

        $completed = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-Q-DONE',
            'status' => 'completed',
        ], [
            'live_url' => 'https://live.example/done',
        ]);
        $cancelled = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-Q-CXL',
            'status' => 'cancelled',
        ]);
        $processing = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-Q-PROC',
            'status' => 'processing',
        ]);
        $review = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-Q-REV',
            'status' => 'review',
        ], [
            'live_url' => 'https://live.example/review-me',
        ]);
        $completed->forceFill(['created_at' => now()->subDay()])->save();
        $cancelled->forceFill(['created_at' => now()->subHours(2)])->save();
        $processing->forceFill(['created_at' => now()->subHours(6)])->save();
        $review->forceFill(['created_at' => now()->subHours(8)])->save();

        $ids = collect($this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->json('orders'))->pluck('id')->all();

        $this->assertSame([
            $review->id,
            $processing->id,
            $cancelled->id,
            $completed->id,
        ], $ids);
    }

    public function test_completed_filter_is_newest_first_without_sink(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Completed Filter Site');

        $older = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-C-OLD',
            'status' => 'completed',
        ]);
        $newer = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-C-NEW',
            'status' => 'completed',
        ]);
        $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-C-PROC',
            'status' => 'processing',
        ]);
        $older->forceFill(['created_at' => now()->subDays(3)])->save();
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        $ids = collect($this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['status' => 'completed']))
            ->assertOk()
            ->json('orders'))->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_search_still_returns_completed_but_keeps_it_sunk(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Sink Search Site');

        $completed = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-SINK-DONE',
            'reference_code' => 'REF-SINK-SHARED',
            'status' => 'completed',
        ]);
        $processing = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-SINK-PROC',
            'reference_code' => 'REF-SINK-SHARED',
            'status' => 'processing',
        ]);
        $completed->forceFill(['created_at' => now()->subHour()])->save();
        $processing->forceFill(['created_at' => now()->subHours(3)])->save();

        $ids = collect($this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.list', ['search' => 'SINK-SHARED']))
            ->assertOk()
            ->json('orders'))->pluck('id')->all();

        $this->assertSame([$processing->id, $completed->id], $ids);
    }
}
