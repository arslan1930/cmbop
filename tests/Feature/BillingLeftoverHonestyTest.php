<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\Billing\BillingDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingLeftoverHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Billing Advertiser',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function paidOrder(User $advertiser): Order
    {
        $publisher = User::factory()->create();
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Honesty Site',
            'site_url' => 'https://honesty.example',
            'domain' => 'honesty.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 1000,
            'country' => 'us',
            'language' => 'en',
            'category' => 'News',
            'price' => 80,
            'publication_time' => '3',
            'description' => 'Test',
            'link_type' => 'dofollow',
            'verified' => true,
            'active' => true,
        ]);

        return DB::transaction(function () use ($advertiser, $site) {
            $order = Order::create([
                'user_id' => $advertiser->id,
                'order_number' => 'ORD-HON-'.uniqid(),
                'reference_code' => 'REF-HON-'.uniqid(),
                'subtotal' => 80,
                'tax' => 0,
                'total_amount' => 80,
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
                'price' => 80,
                'content_link' => 'https://example.com/article.docx',
            ]);

            return $order->fresh(['user', 'items']);
        });
    }

    public function test_billing_index_shows_refunded_for_frozen_paid_status(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $invoice = Invoice::create([
            'user_id' => $advertiser->id,
            'invoice_number' => 'INV-LEFT-PAID',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'refunded',
            'invoice_date' => now(),
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 80,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'order_number' => 'ORD-LEFT-PAID',
            'line_items' => [['description' => 'Guest post', 'line_total' => 80]],
            'billing_snapshot' => [],
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertSee('INV-LEFT-PAID', false)
            ->assertSee('Refunded', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'refunded']))
            ->assertOk()
            ->assertSee('INV-LEFT-PAID', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'paid']))
            ->assertOk()
            ->assertDontSee('INV-LEFT-PAID', false);
    }

    public function test_leftover_refunded_show_does_not_say_cancelled(): void
    {
        $advertiser = $this->advertiser();
        $invoice = Invoice::create([
            'user_id' => $advertiser->id,
            'invoice_number' => 'INV-LEFT-SHOW',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'refunded',
            'invoice_date' => now(),
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 80,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'order_number' => 'ORD-LEFT-SHOW',
            'line_items' => [['description' => 'Guest post', 'line_total' => 80]],
            'billing_snapshot' => [],
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.show', $invoice))
            ->assertOk()
            ->assertSee('INV-LEFT-SHOW', false)
            ->assertSee('Refunded', false)
            ->assertSee('was refunded', false)
            ->assertDontSee('This document has been cancelled.', false);
    }

    public function test_leftover_refunded_pdf_badges_refunded_not_paid(): void
    {
        $advertiser = $this->advertiser();
        $invoice = Invoice::create([
            'user_id' => $advertiser->id,
            'invoice_number' => 'INV-LEFT-PDF',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'refunded',
            'invoice_date' => now(),
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 80,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'order_number' => 'ORD-LEFT-PDF',
            'line_items' => [['description' => 'Guest post', 'line_total' => 80]],
            'billing_snapshot' => [],
        ]);

        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('REFUNDED', $html);
        $this->assertStringContainsString('class="badge badge-refunded">REFUNDED</span>', $html);
        $this->assertStringContainsString('Status: <strong>Refunded</strong>', $html);
        $this->assertStringNotContainsString('class="badge badge-paid">PAID</span>', $html);
    }

    public function test_billing_show_prefers_refunded_over_frozen_paid_payment_status(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();

        $service = app(BillingDocumentService::class);
        $invoice = $service->handlePaymentPaid($order->fresh(['user', 'items']));
        $this->assertNotNull($invoice);

        $order->payment_status = 'refunded';
        $order->saveQuietly();
        $refund = $service->handlePaymentRefunded($order->fresh(['user', 'items']), 'Publisher rejected');

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.show', $invoice->fresh()))
            ->assertOk()
            ->assertSee('Payment status', false)
            ->assertSee('Refunded', false)
            ->assertDontSee('>Paid<', false)
            ->assertSee('was refunded', false)
            ->assertSee($refund->invoice_number, false)
            ->assertSee('#'.$order->order_number, false);
    }

    public function test_rejected_deposit_html_invoice_is_not_payable(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-NO-PAY',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'rejected',
        ]);

        $this->actingAs($advertiser)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk()
            ->assertSee('CANCELLED', false)
            ->assertSee('not payable', false)
            ->assertDontSee('Thank you for your business!', false)
            ->assertDontSee('OK, I have made the payment', false)
            ->assertDontSee('Beneficiary:', false);
    }

    public function test_failed_order_invoice_route_opens_failure_document(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();

        $order->payment_status = 'failed';
        $order->saveQuietly();
        $failure = app(BillingDocumentService::class)->handlePaymentFailed(
            $order->fresh(['user', 'items']),
            'Card declined'
        );

        $this->actingAs($advertiser)
            ->get(route('advertiser.invoice', $order->reference_code))
            ->assertRedirect(route('advertiser.billing.view', $failure));
    }

    public function test_existing_refund_receipt_still_repairs_paid_siblings(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();

        $service = app(BillingDocumentService::class);
        $invoice = $service->handlePaymentPaid($order->fresh(['user', 'items']));
        $this->assertNotNull($invoice);

        $order->payment_status = 'refunded';
        $order->saveQuietly();
        $first = $service->handlePaymentRefunded($order->fresh(['user', 'items']), 'First refund');
        $this->assertNotNull($first);

        Invoice::query()
            ->where('order_id', $order->id)
            ->whereIn('type', [Invoice::TYPE_TAX_INVOICE, Invoice::TYPE_PAYMENT_RECEIPT])
            ->update([
                'status' => Invoice::STATUS_PAID,
                'payment_status' => 'paid',
            ]);

        $again = $service->handlePaymentRefunded($order->fresh(['user', 'items']), 'Replay');
        $this->assertSame($first->id, $again->id);

        $tax = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->first();
        $receipt = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_PAYMENT_RECEIPT)
            ->first();

        $this->assertSame(Invoice::STATUS_REFUNDED, $tax->status);
        $this->assertSame('refunded', $tax->payment_status);
        $this->assertSame(Invoice::STATUS_REFUNDED, $receipt->status);
        $this->assertSame('refunded', $receipt->payment_status);
    }

    public function test_refunded_order_invoice_route_opens_refund_receipt(): void
    {
        Mail::fake();
        Storage::fake('local');

        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();

        $service = app(BillingDocumentService::class);
        $service->handlePaymentPaid($order->fresh(['user', 'items']));
        $order->payment_status = 'refunded';
        $order->saveQuietly();
        $refund = $service->handlePaymentRefunded($order->fresh(['user', 'items']), 'Publisher rejected');

        $this->actingAs($advertiser)
            ->get(route('advertiser.invoice', $order->reference_code))
            ->assertRedirect(route('advertiser.billing.view', $refund));
    }

    public function test_add_funds_js_escapes_invoice_url_in_swal(): void
    {
        $js = file_get_contents(public_path('assets/js/add-funds.js'));

        $this->assertStringContainsString('escapeHtml(data.invoice_url)', $js);
        $this->assertStringNotContainsString('href="${data.invoice_url}"', $js);
    }

    public function test_leftover_deposit_receipt_follows_refunded_deposit(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-LEFT-RCT',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'refunded',
        ]);
        $receipt = Invoice::create([
            'user_id' => $advertiser->id,
            'invoice_number' => 'RCT-LEFT-PAID',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'paid',
            'invoice_date' => now(),
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 40,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'reference_code' => $deposit->reference_code,
            'line_items' => [['description' => 'Wallet deposit', 'line_total' => 40]],
            'billing_snapshot' => [],
            'meta' => ['deposit_request_id' => $deposit->id],
        ]);

        $this->assertSame(Invoice::STATUS_REFUNDED, $receipt->displayPaymentStatus());
        $this->assertFalse($receipt->canResendCustomerEmail());

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertSee('RCT-LEFT-PAID', false)
            ->assertSee('Refunded', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'refunded']))
            ->assertOk()
            ->assertSee('RCT-LEFT-PAID', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'paid']))
            ->assertOk()
            ->assertDontSee('RCT-LEFT-PAID', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.show', $receipt))
            ->assertOk()
            ->assertSee('was refunded', false)
            ->assertDontSee('This document has been cancelled.', false);
    }

    public function test_leftover_order_invoice_follows_refunded_order(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();
        $order->payment_status = 'refunded';
        $order->saveQuietly();

        $invoice = Invoice::create([
            'user_id' => $advertiser->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-ORDER-LEFT',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'paid',
            'invoice_date' => now(),
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 80,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'order_number' => $order->order_number,
            'line_items' => [['description' => 'Guest post', 'line_total' => 80]],
            'billing_snapshot' => [],
        ]);

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->displayPaymentStatus());
        $this->assertFalse($invoice->fresh()->canResendCustomerEmail());

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'refunded']))
            ->assertOk()
            ->assertSee('INV-ORDER-LEFT', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'paid']))
            ->assertOk()
            ->assertDontSee('INV-ORDER-LEFT', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.show', $invoice))
            ->assertOk()
            ->assertSee('was refunded', false)
            ->assertDontSee('This document has been cancelled.', false);

        $partial = $invoice->fresh();
        $partial->load(['order:id,order_number,reference_code']);
        $this->assertSame(Invoice::STATUS_REFUNDED, $partial->displayPaymentStatus());

        $list = $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'refunded']))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/INV-ORDER-LEFT[\s\S]{0,1200}text-bg-info">Refunded/',
            $list
        );
        $this->assertDoesNotMatchRegularExpression(
            '/INV-ORDER-LEFT[\s\S]{0,1200}text-bg-success">Paid/',
            $list
        );
    }

    public function test_leftover_order_invoice_follows_failed_order(): void
    {
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        Invoice::query()->where('order_id', $order->id)->delete();
        $order->payment_status = 'failed';
        $order->saveQuietly();

        $invoice = Invoice::create([
            'user_id' => $advertiser->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-ORDER-FAIL',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'paid',
            'invoice_date' => now(),
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 80,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 80,
            'payment_method' => 'wallet',
            'order_number' => $order->order_number,
            'line_items' => [['description' => 'Guest post', 'line_total' => 80]],
            'billing_snapshot' => [],
        ]);

        $this->assertSame(Invoice::STATUS_FAILED, $invoice->fresh()->displayPaymentStatus());
        $this->assertFalse($invoice->fresh()->canResendCustomerEmail());

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'failed']))
            ->assertOk()
            ->assertSee('INV-ORDER-FAIL', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.index', ['status' => 'paid']))
            ->assertOk()
            ->assertDontSee('INV-ORDER-FAIL', false);

        $this->actingAs($advertiser)
            ->get(route('advertiser.billing.show', $invoice))
            ->assertOk()
            ->assertSee('This payment attempt failed', false);
    }

    public function test_admin_invoice_links_use_display_status_for_leftover_docs(): void
    {
        $advertiser = $this->advertiser();
        $deposit = DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-ADMIN-JSON',
            'amount' => 40,
            'payment_method' => 'card',
            'status' => 'refunded',
        ]);
        $receipt = Invoice::create([
            'user_id' => $advertiser->id,
            'invoice_number' => 'RCT-ADMIN-JSON',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'payment_status' => 'paid',
            'invoice_date' => now(),
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 40,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 40,
            'payment_method' => 'card',
            'reference_code' => $deposit->reference_code,
            'line_items' => [['description' => 'Wallet deposit', 'line_total' => 40]],
            'billing_snapshot' => [],
            'meta' => ['deposit_request_id' => $deposit->id],
        ]);

        $summary = app(AdminInvoiceLinks::class)->summarize($receipt);
        $this->assertSame(Invoice::STATUS_REFUNDED, $summary['status']);
        $this->assertSame('RCT-ADMIN-JSON', $summary['invoice_number']);
    }

    public function test_add_funds_activity_uses_receipt_label_when_refunded(): void
    {
        $blade = file_get_contents(resource_path('views/advertiser/add-funds.blade.php'));

        $this->assertStringContainsString("(status === 'refunded' || status === 'failed') ? 'Download receipt' : 'Download invoice'", $blade);
        $this->assertStringContainsString("(status === 'refunded' || status === 'failed') ? 'View receipt' : 'Invoice'", $blade);
        $this->assertStringContainsString("(status === 'refunded' || status === 'failed') ? 'Download receipt' : 'Download Invoice'", $blade);
        $this->assertStringContainsString("(status === 'refunded' || status === 'failed') ? 'View receipt' : 'View Invoice'", $blade);
    }
}
