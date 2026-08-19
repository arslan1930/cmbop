<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
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
            ->assertSee(route('publisher.tasks', absolute: false), false)
            ->assertSee('Available to Withdraw', false)
            ->assertSee('Lifetime', false)
            ->assertSee('Pending payout', false)
            ->assertSee('id="pendingPayout"', false)
            ->assertSee('id="availableNote"', false)
            ->assertSee('id="ordersPayoutHeading"', false)
            ->assertSee('You earned', false)
            ->assertSee('Homepage', false)
            ->assertSee('Open placements:', false)
            ->assertDontSee('Pending:', false)
            ->assertDontSee('>Total Earned</th>', false);

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
            ->assertJsonPath('data.open_orders', 1)
            ->assertJsonPath('data.pending_payout', 0)
            ->assertJsonPath('data.debt_balance', 0)
            ->assertJsonPath('data.min_withdrawal_amount', 20)
            ->assertJsonPath('data.total_withdrawn', 38)
            ->assertJsonPath('data.total_withdrawn_gross', 40)
            ->assertJsonPath('data.total_withdrawal_fees', 2)
            ->assertJsonPath('data.available_to_withdraw', 50);
    }

    public function test_statistics_pending_payout_is_publisher_wallet_gross(): void
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $user->roles()->attach([$publisherRole->id, $advertiserRole->id]);

        $publisherWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $advertiserWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiserRole->id,
            'balance' => 50,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 30,
            'fee' => 0,
            'net_amount' => 30,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes($publisherWallet)));
        Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 12,
            'fee' => 0,
            'net_amount' => 12,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'processing',
        ], Withdrawal::walletIdAttributes($publisherWallet)));
        Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 100,
            'fee' => 0,
            'net_amount' => 100,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'adv@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes($advertiserWallet)));

        $this->actingAs($user->fresh())
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_payout', 42)
            ->assertJsonPath('data.available_to_withdraw', 80);
    }

    public function test_statistics_available_stays_withdrawable_when_in_debt(): void
    {
        $publisher = $this->publisher(50);
        $wallet = Wallet::forPublisher((int) $publisher->id);
        $this->assertNotNull($wallet);
        $wallet->debt_balance = 12;
        $wallet->save();

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('data.available_to_withdraw', 50)
            ->assertJsonPath('data.debt_balance', 12);

        $js = file_get_contents(resource_path('views/publisher/reports.blade.php'));
        $this->assertStringContainsString('Outstanding clawback debt', $js);
        $this->assertStringContainsString('Minimum payout', $js);
        $this->assertStringContainsString('d.pending_payout', $js);
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
            ->assertJsonPath('data.0.publisher_base_price', 100)
            ->assertJsonPath('data.0.homepage_price', 0)
            ->assertJsonPath('data.0.homepage_days', null)
            ->assertJsonPath('data.0.payout_state', 'you_earned')
            ->assertJsonPath('data.0.payout_label', 'You earned')
            ->assertJsonPath('data.0.is_clawed_back', false);

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

    public function test_statistics_count_scheduled_as_open_not_pending(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $this->createOrderItem($advertiser, $site, ['status' => 'pending']);
        $this->createOrderItem($advertiser, $site, [
            'status' => 'pending',
            'publication_mode' => 'scheduled',
            'scheduled_publish_at' => now()->addDays(5),
            'schedule_timezone' => 'Europe/Berlin',
        ]);
        $this->createOrderItem($advertiser, $site, ['status' => 'processing']);
        $this->createOrderItem($advertiser, $site, ['status' => 'review']);
        $this->createOrderItem($advertiser, $site, ['status' => 'completed']);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('data.pending_orders', 1)
            ->assertJsonPath('data.open_orders', 4)
            ->assertJsonPath('data.completed_orders', 1);
    }

    public function test_orders_expose_homepage_line_separate_from_base(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $item = $this->createOrderItem($advertiser, $site, [
            'status' => 'completed',
            'total_amount' => 150,
        ], [
            'price' => 150,
            'additional_price' => 20,
            'sensitive_type' => 'crypto',
            'homepage_price' => 15,
            'homepage_days' => 7,
            'publisher_price' => 100,
        ]);

        $this->assertSame(100.0, $item->publisherBasePrice());
        $this->assertSame(135.0, $item->publisherPayoutAmount());

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.publisher_base_price', 100)
            ->assertJsonPath('data.0.additional_price', 20)
            ->assertJsonPath('data.0.homepage_price', 15)
            ->assertJsonPath('data.0.homepage_days', 7)
            ->assertJsonPath('data.0.price', 135);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.order.details', $item->id))
            ->assertOk()
            ->assertJsonPath('data.publisher_base_price', 100)
            ->assertJsonPath('data.homepage_price', 15)
            ->assertJsonPath('data.homepage_days', 7)
            ->assertJsonPath('data.price', 135);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_earned', 135);
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
            ->assertJsonPath('data.0.status_label', 'Paid')
            ->assertJsonPath('data.0.payment_method', 'paypal')
            ->assertJsonPath('data.0.payment_method_label', 'PayPal')
            ->assertJsonPath('data.0.statement_url', null)
            ->assertJsonPath('data.0.statement_pdf_url', null);
    }

    public function test_completed_publisher_withdrawal_links_payout_statement(): void
    {
        $publisher = $this->publisher();
        $wallet = Wallet::forPublisher((int) $publisher->id);
        $withdrawal = Withdrawal::create(array_merge([
            'user_id' => $publisher->id,
            'amount' => 25,
            'fee' => 1.25,
            'net_amount' => 23.75,
            'payment_method' => 'paypal',
            'payment_details' => ['paypal_email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ], Withdrawal::walletIdAttributes($wallet)));

        $statement = Invoice::create([
            'invoice_number' => 'PAY-2026-000101',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $publisher->id,
            'reference_code' => 'WD-'.$withdrawal->id,
            'transaction_id' => 'WD-'.$withdrawal->id,
            'currency' => 'EUR',
            'subtotal' => 25,
            'tax_amount' => 0,
            'discount_amount' => 1.25,
            'total_amount' => 23.75,
            'payment_method' => 'paypal',
            'invoice_date' => now(),
            'customer_name' => $publisher->name,
            'customer_email' => $publisher->email,
            'line_items' => [],
            'pdf_disk' => 'local',
            'meta' => ['withdrawal_id' => $withdrawal->id],
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.withdrawals', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $withdrawal->id)
            ->assertJsonPath('data.0.statement_url', route('publisher.billing.show', $statement, false))
            ->assertJsonPath('data.0.statement_pdf_url', route('publisher.billing.view', $statement, false));
    }

    public function test_pending_withdrawal_has_no_statement_link(): void
    {
        $publisher = $this->publisher();
        Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 25,
            'fee' => 1.25,
            'net_amount' => 23.75,
            'payment_method' => 'paypal',
            'payment_details' => ['paypal_email' => 'pay@example.com'],
            'status' => 'pending',
        ]);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.withdrawals', ['status' => 'pending']))
            ->assertOk()
            ->assertJsonPath('data.0.statement_url', null)
            ->assertJsonPath('data.0.statement_pdf_url', null);
    }

    public function test_advertiser_wallet_statement_is_not_linked_on_reports(): void
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $user->roles()->attach([$publisherRole->id, $advertiserRole->id]);

        $publisherWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);
        $advertiserWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $advertiserRole->id,
            'balance' => 50,
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
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ], Withdrawal::walletIdAttributes($publisherWallet)));
        $advertiserPaid = Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 100,
            'fee' => 0,
            'net_amount' => 100,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'adv@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ], Withdrawal::walletIdAttributes($advertiserWallet)));

        $publisherDoc = Invoice::create([
            'invoice_number' => 'PAY-2026-000201',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $user->id,
            'reference_code' => 'WD-'.$publisherPaid->id,
            'transaction_id' => 'WD-'.$publisherPaid->id,
            'currency' => 'EUR',
            'subtotal' => 25,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 25,
            'payment_method' => 'paypal',
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'line_items' => [],
            'pdf_disk' => 'local',
            'meta' => ['withdrawal_id' => $publisherPaid->id],
        ]);
        Invoice::create([
            'invoice_number' => 'PAY-2026-000202',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $user->id,
            'reference_code' => 'WD-'.$advertiserPaid->id,
            'transaction_id' => 'WD-'.$advertiserPaid->id,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'paypal',
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'line_items' => [],
            'pdf_disk' => 'local',
            'meta' => ['withdrawal_id' => $advertiserPaid->id],
        ]);

        $rows = $this->actingAs($user->fresh())
            ->getJson(route('publisher.reports.withdrawals', ['status' => 'completed']))
            ->assertOk()
            ->json('data');

        $byId = collect($rows)->keyBy('id');
        $this->assertSame(
            route('publisher.billing.show', $publisherDoc, false),
            $byId[$publisherPaid->id]['statement_url']
        );
        $this->assertNull($byId[$advertiserPaid->id]['statement_url']);
        $this->assertNull($byId[$advertiserPaid->id]['statement_pdf_url']);
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

    public function test_clawed_completed_is_excluded_from_earned_and_completed_list(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $kept = $this->createOrderItem($advertiser, $site, ['status' => 'completed'], [
            'price' => 115,
            'additional_price' => 0,
        ]);
        $clawed = $this->createOrderItem($advertiser, $site, ['status' => 'completed'], [
            'price' => 115,
            'additional_price' => 0,
        ]);
        $this->upholdClawback($clawed);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_earned', 100)
            ->assertJsonPath('data.completed_orders', 1);

        $completed = $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'completed']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id)
            ->json('data');
        $this->assertSame('you_earned', $completed[0]['payout_state']);

        $all = $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'all']))
            ->assertOk()
            ->json('data');

        $clawedRow = collect($all)->firstWhere('id', $clawed->id);
        $this->assertNotNull($clawedRow);
        $this->assertTrue($clawedRow['is_clawed_back']);
        $this->assertSame('none', $clawedRow['payout_state']);
        $this->assertNull($clawedRow['payout_label']);
    }

    public function test_open_row_uses_you_earn_and_cancelled_has_no_payout(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);

        $open = $this->createOrderItem($advertiser, $site, ['status' => 'processing']);
        $cancelled = $this->createOrderItem($advertiser, $site, ['status' => 'cancelled']);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'processing']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.payout_state', 'you_earn')
            ->assertJsonPath('data.0.payout_label', 'You earn');

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.orders', ['status' => 'cancelled']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $cancelled->id)
            ->assertJsonPath('data.0.payout_state', 'none')
            ->assertJsonPath('data.0.payout_label', null);
    }

    public function test_refunded_clawed_line_is_not_counted_as_earned(): void
    {
        $publisher = $this->publisher();
        $advertiser = $this->advertiser();
        $site = $this->site($publisher);
        $item = $this->createOrderItem($advertiser, $site, [
            'status' => 'completed',
            'payment_status' => 'refunded',
        ]);
        $this->upholdClawback($item);

        $this->actingAs($publisher)
            ->getJson(route('publisher.reports.statistics'))
            ->assertOk()
            ->assertJsonPath('data.total_earned', 0)
            ->assertJsonPath('data.completed_orders', 0);
    }

    public function test_reports_row_script_does_not_prefix_plus_euro(): void
    {
        $js = file_get_contents(resource_path('views/publisher/reports.blade.php'));

        $this->assertStringContainsString('function payoutCell', $js);
        $this->assertStringContainsString('function homepageCell', $js);
        $this->assertStringContainsString('item.homepage_price', $js);
        $this->assertStringContainsString('item.price - additionalPrice - homepagePrice', $js);
        $this->assertStringContainsString('d.open_orders', $js);
        $this->assertStringContainsString('payment_method_label', $js);
        $this->assertStringContainsString('slbAlert', $js);
        $this->assertStringNotContainsString('Swal.fire', $js);
        $this->assertStringNotContainsString("'+ €' + money(totalPrice)", $js);
        $this->assertStringNotContainsString('Total Earned:</strong>', $js);
        $this->assertStringNotContainsString('item.price - additionalPrice)', $js);
        $this->assertStringNotContainsString('Pending:', $js);
        $this->assertStringNotContainsString("w.payment_method || 'Bank Transfer'", $js);
    }

    private function upholdClawback(OrderItem $item): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $adminRole->id,
        ]);

        OrderItemDispute::create([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'opened_by' => $admin->id,
            'status' => OrderItemDispute::STATUS_UPHELD,
            'reason' => 'Live URL was removed after completion.',
            'resolved_at' => now(),
            'advertiser_credited' => 115,
            'publisher_debited' => 100,
        ]);
    }
}
