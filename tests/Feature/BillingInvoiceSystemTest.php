<?php

namespace Tests\Feature;

use App\Mail\PaymentFailedMail;
use App\Mail\PaymentPendingMail;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\RefundReceiptMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoiceNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingInvoiceSystemTest extends TestCase
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
            'site_name' => 'Billing Test Site',
            'site_url' => 'https://billing-test.example',
            'domain' => 'billing-test.example',
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

        // Mirror production wallet checkout: create order + items inside a transaction
        // so billing hooks run afterCommit with line items available.
        $order = \Illuminate\Support\Facades\DB::transaction(function () use ($advertiser, $site, $paymentStatus) {
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

    public function test_invoice_numbers_are_sequential_and_unique(): void
    {
        $gen = app(InvoiceNumberGenerator::class);
        $a = $gen->next(2099);
        $b = $gen->next(2099);

        $this->assertMatchesRegularExpression('/^INV-2099-\d{6}$/', $a);
        $this->assertSame('INV-2099-000001', $a);
        $this->assertSame('INV-2099-000002', $b);
        $this->assertNotSame($a, $b);
    }

    public function test_paid_order_generates_invoice_receipt_pdf_and_email(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);

        // created() hook may have already run; call service explicitly for determinism
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->assertNotNull($invoice);
        $this->assertSame(Invoice::TYPE_TAX_INVOICE, $invoice->type);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);

        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'type' => Invoice::TYPE_PAYMENT_RECEIPT,
        ]);

        Mail::assertQueued(PaymentSuccessfulInvoiceMail::class);
    }

    public function test_paid_invoice_generation_is_idempotent(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $service = app(BillingDocumentService::class);

        $first = $service->handlePaymentPaid($order);
        $second = $service->handlePaymentPaid($order->fresh(['user', 'items']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->where('type', Invoice::TYPE_TAX_INVOICE)->count());
    }

    public function test_failed_payment_creates_failure_doc_not_tax_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser, 'pending');
        Invoice::query()->where('order_id', $order->id)->delete();

        $doc = app(BillingDocumentService::class)->handlePaymentFailed($order->fresh(['user', 'items']), 'Card declined');

        $this->assertNotNull($doc);
        $this->assertSame(Invoice::TYPE_PAYMENT_FAILURE, $doc->type);
        $this->assertSame(0, Invoice::where('order_id', $order->id)->where('type', Invoice::TYPE_TAX_INVOICE)->count());
        Mail::assertQueued(PaymentFailedMail::class);
    }

    public function test_pending_payment_sends_email_without_tax_invoice(): void
    {
        Mail::fake();

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser, 'pending');
        Invoice::query()->where('order_id', $order->id)->delete();
        \App\Models\BillingEvent::query()->where('order_id', $order->id)->delete();
        Mail::fake();

        app(BillingDocumentService::class)->handlePaymentPending($order->fresh(['user', 'items']));

        Mail::assertQueued(PaymentPendingMail::class);
        $this->assertSame(0, Invoice::where('order_id', $order->id)->where('type', Invoice::TYPE_TAX_INVOICE)->count());
    }

    public function test_refund_generates_refund_receipt(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser, 'pending');
        Invoice::query()->where('order_id', $order->id)->delete();
        \App\Models\BillingEvent::query()->where('order_id', $order->id)->delete();
        Mail::fake();

        $service = app(BillingDocumentService::class);
        $service->handlePaymentPaid($order->fresh(['user', 'items']));

        // Call service directly (avoid double-fire from model update + explicit call)
        $order->payment_status = 'refunded';
        $order->saveQuietly();
        $refund = $service->handlePaymentRefunded($order->fresh(['user', 'items']), 'Publisher rejected');

        $this->assertNotNull($refund);
        $this->assertSame(Invoice::TYPE_REFUND_RECEIPT, $refund->type);
        Mail::assertQueued(RefundReceiptMail::class);
    }

    public function test_advertiser_can_list_and_download_own_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertSee($invoice->invoice_number, false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.download', $invoice))
            ->assertOk();

        $this->assertGreaterThan(0, $invoice->fresh()->download_count);
    }

    public function test_other_advertiser_cannot_download_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $owner = $this->advertiser();
        $other = $this->advertiser();
        $order = $this->paidOrder($owner);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($other)
            ->get(route('advertiser.billing.download', $invoice))
            ->assertForbidden();
    }

    public function test_admin_can_view_and_cancel_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $admin = $this->admin();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index'))
            ->assertOk()
            ->assertSee($invoice->invoice_number, false);

        $this->actingAs($admin)
            ->post(route('admin.invoices.cancel', $invoice), ['reason' => 'Test cancel'])
            ->assertRedirect();

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertTrue($invoice->fresh()->pdfExists() || filled($invoice->fresh()->pdf_path));
    }

    public function test_marking_order_paid_via_update_triggers_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser, 'pending');
        Invoice::query()->where('order_id', $order->id)->delete();
        Mail::fake();

        $order->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
        ]);
    }
}

