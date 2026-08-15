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

    public function test_releasing_a_leftover_card_row_does_not_unlock_a_later_paid_article(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $publisher = $this->makeUser('publisher');
        $site = $this->makeSite($publisher);
        $submission = $this->createApprovedSubmission(
            $advertiser,
            $site->id,
            0,
            'kept paid article',
            'https://example.com/kept'
        );

        $leftover = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-LEFTOVER-UNLOCK',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'card',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $leftover->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);

        $paid = Order::create([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-PAID-KEEP-ARTICLE',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        $paidItem = OrderItem::create([
            'order_id' => $paid->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'content_submission_id' => $submission->id,
            'price' => 80,
        ]);
        $submission->forceFill([
            'order_id' => $paid->id,
            'order_item_id' => $paidItem->id,
        ])->save();

        ContentSubmission::releaseAllForOrder((int) $leftover->id);

        $submission->refresh();
        $this->assertSame($paid->id, (int) $submission->order_id);
        $this->assertSame($paidItem->id, (int) $submission->order_item_id);
        $this->assertTrue($submission->isClaimedByAnotherOrder((int) $leftover->id));
    }
}
