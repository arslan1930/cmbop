<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\WithdrawalStatusUpdated;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Billing\BillingDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminInvoiceOpsTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Alice Advertiser',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Pat Publisher',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function paidOrder(User $advertiser, string $paymentStatus = 'paid'): Order
    {
        $publisher = User::factory()->create();
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Ops Test Site',
            'site_url' => 'https://ops-test.example',
            'domain' => 'ops-test.example',
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

        $order = DB::transaction(function () use ($advertiser, $site, $paymentStatus) {
            $order = Order::create([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-'.uniqid(),
                'reference_code' => 'REF-'.uniqid(),
                'subtotal' => 115,
                'tax' => 0,
                'total_amount' => 115,
                'payment_method' => 'wallet',
                'payment_status' => $paymentStatus,
                'status' => 'pending',
                'paid_at' => $paymentStatus === 'paid' ? now() : null,
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'site_id' => $site->id,
                'site_name' => $site->site_name,
                'site_url' => $site->site_url,
                'price' => 115,
                'content_link' => 'https://example.com/article.docx',
            ]);

            return $order;
        });

        return $order->fresh(['user', 'items']);
    }

    private function stubInvoice(User $user, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_number' => 'DOC-'.uniqid(),
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $user->id,
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'subtotal' => 10,
            'total_amount' => 10,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Test', 'line_total' => 10]],
            'pdf_disk' => 'local',
        ], $overrides));
    }

    public function test_backfill_does_not_email_customers(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();
        Mail::fake();

        $admin = $this->admin();
        $this->actingAs($admin)
            ->from(route('admin.invoices.index'))
            ->post(route('admin.invoices.backfill-missing'), ['limit' => 10])
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'type' => Invoice::TYPE_TAX_INVOICE,
        ]);
        $this->assertSame(0, Invoice::where('order_id', $order->id)->where('type', Invoice::TYPE_PAYMENT_RECEIPT)->count());
        Mail::assertNothingQueued();
        Mail::assertNotQueued(PaymentSuccessfulInvoiceMail::class);
        $backfill = ActivityLog::query()->where('action', 'invoice.backfill_run')->first();
        $this->assertNotNull($backfill);
        $this->assertGreaterThan(0, (int) data_get($backfill->properties, 'created') + (int) data_get($backfill->properties, 'failed'));
    }

    public function test_regenerate_missing_pdfs_includes_null_path_and_older_gaps(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = $this->advertiser();
        $missingNull = $this->stubInvoice($user, ['pdf_path' => null]);
        $stale = $this->stubInvoice($user, ['pdf_path' => 'invoices/missing-on-disk.pdf']);

        for ($i = 0; $i < 50; $i++) {
            $path = 'invoices/ok-'.$i.'.pdf';
            Storage::disk('local')->put($path, '%PDF-1.4');
            $this->stubInvoice($user, ['pdf_path' => $path]);
        }

        $result = app(BillingDocumentService::class)->regenerateMissingPdfs(50);

        $this->assertSame(2, $result['regenerated']);
        $this->assertSame(0, $result['failed']);
        $this->assertTrue($missingNull->fresh()->pdfExists());
        $this->assertTrue($stale->fresh()->pdfExists());
    }

    public function test_regenerate_missing_pdfs_http_logs_when_files_are_written(): void
    {
        Mail::fake();
        Storage::fake('local');
        $admin = $this->admin();
        $this->stubInvoice($this->advertiser(), ['pdf_path' => null]);

        $this->actingAs($admin)
            ->from(route('admin.invoices.index'))
            ->post(route('admin.invoices.regenerate-missing-pdfs'), ['limit' => 10])
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('success');

        $log = ActivityLog::query()->where('action', 'invoice.pdfs_regenerated')->first();
        $this->assertNotNull($log);
        $this->assertGreaterThan(0, (int) data_get($log->properties, 'regenerated') + (int) data_get($log->properties, 'failed'));
    }

    public function test_resend_cancelled_invoice_fails_without_mail(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
        app(BillingDocumentService::class)->cancelInvoice($invoice, $admin, 'Cancel for test');
        Mail::fake();

        $emailCount = (int) $invoice->fresh()->email_count;

        $this->actingAs($admin)
            ->from(route('admin.invoices.show', $invoice))
            ->post(route('admin.invoices.resend', $invoice))
            ->assertRedirect(route('admin.invoices.show', $invoice))
            ->assertSessionHas('error', 'Cancelled documents cannot be resent.');

        $this->assertSame($emailCount, (int) $invoice->fresh()->email_count);
        Mail::assertNothingQueued();
        $this->assertDatabaseMissing('billing_events', [
            'invoice_id' => $invoice->id,
            'event_type' => 'invoice_resent',
        ]);
    }

    public function test_resend_refunded_tax_invoice_fails_without_mail(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $order->payment_status = 'refunded';
        $order->saveQuietly();
        app(BillingDocumentService::class)->handlePaymentRefunded($order->fresh(['user', 'items']), 'Test refund');
        Mail::fake();

        $emailCount = (int) $invoice->fresh()->email_count;

        $this->actingAs($admin)
            ->from(route('admin.invoices.show', $invoice))
            ->post(route('admin.invoices.resend', $invoice->fresh()))
            ->assertRedirect(route('admin.invoices.show', $invoice))
            ->assertSessionHas('error', 'Refunded or failed payment documents cannot be resent as a payment confirmation.');

        $this->assertSame($emailCount, (int) $invoice->fresh()->email_count);
        Mail::assertNothingQueued();
        Mail::assertNotQueued(PaymentSuccessfulInvoiceMail::class);
    }

    public function test_admin_invoice_show_prefers_document_status_over_frozen_paid(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $invoice = $this->stubInvoice($advertiser, [
            'status' => Invoice::STATUS_REFUNDED,
            'payment_status' => 'paid',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Payment status', false)
            ->assertSee('Refunded', false)
            ->assertDontSee('>Paid<', false)
            ->assertDontSee('Resend email', false);
    }

    public function test_resend_payout_statement_queues_mail_when_withdrawal_exists(): void
    {
        Mail::fake();
        Storage::fake('local');

        $publisher = $this->publisher();
        $admin = $this->admin();
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 80,
            'fee' => 5,
            'net_amount' => 75,
            'payment_method' => 'wise',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ]);
        $statement = $this->stubInvoice($publisher, [
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'reference_code' => 'WD-'.$withdrawal->id,
            'email_count' => 0,
            'meta' => ['withdrawal_id' => $withdrawal->id],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.invoices.show', $statement))
            ->post(route('admin.invoices.resend', $statement))
            ->assertRedirect(route('admin.invoices.show', $statement))
            ->assertSessionHas('success');

        Mail::assertQueued(WithdrawalStatusUpdated::class);
        $this->assertSame(1, (int) $statement->fresh()->email_count);
        $this->assertDatabaseHas('billing_events', [
            'invoice_id' => $statement->id,
            'event_type' => 'invoice_resent',
        ]);
    }

    public function test_resend_payout_without_withdrawal_fails(): void
    {
        Mail::fake();
        Storage::fake('local');

        $publisher = $this->publisher();
        $admin = $this->admin();
        $statement = $this->stubInvoice($publisher, [
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'reference_code' => 'WD-999999',
            'email_count' => 0,
            'meta' => ['withdrawal_id' => 999999],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.invoices.show', $statement))
            ->post(route('admin.invoices.resend', $statement))
            ->assertRedirect(route('admin.invoices.show', $statement))
            ->assertSessionHas('error', 'Cannot resend this payout statement — the withdrawal was not found.');

        Mail::assertNothingQueued();
        $this->assertSame(0, (int) $statement->fresh()->email_count);
        $this->assertDatabaseMissing('billing_events', [
            'invoice_id' => $statement->id,
            'event_type' => 'invoice_resent',
        ]);
    }

    public function test_resend_deposit_without_deposit_request_fails(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $receipt = $this->stubInvoice($advertiser, [
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'reference_code' => 'DEP-MISSING',
            'email_count' => 0,
            'meta' => ['deposit_request_id' => 999999],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.invoices.show', $receipt))
            ->post(route('admin.invoices.resend', $receipt))
            ->assertRedirect(route('admin.invoices.show', $receipt))
            ->assertSessionHas('error', 'Cannot resend this deposit receipt — the deposit request was not found.');

        Mail::assertNothingQueued();
        Mail::assertNotQueued(DepositApproved::class);
        $this->assertSame(0, (int) $receipt->fresh()->email_count);
    }

    public function test_generate_without_customer_account_flashes_error(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser, 'pending');
        Invoice::query()->where('order_id', $order->id)->delete();

        $this->mock(BillingDocumentService::class, function ($mock) {
            $mock->shouldReceive('generateManually')
                ->once()
                ->andThrow(new \RuntimeException('Cannot generate an invoice: the order has no customer account.'));
        });

        $this->actingAs($admin)
            ->from(route('admin.invoices.index'))
            ->post(route('admin.invoices.generate'), ['order_id' => $order->id])
            ->assertRedirect(route('admin.invoices.index'))
            ->assertSessionHas('error', 'Cannot generate an invoice: the order has no customer account.');
    }

    public function test_generate_unpaid_order_warns(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser, 'pending');
        Invoice::query()->where('order_id', $order->id)->delete();

        $this->actingAs($admin)
            ->post(route('admin.invoices.generate'), ['order_id' => $order->id])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('warning', 'Invoice issued for an unpaid order.');

        $invoice = Invoice::where('order_id', $order->id)->where('type', Invoice::TYPE_TAX_INVOICE)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);
    }

    public function test_generate_logs_when_invoice_is_new(): void
    {
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();

        $this->mock(BillingDocumentService::class, function ($mock) use ($advertiser, $order) {
            $mock->shouldReceive('generateManually')
                ->once()
                ->andReturnUsing(function () use ($advertiser, $order) {
                    return Invoice::create([
                        'invoice_number' => 'DOC-NEW-'.uniqid(),
                        'type' => Invoice::TYPE_TAX_INVOICE,
                        'status' => Invoice::STATUS_ISSUED,
                        'user_id' => $advertiser->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'customer_name' => $advertiser->name,
                        'customer_email' => $advertiser->email,
                        'subtotal' => 10,
                        'total_amount' => 10,
                        'invoice_date' => now(),
                        'line_items' => [['description' => 'Test', 'line_total' => 10]],
                        'pdf_disk' => 'local',
                    ]);
                });
        });

        $this->actingAs($admin)
            ->from(route('admin.invoices.index'))
            ->post(route('admin.invoices.generate'), ['order_id' => $order->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, ActivityLog::query()->where('action', 'invoice.generated')->count());
    }

    public function test_generate_reuse_does_not_log_again(): void
    {
        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser);
        $existing = $this->stubInvoice($advertiser, [
            'order_id' => $order->id,
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_ISSUED,
            'order_number' => $order->order_number,
        ]);

        $this->mock(BillingDocumentService::class, function ($mock) use ($existing) {
            $mock->shouldReceive('generateManually')
                ->once()
                ->andReturn($existing);
        });

        $this->actingAs($admin)
            ->from(route('admin.invoices.index'))
            ->post(route('admin.invoices.generate'), ['order_id' => $order->id])
            ->assertRedirect(route('admin.invoices.show', $existing))
            ->assertSessionHas('success');

        $this->assertSame(0, ActivityLog::query()->where('action', 'invoice.generated')->count());
    }

    public function test_admin_download_does_not_increment_customer_download_count(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($admin)
            ->get(route('admin.invoices.download', $invoice))
            ->assertOk();

        $this->assertSame(0, (int) $invoice->fresh()->download_count);
        $this->assertDatabaseHas('billing_events', [
            'invoice_id' => $invoice->id,
            'event_type' => 'invoice_downloaded_by_admin',
        ]);
    }

    public function test_advertiser_download_still_increments_download_count(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.download', $invoice))
            ->assertOk();

        $this->assertGreaterThan(0, (int) $invoice->fresh()->download_count);
    }

    public function test_cancelled_invoice_hides_resend_on_show_page(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
        app(BillingDocumentService::class)->cancelInvoice($invoice, $admin, 'Hide resend');

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Resend email', false);
    }

    public function test_generate_manually_throws_when_order_has_no_user(): void
    {
        $order = new Order([
            'payment_status' => 'paid',
            'order_number' => 'ORD-NOUSER',
            'total_amount' => 10,
        ]);
        $order->setRelation('items', collect());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot generate an invoice: the order has no customer account.');

        app(BillingDocumentService::class)->generateManually($order);
    }
}
