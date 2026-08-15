<?php

namespace Tests\Feature;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OrderPaymentService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

class CancelledCardOrderMarkPaidTest extends TestCase
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

        return $user->fresh();
    }

    private function makeSite(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Cancelled Card Site',
            'site_url' => 'https://cancelled-card.example',
            'domain' => 'cancelled-card.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'Technology',
            'price' => 80,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Cancelled card order site. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    public function test_stripe_session_does_not_revive_a_cancelled_card_order(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-CANCELLED-CARD',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'cancelled',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 80,
        ]);

        $session = (object) [
            'id' => 'cs_cancelled_card',
            'object' => 'checkout.session',
            'amount_total' => 8000,
            'payment_intent' => 'pi_cancelled_card',
            'metadata' => (object) [
                'type' => 'order_payment',
                'reference_code' => 'REF-CANCELLED-CARD',
                'expected_amount' => '80',
            ],
        ];

        $paid = app(OrderPaymentService::class)
            ->markOrdersPaidFromStripeSession('REF-CANCELLED-CARD', $session);

        $this->assertTrue($paid->isEmpty());
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNull($order->paid_at);
    }

    public function test_new_checkout_fails_and_cancels_conflicting_unpaid_card_orders(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 20,
            'bonus_balance' => 0,
            'bonus_reserved' => 20,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'cancelled card anchor',
            'https://example.com/target'
        );

        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-CARD',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);

        Cache::put('checkout_bonus:'.$advertiser->id.':REF-STALE-CARD', 20, now()->addHour());

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status);
        $this->assertSame('failed', $stale->payment_status);

        $wallet = Wallet::where('user_id', $advertiser->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $wallet->bonus_balance, 0.01);
    }

    public function test_new_checkout_replaces_abandoned_wise_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'wise leftover anchor',
            'https://example.com/target'
        );

        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertFalse($submission->fresh()->isReadyForCheckout());

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET-WISE',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status);
        $this->assertSame('failed', $stale->payment_status);

        $paid = Order::query()->where('reference_code', 'REF-NEW-WALLET-WISE')->first();
        $this->assertNotNull($paid);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);

        ContentSubmission::releaseAllForOrder((int) $stale->id);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);
        $this->assertSame(
            (int) OrderItem::query()->where('order_id', $paid->id)->value('id'),
            (int) $submission->fresh()->order_item_id
        );
    }

    public function test_order_from_library_releases_abandoned_wise_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-LIB',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.catalog', [
                'content_submission_id' => $submission->id,
            ]));

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status);
        $this->assertNull($submission->fresh()->order_id);
        $this->assertTrue($submission->fresh()->isReadyForCheckout());
    }

    public function test_new_checkout_replaces_failed_card_leftover(): void
    {
        config(['content_moderation.enabled' => false]);

        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);

        Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => 100,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'failed card leftover anchor',
            'https://example.com/target'
        );
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertFalse($submission->fresh()->isReadyForCheckout());
        $this->assertTrue($submission->fresh()->load('order')->canReplaceUnpaidLeftover());

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [
                    ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
                ],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'wallet',
                'reference_code' => 'REF-NEW-WALLET-FAILED-CARD',
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$submission->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $leftover->refresh();
        $this->assertSame('cancelled', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);

        $paid = Order::query()->where('reference_code', 'REF-NEW-WALLET-FAILED-CARD')->first();
        $this->assertNotNull($paid);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);

        ContentSubmission::releaseAllForOrder((int) $leftover->id);
        $this->assertSame($paid->id, (int) $submission->fresh()->order_id);
        $this->assertSame(
            (int) OrderItem::query()->where('order_id', $paid->id)->value('id'),
            (int) $submission->fresh()->order_item_id
        );
    }

    public function test_library_shows_order_for_abandoned_wise_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Stuck Wise Piece']);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-UI',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->canReplaceUnpaidLeftover());
        $this->assertSame('in_progress', $submission->fresh()->libraryAvailability());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Stuck Wise Piece')
            ->assertSee(route('advertiser.content-library.order', $submission, false), false)
            ->assertSee('View order');
    }

    public function test_library_does_not_offer_order_for_paid_in_progress(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Paid Processing Piece']);
        $order = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAID-PROGRESS',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertFalse($submission->fresh()->load('order')->canReplaceUnpaidLeftover());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Paid Processing Piece')
            ->assertSee('View order')
            ->assertDontSee(route('advertiser.content-library.order', $submission, false), false);
    }

    public function test_cart_picker_keeps_abandoned_wise_leftover_assignment(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Picker Wise Piece']);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-PICKER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->isAvailableForPicker());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->availableForPicker()->exists()
        );

        $payload = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                    'content_submission_ids' => [$submission->id],
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $articleIds = collect($payload['approved_articles'] ?? [])->pluck('id')->all();
        $this->assertContains($submission->id, $articleIds);
        $this->assertSame($submission->id, (int) ($payload['cart'][0]['content_submission_id'] ?? 0));
        $this->assertSame('pending', $stale->fresh()->status);
    }

    public function test_cart_picker_keeps_failed_card_leftover_assignment(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $ready = $this->createApprovedSubmission($advertiser);
        $ready->update(['title' => 'Free Ready Piece']);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Failed Card Piece']);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD-PICKER',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->isAvailableForPicker());
        $this->assertTrue(
            ContentSubmission::query()->whereKey($submission->id)->availableForPicker()->exists()
        );

        $payload = $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $submission->id,
                    'content_submission_ids' => [$submission->id],
                ]],
            ])
            ->getJson(route('advertiser.cart.get'))
            ->assertOk()
            ->json();

        $articleIds = collect($payload['approved_articles'] ?? [])->pluck('id')->all();
        $this->assertContains($ready->id, $articleIds);
        $this->assertContains($submission->id, $articleIds);
        $this->assertSame($submission->id, (int) ($payload['cart'][0]['content_submission_id'] ?? 0));
        $this->assertSame('pending', $leftover->fresh()->status);
        $this->assertSame('failed', $leftover->fresh()->payment_status);
    }

    public function test_library_shows_order_for_failed_card_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $submission->update(['title' => 'Stuck Failed Card Piece']);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD-UI',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->assertTrue($submission->fresh()->load('order')->canReplaceUnpaidLeftover());
        $this->assertSame('in_progress', $submission->fresh()->libraryAvailability());

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library', ['availability' => 'in_progress']))
            ->assertOk()
            ->assertSee('Stuck Failed Card Piece')
            ->assertSee(route('advertiser.content-library.order', $submission, false), false)
            ->assertSee('View order');
    }

    public function test_order_from_library_releases_failed_card_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-FAILED-CARD-LIB',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $leftover->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.content-library.order', $submission))
            ->assertRedirect(route('advertiser.catalog', [
                'content_submission_id' => $submission->id,
            ]));

        $leftover->refresh();
        $this->assertSame('cancelled', $leftover->status);
        $this->assertSame('failed', $leftover->payment_status);
        $this->assertNull($submission->fresh()->order_id);
        $this->assertTrue($submission->fresh()->isReadyForCheckout());
    }

    public function test_catalog_query_releases_abandoned_wise_leftover(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission($advertiser);
        $stale = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-STALE-WISE-CATALOG',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wise',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $stale->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->update([
            'order_id' => $stale->id,
            'order_item_id' => $item->id,
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.catalog', [
                'content_submission_id' => $submission->id,
            ]))
            ->assertOk();

        $stale->refresh();
        $this->assertSame('cancelled', $stale->status);
        $this->assertNull($submission->fresh()->order_id);
        $this->assertSame($submission->id, (int) session('checkout_content_submission_id'));
        $this->assertTrue((bool) session('ordering_from_library'));
    }
}
