<?php

namespace Tests\Feature;

use App\Mail\DepositRequestSubmitted;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdvertiserAddFundsErrorTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ]);
        $user->roles()->attach($role->id);

        Wallet::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id],
            [
                'balance' => 0,
                'reserved_balance' => 0,
                'bonus_balance' => 0,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]
        );

        return $user->fresh();
    }

    public function test_invoice_store_validation_is_422_not_500(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 5,
                'payment_method' => 'wise',
                'reference_code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount')
            ->assertJsonMissingPath('exception');
    }

    public function test_store_validation_is_json_even_without_accept_json_header(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->from(route('advertiser.add-funds'))
            ->post(route('advertiser.add-funds.store'), [
                'amount' => 5,
                'payment_method' => 'wise',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_store_creates_invoice_from_html_form_post(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->post(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'bank',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $code = (string) DepositRequest::query()->where('user_id', $advertiser->id)->value('reference_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_store_does_not_persist_dummy_xxxxxxxx_placeholder(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 40,
                'payment_method' => 'wise',
                'reference_code' => 'XXXXXXXX',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissing(['reference_code' => 'XXXXXXXX']);

        $this->assertDatabaseMissing('deposit_requests', [
            'user_id' => $advertiser->id,
            'reference_code' => 'XXXXXXXX',
        ]);
    }

    public function test_store_skips_admin_mail_when_no_mailbox_is_configured(): void
    {
        config([
            'mail.admin_email' => '',
            'email_notifications.brand.support_email' => '',
        ]);
        Mail::fake();
        Log::spy();

        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'wise',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertNothingOutgoing();
        Log::shouldNotHaveReceived(
            'error',
            fn (...$args) => is_string($args[0] ?? null)
                && str_contains($args[0], 'Failed to send deposit notification email')
        );
    }

    public function test_store_uses_support_fallback_when_admin_email_is_empty(): void
    {
        config([
            'mail.admin_email' => '',
            'email_notifications.brand.support_email' => 'ops@seolinkbuildings.com',
        ]);
        Mail::fake();

        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'bank',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertQueued(DepositRequestSubmitted::class, function ($mail) {
            return $mail->hasTo('ops@seolinkbuildings.com');
        });
    }

    public function test_store_survives_missing_users_company_name_column(): void
    {
        $advertiser = $this->advertiser();
        Schema::table('users', function ($table) {
            $table->dropColumn('company_name');
        });

        $this->actingAs($advertiser->fresh())
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50,
                'payment_method' => 'wise',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('SQLSTATE');
    }

    public function test_stripe_checkout_validation_is_422_not_wrapped_success(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.create-checkout-session'), [
                'amount' => 5,
                'reference_code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_paypal_create_validation_is_422(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.add-funds.paypal.create'), [
                'amount' => 5,
                'reference_code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_add_funds_hides_fake_zeros_when_wallets_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('wallets');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Add funds', false)
            ->assertSee('We could not load your wallet', false)
            ->assertSee('Unavailable', false)
            ->assertDontSee('SQLSTATE', false)
            ->getContent();

        $this->assertStringContainsString('id="kpiSpendable">—', $html);
        $this->assertStringContainsString('id="kpiAvailable">—', $html);
        $this->assertStringNotContainsString('id="kpiSpendable">€0.00', $html);
        $this->assertStringNotContainsString('pending deposit confirmation', $html);
    }

    public function test_activity_feed_ignores_junk_dates(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.transactions', [
                'from' => 'leftover',
                'to' => 'not-a-date',
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.analytics', [
                'range' => 'custom',
                'from' => '0000-00-00',
                'to' => 'tomorrow',
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_add_funds_page_survives_leftover_deposit_dates(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DATE500',
            'amount' => 40,
            'payment_method' => 'wise',
            'status' => 'completed',
        ]);
        DB::table('deposit_requests')->where('id', $deposit->id)->update([
            'created_at' => 'not-a-date',
            'updated_at' => 'also-bad',
            'paid_at' => 'leftover',
            'approved_at' => '0000-00-00',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Add funds', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('Something went wrong', false);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.analytics', ['range' => 'month']))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_add_funds_page_survives_missing_deposit_requests_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('deposit_requests');
        $this->assertFalse(Schema::hasTable('deposit_requests'));

        try {
            $this->actingAs($advertiser)
                ->get(route('advertiser.add-funds'))
                ->assertOk()
                ->assertSee('Add funds', false)
                ->assertDontSee('SQLSTATE', false)
                ->assertDontSee('Something went wrong', false);

            $this->actingAs($advertiser)
                ->postJson(route('advertiser.add-funds.store'), [
                    'amount' => 50,
                    'payment_method' => 'wise',
                    'reference_code' => '654321',
                ])
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Deposits are temporarily unavailable. Please try again shortly.')
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');

            $this->actingAs($advertiser)
                ->getJson(route('advertiser.add-funds.status', 1))
                ->assertStatus(503)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE');

            $this->actingAs($advertiser)
                ->getJson(route('advertiser.balance.transactions'))
                ->assertOk()
                ->assertJsonPath('success', true);
        } finally {
            $this->restoreDepositRequestsTable();
        }
    }

    public function test_activity_feed_keeps_ledger_rows_when_invoices_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        $wallet = Wallet::where('user_id', $advertiser->id)->first();
        $invoice = Invoice::create([
            'invoice_number' => 'INV-LEFTOVER',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $advertiser->id,
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'subtotal' => 50,
            'total_amount' => 50,
            'invoice_date' => now(),
            'reference_code' => '654321',
            'line_items' => [['description' => 'Deposit', 'line_total' => 50]],
        ]);
        WalletTransaction::create([
            'user_id' => $advertiser->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'direction' => 'credit',
            'amount' => 50,
            'status' => 'completed',
            'description' => 'Wallet deposit via Wise',
            'reference' => '654321',
            'related_type' => Invoice::class,
            'related_id' => $invoice->id,
            'currency' => 'EUR',
        ]);

        Schema::dropIfExists('invoices');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('transactions.0.reference', '654321')
            ->assertJsonPath('transactions.0.invoice_id', null)
            ->assertDontSee('SQLSTATE');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.analytics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('SQLSTATE');
    }

    public function test_activity_feed_survives_missing_legacy_wallet_tables(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('balance_transfers');
        Schema::dropIfExists('orders');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('SQLSTATE');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.analytics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('SQLSTATE');
    }

    public function test_add_funds_keeps_checkout_hold_when_withdrawals_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        Wallet::where('user_id', $advertiser->id)->update([
            'balance' => 80,
            'reserved_balance' => 15,
            'bonus_balance' => 0,
        ]);
        Schema::dropIfExists('withdrawals');

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('on hold for checkout', false)
            ->assertDontSee('SQLSTATE', false)
            ->getContent();

        $this->assertStringContainsString('id="kpiSpendable">€80.00', $html);
        $this->assertStringContainsString('€15.00', $html);
    }

    public function test_add_funds_shows_hold_and_pending_when_overview_summary_fails(): void
    {
        $advertiser = $this->advertiser();
        Wallet::where('user_id', $advertiser->id)->update([
            'balance' => 80,
            'reserved_balance' => 15,
            'bonus_balance' => 20,
        ]);
        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'HOLD15',
            'amount' => 40,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);

        $this->mock(WalletOverviewService::class, function ($mock) {
            $mock->shouldReceive('summary')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[HY000]: leftover'));
            $mock->shouldReceive('analytics')
                ->once()
                ->andReturn(['labels' => [], 'deposits' => [], 'orders' => []]);
        });

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('on hold for checkout', false)
            ->assertSee('deposit confirmation', false)
            ->assertDontSee('SQLSTATE', false)
            ->getContent();

        $this->assertStringContainsString('id="kpiSpendable">€80.00', $html);
        $this->assertStringContainsString('€15.00', $html);
        $this->assertStringContainsString('€40.00', $html);
    }

    public function test_activity_feed_survives_missing_wallet_transactions_table(): void
    {
        $advertiser = $this->advertiser();
        Schema::dropIfExists('wallet_transactions');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.transactions'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('SQLSTATE');

        $this->actingAs($advertiser)
            ->getJson(route('advertiser.balance.analytics'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertDontSee('SQLSTATE');
    }

    public function test_header_hides_fake_zero_when_wallet_row_is_missing(): void
    {
        $advertiser = $this->advertiser();
        Wallet::where('user_id', $advertiser->id)->delete();

        $html = $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertSee('Spendable balance unavailable', false)
            ->assertDontSee('SQLSTATE', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/class="balance-amount">—/', $html);
        $this->assertStringNotContainsString('class="balance-amount">€0.00', $html);
    }

    public function test_withdraw_is_503_when_withdrawals_table_is_gone(): void
    {
        $advertiser = $this->advertiser();
        Wallet::where('user_id', $advertiser->id)->update([
            'balance' => 50,
            'bonus_balance' => 0,
        ]);
        Schema::dropIfExists('withdrawals');

        $this->actingAs($advertiser)
            ->postJson(route('advertiser.balance.withdraw'), [
                'amount' => 10,
                'payment_method' => 'paypal',
                'business_name' => 'Acme Media',
                'paypal_email' => 'user@example.com',
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Withdrawals are temporarily unavailable. Please try again shortly.')
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_missing_pay_in_invoice_returns_to_add_funds(): void
    {
        $advertiser = $this->advertiser();

        $this->actingAs($advertiser)
            ->get(route('advertiser.invoice', 'MISSING99'))
            ->assertRedirect(route('advertiser.add-funds'));
        $this->assertSame('Invoice not found', session('error'));
    }

    private function restoreDepositRequestsTable(): void
    {
        foreach ([
            'database/migrations/2026_04_21_115734_create_deposit_requests_table.php',
            'database/migrations/2026_04_22_113004_add_stripe_fields_to_deposit_requests_table.php',
            'database/migrations/2026_07_21_140000_add_user_marked_paid_to_deposit_requests.php',
            'database/migrations/2026_08_14_160000_unique_deposit_stripe_ids.php',
            'database/migrations/2026_08_18_160000_add_paypal_columns_to_deposit_requests.php',
        ] as $path) {
            $this->artisan('migrate', [
                '--path' => $path,
                '--force' => true,
            ]);
        }
    }
}
