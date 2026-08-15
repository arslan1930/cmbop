<?php

namespace Tests\Feature;

use App\Mail\DisputeClawbackPublisher;
use App\Mail\DisputeRefundAdvertiser;
use App\Mail\OrderApprovedByAdvertiser;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\CheckoutIntentService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\EmailCatalog;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class OrderDisputeClawbackTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        Mail::fake();
    }

    private function makeUser(string $roleName): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Clawback Blog',
            'site_url' => 'https://clawback-blog.example',
            'domain' => 'clawback-blog.example',
            'example_url' => 'https://clawback-blog.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 100,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Clawback dispute site description. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeCompletedOrder(User $advertiser, Site $site, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CLAW-'.random_int(1000, 9999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDays(1),
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'publisher_price' => 100,
            'platform_fee_amount' => 15,
            'additional_price' => 0,
            'live_url' => 'https://clawback-blog.example/live-post',
        ]);

        return $order->fresh(['items']);
    }

    private function publisherWallet(User $publisher, float $balance = 100): Wallet
    {
        $roleId = Wallet::publisherRoleId();

        return Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $roleId,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function advertiserWallet(User $advertiser, float $balance = 0): Wallet
    {
        $roleId = Wallet::advertiserRoleId();

        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $roleId,
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_advertiser_can_open_dispute_within_window(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.orders.report-link-removed', $order->id),
            ['reason' => 'The live article was deleted two days after completion.']
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('order_item_disputes', [
            'order_id' => $order->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'opened_by' => $advertiser->id,
        ]);
    }

    public function test_advertiser_cannot_open_dispute_outside_window(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site, [
            'completed_at' => now()->subDays(45),
        ]);

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.orders.report-link-removed', $order->id),
            ['reason' => 'The live article was deleted long after completion.']
        );

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseCount('order_item_disputes', 0);
    }

    public function test_advertiser_cannot_dispute_non_completed_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site, ['status' => 'review']);

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.orders.report-link-removed', $order->id),
            ['reason' => 'Trying to dispute before completion is invalid.']
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('order_item_disputes', 0);
    }

    public function test_advertiser_cannot_dispute_unpaid_completed_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site, [
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $response = $this->actingAs($advertiser)->postJson(
            route('advertiser.orders.report-link-removed', $order->id),
            ['reason' => 'Trying to dispute an unpaid completed order.']
        );

        $response->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseCount('order_item_disputes', 0);
    }

    public function test_uphold_unpaid_completed_order_does_not_credit_advertiser(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site, [
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);
        $pubWallet = $this->publisherWallet($publisher, 100);
        $advWallet = $this->advertiserWallet($advertiser, 10);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Legacy unpaid completed row should not mint a refund.',
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed this unpaid row must not credit the advertiser.']
        );

        $response->assertStatus(422)->assertJson(['success' => false]);

        $pubWallet->refresh();
        $advWallet->refresh();
        $order->refresh();
        $dispute->refresh();

        $this->assertSame(100.0, (float) $pubWallet->balance);
        $this->assertSame(10.0, (float) $advWallet->balance);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(OrderItemDispute::STATUS_OPEN, $dispute->status);
    }

    public function test_uphold_with_full_balance_debits_publisher_and_credits_advertiser(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $pubWallet = $this->publisherWallet($publisher, 100);
        $advWallet = $this->advertiserWallet($advertiser, 10);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Live URL returns 404 after the publisher deleted the post.',
        ]);

        $response = $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed 404. Clawing back publisher payout and refunding advertiser.']
        );

        $response->assertOk()->assertJson(['success' => true]);

        $pubWallet->refresh();
        $advWallet->refresh();
        $order->refresh();
        $dispute->refresh();

        $this->assertSame(0.0, (float) $pubWallet->balance);
        $this->assertSame(0.0, (float) $pubWallet->debt_balance);
        $this->assertSame(125.0, (float) $advWallet->balance);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame('completed', $order->status);
        $this->assertSame(OrderItemDispute::STATUS_UPHELD, $dispute->status);
        $this->assertEquals(100.0, (float) $dispute->publisher_debited);
        $this->assertEquals(115.0, (float) $dispute->advertiser_credited);
        $this->assertEquals(0.0, (float) $dispute->debt_created);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $pubWallet->id,
            'type' => WalletTransaction::TYPE_TRANSFER_OUT,
            'direction' => 'debit',
            'amount' => 100,
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $advWallet->id,
            'type' => WalletTransaction::TYPE_REFUND,
            'direction' => 'credit',
            'amount' => 115,
        ]);

        Mail::assertQueued(DisputeClawbackPublisher::class);
        Mail::assertQueued(DisputeRefundAdvertiser::class);

        // Full clawback with no debt — withdrawal still allowed.
        $this->assertFalse($pubWallet->hasDebt());
        $this->assertTrue($pubWallet->canWithdraw(0.01) === false); // balance 0
    }

    public function test_uphold_releases_only_the_disputed_library_article(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $this->publisherWallet($publisher, 100);
        $this->advertiserWallet($advertiser, 0);

        $disputed = $order->items->first();
        $sibling = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/sibling-article',
            'price' => 80,
            'publisher_price' => 70,
            'platform_fee_amount' => 10,
            'additional_price' => 0,
            'live_url' => 'https://clawback-blog.example/sibling-post',
        ]);

        $disputedArticle = $this->createApprovedSubmission($advertiser);
        $siblingArticle = $this->createApprovedSubmission($advertiser);
        $disputedArticle->forceFill([
            'order_id' => $order->id,
            'order_item_id' => $disputed->id,
        ])->save();
        $siblingArticle->forceFill([
            'order_id' => $order->id,
            'order_item_id' => $sibling->id,
        ])->save();
        $disputed->update(['content_submission_id' => $disputedArticle->id]);
        $sibling->update(['content_submission_id' => $siblingArticle->id]);

        $this->assertFalse($disputedArticle->fresh()->canBeOrdered());
        $this->assertFalse($siblingArticle->fresh()->canBeOrdered());

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $disputed->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'The disputed placement was deleted after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed removal. Release only this library article.']
        )->assertOk();

        $this->assertTrue($disputedArticle->fresh()->canBeOrdered());
        $this->assertFalse($siblingArticle->fresh()->canBeOrdered());
        $this->assertNull($disputedArticle->fresh()->order_id);
        $this->assertSame($order->id, $siblingArticle->fresh()->order_id);
        $released = $disputedArticle->fresh();
        $this->assertFalse($released->isClaimedByAnotherOrder());
        $this->assertFalse($released->isLockedByPaidOrder());
        $this->assertTrue($released->isReadyForCheckout());
        $this->assertSame('available', $released->libraryAvailability());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($disputedArticle->id)->checkoutReady()->exists()
        );
        $this->assertFalse($siblingArticle->fresh()->isReadyForCheckout());
        $released = $released->load(['orderItems.disputes', 'orderItems.order']);
        $this->assertNull($released->liveUrl());
        $this->assertNull($released->placementItem());

        $this->actingAs($publisher)
            ->get(route('publisher.content.download', $disputedArticle))
            ->assertForbidden();
        $this->actingAs($publisher)
            ->get(route('publisher.content.download', $siblingArticle))
            ->assertOk();

        $disputedArticle->forceFill([
            'preview_html' => '<p>Edited after clawback — clawed publisher must not see this.</p>',
            'title' => 'Reused secret draft',
        ])->save();

        $this->actingAs($publisher)
            ->getJson(route('publisher.orders.details', $disputed->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.preview_html', null)
            ->assertJsonPath('data.content_download_url', null)
            ->assertJsonPath('data.content_link', null)
            ->assertJsonMissing(['Reused secret draft'])
            ->assertJsonMissing(['Edited after clawback']);
        $this->actingAs($publisher)
            ->getJson(route('publisher.orders.details', $sibling->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.preview_html', $siblingArticle->fresh()->preview_html)
            ->assertJsonPath('data.content_download_url', route('publisher.content.download', $siblingArticle));

        $this->actingAs($publisher)
            ->getJson(route('chat.messages', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order_details.content_link', null)
            ->assertJsonMissing(['Reused secret draft'])
            ->assertJsonMissing([route('publisher.content.download', $disputedArticle)]);

        $this->actingAs($advertiser)
            ->deleteJson(route('advertiser.content-submissions.destroy', $disputedArticle))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('content_submissions', ['id' => $disputedArticle->id]);

        $this->actingAs($advertiser)
            ->deleteJson(route('advertiser.content-submissions.destroy', $siblingArticle))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertDatabaseHas('content_submissions', ['id' => $siblingArticle->id]);
    }

    public function test_advertiser_can_report_a_sibling_placement_and_must_choose_a_line(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $firstPublisher = $this->makeUser('publisher');
        $secondPublisher = $this->makeUser('publisher');
        $firstSite = $this->makeSite($firstPublisher);
        $secondSite = Site::create([
            'publisher_id' => $secondPublisher->id,
            'site_name' => 'Sibling Report Blog',
            'site_url' => 'https://sibling-report.example',
            'domain' => 'sibling-report.example',
            'example_url' => 'https://sibling-report.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Sibling report site description. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CLAW-REPORT-'.random_int(1000, 9999),
            'subtotal' => 230,
            'tax' => 0,
            'total_amount' => 230,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDays(1),
        ]);
        $firstItem = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $firstSite->id,
            'site_name' => $firstSite->site_name,
            'site_url' => $firstSite->site_url,
            'content_link' => 'https://example.com/article-a',
            'price' => 115,
            'publisher_price' => 100,
            'live_url' => 'https://clawback-blog.example/live-a',
        ]);
        $secondItem = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $secondSite->id,
            'site_name' => $secondSite->site_name,
            'site_url' => $secondSite->site_url,
            'content_link' => 'https://example.com/article-b',
            'price' => 115,
            'publisher_price' => 100,
            'live_url' => 'https://sibling-report.example/live-b',
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.report-link-removed', $order->id), [
                'reason' => 'The second live article was deleted after completion.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.report-link-removed', $order->id), [
                'reason' => 'The second live article was deleted after completion.',
                'order_item_id' => $secondItem->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('order_item_disputes', [
            'order_id' => $order->id,
            'order_item_id' => $secondItem->id,
            'status' => OrderItemDispute::STATUS_OPEN,
        ]);
        $this->assertDatabaseMissing('order_item_disputes', [
            'order_item_id' => $firstItem->id,
        ]);

        $detail = $this->actingAs($advertiser)
            ->getJson(route('advertiser.orders.get', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.can_report_link_removed', true);

        $items = collect($detail->json('order.items'));
        $this->assertTrue((bool) $items->firstWhere('id', $firstItem->id)['can_report_link_removed']);
        $this->assertFalse((bool) $items->firstWhere('id', $secondItem->id)['can_report_link_removed']);
        $this->assertSame(OrderItemDispute::STATUS_OPEN, $items->firstWhere('id', $secondItem->id)['dispute_status']);
    }

    public function test_email_catalog_can_preview_dispute_mailables(): void
    {
        $clawback = EmailCatalog::makeMailable('dispute_clawback_publisher');
        $refund = EmailCatalog::makeMailable('dispute_refund_advertiser');

        $this->assertNotNull($clawback);
        $this->assertNotNull($refund);
        $this->assertStringContainsString('clawback', strtolower($clawback->render()));
        $this->assertStringContainsString('refund credited', strtolower($refund->render()));
    }

    public function test_uphold_with_partial_balance_creates_debt_and_blocks_withdrawal(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $pubWallet = $this->publisherWallet($publisher, 40);
        $this->advertiserWallet($advertiser, 0);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'Article deleted after publisher already withdrew most earnings.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Partial wallet balance; create debt for the remainder.']
        )->assertOk();

        $pubWallet->refresh();
        $dispute->refresh();

        $this->assertSame(0.0, (float) $pubWallet->balance);
        $this->assertSame(60.0, (float) $pubWallet->debt_balance);
        $this->assertEquals(40.0, (float) $dispute->publisher_debited);
        $this->assertEquals(60.0, (float) $dispute->debt_created);
        $this->assertTrue($pubWallet->hasDebt());

        // Credit some earnings later — still blocked by debt.
        $pubWallet->credit(80);
        $this->assertFalse($pubWallet->canWithdraw(10));

        $this->actingAs($publisher)->postJson(route('publisher.withdraw.request'), [
            'amount' => 10,
            'payment_method' => 'paypal',
            'paypal_email' => 'pub@example.com',
            'paypal_email_confirm' => 'pub@example.com',
            'details_confirmed' => '1',
        ])->assertStatus(422)->assertJsonPath('code', 'wallet_debt');
    }

    public function test_upholding_one_line_does_not_block_a_sibling_dispute(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $firstPublisher = $this->makeUser('publisher');
        $secondPublisher = $this->makeUser('publisher');
        $firstSite = $this->makeSite($firstPublisher);
        $secondSite = Site::create([
            'publisher_id' => $secondPublisher->id,
            'site_name' => 'Sibling Clawback Blog',
            'site_url' => 'https://sibling-clawback.example',
            'domain' => 'sibling-clawback.example',
            'example_url' => 'https://sibling-clawback.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Sibling clawback site description. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CLAW-MULTI-'.random_int(1000, 9999),
            'subtotal' => 230,
            'tax' => 0,
            'total_amount' => 230,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'completed_at' => now()->subDays(1),
        ]);
        $firstItem = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $firstSite->id,
            'site_name' => $firstSite->site_name,
            'site_url' => $firstSite->site_url,
            'content_link' => 'https://example.com/article-a',
            'price' => 115,
            'publisher_price' => 100,
            'live_url' => 'https://clawback-blog.example/live-a',
        ]);
        $secondItem = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $secondSite->id,
            'site_name' => $secondSite->site_name,
            'site_url' => $secondSite->site_url,
            'content_link' => 'https://example.com/article-b',
            'price' => 115,
            'publisher_price' => 100,
            'live_url' => 'https://sibling-clawback.example/live-b',
        ]);

        $this->publisherWallet($firstPublisher, 100);
        $this->publisherWallet($secondPublisher, 100);
        $advWallet = $this->advertiserWallet($advertiser, 0);

        $firstDispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $firstItem->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'First placement live URL was deleted after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $firstDispute->id),
            ['admin_notes' => 'First line confirmed removed; claw back only that publisher.']
        )->assertOk();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(115.0, (float) $advWallet->fresh()->balance, 0.01);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.open', $order->id),
            [
                'reason' => 'Second placement was also deleted after the first clawback.',
                'order_item_id' => $secondItem->id,
            ]
        )->assertOk();

        $secondDispute = OrderItemDispute::query()
            ->where('order_item_id', $secondItem->id)
            ->first();
        $this->assertNotNull($secondDispute);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $secondDispute->id),
            ['admin_notes' => 'Second line also removed; now the whole order is refunded.']
        )->assertOk();

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertEqualsWithDelta(230.0, (float) $advWallet->fresh()->balance, 0.01);
    }

    public function test_second_uphold_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $this->publisherWallet($publisher, 100);
        $this->advertiserWallet($advertiser, 0);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'First dispute for removed live link after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'First uphold applies clawback successfully now.']
        )->assertOk();

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Second uphold must be rejected as already resolved.']
        )->assertStatus(422);
    }

    public function test_dismiss_leaves_balances_unchanged(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $pubWallet = $this->publisherWallet($publisher, 100);
        $advWallet = $this->advertiserWallet($advertiser, 5);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'False alarm — the article is still live on the site.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.dismiss', $dispute->id),
            ['admin_notes' => 'Checked live URL; article is still published correctly.']
        )->assertOk();

        $pubWallet->refresh();
        $advWallet->refresh();
        $order->refresh();
        $dispute->refresh();

        $this->assertSame(100.0, (float) $pubWallet->balance);
        $this->assertSame(5.0, (float) $advWallet->balance);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(OrderItemDispute::STATUS_DISMISSED, $dispute->status);
    }

    public function test_manual_approve_sets_completed_at(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $this->publisherWallet($publisher, 0);
        $this->advertiserWallet($advertiser, 0);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-APPR-'.random_int(1000, 9999),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'stripe',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'publisher_price' => 100,
            'platform_fee_amount' => 15,
            'live_url' => 'https://clawback-blog.example/live',
        ]);

        $this->actingAs($advertiser)->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);
    }

    public function test_uphold_restores_purchase_bonus_as_spend_only(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $this->publisherWallet($publisher, 100);
        $advWallet = $this->advertiserWallet($advertiser, 10);

        app(WalletLedgerService::class)->recordPurchase(
            $advWallet,
            115,
            20,
            $order,
            $order->reference_code
        );

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $order->items->first()->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'The live article was deleted two days after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed 404. Refund must not turn promo credit into cash.']
        )->assertOk()->assertJson(['success' => true]);

        $advWallet->refresh();
        $this->assertEqualsWithDelta(125.0, (float) $advWallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $advWallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(105.0, $advWallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(20.0, $advWallet->lockedBonusBalance(), 0.01);
    }

    public function test_uphold_after_admin_mark_paid_restores_promo_as_spend_only(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $this->publisherWallet($publisher, 100);
        $advWallet = Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 0,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'debt_balance' => 0,
            'currency' => 'EUR',
        ]);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-ADMIN-CLAW-BONUS',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 80,
            'publisher_price' => 70,
            'platform_fee_amount' => 10,
            'additional_price' => 0,
        ]);
        app(CheckoutIntentService::class)->rememberBonus($advertiser->id, $order->reference_code, 20);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.updateStatus', $order->id), [
                'payment_status' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $advWallet->id,
            'type' => WalletTransaction::TYPE_PURCHASE,
            'reference' => $order->reference_code,
            'bonus_amount' => 20,
        ]);

        $order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://clawback-blog.example/admin-mark-paid-live',
            'live_url_submitted_at' => now()->subHour(),
            'accepted_at' => now()->subHours(2),
        ]);

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'The live article was deleted two days after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed 404. Admin mark-paid promo must stay spend-only.']
        )->assertOk()->assertJson(['success' => true]);

        $advWallet->refresh();
        $this->assertEqualsWithDelta(80.0, (float) $advWallet->balance, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $advWallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(60.0, $advWallet->withdrawableBalance(), 0.01);
        $this->assertEqualsWithDelta(20.0, $advWallet->lockedBonusBalance(), 0.01);
    }

    public function test_admin_can_clear_wallet_debt(): void
    {
        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $wallet->update(['debt_balance' => 60]);

        $this->actingAs($admin)->post(
            route('admin.finance.wallets.clear-debt', $wallet),
            ['reason' => 'Publisher settled debt offline via invoice.']
        )->assertRedirect();

        $wallet->refresh();
        $this->assertSame(0.0, (float) $wallet->debt_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_ADJUSTMENT,
            'amount' => 60,
        ]);
    }

    public function test_releasing_a_clawed_line_does_not_steal_a_reused_library_article(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $this->publisherWallet($publisher, 100);
        $this->advertiserWallet($advertiser, 0);

        $item = $order->items->first();
        $article = $this->createApprovedSubmission($advertiser);
        $article->forceFill([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ])->save();
        $item->update(['content_submission_id' => $article->id]);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'The disputed placement was deleted after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed removal.']
        )->assertOk();

        $this->assertNull($article->fresh()->order_id);

        $reuse = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-REUSE-AFTER-CLAW',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $reuseItem = OrderItem::create([
            'order_id' => $reuse->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/reused',
            'content_submission_id' => $article->id,
            'price' => 80,
        ]);
        $article->forceFill([
            'order_id' => $reuse->id,
            'order_item_id' => $reuseItem->id,
        ])->save();

        ContentSubmission::releaseAllForOrderItem((int) $item->id);

        $this->assertSame($reuse->id, (int) $article->fresh()->order_id);
        $this->assertSame($reuseItem->id, (int) $article->fresh()->order_item_id);
    }

    public function test_publisher_completion_email_hides_content_link_after_clawback(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $order = $this->makeCompletedOrder($advertiser, $site);
        $item = $order->items->first();

        $html = (new OrderApprovedByAdvertiser($order, $item, $site))->render();
        $this->assertStringContainsString('https://example.com/article', $html);
        $this->assertStringContainsString('View Content', $html);

        OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'The live link was removed after publication.',
            'admin_notes' => 'Upheld after the publisher pulled the placement.',
            'resolved_at' => now(),
        ]);

        $this->assertNull($item->publisherContentLink());
        $this->assertSame('https://example.com/article', $item->content_link);

        $retried = (new OrderApprovedByAdvertiser($order, $item, $site))->render();
        $this->assertStringNotContainsString('https://example.com/article', $retried);
        $this->assertStringNotContainsString('View Content', $retried);
        $this->assertStringContainsString('https://clawback-blog.example/live-post', $retried);
    }
}
