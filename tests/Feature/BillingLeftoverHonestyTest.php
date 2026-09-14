<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
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
}
