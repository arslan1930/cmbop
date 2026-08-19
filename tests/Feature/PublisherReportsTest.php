<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublisherReportsTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(float $balance = 80): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'balance' => $balance,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        return $user->fresh();
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

    private function site(User $publisher): Site
    {
        return Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Reports Site',
            'site_url' => 'https://reports-site.example',
            'domain' => 'reports-site.example',
            'example_url' => 'https://reports-site.example/post',
            'da' => 40,
            'dr' => 40,
            'traffic' => 12000,
            'country' => 'us',
            'language' => 'en',
            'countries' => ['us'],
            'languages' => ['en'],
            'category' => 'News',
            'categories' => ['News'],
            'price' => 100,
            'turnaround_time' => '3days',
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Publisher reports test site description. ', 3),
            'verified' => true,
            'active' => true,
        ]);
    }

    /**
     * Advertiser price includes 15% markup: listing €100 → €115 stored.
     */
    private function createOrderItem(
        User $advertiser,
        Site $site,
        array $orderAttrs = [],
        array $itemAttrs = []
    ): OrderItem {
        $order = Order::create(array_merge([
            'user_id' => $advertiser->id,
            'order_number' => (string) random_int(100000, 999999),
            'reference_code' => 'REF-'.uniqid(),
            'subtotal' => 115,
            'tax' => 0,
            'total_amount' => 115,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'completed',
            'paid_at' => now(),
        ], $orderAttrs));

        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://example.com/article',
            'price' => 115,
            'additional_price' => 0,
        ], $itemAttrs));
    }

    public function test_reports_page_loads(): void
    {
        $publisher = $this->publisher();

        $this->actingAs($publisher)
            ->get(route('publisher.reports'))
            ->assertOk()
            ->assertSee('Financial Reports', false)
            ->assertSee(route('publisher.reports.statistics', absolute: false), false)
            ->assertSee('Available to Withdraw', false);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['date_from' => 'not-a-date', 'status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_statistics_use_publisher_payout_and_net_withdrawn(): void
    {
        $publisher = $this->publisher(50);
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        // €115 advertiser → €100 publisher base + €20 sensitive = €120 earned
        $this->createOrderItem($advertiser, $site, [
            'status' => 'completed',
            'payment_status' => 'paid',
            'total_amount' => 135,
        ], [
            'price' => 135,
            'additional_price' => 20,
            'sensitive_type' => 'crypto',
        ]);

        // Unpaid completed should not count
        $this->createOrderItem($advertiser, $site, [
            'status' => 'completed',
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);

        // Pending paid counts as pending, not completed
        $this->createOrderItem($advertiser, $site, [
            'status' => 'pending',
            'payment_status' => 'paid',
        ]);

        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 2,
            'net_amount' => 38,
            'payment_method' => 'paypal',
            'payment_details' => ['paypal_email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_earned', 120)
            ->assertJsonPath('data.completed_orders', 1)
            ->assertJsonPath('data.pending_orders', 1)
            ->assertJsonPath('data.total_withdrawn', 38)
            ->assertJsonPath('data.total_withdrawn_gross', 40)
            ->assertJsonPath('data.total_withdrawal_fees', 2)
            ->assertJsonPath('data.available_to_withdraw', 50);
    }

    public function test_orders_list_exposes_payout_and_filters_status(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $completed = $this->createOrderItem($advertiser, $site, ['status' => 'completed'], [
            'price' => 115,
            'additional_price' => 0,
        ]);
        $this->createOrderItem($advertiser, $site, ['status' => 'processing']);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id)
            ->assertJsonPath('data.0.price', 100)
            ->assertJsonPath('data.0.publisher_base_price', 100);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'all']))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_orders_list_filters_checkout_scheduled_rows(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $scheduled = $this->createOrderItem($advertiser, $site, [
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);
        $this->createOrderItem($advertiser, $site, ['status' => 'pending']);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'scheduled']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $scheduled->id)
            ->assertJsonPath('data.0.order.is_awaiting_scheduled_release', true);

        $pending = $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'pending']))
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $pending);
        $this->assertNotSame($scheduled->id, $pending[0]['id']);
    }

    public function test_order_details_are_scoped_to_owner(): void
    {
        $owner = $this->publisher();
        $other = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($owner);
        $item = $this->createOrderItem($advertiser, $site);

        $this->actingAs($owner)
            ->getJson(route('publisher.reports.order.details', $item->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.order.payment_status', 'paid');

        $this->actingAs($other)
            ->getJson(route('publisher.reports.order.details', $item->id))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_order_details_hide_unpaid_placements(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->createOrderItem($advertiser, $site, [
            'payment_status' => 'pending',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.order.details', $item->id))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_withdrawals_payload_includes_reference_fee_and_net(): void
    {
        $publisher = $this->publisher();

        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 25,
            'fee' => 1.25,
            'net_amount' => 23.75,
            'payment_method' => 'paypal',
            'payment_details' => ['paypal_email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.withdrawals', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $withdrawal->id)
            ->assertJsonPath('data.0.reference', 'WD-'.$withdrawal->id)
            ->assertJsonPath('data.0.amount', 25)
            ->assertJsonPath('data.0.fee', 1.25)
            ->assertJsonPath('data.0.net_amount', 23.75)
            ->assertJsonPath('data.0.status_label', 'Paid');
    }

    public function test_withdrawals_ok_when_processed_at_is_unparseable(): void
    {
        $publisher = $this->publisher();
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 25,
            'fee' => 1.25,
            'net_amount' => 23.75,
            'payment_method' => 'paypal',
            'payment_details' => ['paypal_email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ]);
        DB::table('withdrawals')->where('id', $withdrawal->id)->update([
            'processed_at' => 'not-a-date',
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.withdrawals', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $withdrawal->id)
            ->assertJsonPath('data.0.processed_at', null)
            ->assertJsonPath('data.0.status_label', 'Paid');
    }

    public function test_withdrawals_and_totals_exclude_advertiser_wallet_rows(): void
    {
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $user->roles()->attach([$advertiserRole->id, $publisherRole->id]);

        $advertiserWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiserRole->id,
            'balance' => 50,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $publisherWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $publisherPaid = Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 25,
            'fee' => 0,
            'net_amount' => 25,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pub@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ], Withdrawal::walletIdAttributes($publisherWallet)));

        Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 100,
            'fee' => 5,
            'net_amount' => 95,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'adv@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ], Withdrawal::walletIdAttributes($advertiserWallet)));

        $this->actingAs($user->fresh())
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_withdrawn', 25)
            ->assertJsonPath('data.total_withdrawn_gross', 25)
            ->assertJsonPath('data.available_to_withdraw', 80);

        $this->actingAs($user->fresh())
            ->getJson(route('publisher.reports.withdrawals', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $publisherPaid->id);
    }
}
