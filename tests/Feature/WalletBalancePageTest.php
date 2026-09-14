<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WalletBalancePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $advertiser = Role::create(['name' => 'advertiser']);
        $publisher = Role::create(['name' => 'publisher']);

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $advertiser->id,
        ]);
        $this->user->roles()->attach([$advertiser->id, $publisher->id]);

        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'role_id' => $advertiser->id,
            'balance' => 20,
            'reserved_balance' => 0,
            'bonus_balance' => 20,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);

        Wallet::create([
            'user_id' => $this->user->id,
            'role_id' => $publisher->id,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    public function test_balance_redirects_to_merged_add_funds_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('advertiser.balance'))
            ->assertRedirect(route('advertiser.add-funds'));
    }

    public function test_add_funds_page_renders_deposit_first_ui(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Add funds', false)
            ->assertSee('Top up your wallet', false)
            ->assertSee('Spendable', false)
            ->assertSee('Money', false)
            ->assertSee('Bonus', false)
            ->assertSee('depositSection', false)
            ->assertSee('proceedBtn', false)
            ->assertSee(Wallet::PROMOTIONAL_BONUS_MESSAGE, false)
            ->assertSee('Bonus €20.00', false)
            ->assertSee('PayPal', false)
            ->assertDontSee('PayPal coming soon', false)
            ->assertDontSee('Spending Overview', false)
            ->assertDontSee('Quick Actions', false)
            ->assertDontSee('Processing Fee', false)
            ->assertDontSee('Transfer to Publisher Wallet', false)
            ->assertDontSee('Lifetime Spending', false)
            ->assertDontSee('Lifetime Withdrawals', false)
            ->getContent();

        // Header twin "Add Funds" primary CTA removed; composer is the path.
        $this->assertStringNotContainsString('href="#depositSection" class="btn btn-sm btn-primary"', $html);
        $this->assertStringNotContainsString('href="#depositSection" class="btn btn-primary"', $html);
        $this->assertStringContainsString('id="kpiSpendable"', $html);
        $this->assertStringContainsString('af-spendable__chip--bonus', $html);
        $this->assertStringContainsString('PayPal', $html);
        $this->assertStringNotContainsString('PayPal coming soon', $html);
        $this->assertStringContainsString('paypal.svg', $html);
        $this->assertStringContainsString('ref-code', $html);
        $this->assertStringContainsString('Recent activity', $html);
        $this->assertStringContainsString('value="refunded"', $html);
        $this->assertStringContainsString('value="rejected"', $html);
        $this->assertStringContainsString('id="publisherRoleStrip"', $html);
        $this->assertStringContainsString(route('publisher.balance'), $html);
        $this->assertStringContainsString(route('publisher.withdraw'), $html);
    }

    public function test_add_funds_publisher_strip_shows_withdrawable_earnings(): void
    {
        Wallet::where('user_id', $this->user->id)
            ->where('role_id', Wallet::publisherRoleId())
            ->update(['balance' => 7.64]);

        $html = $this->actingAs($this->user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="publisherRoleStrip"', $html);
        $this->assertStringContainsString('Publisher earnings', $html);
        $this->assertStringContainsString('id="publisherEarningsKpi">€7.64', $html);
        $this->assertStringContainsString('Open Balance to move earnings here for catalog spend', $html);
        $this->assertStringContainsString('id="publisherBalanceCta"', $html);
        $this->assertStringContainsString('id="publisherWithdrawCta"', $html);
        $this->assertStringNotContainsString('Transfer to Publisher Wallet', $html);
    }

    public function test_add_funds_hides_publisher_strip_without_publisher_role(): void
    {
        $this->user->roles()->detach(Wallet::publisherRoleId());

        $html = $this->actingAs($this->user->fresh())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="publisherRoleStrip"', $html);
        $this->assertStringNotContainsString('id="publisherBalanceCta"', $html);
        $this->assertStringNotContainsString(route('publisher.balance'), $html);
    }

    public function test_dual_role_advertiser_can_open_publisher_balance_and_withdraw(): void
    {
        $this->assertSame('advertiser', $this->user->activeRole());

        $this->actingAs($this->user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->assertSee('Publisher earnings', false);

        $this->assertSame('publisher', $this->user->fresh()->activeRole());

        $this->user->forceFill(['active_role_id' => Wallet::advertiserRoleId()])->save();

        $this->actingAs($this->user->fresh())
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee('Withdraw Funds', false);

        $this->assertSame('publisher', $this->user->fresh()->activeRole());
    }

    public function test_brand_colors_use_icon_signal_caution_and_teal_code(): void
    {
        $brand = file_get_contents(public_path('assets/css/brand-colors.css'));
        $this->assertIsString($brand);
        $this->assertStringContainsString('--bs-code-color: #1a585e', $brand);
        $this->assertStringContainsString('--brand-primary: #1a585e', $brand);
        $this->assertStringContainsString('--brand-warning-bg: #fff7ed', $brand);
        // Amber, not the danger red it used to share.
        $this->assertStringContainsString('--brand-warning: #b45309', $brand);
        $this->assertStringContainsString('.alert-warning', $brand);
        $this->assertStringContainsString('.ui-callout--attention', $brand);
        $this->assertStringNotContainsString('--brand-warning-bg: #fffbeb', $brand);
        $this->assertStringNotContainsString('--brand-warning: #1a585e', $brand);
        $this->assertStringNotContainsString('#185054', $brand);
    }

    public function test_add_funds_reconciles_inflated_bonus_to_welcome_credit(): void
    {
        $this->wallet->update([
            'balance' => 45,
            'bonus_balance' => 45,
        ]);

        DB::table('wallet_transactions')->insert([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'bonus_credit',
            'direction' => 'credit',
            'amount' => 20,
            'bonus_amount' => 20,
            'currency' => 'EUR',
            'status' => 'completed',
            'description' => 'Welcome promotional bonus',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Bonus €20.00', false)
            ->assertSee('Money', false)
            ->assertDontSee('Bonus €45.00', false)
            ->getContent();

        $this->assertStringContainsString('id="kpiBonus">€20.00', $html);
        $this->assertStringContainsString('id="kpiAvailable">€25.00', $html);
        $this->assertStringContainsString('id="kpiSpendable">€45.00', $html);

        $this->wallet->refresh();
        $this->assertEquals(20.0, (float) $this->wallet->bonus_balance);
        $this->assertEquals(45.0, (float) $this->wallet->balance);
    }

    public function test_cannot_withdraw_bonus_only_balance(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 10,
            'payment_method' => 'paypal',
            'business_name' => 'Acme Media',
            'paypal_email' => 'user@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'code' => 'bonus_not_withdrawable',
            'message' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
        ]);

        $this->wallet->refresh();
        $this->assertEquals(20.0, (float) $this->wallet->balance);
        $this->assertEquals(20.0, (float) $this->wallet->bonus_balance);
        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_can_withdraw_from_available_balance_only(): void
    {
        Mail::fake();
        $this->wallet->addBalance(50);
        $this->wallet->refresh();

        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 30,
            'payment_method' => 'paypal',
            'business_name' => 'Acme Media',
            'paypal_email' => 'user@example.com',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->wallet->refresh();
        $this->assertEquals(40.0, (float) $this->wallet->balance);
        $this->assertEquals(20.0, (float) $this->wallet->bonus_balance);
        $this->assertSame(20.0, $this->wallet->withdrawableBalance());
        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'amount' => 30,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->user->id,
            'type' => 'withdrawal',
            'amount' => 30,
        ]);
        $this->user->refresh();
        $this->assertSame('Acme Media', $this->user->payout_business_name);
        $this->assertSame('user@example.com', $this->user->payout_paypal_email);
        $this->assertNotNull($this->user->payout_profile_locked_at);
    }

    public function test_locked_payout_fields_cannot_be_changed(): void
    {
        Mail::fake();
        $this->wallet->addBalance(50);
        $this->user->forceFill([
            'payout_business_name' => 'Locked Biz',
            'payout_paypal_email' => 'locked@example.com',
            'payout_profile_locked_at' => now(),
        ])->save();

        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 10,
            'payment_method' => 'paypal',
            'business_name' => 'Different Biz',
            'paypal_email' => 'other@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('locked', strtolower((string) $response->json('message')));
    }

    public function test_locked_advertiser_can_withdraw_via_another_saved_method(): void
    {
        Mail::fake();
        $this->wallet->addBalance(50);
        $this->user->forceFill([
            'payout_business_name' => 'Locked Biz',
            'payout_paypal_email' => 'locked@example.com',
            'payout_wise_email' => 'wise@example.com',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 10,
            'payment_method' => 'wise',
            'business_name' => 'Locked Biz',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->user->refresh();
        $this->assertSame('wise', $this->user->payout_preferred_method);
        $this->assertSame('locked@example.com', $this->user->payout_paypal_email);
        $this->assertSame('wise@example.com', $this->user->payout_wise_email);

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $this->user->id,
            'payment_method' => 'wise',
            'amount' => 10,
        ]);
    }

    public function test_locked_advertiser_cannot_select_method_without_saved_details(): void
    {
        Mail::fake();
        $this->wallet->addBalance(50);
        $this->user->forceFill([
            'payout_business_name' => 'Locked Biz',
            'payout_paypal_email' => 'locked@example.com',
            'payout_preferred_method' => 'paypal',
            'payout_profile_locked_at' => now(),
        ])->save();

        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 10,
            'payment_method' => 'bank',
            'business_name' => 'Locked Biz',
            'bank_name' => 'Hack Bank',
            'account_holder' => 'Hacker',
            'account_number' => 'DE00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('locked', strtolower((string) $response->json('message')));
        $this->assertNull($this->user->fresh()->payout_bank_account);
        $this->assertSame('paypal', $this->user->fresh()->payout_preferred_method);
    }

    public function test_crypto_withdraw_requires_double_wallet_entry(): void
    {
        Mail::fake();
        $this->wallet->addBalance(50);

        $bad = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 10,
            'payment_method' => 'crypto',
            'business_name' => 'Crypto Co',
            'crypto_type' => 'USDT_TRC20',
            'wallet_address' => 'TXabc123',
            'wallet_address_confirm' => 'TXdifferent',
        ]);
        $bad->assertStatus(422);

        $ok = $this->actingAs($this->user)->postJson(route('advertiser.balance.withdraw'), [
            'amount' => 10,
            'payment_method' => 'crypto',
            'business_name' => 'Crypto Co',
            'crypto_type' => 'USDT_TRC20',
            'wallet_address' => 'TXabc123',
            'wallet_address_confirm' => 'TXabc123',
        ]);
        $ok->assertOk();
        $this->user->refresh();
        $this->assertSame('TXabc123', $this->user->payout_crypto_trx_wallet);
        $this->assertNotNull($this->user->payout_crypto_trx_verified_at);
    }

    public function test_role_transfers_are_disabled(): void
    {
        $this->wallet->addBalance(50);

        $response = $this->actingAs($this->user)->postJson(route('advertiser.balance.transfer'), [
            'amount' => 5,
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', 'transfers_disabled');
        $this->wallet->refresh();
        $this->assertEquals(70.0, (float) $this->wallet->balance);
    }

    public function test_transactions_endpoint_does_not_invent_a_purchase_for_failed_wallet_orders(): void
    {
        Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-FAIL-WALLET',
            'reference_code' => 'REF-FAIL-WALLET',
            'subtotal' => 40,
            'tax' => 0,
            'total_amount' => 40,
            'payment_method' => 'wallet',
            'payment_status' => 'failed',
            'status' => 'pending',
        ]);
        Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-LEFTOVER-REFUND',
            'reference_code' => 'REF-LEFTOVER-REFUND',
            'subtotal' => 25,
            'tax' => 0,
            'total_amount' => 25,
            'payment_method' => 'wallet',
            'payment_status' => 'refunded',
            'status' => 'review',
        ]);
        Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-CLAWBACK-NO-LEDGER',
            'reference_code' => 'REF-CLAWBACK-NO-LEDGER',
            'subtotal' => 55,
            'tax' => 0,
            'total_amount' => 55,
            'payment_method' => 'wallet',
            'payment_status' => 'refunded',
            'status' => 'completed',
            'paid_at' => now(),
        ]);
        Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-CANCEL-NO-LEDGER',
            'reference_code' => 'REF-CANCEL-NO-LEDGER',
            'subtotal' => 18,
            'tax' => 0,
            'total_amount' => 18,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'cancelled',
            'paid_at' => now(),
        ]);
        Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-PAID-WALLET',
            'reference_code' => 'REF-PAID-WALLET',
            'subtotal' => 30,
            'tax' => 0,
            'total_amount' => 30,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now(),
        ]);

        $rows = collect($this->actingAs($this->user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('transactions'));

        $this->assertFalse($rows->contains(fn ($row) => ($row['reference'] ?? '') === 'REF-FAIL-WALLET'));
        $this->assertFalse($rows->contains(fn ($row) => ($row['reference'] ?? '') === 'REF-LEFTOVER-REFUND'));
        $this->assertFalse($rows->contains(fn ($row) => ($row['reference'] ?? '') === 'REF-CLAWBACK-NO-LEDGER'));
        $this->assertFalse($rows->contains(fn ($row) => ($row['reference'] ?? '') === 'REF-CANCEL-NO-LEDGER'));
        $paid = $rows->first(fn ($row) => ($row['reference'] ?? '') === 'REF-PAID-WALLET');
        $this->assertNotEmpty($paid);
        $this->assertSame('Purchase', $paid['type_label']);
        $this->assertSame('Marketplace order purchase', $paid['description']);
    }

    public function test_transactions_endpoint_does_not_credit_rejected_or_refunded_legacy_deposits(): void
    {
        DepositRequest::create([
            'user_id' => $this->user->id,
            'reference_code' => 'DEP-REJECTED',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'rejected',
            'user_marked_paid_at' => now(),
        ]);
        DepositRequest::create([
            'user_id' => $this->user->id,
            'reference_code' => 'DEP-REFUNDED',
            'amount' => 25,
            'payment_method' => 'paypal',
            'status' => 'refunded',
        ]);

        $rows = collect($this->actingAs($this->user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('transactions'));

        $rejected = $rows->first(fn ($row) => ($row['reference'] ?? '') === 'DEP-REJECTED');
        $this->assertNotEmpty($rejected);
        $this->assertSame('Rejected deposit', $rejected['type_label']);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('none', $rejected['direction']);
        $this->assertSame(0, (int) $rejected['signed_amount']);
        $this->assertFalse($rejected['can_mark_paid']);
        $this->assertNull($rejected['mark_paid_url']);
        $this->assertStringContainsString('not credited', $rejected['description']);

        $refunded = $rows->first(fn ($row) => ($row['reference'] ?? '') === 'DEP-REFUNDED');
        $this->assertNotEmpty($refunded);
        $this->assertSame('Refunded deposit', $refunded['type_label']);
        $this->assertSame('refunded', $refunded['status']);
        $this->assertSame('debit', $refunded['direction']);
        $this->assertSame(-25.0, (float) $refunded['signed_amount']);
        $this->assertFalse($refunded['can_mark_paid']);
        $this->assertNull($refunded['mark_paid_url']);
    }

    public function test_transactions_endpoint_relabels_ledger_deposit_after_clawback(): void
    {
        $deposit = DepositRequest::create([
            'user_id' => $this->user->id,
            'reference_code' => 'DEP-LEDGER-RF',
            'amount' => 25,
            'payment_method' => 'paypal',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);
        app(WalletLedgerService::class)->recordDeposit(
            $this->wallet,
            25,
            $deposit,
            'paypal',
            $deposit->reference_code
        );
        app(WalletLedgerService::class)->recordAdjustment(
            $this->wallet,
            25,
            'debit',
            $deposit,
            $deposit->reference_code,
            'PayPal deposit refunded'
        );
        $deposit->update(['status' => 'refunded']);

        $rows = collect($this->actingAs($this->user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('transactions'));

        $credit = $rows->first(fn ($row) => ($row['reference'] ?? '') === 'DEP-LEDGER-RF'
            && ($row['type'] ?? '') === 'deposit');
        $this->assertNotEmpty($credit);
        $this->assertSame('Refunded deposit', $credit['type_label']);
        $this->assertSame('refunded', $credit['status']);
        $this->assertSame('credit', $credit['direction']);
        $this->assertSame(25.0, (float) $credit['signed_amount']);
        $this->assertFalse($credit['can_mark_paid']);
        $this->assertStringContainsString('refunded and removed', $credit['description']);

        $debit = $rows->first(fn ($row) => ($row['reference'] ?? '') === 'DEP-LEDGER-RF'
            && ($row['type'] ?? '') === 'adjustment');
        $this->assertNotEmpty($debit);
        $this->assertSame('debit', $debit['direction']);
        $this->assertSame(-25.0, (float) $debit['signed_amount']);
    }

    public function test_transactions_endpoint_marks_leftover_refunded_purchase_refunded(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-LEDGER-LEFT',
            'reference_code' => 'REF-LEDGER-LEFT',
            'subtotal' => 80,
            'tax' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'payment_status' => 'refunded',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        app(WalletLedgerService::class)->recordPurchase(
            $this->wallet,
            80,
            0,
            $order,
            $order->reference_code
        );
        Invoice::create([
            'user_id' => $this->user->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-LEDGER-LEFT',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'paid',
            'invoice_date' => now(),
            'customer_name' => $this->user->name,
            'customer_email' => $this->user->email,
            'currency' => 'EUR',
            'subtotal' => 80,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'order_number' => $order->order_number,
            'reference_code' => $order->reference_code,
            'line_items' => [['description' => 'Leftover purchase', 'line_total' => 80]],
            'billing_snapshot' => [],
        ]);

        $rows = collect($this->actingAs($this->user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('transactions'));

        $purchase = $rows->first(fn ($row) => ($row['reference'] ?? '') === 'REF-LEDGER-LEFT'
            && ($row['type'] ?? '') === 'purchase');
        $this->assertNotEmpty($purchase);
        $this->assertSame('refunded', $purchase['status']);
        $this->assertSame('Refunded purchase', $purchase['type_label']);
        $this->assertStringContainsString('refunded', $purchase['description']);
        $this->assertSame('debit', $purchase['direction']);
        $this->assertSame(-80.0, (float) $purchase['signed_amount']);
    }

    public function test_transactions_endpoint_marks_leftover_failed_purchase_failed(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-LEDGER-FAIL',
            'reference_code' => 'REF-LEDGER-FAIL',
            'subtotal' => 60,
            'tax' => 0,
            'total_amount' => 60,
            'payment_method' => 'wallet',
            'payment_status' => 'failed',
            'status' => 'pending',
            'paid_at' => now(),
        ]);
        app(WalletLedgerService::class)->recordPurchase(
            $this->wallet,
            60,
            0,
            $order,
            $order->reference_code
        );
        Invoice::create([
            'user_id' => $this->user->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-LEDGER-FAIL',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'paid',
            'invoice_date' => now(),
            'customer_name' => $this->user->name,
            'customer_email' => $this->user->email,
            'currency' => 'EUR',
            'subtotal' => 60,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 60,
            'payment_method' => 'wallet',
            'order_number' => $order->order_number,
            'reference_code' => $order->reference_code,
            'line_items' => [['description' => 'Leftover failed purchase', 'line_total' => 60]],
            'billing_snapshot' => [],
        ]);
        Invoice::create([
            'user_id' => $this->user->id,
            'order_id' => $order->id,
            'invoice_number' => 'FAIL-LEDGER-FAIL',
            'type' => Invoice::TYPE_PAYMENT_FAILURE,
            'status' => Invoice::STATUS_FAILED,
            'payment_status' => 'failed',
            'invoice_date' => now(),
            'customer_name' => $this->user->name,
            'customer_email' => $this->user->email,
            'currency' => 'EUR',
            'subtotal' => 60,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 60,
            'payment_method' => 'wallet',
            'order_number' => $order->order_number,
            'reference_code' => $order->reference_code,
            'line_items' => [['description' => 'Payment failed', 'line_total' => 60]],
            'billing_snapshot' => [],
        ]);

        $rows = collect($this->actingAs($this->user)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('transactions'));

        $purchase = $rows->first(fn ($row) => ($row['reference'] ?? '') === 'REF-LEDGER-FAIL'
            && ($row['type'] ?? '') === 'purchase');
        $this->assertNotEmpty($purchase);
        $this->assertSame('failed', $purchase['status']);
        $this->assertSame('Failed purchase', $purchase['type_label']);
        $this->assertStringContainsString('not live spend', $purchase['description']);
        $this->assertSame('debit', $purchase['direction']);
        $this->assertSame(-60.0, (float) $purchase['signed_amount']);
        $this->assertSame('FAIL-LEDGER-FAIL', $purchase['invoice_number']);
    }

    public function test_transactions_endpoint_returns_bonus_activity(): void
    {
        app(WalletLedgerService::class)->recordBonusCredit(
            $this->wallet,
            20,
            'Welcome promotional bonus'
        );

        $response = $this->actingAs($this->user)->getJson(route('advertiser.balance.transactions'));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $types = collect($response->json('transactions'))->pluck('type')->all();
        $this->assertContains('bonus_credit', $types);
    }

    public function test_analytics_endpoint_accepts_ranges(): void
    {
        $response = $this->actingAs($this->user)->getJson(route('advertiser.balance.analytics', [
            'range' => '7d',
        ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'analytics' => [
                'labels', 'deposits', 'orders', 'withdrawals', 'bonus_usage',
                'points', 'order_details', 'has_spend', 'keys',
            ],
        ]);
    }
}
