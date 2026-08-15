<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\CartPricingService;
use App\Services\CheckoutIntentService;
use App\Services\LiveUrlHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesContentSubmissions;
use Tests\TestCase;

/**
 * Card checkout fully covered by welcome bonus must keep funds reserved
 * (spend-only) until approve/reject — the same model as wallet checkout.
 */
class BonusOnlyCheckoutWalletInvariantTest extends TestCase
{
    use CreatesContentSubmissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'content_moderation.enabled' => false,
            'services.stripe.secret' => 'sk_test_fake_key_for_unit_tests',
            'services.stripe.key' => 'pk_test_fake_key_for_unit_tests',
        ]);

        $this->swap(LiveUrlHealthChecker::class, new class extends LiveUrlHealthChecker
        {
            public function check(string $url): array
            {
                return ['ok' => true, 'status' => 200, 'checked_at' => now(), 'message' => 'OK'];
            }
        });
    }

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

    private function activeSite(User $publisher, string $slug, float $price = 20): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Bonus Site '.$slug,
            'site_url' => 'https://'.$slug.'.example',
            'domain' => $slug.'.example',
            'da' => 40,
            'dr' => 40,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'marketing',
            'price' => $price,
            'publication_time' => '7 days',
            'link_type' => 'dofollow',
            'description' => 'Bonus-only checkout invariant test site',
            'verified' => true,
            'active' => true,
        ]);
    }

    private function advertiserBonusWallet(User $advertiser, float $bonus): Wallet
    {
        $roleId = Role::firstOrCreate(['name' => 'advertiser'])->id;

        return Wallet::create([
            'user_id' => $advertiser->id,
            'role_id' => $roleId,
            'balance' => $bonus,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function publisherWallet(User $publisher): Wallet
    {
        $roleId = Role::firstOrCreate(['name' => 'publisher'])->id;

        return Wallet::create([
            'user_id' => $publisher->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: Site, 3: Wallet, 4: float}
     */
    private function bonusCoveredCheckoutSetup(): array
    {
        $advertiser = $this->advertiser();
        $publisher = $this->publisher();
        $site = $this->activeSite($publisher, 'bonus-cover', 20);
        $total = (float) app(CartPricingService::class)
            ->priceForAdvertiser($site, null, 1, 'none', false)['total'];
        $wallet = $this->advertiserBonusWallet($advertiser, $total);
        $this->publisherWallet($publisher);

        return [$advertiser, $publisher, $site, $wallet, $total];
    }

    private function placeBonusOnlyOrder(User $advertiser, Site $site, string $reference): Order
    {
        $sub = $this->createApprovedSubmission($advertiser, null);

        $this->actingAs($advertiser)
            ->withSession([
                'cart' => [[
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'quantity' => 1,
                    'content_submission_id' => $sub->id,
                    'language' => 'en',
                    'homepage_days' => 'none',
                ]],
            ])
            ->postJson(route('advertiser.checkout.process'), [
                'payment_method' => 'card',
                'use_bonus' => '1',
                'reference_code' => $reference,
                'publication_mode' => 'immediate',
                'content_submissions' => [
                    $site->id => [$sub->id],
                ],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $order = Order::where('reference_code', $reference)->first();
        $this->assertNotNull($order);

        return $order;
    }

    public function test_bonus_only_card_checkout_keeps_promo_reserved_not_consumed(): void
    {
        [$advertiser, , $site, $wallet, $total] = $this->bonusCoveredCheckoutSetup();

        $order = $this->placeBonusOnlyOrder($advertiser, $site, 'BONUS1');

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('wallet', $order->payment_method);
        $this->assertEqualsWithDelta($total, (float) $order->total_amount, 0.01);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta($total, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta($total, (float) $wallet->bonus_reserved, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
        $this->assertGreaterThanOrEqual(0.0, (float) $wallet->reserved_balance);
        $this->assertEqualsWithDelta(
            $total,
            app(CheckoutIntentService::class)->recordedBonus($advertiser->id, 'BONUS1'),
            0.01
        );

        $this->actingAs($advertiser)
            ->get(route('advertiser.checkout', ['canceled' => 1, 'ref' => 'BONUS1']));

        $this->assertEqualsWithDelta(
            $total,
            app(CheckoutIntentService::class)->recordedBonus($advertiser->id, 'BONUS1'),
            0.01
        );
        $wallet->refresh();
        $this->assertEqualsWithDelta($total, (float) $wallet->bonus_reserved, 0.01);
    }

    public function test_approving_bonus_only_order_spends_promo_and_never_goes_negative(): void
    {
        [$advertiser, $publisher, $site, $wallet, $total] = $this->bonusCoveredCheckoutSetup();
        $order = $this->placeBonusOnlyOrder($advertiser, $site, 'BONUS2');
        $item = $order->items()->first();
        $this->assertNotNull($item);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://bonus-cover.example/published-post',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('review', $order->fresh()->status);

        $expectedPayout = $item->fresh()->publisherPayoutAmount();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('completed', $order->fresh()->status);

        $wallet->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
        $this->assertGreaterThanOrEqual(0.0, (float) $wallet->reserved_balance);

        $publisherWallet = Wallet::where('user_id', $publisher->id)->first();
        $this->assertEqualsWithDelta($expectedPayout, (float) $publisherWallet->balance, 0.01);
        $this->assertEqualsWithDelta($total, (float) $order->fresh()->total_amount, 0.01);
    }

    public function test_rejecting_bonus_only_order_restores_spend_only_bonus(): void
    {
        [$advertiser, $publisher, $site, $wallet, $total] = $this->bonusCoveredCheckoutSetup();
        $order = $this->placeBonusOnlyOrder($advertiser, $site, 'BONUS3');
        $item = $order->items()->first();
        $this->assertNotNull($item);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.reject', $item->id), [
                'reason' => 'The topic does not fit our editorial guidelines.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('refunded', $order->payment_status);

        $wallet->refresh();
        $this->assertEqualsWithDelta($total, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->reserved_balance, 0.01);
        $this->assertEqualsWithDelta($total, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $wallet->bonus_reserved, 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }

    public function test_bonus_only_checkout_writes_a_purchase_ledger_row(): void
    {
        [$advertiser, , $site, $wallet, $total] = $this->bonusCoveredCheckoutSetup();
        $this->placeBonusOnlyOrder($advertiser, $site, 'BONUS-LEDGER');

        $purchase = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where('reference', 'BONUS-LEDGER')
            ->first();

        $this->assertNotNull($purchase);
        $this->assertEqualsWithDelta($total, (float) $purchase->amount, 0.01);
        $this->assertEqualsWithDelta($total, (float) $purchase->bonus_amount, 0.01);
        $this->assertSame('debit', $purchase->direction);
    }

    public function test_upholding_bonus_only_dispute_restores_spend_only_bonus(): void
    {
        [$advertiser, $publisher, $site, $wallet, $total] = $this->bonusCoveredCheckoutSetup();
        $order = $this->placeBonusOnlyOrder($advertiser, $site, 'BONUS-CLAW');
        $item = $order->items()->first();
        $this->assertNotNull($item);

        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.accept', $item->id))
            ->assertOk();
        $this->actingAs($publisher)
            ->postJson(route('publisher.orders.complete', $item->id), [
                'live_url' => 'https://bonus-cover.example/published-post',
            ])
            ->assertOk();
        $this->actingAs($advertiser)
            ->postJson(route('advertiser.orders.approve', $order->id))
            ->assertOk();

        $order->refresh();
        $this->assertSame('completed', $order->status);

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);
        $admin->roles()->attach($adminRole->id);

        $dispute = OrderItemDispute::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'opened_by' => $advertiser->id,
            'status' => OrderItemDispute::STATUS_OPEN,
            'reason' => 'The live article was deleted two days after completion.',
        ]);

        $this->actingAs($admin)->postJson(
            route('admin.orders.disputes.uphold', $dispute->id),
            ['admin_notes' => 'Confirmed 404. Refund must not turn promo credit into cash.']
        )->assertOk()->assertJson(['success' => true]);

        $wallet->refresh();
        $this->assertEqualsWithDelta($total, (float) $wallet->balance, 0.01);
        $this->assertEqualsWithDelta($total, (float) $wallet->bonus_balance, 0.01);
        $this->assertEqualsWithDelta($total, $wallet->lockedBonusBalance(), 0.01);
        $this->assertSame(0.0, $wallet->withdrawableBalance());
    }
}
