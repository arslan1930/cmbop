<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LiveUrlHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Covers the publisher side of an order: accept, reject (with refund) and
 * live-URL submission. These endpoints move money and order state but had no
 * direct controller tests.
 */
class PublisherOrderLifecycleTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    private User $publisher;

    private User $advertiser;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);

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

        $this->site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Lifecycle Site',
            'site_url' => 'https://lifecycle.example',
            'domain' => 'lifecycle.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 1200,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 80,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Publisher lifecycle test site',
            'verified' => true,
            'active' => true,
        ]);

        // Live URL checks must never hit the network during tests.
        $this->swap(LiveUrlHealthChecker::class, new class extends LiveUrlHealthChecker
        {
            public function check(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'checked_at' => now(), 'message' => 'OK'];
            }
        });
    }

    private function advertiserWallet(): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $this->advertiser->id, 'role_id' => Wallet::advertiserRoleId()],
            ['balance' => 0, 'reserved_balance' => 0, 'currency' => 'EUR']
        );
    }

    private function makeOrder(string $paymentMethod = 'wallet', string $paymentStatus = 'paid', float $amount = 80): OrderItem
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => $amount,
            'tax' => 0,
            'total_amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
            'status' => 'pending',
            'paid_at' => $paymentStatus === 'paid' ? now() : null,
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/article',
            'price' => $amount,
        ]);
    }

    public function test_a_second_publisher_can_accept_their_own_placement(): void
    {
        $item = $this->makeOrder();
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $other->roles()->attach($publisherRole->id);
        $otherSite = Site::create([
            'publisher_id' => $other->id,
            'site_name' => 'Sibling Site',
            'site_url' => 'https://sibling.example',
            'domain' => 'sibling.example',
            'da' => 20,
            'dr' => 20,
            'traffic' => 100,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => 40,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Second publisher site',
            'verified' => true,
            'active' => true,
        ]);
        $sibling = OrderItem::create([
            'order_id' => $item->order_id,
            'site_id' => $otherSite->id,
            'site_name' => $otherSite->site_name,
            'site_url' => $otherSite->site_url,
            'content_link' => 'https://docs.example/sibling',
            'price' => 40,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertOk();

        $this->actingAs($other)
            ->postJson(route('publisher.orders.accept', $sibling->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('processing', $item->order->fresh()->status);
        $this->assertSame('accepted', $sibling->fresh()->publisher_status);
        $this->assertNotNull($sibling->fresh()->accepted_at);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertStatus(422);
    }

    public function test_publisher_cannot_reaccept_a_processing_order(): void
    {
        $item = $this->makeOrder();
        $item->order->update(['status' => 'processing']);
        $item->update(['accepted_at' => now()->subHour(), 'publisher_status' => 'accepted']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('processing', $item->order->fresh()->status);
        $this->assertTrue($item->fresh()->accepted_at->equalTo($item->accepted_at));
    }

    public function test_publisher_cannot_submit_live_url_before_accepting(): void
    {
        $item = $this->makeOrder(); // pending

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://lifecycle.example/too-early',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($item->fresh()->live_url);
        $this->assertSame('pending', $item->order->fresh()->status);
    }

    public function test_publisher_accepting_a_paid_order_moves_it_to_processing(): void
    {
        $item = $this->makeOrder();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('processing', $item->order->fresh()->status);
        $this->assertNotNull($item->fresh()->accepted_at);
    }

    public function test_publisher_cannot_accept_an_unpaid_order(): void
    {
        $item = $this->makeOrder('card', 'pending');

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $item->order->fresh()->status);
    }

    public function test_publisher_cannot_accept_an_order_for_another_publishers_site(): void
    {
        $item = $this->makeOrder();

        $otherPublisher = User::factory()->create(['email_verified_at' => now()]);
        $otherPublisher->roles()->attach(Role::firstOrCreate(['name' => 'publisher'])->id);

        $this->actingAs($otherPublisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertStatus(403);

        $this->assertSame('pending', $item->order->fresh()->status);
    }

    public function test_rejecting_a_wallet_order_releases_reserved_funds(): void
    {
        $wallet = $this->advertiserWallet();
        $wallet->addBalance(200);
        $wallet->refresh()->reserveForOrder(80);

        $item = $this->makeOrder('wallet', 'paid', 80);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $wallet->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);

        $order = $item->order->fresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('refunded', $order->payment_status);
    }

    public function test_rejecting_a_card_order_credits_the_advertiser_wallet(): void
    {
        $wallet = $this->advertiserWallet();

        $item = $this->makeOrder('card', 'paid', 80);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'We cannot publish this content right now, sorry.',
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(80.0, (float) $wallet->fresh()->balance, 0.01);
    }

    public function test_rejecting_an_unpaid_order_does_not_credit_the_advertiser(): void
    {
        $wallet = $this->advertiserWallet();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);

        $item = $this->makeOrder('card', 'pending', 80);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'We cannot publish this content right now, sorry.',
            ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());

        $order = $item->order->fresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->payment_status);
    }

    public function test_rejecting_requires_a_meaningful_reason(): void
    {
        $item = $this->makeOrder();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), ['reason' => 'no'])
            ->assertStatus(422);

        $this->assertSame('pending', $item->order->fresh()->status);
    }

    public function test_an_order_cannot_be_rejected_twice(): void
    {
        $wallet = $this->advertiserWallet();
        $wallet->addBalance(80);
        $wallet->refresh()->reserveForOrder(80);

        $item = $this->makeOrder('wallet', 'paid', 80);
        $reason = 'Duplicate rejection guard check for this order.';

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), ['reason' => $reason])
            ->assertOk();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), ['reason' => $reason])
            ->assertStatus(400);

        // Exactly one refund: balance back to 80, not 160.
        $this->assertEqualsWithDelta(80.0, (float) $wallet->fresh()->balance, 0.01);
    }

    public function test_completed_orders_cannot_be_rejected_after_payout(): void
    {
        $item = $this->makeOrder();
        $item->order->update(['status' => 'completed']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'Trying to reject after the payout already happened.',
            ])
            ->assertStatus(400);

        $this->assertSame('completed', $item->order->fresh()->status);
    }

    public function test_submitting_a_live_url_moves_the_order_to_review(): void
    {
        $item = $this->makeOrder();
        $item->order->update(['status' => 'processing']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://lifecycle.example/published-article',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertSame('https://lifecycle.example/published-article', $item->live_url);
        $this->assertNotNull($item->live_url_submitted_at);
        $this->assertSame('review', $item->order->fresh()->status);
    }

    public function test_live_url_must_be_a_valid_url(): void
    {
        $item = $this->makeOrder();
        $item->order->update(['status' => 'processing']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), ['live_url' => 'not-a-url'])
            ->assertStatus(422);

        $this->assertNull($item->fresh()->live_url);
    }

    public function test_publisher_cannot_submit_a_live_url_for_another_publishers_order(): void
    {
        $item = $this->makeOrder();

        $otherPublisher = User::factory()->create(['email_verified_at' => now()]);
        $otherPublisher->roles()->attach(Role::firstOrCreate(['name' => 'publisher'])->id);

        $this->actingAs($otherPublisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://evil.example/not-mine',
            ])
            ->assertStatus(403);

        $this->assertNull($item->fresh()->live_url);
    }

    public function test_publisher_cannot_replace_live_url_after_payout(): void
    {
        $item = $this->makeOrder();
        $item->order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://lifecycle.example/original',
            'live_url_submitted_at' => now()->subDay(),
            'auto_approve_triggered' => true,
            'completed_at' => now()->subHour(),
            'publisher_status' => 'completed',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://lifecycle.example/replaced-after-payout',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('https://lifecycle.example/original', $item->fresh()->live_url);
    }

    public function test_rejecting_a_paid_order_releases_the_content_library_article(): void
    {
        $item = $this->makeOrder('card', 'paid', 80);
        $item->order->update(['status' => 'pending']);
        $submission = $this->createApprovedSubmission($this->advertiser);
        $submission->forceFill([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ])->save();
        $item->update(['content_submission_id' => $submission->id]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'We cannot publish this content right now, sorry.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $submission->refresh();
        $this->assertNull($submission->order_id);
        $this->assertNull($submission->order_item_id);
        $this->assertTrue($submission->canBeOrdered());
    }

    public function test_publisher_cannot_accept_an_upcoming_scheduled_order(): void
    {
        $item = $this->makeOrder();
        $item->order->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'UTC',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('pending', $item->order->fresh()->status);
        $this->assertTrue($item->order->fresh()->isAwaitingScheduledRelease());
    }

    public function test_publisher_cannot_reject_an_upcoming_scheduled_order(): void
    {
        $item = $this->makeOrder();
        $item->order->update([
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'UTC',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'We cannot wait for the scheduled publication date.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $order = $item->order->fresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertTrue($order->isAwaitingScheduledRelease());
    }

    public function test_publisher_cannot_submit_live_url_when_payment_is_not_paid(): void
    {
        $item = $this->makeOrder('card', 'failed');
        $item->order->update(['status' => 'processing']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://lifecycle.example/unpaid-live',
            ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertNull($item->fresh()->live_url);
        $this->assertSame('processing', $item->order->fresh()->status);
        $this->assertSame('failed', $item->order->fresh()->payment_status);
    }

    public function test_publisher_cannot_resubmit_live_url_on_a_cancelled_order(): void
    {
        $item = $this->makeOrder();
        $item->order->update(['status' => 'cancelled', 'payment_status' => 'paid']);
        $item->update([
            'live_url' => 'https://lifecycle.example/original',
            'live_url_submitted_at' => now()->subDay(),
            'modification_requested' => 'yes',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.resubmit', $item->id), [
                'live_url' => 'https://lifecycle.example/after-cancel',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('https://lifecycle.example/original', $item->fresh()->live_url);
        $this->assertSame('yes', $item->fresh()->modification_requested);
        $this->assertSame('cancelled', $item->order->fresh()->status);
    }
}
