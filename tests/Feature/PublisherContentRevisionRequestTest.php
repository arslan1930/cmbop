<?php

namespace Tests\Feature;

use App\Mail\ContentRevisionFulfilled;
use App\Mail\ContentRevisionRequested;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AdvertiserOrderStatus;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class PublisherContentRevisionRequestTest extends TestCase
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
            'site_name' => 'Revision Site',
            'site_url' => 'https://revision.example',
            'domain' => 'revision.example',
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
            'description' => 'Content revision test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function makeProcessingItem(): OrderItem
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/old-article',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
        ]);
    }

    public function test_publisher_can_request_content_revision_after_accept(): void
    {
        $item = $this->makeProcessingItem();
        $reason = 'Please fix the brand spelling and shorten the intro paragraph.';

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.request-content-revision', $item->id), [
                'reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertTrue($item->isContentRevisionRequested());
        $this->assertSame($reason, $item->content_revision_reason);

        Mail::assertQueued(ContentRevisionRequested::class);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->advertiser->id)
                ->where('type', 'content_revision_requested')
                ->exists()
        );
        $this->assertTrue(
            OrderChatMessage::query()
                ->where('order_id', $item->order_id)
                ->where('sender_type', 'publisher')
                ->where('message', 'like', 'Revised article requested:%')
                ->exists()
        );
    }

    public function test_publisher_can_update_reason_while_revision_open(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now()->subHour(),
            'content_revision_reason' => 'Please fix the brand spelling and shorten the intro.',
        ]);

        $updated = 'Also remove the competitor mention in paragraph two please.';

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.request-content-revision', $item->id), [
                'reason' => $updated,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated', true);

        $item->refresh();
        $this->assertTrue($item->isContentRevisionRequested());
        $this->assertSame($updated, $item->content_revision_reason);
        Mail::assertNotQueued(ContentRevisionRequested::class);
        $this->assertTrue(
            OrderChatMessage::query()
                ->where('order_id', $item->order_id)
                ->where('message', 'like', 'Revised article request updated:%')
                ->exists()
        );
    }

    public function test_publisher_cannot_request_content_revision_before_accept(): void
    {
        $item = $this->makeProcessingItem();
        $item->order->update(['status' => 'pending']);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.request-content-revision', $item->id), [
                'reason' => 'Please send a cleaner draft with correct links.',
            ])
            ->assertStatus(422);

        $this->assertFalse($item->fresh()->isContentRevisionRequested());
    }

    public function test_live_url_submit_blocked_while_content_revision_open(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Need a shorter draft for our guidelines.',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://revision.example/post',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_live_url_resubmit_blocked_while_content_revision_open(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Need a shorter draft for our guidelines.',
            'modification_requested' => 'yes',
            'modification_requested_at' => now(),
            'live_url' => 'https://revision.example/old-post',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.resubmit', $item->id), [
                'live_url' => 'https://revision.example/new-post',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_fulfill_targets_item_with_open_revision_not_first_line(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $first = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/first',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'no',
        ]);

        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/second-old',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the second placement article.',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_link' => 'https://docs.example/second-new',
                'order_item_id' => $second->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($second->fresh()->isContentRevisionRequested());
        $this->assertSame('https://docs.example/second-new', $second->fresh()->content_link);
        $this->assertSame('https://docs.example/first', $first->fresh()->content_link);
        $this->assertSame('no', $first->fresh()->content_revision_requested ?? 'no');
    }

    public function test_fulfill_without_item_id_is_rejected_when_two_revisions_are_open(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $first = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/first-old',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Revise the first placement.',
        ]);
        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/second-old',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Revise the second placement.',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_link' => 'https://docs.example/ambiguous',
            ])
            ->assertStatus(422);

        $this->assertTrue($first->fresh()->isContentRevisionRequested());
        $this->assertTrue($second->fresh()->isContentRevisionRequested());
        $this->assertSame('https://docs.example/first-old', $first->fresh()->content_link);
        $this->assertSame('https://docs.example/second-old', $second->fresh()->content_link);
    }

    public function test_fulfill_without_item_id_picks_open_revision_line(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/first',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'no',
        ]);

        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/second-old',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the second placement article.',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_link' => 'https://docs.example/second-auto',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($second->fresh()->isContentRevisionRequested());
        $this->assertSame('https://docs.example/second-auto', $second->fresh()->content_link);
    }

    public function test_advertiser_fulfills_content_revision_with_link(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please send a cleaner draft with correct links.',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'content_link' => 'https://docs.example/new-article',
                'note' => 'Updated intro and brand mentions.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertFalse($item->isContentRevisionRequested());
        $this->assertSame('https://docs.example/new-article', $item->content_link);
        $this->assertNotNull($item->content_revision_resolved_at);

        Mail::assertQueued(ContentRevisionFulfilled::class);
        $this->assertTrue(
            InAppNotification::query()
                ->where('user_id', $this->publisher->id)
                ->where('type', 'content_revision_fulfilled')
                ->exists()
        );
    }

    public function test_advertiser_cannot_fulfill_content_revision_when_unpaid(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please send a cleaner draft with correct links.',
        ]);
        $item->order->update([
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'content_link' => 'https://docs.example/unpaid-article',
            ])
            ->assertStatus(422);

        $item->refresh();
        $this->assertTrue($item->isContentRevisionRequested());
        $this->assertSame('https://docs.example/old-article', $item->content_link);
    }

    public function test_publisher_can_cancel_after_accept_and_refunds_advertiser(): void
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $this->advertiser->id, 'role_id' => Wallet::advertiserRoleId()],
            ['balance' => 0, 'reserved_balance' => 0, 'currency' => 'EUR']
        );
        $wallet->addBalance(200);
        $wallet->refresh()->reserveForOrder(80);

        $item = $this->makeProcessingItem();

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'We cannot publish this niche after editorial review.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order = $item->order->fresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('refunded', $order->payment_status);
        $wallet->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
    }

    public function test_multi_item_cancel_refunds_full_order_total(): void
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $this->advertiser->id, 'role_id' => Wallet::advertiserRoleId()],
            ['balance' => 0, 'reserved_balance' => 0, 'currency' => 'EUR']
        );
        $wallet->addBalance(200);
        $wallet->refresh()->reserveForOrder(160);

        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $first = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/first',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the first placement article.',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/second',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://revision.example/second-post',
            'live_url_submitted_at' => now(),
            'content_revision_requested' => 'no',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.reject', $first->id), [
                'reason' => 'We cannot fulfill either placement after editorial review.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('refunded', $order->payment_status);

        $wallet->refresh();
        $this->assertEqualsWithDelta(200.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
    }

    public function test_email_catalog_includes_content_revision_mailables(): void
    {
        $types = EmailCatalog::all();
        $this->assertArrayHasKey('content_revision_requested', $types);
        $this->assertArrayHasKey('content_revision_fulfilled', $types);

        $requested = EmailCatalog::makeMailable('content_revision_requested');
        $fulfilled = EmailCatalog::makeMailable('content_revision_fulfilled');
        $this->assertNotNull($requested);
        $this->assertNotNull($fulfilled);
        $this->assertStringContainsString('revised article', strtolower($requested->render()));
        $this->assertStringContainsString('revised article', strtolower($fulfilled->render()));
    }

    public function test_publisher_tasks_page_exposes_content_revision_and_cancel_hooks(): void
    {
        $page = $this->actingAs($this->publisher)->get(route('publisher.tasks'));
        $page->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('request-content-revision', $html);
        $this->assertStringContainsString('contentRevisionModal', $html);
        $this->assertStringContainsString('Cancel order', $html);
        $this->assertStringContainsString('Update reason', $html);
        $this->assertStringContainsString('has_open_content_revision', $html);
        $this->assertStringContainsString('orderHeldForContentRevision', $html);
    }

    public function test_library_item_rejects_content_link_only_fulfill(): void
    {
        $submission = $this->createApprovedSubmission($this->advertiser);
        $item = $this->makeProcessingItem();
        $item->update([
            'content_submission_id' => $submission->id,
            'content_original_name' => $submission->original_filename,
            'content_disk' => $submission->disk,
            'content_path' => $submission->path,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please tighten the intro and fix brand spelling.',
        ]);
        $submission->update([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'content_link' => 'https://docs.example/external-only',
                'order_item_id' => $item->id,
            ])
            ->assertStatus(422);

        $this->assertTrue($item->fresh()->isContentRevisionRequested());
    }

    public function test_library_item_can_confirm_existing_edited_article(): void
    {
        $submission = $this->createApprovedSubmission($this->advertiser);
        $item = $this->makeProcessingItem();
        $item->update([
            'content_submission_id' => $submission->id,
            'content_original_name' => $submission->original_filename,
            'content_disk' => $submission->disk,
            'content_path' => $submission->path,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please tighten the intro and fix brand spelling.',
        ]);
        $submission->update([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'confirm_existing' => true,
                'note' => 'Edited the existing library article.',
                'order_item_id' => $item->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertFalse($item->isContentRevisionRequested());
        $this->assertSame($submission->id, (int) $item->content_submission_id);
        Mail::assertQueued(ContentRevisionFulfilled::class);
    }

    public function test_library_item_cannot_confirm_existing_article_without_image_rights(): void
    {
        $submission = $this->createApprovedSubmission($this->advertiser);
        $submission->update([
            'preview_html' => '<p>Updated piece with a photo.</p><p><img src="/storage/content-articles/demo.png" alt=""></p>',
            'image_rights' => null,
        ]);
        $item = $this->makeProcessingItem();
        $item->update([
            'content_submission_id' => $submission->id,
            'content_original_name' => $submission->original_filename,
            'content_disk' => $submission->disk,
            'content_path' => $submission->path,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please replace the hero image.',
        ]);
        $submission->update([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'confirm_existing' => true,
                'note' => 'Kept the existing library article.',
                'order_item_id' => $item->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_existing']);

        $this->assertTrue($item->fresh()->isContentRevisionRequested());
        Mail::assertNotQueued(ContentRevisionFulfilled::class);
    }

    public function test_library_item_can_reattach_another_approved_article(): void
    {
        $current = $this->createApprovedSubmission($this->advertiser);
        $replacement = $this->createApprovedSubmission($this->advertiser);
        $replacement->update(['title' => 'Replacement Piece']);

        $item = $this->makeProcessingItem();
        $item->update([
            'content_submission_id' => $current->id,
            'content_original_name' => $current->original_filename,
            'content_disk' => $current->disk,
            'content_path' => $current->path,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please send a cleaner draft from the library.',
        ]);
        $current->update([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'content_submission_id' => $replacement->id,
                'order_item_id' => $item->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item->refresh();
        $this->assertFalse($item->isContentRevisionRequested());
        $this->assertSame($replacement->id, (int) $item->content_submission_id);
        $this->assertNull($current->fresh()->order_id);
        $this->assertSame($item->order_id, (int) $replacement->fresh()->order_id);
    }

    public function test_library_item_cannot_reattach_an_article_with_an_incomplete_link(): void
    {
        $current = $this->createApprovedSubmission($this->advertiser);
        $replacement = $this->createApprovedSubmission($this->advertiser);
        $replacement->update([
            'title' => 'Broken Link Piece',
            'target_url' => null,
        ]);

        $item = $this->makeProcessingItem();
        $item->update([
            'content_submission_id' => $current->id,
            'content_original_name' => $current->original_filename,
            'content_disk' => $current->disk,
            'content_path' => $current->path,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please attach a cleaner draft from the library.',
        ]);
        $current->update([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'content_submission_id' => $replacement->id,
                'order_item_id' => $item->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content_submission_id']);

        $this->assertTrue($item->fresh()->isContentRevisionRequested());
        $this->assertSame($current->id, (int) $item->fresh()->content_submission_id);
        Mail::assertNotQueued(ContentRevisionFulfilled::class);
    }

    public function test_library_item_cannot_confirm_existing_with_an_incomplete_link(): void
    {
        $submission = $this->createApprovedSubmission($this->advertiser);
        $submission->update(['anchor_text' => 'only the label', 'target_url' => null]);
        $item = $this->makeProcessingItem();
        $item->update([
            'content_submission_id' => $submission->id,
            'content_original_name' => $submission->original_filename,
            'content_disk' => $submission->disk,
            'content_path' => $submission->path,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please fix the outbound link.',
        ]);
        $submission->update([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $item->order_id), [
                'confirm_existing' => true,
                'note' => 'Kept the existing library article.',
                'order_item_id' => $item->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_existing']);

        $this->assertTrue($item->fresh()->isContentRevisionRequested());
        Mail::assertNotQueued(ContentRevisionFulfilled::class);
    }

    public function test_revision_options_omit_articles_with_incomplete_links(): void
    {
        $ready = $this->createApprovedSubmission($this->advertiser);
        $broken = $this->createApprovedSubmission($this->advertiser);
        $broken->update(['title' => 'Incomplete Link Article', 'target_url' => null]);
        $item = $this->makeProcessingItem();
        $item->update([
            'content_submission_id' => $ready->id,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
        ]);
        $ready->update([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
        ]);

        $response = $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.orders.content-revision-options', $item->order_id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $orderableIds = collect($response->json('orderable'))->pluck('id')->all();
        $this->assertNotContains($broken->id, $orderableIds);
    }

    public function test_sibling_live_url_submit_keeps_order_processing_while_revision_open(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $waiting = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/waiting',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the first placement article.',
        ]);

        $ready = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/ready',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'no',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $ready->id), [
                'live_url' => 'https://revision.example/sibling-post',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertTrue($waiting->fresh()->isContentRevisionRequested());
        $this->assertSame('https://revision.example/sibling-post', $ready->fresh()->live_url);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_link' => 'https://docs.example/waiting-fixed',
                'order_item_id' => $waiting->id,
            ])
            ->assertOk();

        $this->assertFalse($waiting->fresh()->isContentRevisionRequested());
        // Waiting line still has no live URL, so order stays in processing (not stranded).
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('https://docs.example/waiting-fixed', $waiting->fresh()->content_link);
    }

    public function test_cannot_reattach_library_article_used_by_sibling_line(): void
    {
        $firstSubmission = $this->createApprovedSubmission($this->advertiser);
        $secondSubmission = $this->createApprovedSubmission($this->advertiser);

        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $first = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_submission_id' => $firstSubmission->id,
            'content_link' => 'https://docs.example/first-lib',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'no',
        ]);
        $firstSubmission->update(['order_id' => $order->id, 'order_item_id' => $first->id]);

        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_submission_id' => $secondSubmission->id,
            'content_link' => 'https://docs.example/second-lib',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please attach a different library article.',
        ]);
        $secondSubmission->update(['order_id' => $order->id, 'order_item_id' => $second->id]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_submission_id' => $firstSubmission->id,
                'order_item_id' => $second->id,
            ])
            ->assertStatus(422);

        $this->assertTrue($second->fresh()->isContentRevisionRequested());
        $this->assertSame($secondSubmission->id, (int) $second->fresh()->content_submission_id);
    }

    public function test_auto_approve_blocked_while_content_revision_open(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'live_url' => 'https://revision.example/post',
            'live_url_submitted_at' => now()->subHours(OrderItem::autoApproveHours() + 1),
            'live_url_check_ok' => true,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Need a shorter draft for our guidelines.',
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        $this->assertFalse($item->fresh()->isReadyForAutoApprove());
    }

    public function test_needs_action_count_includes_open_content_revision(): void
    {
        $reviewOnly = $this->makeProcessingItem();
        $reviewOnly->order->update(['status' => 'review']);
        $reviewOnly->update([
            'live_url' => 'https://revision.example/ready',
            'live_url_submitted_at' => now(),
            'content_revision_requested' => 'no',
        ]);

        $revisionWaiting = $this->makeProcessingItem();
        $revisionWaiting->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the article before we publish.',
        ]);

        $this->assertSame(
            2,
            AdvertiserOrderStatus::needsActionCountForUser((int) $this->advertiser->id)
        );

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.orders.list'))
            ->assertOk()
            ->assertJsonPath('needs_action', 2);

        $this->actingAs($this->advertiser)
            ->getJson(route('chat.unread-summary'))
            ->assertOk()
            ->assertJsonPath('needs_action', 2);
    }

    public function test_needs_action_filter_includes_content_revision_orders(): void
    {
        $revisionWaiting = $this->makeProcessingItem();
        $revisionWaiting->update([
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the article before we publish.',
        ]);

        $this->actingAs($this->advertiser)
            ->getJson(route('advertiser.orders.list', ['status' => 'needs_action']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('orders.0.id', $revisionWaiting->order_id);
    }

    public function test_auto_approve_command_skips_orders_with_open_content_revision(): void
    {
        $item = $this->makeProcessingItem();
        $item->order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://revision.example/post',
            'live_url_submitted_at' => now()->subHours(OrderItem::autoApproveHours() + 2),
            'live_url_check_ok' => true,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Need a shorter draft for our guidelines.',
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        $this->artisan('orders:auto-approve')->assertSuccessful();

        $this->assertSame('review', $item->order->fresh()->status);
        $this->assertFalse((bool) $item->fresh()->auto_approve_triggered);
    }

    public function test_promote_after_revision_hold_restarts_auto_approve_clock(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $waiting = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/waiting',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the first placement article.',
        ]);

        $ready = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/ready',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://revision.example/ready-post',
            'live_url_submitted_at' => now()->subHours(OrderItem::autoApproveHours() + 5),
            'live_url_check_ok' => true,
            'content_revision_requested' => 'no',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_link' => 'https://docs.example/waiting-fixed',
                'order_item_id' => $waiting->id,
            ])
            ->assertOk();

        // Waiting line still has no live URL, so order stays processing.
        $this->assertSame('processing', $order->fresh()->status);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $waiting->id), [
                'live_url' => 'https://revision.example/waiting-post',
            ])
            ->assertOk();

        $order->refresh();
        $this->assertSame('review', $order->status);

        $readySubmittedAt = $ready->fresh()->live_url_submitted_at;
        $this->assertNotNull($readySubmittedAt);
        $this->assertTrue($readySubmittedAt->greaterThan(now()->subMinutes(2)));
        $this->assertFalse($ready->fresh()->isReadyForAutoApprove());

        $this->artisan('orders:auto-approve')->assertSuccessful();
        $this->assertSame('review', $order->fresh()->status);
    }

    public function test_normal_live_url_submit_does_not_reset_sibling_review_clock_when_already_in_review(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);

        $aged = now()->subHours(12);
        $first = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/first',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://revision.example/first-post',
            'live_url_submitted_at' => $aged,
            'live_url_check_ok' => true,
            'content_revision_requested' => 'no',
        ]);

        $second = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/second',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'no',
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.complete', $second->id), [
                'live_url' => 'https://revision.example/second-post',
            ])
            ->assertOk();

        $this->assertSame('review', $order->fresh()->status);
        // Still aged (~12h), not restarted to "now".
        $this->assertTrue(
            $first->fresh()->live_url_submitted_at->lt(now()->subHours(11)),
            'Sibling review clock must not reset when order is already in review'
        );
    }

    public function test_request_modification_blocked_while_content_revision_open(): void
    {
        $item = $this->makeProcessingItem();
        $item->order->update(['status' => 'review']);
        $item->update([
            'live_url' => 'https://revision.example/post',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise before we can continue review.',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.order.modification', $item->order_id), [
                'reason' => 'Please fix the live URL anchor text on the published page.',
            ])
            ->assertStatus(422);

        $this->assertTrue($item->fresh()->isContentRevisionRequested());
        $this->assertSame('review', $item->order->fresh()->status);
    }

    public function test_publisher_order_payload_flags_open_content_revision_on_sibling_hold(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $waiting = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/waiting',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise the first placement article.',
        ]);

        $ready = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/ready',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://revision.example/ready-post',
            'live_url_submitted_at' => now()->subHours(6),
            'content_revision_requested' => 'no',
        ]);

        $list = $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.data'))
            ->assertOk()
            ->json('data');

        $readyRow = collect($list)->firstWhere('id', $ready->id);
        $waitingRow = collect($list)->firstWhere('id', $waiting->id);

        $this->assertNotNull($readyRow);
        $this->assertTrue($readyRow['order']['has_open_content_revision']);
        $this->assertTrue($waitingRow['order']['has_open_content_revision']);

        $this->actingAs($this->publisher)
            ->getJson(route('publisher.orders.details', $ready->id))
            ->assertOk()
            ->assertJsonPath('data.order.has_open_content_revision', true);
    }

    public function test_fulfill_while_already_in_review_returns_to_processing_for_fresh_live_url(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'review',
            'paid_at' => now(),
        ]);

        $aged = now()->subHours(70);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/aged',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://revision.example/aged-post',
            'live_url_submitted_at' => $aged,
            'live_url_check_ok' => true,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now(),
            'content_revision_reason' => 'Please revise before we continue review.',
            'modification_requested' => 'no',
            'auto_approve_triggered' => false,
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_link' => 'https://docs.example/aged-fixed',
                'order_item_id' => $item->id,
            ])
            ->assertOk();

        $item->refresh();
        $this->assertFalse($item->isContentRevisionRequested());
        $this->assertNull($item->live_url);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertFalse($item->isReadyForAutoApprove());
    }

    public function test_request_content_revision_clears_existing_live_url(): void
    {
        $item = $this->makeProcessingItem();
        $item->update([
            'live_url' => 'https://revision.example/old-post',
            'live_url_submitted_at' => now()->subHours(5),
            'live_url_check_ok' => true,
        ]);

        $this->actingAs($this->publisher)
            ->postJson(route('publisher.orders.request-content-revision', $item->id), [
                'reason' => 'Please rewrite the intro with the correct brand spelling.',
            ])
            ->assertOk();

        $item->refresh();
        $this->assertTrue($item->isContentRevisionRequested());
        $this->assertNull($item->live_url);
        $this->assertNull($item->live_url_submitted_at);
    }

    public function test_fulfill_does_not_promote_on_stale_pre_revision_live_url(): void
    {
        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.random_int(1000, 9999),
            'subtotal' => 160,
            'tax' => 0,
            'total_amount' => 160,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $waiting = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/waiting',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            // Legacy row: revision open but old live URL still present.
            'live_url' => 'https://revision.example/stale-waiting',
            'live_url_submitted_at' => now()->subHours(8),
            'live_url_check_ok' => true,
            'content_revision_requested' => 'yes',
            'content_revision_requested_at' => now()->subHour(),
            'content_revision_reason' => 'Please revise the first placement article.',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $this->site->id,
            'site_name' => $this->site->site_name,
            'site_url' => $this->site->site_url,
            'content_link' => 'https://docs.example/ready',
            'price' => 80,
            'accepted_at' => now(),
            'publisher_status' => 'accepted',
            'live_url' => 'https://revision.example/ready-post',
            'live_url_submitted_at' => now()->subHours(2),
            'live_url_check_ok' => true,
            'content_revision_requested' => 'no',
        ]);

        $this->actingAs($this->advertiser)
            ->postJson(route('advertiser.orders.fulfill-content-revision', $order->id), [
                'content_link' => 'https://docs.example/waiting-fixed',
                'order_item_id' => $waiting->id,
            ])
            ->assertOk();

        $this->assertFalse($waiting->fresh()->isContentRevisionRequested());
        $this->assertNull($waiting->fresh()->live_url);
        $this->assertSame('processing', $order->fresh()->status);
    }
}
