<?php

namespace Tests\Feature;

use App\Mail\WithdrawalStatusUpdated;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Billing\WithdrawalPayoutStatementService;
use App\Services\Orders\OrderRefundService;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingPhases46Test extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function publisherWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::publisherRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function pendingWithdrawal(User $user, float $amount = 100): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'fee' => 5,
            'net_amount' => $amount - 5,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ]);
    }

    private function paidOrder(User $advertiser): Order
    {
        $publisher = User::factory()->create();
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Billing Phase Site',
            'site_url' => 'https://billing-phase.example',
            'domain' => 'billing-phase.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 100,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        return DB::transaction(function () use ($advertiser, $site) {
            $order = Order::create([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-'.uniqid(),
                'reference_code' => 'REF-'.uniqid(),
                'subtotal' => 115,
                'tax' => 0,
                'total_amount' => 115,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'status' => 'pending',
                'paid_at' => now(),
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 115,
                'content_link' => 'https://example.com/article.docx',
            ]);

            return $order->fresh(['user', 'items']);
        });
    }

    public function test_mark_paid_issues_payout_statement_pdf(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 80);

        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin, 'Paid');

        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());
        $this->assertNotNull($statement);
        $this->assertSame(Invoice::TYPE_WITHDRAWAL_PAYOUT, $statement->type);
        $this->assertMatchesRegularExpression('/^PAY-\d{4}-\d{6}$/', $statement->invoice_number);
        $this->assertSame(75.0, (float) $statement->total_amount);
        Storage::disk('local')->assertExists($statement->pdf_path);
    }

    public function test_reject_records_ledger_credit(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $wallet = $this->publisherWallet($publisher, 0);
        $withdrawal = $this->pendingWithdrawal($publisher, 40);

        app(ManualWithdrawalSettlementService::class)->reject($withdrawal, $admin, 'Bad IBAN');

        $this->assertSame(40.0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_ADJUSTMENT,
            'direction' => 'credit',
            'amount' => 40,
            'reference' => 'WD-'.$withdrawal->id.'-refund',
        ]);
    }

    public function test_publisher_can_list_and_download_payout_docs(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 60);
        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);
        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());

        $this->actingAs($publisher)
            ->get(route('publisher.billing.index'))
            ->assertOk()
            ->assertSee($statement->invoice_number, false)
            ->assertSee('Wise', false);

        $this->actingAs($publisher)
            ->get(route('publisher.billing.show', $statement))
            ->assertOk()
            ->assertSee('View PDF', false);

        $this->actingAs($publisher)
            ->get(route('publisher.billing.view', $statement))
            ->assertOk();

        $this->actingAs($publisher)
            ->get(route('publisher.billing.download', $statement))
            ->assertOk();
    }

    public function test_payout_pdf_html_uses_payout_labels_not_invoice_ones(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 80);
        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);
        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());
        $this->assertNotNull($statement);

        $html = view('billing.pdf.invoice', [
            'invoice' => $statement,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('Payout Statement', $html);
        $this->assertStringContainsString('Withdrawal fee', $html);
        $this->assertStringContainsString('WD-'.$withdrawal->id, $html);
        $this->assertStringContainsString('Net payout', $html);
        $this->assertStringContainsString('Pay to', $html);
        $this->assertStringContainsString('About this statement', $html);
        $this->assertStringNotContainsString('Publisher website', $html);
        $this->assertStringNotContainsString('Order: <strong>#</strong>', $html);
        $this->assertStringNotContainsString('>Discount', $html);
        $this->assertSame(1, substr_count($html, 'Withdrawal fee'));
        $this->assertStringNotContainsString('Bill to', $html);
    }

    public function test_publisher_cannot_access_another_publishers_payout_doc(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $owner = $this->makeUser('publisher');
        $other = $this->makeUser('publisher');
        $this->publisherWallet($owner);
        $withdrawal = $this->pendingWithdrawal($owner, 50);
        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);
        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());

        $this->actingAs($other)
            ->get(route('publisher.billing.show', $statement))
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('publisher.billing.download', $statement))
            ->assertForbidden();
    }

    public function test_billing_index_ignores_invalid_dates_and_swaps_reversed_range(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 40);
        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);
        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());

        $this->actingAs($publisher)
            ->get(route('publisher.billing.index', ['from' => 'not-a-date', 'to' => 'also-bad']))
            ->assertOk()
            ->assertSee($statement->invoice_number, false);

        // Overflow / impossible calendar dates must be ignored (not coerced).
        $this->actingAs($publisher)
            ->get(route('publisher.billing.index', ['from' => '2024-13-40', 'to' => '2024-02-30']))
            ->assertOk()
            ->assertSee($statement->invoice_number, false);

        $this->actingAs($publisher)
            ->get(route('publisher.billing.index', [
                'from' => now()->addDay()->toDateString(),
                'to' => now()->subDay()->toDateString(),
            ]))
            ->assertOk();
    }

    public function test_publisher_billing_rejects_non_payout_invoice_type(): void
    {
        $publisher = $this->makeUser('publisher');
        $tax = Invoice::create([
            'invoice_number' => 'INV-TEST-0001',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $publisher->id,
            'currency' => 'EUR',
            'subtotal' => 10,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 10,
            'invoice_date' => now(),
            'customer_name' => $publisher->name,
            'customer_email' => $publisher->email,
            'line_items' => [],
            'pdf_disk' => 'local',
        ]);

        $this->actingAs($publisher)
            ->get(route('publisher.billing.show', $tax))
            ->assertNotFound();

        $this->actingAs($publisher)
            ->get(route('publisher.billing.download', $tax))
            ->assertNotFound();
    }

    public function test_issue_keeps_statement_when_pdf_generation_fails(): void
    {
        Mail::fake();
        Storage::fake('local');

        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 45);
        $withdrawal->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $pdfs = \Mockery::mock(InvoicePdfGenerator::class);
        $pdfs->shouldReceive('generateAndStore')->once()->andThrow(new \RuntimeException('pdf boom'));
        $this->app->instance(InvoicePdfGenerator::class, $pdfs);

        $statement = app(WithdrawalPayoutStatementService::class)->issue($withdrawal->fresh(['user']));

        $this->assertNotNull($statement);
        $this->assertSame(Invoice::TYPE_WITHDRAWAL_PAYOUT, $statement->type);
        $this->assertDatabaseHas('invoices', [
            'id' => $statement->id,
            'reference_code' => 'WD-'.$withdrawal->id,
        ]);
    }

    public function test_backfill_creates_missing_payout_statement(): void
    {
        Mail::fake();
        Storage::fake('local');

        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 70);
        $withdrawal->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $this->assertNull(app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh()));

        $result = app(WithdrawalPayoutStatementService::class)->backfillMissing(10);

        $this->assertSame(1, $result['created']);
        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());
        $this->assertNotNull($statement);
        $this->assertSame(Invoice::TYPE_WITHDRAWAL_PAYOUT, $statement->type);
    }

    public function test_legacy_fee_line_item_is_stripped_from_payout_pdf(): void
    {
        Mail::fake();
        Storage::fake('local');

        $publisher = $this->makeUser('publisher');
        $publisher->forceFill([
            'payout_business_name' => 'Acme Media GmbH',
            'name' => 'Jane Publisher',
        ])->save();

        $statement = Invoice::create([
            'invoice_number' => 'PAY-2026-999001',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $publisher->id,
            'reference_code' => 'WD-999001',
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 5,
            'total_amount' => 95,
            'payment_method' => 'wise',
            'payment_status' => 'paid',
            'invoice_date' => now(),
            'paid_at' => now(),
            'customer_name' => 'Acme Media GmbH',
            'customer_email' => $publisher->email,
            'billing_snapshot' => [
                'name' => 'Acme Media GmbH',
                'company' => 'Acme Media GmbH',
                'email' => $publisher->email,
                'payment_details' => ['email' => 'pay@example.com'],
            ],
            'line_items' => [
                [
                    'description' => 'Publisher withdrawal payout',
                    'reference' => 'WD-999001',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'line_total' => 100,
                ],
                [
                    'description' => 'Withdrawal fee',
                    'reference' => 'WD-999001-fee',
                    'quantity' => 1,
                    'unit_price' => -5,
                    'line_total' => -5,
                ],
            ],
            'pdf_disk' => 'local',
            'notes' => 'Payout statement',
        ]);

        $normalized = app(WithdrawalPayoutStatementService::class)
            ->normalizeLegacyFeeLineItems($statement->fresh());

        $this->assertCount(1, $normalized->line_items);
        $this->assertSame('Publisher withdrawal payout', $normalized->line_items[0]['description']);

        $html = view('billing.pdf.invoice', [
            'invoice' => $normalized,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertSame(1, substr_count($html, 'Withdrawal fee'));
        $this->assertStringContainsString('Pay to', $html);
        // Company must not duplicate the payee name.
        $this->assertSame(1, substr_count($html, 'Acme Media GmbH'));
    }

    public function test_mark_paid_email_and_bell_point_at_payout_docs(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->makeUser('admin');
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);
        $withdrawal = $this->pendingWithdrawal($publisher, 55);

        app(ManualWithdrawalSettlementService::class)->markPaid($withdrawal, $admin);

        $statement = app(WithdrawalPayoutStatementService::class)->find($withdrawal->fresh());
        $this->assertNotNull($statement);

        Mail::assertQueued(WithdrawalStatusUpdated::class, function ($mail) use ($statement) {
            $built = $mail->build();
            $data = $built->viewData;

            return ($data['hasStatement'] ?? false) === true
                && ($data['statementUrl'] ?? null) === route('publisher.billing.download', $statement)
                && (float) $data['withdrawal']->net_amount === 50.0;
        });

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $publisher->id,
            'action_label' => 'View payout document',
        ]);
    }

    public function test_withdraw_page_links_to_payout_documents(): void
    {
        $publisher = $this->makeUser('publisher');
        $this->publisherWallet($publisher);

        $this->actingAs($publisher)
            ->get(route('publisher.withdraw'))
            ->assertOk()
            ->assertSee(route('publisher.billing.index'), false)
            ->assertSee('Payout documents', false);
    }

    public function test_line_refund_amount_uses_order_total_for_single_item(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $order = $this->paidOrder($advertiser);

        $amount = app(OrderRefundService::class)->resolveLineRefundAmount($order, 99.0);
        $this->assertSame(115.0, $amount);
    }

    public function test_backfill_creates_missing_tax_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->makeUser('advertiser');
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();

        $result = app(BillingDocumentService::class)->backfillMissingTaxInvoices(10);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'type' => Invoice::TYPE_TAX_INVOICE,
        ]);
    }

    public function test_promo_feature_invoicing_stays_disabled(): void
    {
        $this->assertFalse((bool) config('billing.promo_feature.issue_invoice'));
        $this->assertNotEmpty(config('billing.promo_feature.exclusion_note'));
    }
}
