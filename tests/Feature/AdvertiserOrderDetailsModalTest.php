<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\AdvertiserOrderDetails;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvertiserOrderDetailsModalTest extends TestCase
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

    private function siteFor(User $publisher, string $name = 'Details Site'): Site
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?: 'details-site');

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

    /**
     * @param  array<string, mixed>  $orderAttrs
     * @param  array<string, mixed>|null  $itemAttrs  null skips creating a line item
     */
    private function makeOrder(User $advertiser, Site $site, array $orderAttrs = [], ?array $itemAttrs = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-DET-'.uniqid(),
            'reference_code' => 'REF-DET-'.uniqid(),
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now()->subDay(),
        ], $orderAttrs));

        if ($itemAttrs !== null) {
            OrderItem::create(array_merge([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 50,
                'content_link' => 'https://example.com/article.docx',
                'anchor_text' => 'guest post',
                'target_url' => 'https://advertiser.example/page',
            ], $itemAttrs));
        }

        return $order->fresh('items');
    }

    public function test_get_order_completed_includes_items_live_url_and_flags(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Live Placement Site');
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'completed_at' => now()->subHour(),
            'total_amount' => 80,
        ], [
            'live_url' => 'https://live.example/completed-post',
            'live_url_submitted_at' => now()->subHours(6),
            'completed_at' => now()->subHour(),
        ]);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('order');

        $this->assertSame(1, $detail['items_count']);
        $this->assertFalse($detail['placements_missing']);
        $this->assertTrue($detail['has_live_url']);
        $this->assertCount(1, $detail['items']);
        $this->assertSame('https://live.example/completed-post', $detail['items'][0]['live_url']);
        $this->assertSame('Live Placement Site', $detail['items'][0]['site_name']);
        $this->assertArrayHasKey('visit_url', $detail['items'][0]);
        $this->assertArrayHasKey('can_report_link_removed', $detail['items'][0]);
        $this->assertSame('Completed', $detail['status_label']);
        $this->assertStringContainsString('Your post is live', $detail['next_action']);
        $this->assertStringContainsString('Report link removed', $detail['policy_note']);
        $this->assertNotEmpty($detail['timeline_steps']);
        $urlStep = collect($detail['timeline_steps'])->firstWhere('label', 'URL delivered');
        $this->assertTrue($urlStep['done']);
    }

    public function test_get_order_review_includes_review_flags(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Review Site');
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'review',
        ], [
            'live_url' => 'https://live.example/review-me',
            'live_url_submitted_at' => now()->subHours(2),
            'modification_requested' => 'no',
        ]);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->json('order');

        $this->assertFalse($detail['placements_missing']);
        $this->assertTrue($detail['has_live_url']);
        $this->assertTrue($detail['can_approve']);
        $this->assertTrue($detail['can_request_changes']);
        $this->assertSame('URL delivered · your review', $detail['status_label']);
        $this->assertStringContainsString('auto-approve', strtolower($detail['policy_note']));
        $this->assertCount(1, $detail['items']);
        $this->assertSame('https://live.example/review-me', $detail['items'][0]['live_url']);
    }

    public function test_get_order_completed_without_items_is_honest(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site, [
            'order_number' => 'ORD-797026',
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_amount' => 103.50,
            'completed_at' => now()->subDay(),
        ], null);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->json('order');

        $this->assertSame([], $detail['items']);
        $this->assertSame(0, $detail['items_count']);
        $this->assertTrue($detail['placements_missing']);
        $this->assertFalse($detail['has_live_url']);
        $this->assertStringContainsString('no line items', $detail['empty_items_message']);
        $this->assertStringContainsString('ORD-797026', $detail['empty_items_message']);
        $this->assertStringNotContainsString('No placements', $detail['empty_items_message']);
        $this->assertStringNotContainsString('paid for this placement', $detail['next_action']);
        $this->assertStringContainsString('Placement details are missing', $detail['next_action']);
        $this->assertSame('', $detail['policy_note']);

        $urlStep = collect($detail['timeline_steps'])->firstWhere('label', 'URL delivered');
        $this->assertFalse($urlStep['done']);
        $completedStep = collect($detail['timeline_steps'])->firstWhere('label', 'Completed');
        $this->assertTrue($completedStep['current']);
        $this->assertFalse($completedStep['done']);
    }

    public function test_completed_with_items_but_no_live_url_does_not_offer_report(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Complete No Url');
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ]);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->json('order');

        $this->assertFalse($detail['placements_missing']);
        $this->assertFalse($detail['has_live_url']);
        $this->assertSame('', $detail['policy_note']);
        $this->assertStringNotContainsString('Report link removed', $detail['next_action']);
        $this->assertStringNotContainsString('paid for this placement', $detail['next_action']);
        $this->assertStringContainsString('Placement finished', $detail['next_action']);
    }

    public function test_completed_html_contract_has_live_url_and_honest_empty_state(): void
    {
        $js = file_get_contents(public_path('assets/js/advertiser-orders.js'));
        $this->assertIsString($js);
        $this->assertStringContainsString('emptyPlacementsHtml', $js);
        $this->assertStringContainsString('placements_missing', $js);
        $this->assertStringContainsString('This order has no line items on file', $js);
        $this->assertStringContainsString('Open live URL', $js);
        $this->assertStringContainsString('copyOrderLiveUrl', $js);
        $this->assertMatchesRegularExpression('/requestModification\\s*=\\s*function[\\s\\S]*hideOrderDetailsModal/', $js);
        $this->assertMatchesRegularExpression('/reportLinkRemoved\\s*=\\s*function[\\s\\S]*hideOrderDetailsModal/', $js);
        $this->assertMatchesRegularExpression('/fulfillContentRevision\\s*=\\s*function[\\s\\S]*hideOrderDetailsModal/', $js);
        $this->assertMatchesRegularExpression('/retryOrderPayment\\s*=\\s*function[\\s\\S]*hideOrderDetailsModal/', $js);
        $this->assertMatchesRegularExpression('/recheckLiveUrl\\s*=\\s*function[\\s\\S]{0,1500}hideOrderDetailsModal/', $js);
        $this->assertStringContainsString('ov-live-url', $js);
        $this->assertStringContainsString('ui-callout--info', $js);
        $this->assertStringNotContainsString('ov-empty-placements ui-callout ui-callout--attention', $js);
        $this->assertStringContainsString('order-view-shell--stack', $js);
        $this->assertStringContainsString('} else if (status === \'review\' && hasLiveUrl) {', $js);
        $this->assertStringContainsString("typeof order.policy_note === 'string'", $js);
        $this->assertStringNotContainsString("order.policy_note || 'If a published link is later removed", $js);
        $this->assertStringContainsString('Reconstructed from order dates', $js);
        $this->assertMatchesRegularExpression(
            '/function loadOrderActivityTimeline[\\s\\S]{0,1800}reconstructOrderActivities/',
            $js,
            'A failed timeline fetch must still reconstruct activity from order dates'
        );
        $liveCardPos = strpos($js, '${liveUrlHtml}');
        $documentPos = strpos($js, "ovBlock('Document'");
        $this->assertNotFalse($liveCardPos);
        $this->assertNotFalse($documentPos);
        $this->assertLessThan($documentPos, $liveCardPos, 'Completed card must lead with the live URL before article fields');
        $this->assertStringNotContainsString("|| '<div class=\"text-muted\">No placements on this order.</div>'", $js);

        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Html Live Site');
        $live = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'completed_at' => now(),
        ], [
            'live_url' => 'https://live.example/html-card',
            'live_url_submitted_at' => now()->subHour(),
        ]);
        $orphan = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'total_amount' => 103.50,
            'completed_at' => now(),
        ], null);

        $liveDetail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $live->id))
            ->json('order');
        $orphanDetail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $orphan->id))
            ->json('order');

        $liveHtml = $this->composeDetailsCopy($liveDetail);
        $this->assertStringContainsString('https://live.example/html-card', $liveHtml);
        $this->assertStringContainsString('Open live URL', $js);

        $orphanHtml = $this->composeDetailsCopy($orphanDetail);
        $this->assertStringNotContainsString('No placements', $orphanHtml);
        $this->assertStringNotContainsString('paid for this placement', $orphanHtml);
        $this->assertStringContainsString('no line items', $orphanHtml);
    }

    public function test_timeline_is_ok_for_advertiser_and_forbidden_for_stranger(): void
    {
        $advertiser = $this->advertiser();
        $stranger = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ], [
            'live_url' => 'https://live.example/timeline',
            'live_url_submitted_at' => now()->subHours(3),
        ]);

        $ok = $this->actingAs($advertiser)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_id', $order->id);

        $activities = $ok->json('activities');
        $this->assertIsArray($activities);
        $this->assertNotEmpty($activities);

        $this->actingAs($stranger)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_empty_activity_timeline_is_reconstructed_from_order_dates(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher);
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ], null);

        $response = $this->actingAs($advertiser)
            ->getJson(route('notifications.order-timeline', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reconstructed', true);

        $titles = collect($response->json('activities'))->pluck('title')->all();
        $this->assertContains('Paid', $titles);
        $this->assertContains('Completed', $titles);
        $this->assertSame(
            'Reconstructed from order dates.',
            $response->json('activities.0.description')
        );
    }

    public function test_get_order_survives_leftover_unparseable_item_dates(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'Leftover Dates Site');
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'completed_at' => now(),
        ], [
            'live_url' => 'https://live.example/leftover-dates',
            'live_url_submitted_at' => now()->subDay(),
            'accepted_at' => now()->subDays(2),
            'completed_at' => now(),
        ]);
        $item = $order->items->first();
        DB::table('order_items')->where('id', $item->id)->update([
            'live_url_submitted_at' => 'not-a-date',
            'accepted_at' => 'also-not-a-date',
            'completed_at' => 'still-not-a-date',
        ]);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('order');

        $this->assertSame('https://live.example/leftover-dates', $detail['items'][0]['live_url']);
        $this->assertNull($detail['items'][0]['live_url_submitted_at']);
        $this->assertNull($detail['items'][0]['accepted_at']);
        $this->assertNull($detail['items'][0]['completed_at']);
    }

    public function test_get_order_items_do_not_embed_the_site_model(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->siteFor($publisher, 'No Nested Site');
        $order = $this->makeOrder($advertiser, $site, [
            'status' => 'completed',
            'completed_at' => now(),
        ], [
            'live_url' => 'https://live.example/no-nested-site',
        ]);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('order');

        $this->assertSame('No Nested Site', $detail['items'][0]['site_name']);
        $this->assertSame('https://live.example/no-nested-site', $detail['items'][0]['live_url']);
        $this->assertArrayNotHasKey('site', $detail['items'][0]);
        $this->assertArrayNotHasKey('latest_dispute', $detail['items'][0]);
    }

    public function test_stacked_modal_css_scrolls_instead_of_clipping(): void
    {
        $css = file_get_contents(public_path('assets/css/advertiser-orders.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('.order-view-shell--stack', $css);
        $this->assertStringContainsString('.order-details-body:has(.order-view-shell--stack)', $css);
        $this->assertStringContainsString('overflow-y: auto', $css);
        $this->assertStringNotContainsString('#orderDetailsModal {\n    z-index: 1080;', $css);
        $this->assertStringNotContainsString('z-index: 1075', $css);
    }

    public function test_status_meta_and_steps_do_not_claim_a_placement_without_items(): void
    {
        $advertiser = $this->advertiser();
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-EMPTY-META',
            'reference_code' => 'REF-EMPTY-META',
            'subtotal' => 103.50,
            'tax' => 0,
            'total_amount' => 103.50,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDay(),
        ]);
        $order->load('items');

        $meta = AdvertiserOrderStatus::meta($order);
        $this->assertStringNotContainsString('paid for this placement', $meta['next']);

        $steps = AdvertiserOrderStatus::timelineSteps($order);
        $this->assertFalse(collect($steps)->firstWhere('label', 'URL delivered')['done']);
        $this->assertFalse(collect($steps)->firstWhere('label', 'Accepted')['done']);
        $this->assertTrue(AdvertiserOrderDetails::placementsMissing($order));

        $review = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-REVIEW-NO-URL',
            'reference_code' => 'REF-REVIEW-NO-URL',
            'subtotal' => 50,
            'tax' => 0,
            'total_amount' => 50,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);
        $review->load('items');
        $reviewMeta = AdvertiserOrderStatus::meta($review);
        $this->assertSame('In review', $reviewMeta['label']);
        $this->assertStringContainsString('Waiting for live URL', $reviewMeta['next']);

        $reviewSteps = AdvertiserOrderStatus::timelineSteps($review);
        $this->assertFalse(collect($reviewSteps)->firstWhere('label', 'URL delivered')['current']);
        $this->assertFalse(collect($reviewSteps)->firstWhere('label', 'URL delivered')['done']);
        $this->assertTrue(collect($reviewSteps)->firstWhere('label', 'Processing')['current']);
        $this->assertFalse(collect($reviewSteps)->firstWhere('label', 'Processing')['done']);
    }

    public function test_review_status_uses_any_line_with_a_live_url(): void
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $siteA = $this->siteFor($publisher, 'First No Url');
        $siteB = $this->siteFor($publisher, 'Second Has Url');
        $order = $this->makeOrder($advertiser, $siteA, [
            'status' => 'review',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $siteB->id,
            'site_name' => $siteB->site_name,
            'site_url' => $siteB->site_url,
            'price' => 50,
            'content_link' => 'https://example.com/article-2.docx',
            'live_url' => 'https://live.example/second-line',
            'live_url_submitted_at' => now(),
        ]);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->json('order');

        $this->assertSame('URL delivered · your review', $detail['status_label']);
        $this->assertTrue($detail['has_live_url']);
        $this->assertTrue($detail['can_approve']);
        $urlStep = collect($detail['timeline_steps'])->firstWhere('label', 'URL delivered');
        $this->assertTrue($urlStep['current']);
        $this->assertFalse($urlStep['done']);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function composeDetailsCopy(array $order): string
    {
        $parts = [
            (string) ($order['next_action'] ?? ''),
            (string) ($order['empty_items_message'] ?? ''),
            (string) ($order['policy_note'] ?? ''),
        ];
        foreach ($order['items'] ?? [] as $item) {
            $parts[] = (string) ($item['live_url'] ?? '');
            $parts[] = (string) ($item['site_name'] ?? '');
        }

        return implode("\n", $parts);
    }
}
