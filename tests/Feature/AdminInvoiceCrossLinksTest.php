<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
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

class AdminInvoiceCrossLinksTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(string $name = 'Alice Advertiser'): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => $name,
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

    private function paidOrder(User $advertiser): Order
    {
        $publisher = User::factory()->create();
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Link Test Site',
            'site_url' => 'https://link-test.example',
            'domain' => 'link-test.example',
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

        $order = DB::transaction(function () use ($advertiser, $site) {
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

    public function test_order_show_and_data_link_to_invoices(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Billing documents', false)
            ->assertSee($invoice->invoice_number, false)
            ->assertSee(route('admin.invoices.show', $invoice), false)
            ->assertSee(route('admin.invoices.index', ['search' => $order->order_number]), false);

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['search' => $order->order_number]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.invoice_url', route('admin.invoices.show', $invoice))
            ->assertJsonFragment([
                'invoice_number' => $invoice->invoice_number,
                'url' => route('admin.invoices.show', $invoice),
            ]);
    }

    public function test_payments_data_and_page_expose_invoice_link(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($admin)
            ->get(route('admin.payments'))
            ->assertOk()
            ->assertSee('order.invoice_url', false)
            ->assertSee('Open invoice', false);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => $order->order_number]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.invoice_url', route('admin.invoices.show', $invoice))
            ->assertJsonFragment([
                'invoice_number' => $invoice->invoice_number,
                'url' => route('admin.invoices.show', $invoice),
            ]);

        $this->actingAs($admin)
            ->getJson(route('admin.payments.show', $order->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice_url', route('admin.invoices.show', $invoice));
    }

    public function test_primary_invoice_link_skips_cancelled_tax_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $receipt = Invoice::query()
            ->where('order_id', $order->id)
            ->where('type', Invoice::TYPE_PAYMENT_RECEIPT)
            ->first();
        $this->assertNotNull($receipt);

        app(BillingDocumentService::class)->cancelInvoice($invoice, $admin, 'superseded');

        $this->actingAs($admin)
            ->getJson(route('admin.payments.data', ['search' => $order->order_number]))
            ->assertOk()
            ->assertJsonPath('data.0.invoice_url', route('admin.invoices.show', $receipt));

        $this->actingAs($admin)
            ->getJson(route('admin.orders.data', ['search' => $order->order_number]))
            ->assertOk()
            ->assertJsonPath('data.0.invoice_url', route('admin.invoices.show', $receipt));
    }

    public function test_deposits_list_and_show_link_to_receipt(): void
    {
        Storage::fake('local');

        $admin = $this->admin();
        $user = $this->advertiser('Dana Depositor');
        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'DEP-LINK-1',
            'amount' => 40,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);

        $receipt = Invoice::query()
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->where('reference_code', 'DEP-LINK-1')
            ->first();
        $this->assertNotNull($receipt);

        $this->actingAs($admin)
            ->get(route('admin.deposits', ['search' => 'DEP-LINK-1']))
            ->assertOk()
            ->assertSee(route('admin.invoices.show', $receipt), false)
            ->assertSee('data.invoice', false);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('invoice.url', route('admin.invoices.show', $receipt))
            ->assertJsonPath('invoice.invoice_number', $receipt->invoice_number);
    }

    public function test_withdrawals_data_and_show_link_to_payout_statement(): void
    {
        $admin = $this->admin();
        $publisher = $this->publisher();
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
            'invoice_number' => 'PAY-LINK-1',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'reference_code' => 'WD-'.$withdrawal->id,
            'meta' => ['withdrawal_id' => $withdrawal->id],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.withdrawals'))
            ->assertOk()
            ->assertSee('w.invoice_url', false)
            ->assertSee('Open invoice', false);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', [
                'queue' => 'history',
                'search' => (string) $withdrawal->id,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.invoice_url', route('admin.invoices.show', $statement))
            ->assertJsonPath('data.0.invoice.invoice_number', 'PAY-LINK-1');

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.show', $withdrawal->id))
            ->assertOk()
            ->assertJsonPath('data.invoice_url', route('admin.invoices.show', $statement));
    }

    public function test_open_withdrawal_without_statement_has_null_invoice_url(): void
    {
        $admin = $this->admin();
        $publisher = $this->publisher();
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 40,
            'fee' => 0,
            'net_amount' => 40,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.withdrawals.data', ['search' => (string) $withdrawal->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $withdrawal->id)
            ->assertJsonPath('data.0.invoice', null)
            ->assertJsonPath('data.0.invoice_url', null);
    }

    public function test_invoice_index_links_deposit_and_payout_lists(): void
    {
        $admin = $this->admin();
        $user = $this->advertiser();
        $receipt = $this->stubInvoice($user, [
            'invoice_number' => 'RCT-LINK-9',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'reference_code' => 'DEP-INDEX-9',
            'meta' => ['deposit_request_id' => 9],
        ]);
        $statement = $this->stubInvoice($user, [
            'invoice_number' => 'PAY-LINK-9',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'reference_code' => 'WD-88',
            'meta' => ['withdrawal_id' => 88],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index'))
            ->assertOk()
            ->assertSee($receipt->invoice_number, false)
            ->assertSee($statement->invoice_number, false)
            ->assertSee(e(route('admin.deposits', ['search' => 'DEP-INDEX-9'], false)), false)
            ->assertSee(e(route('admin.withdrawals.show', 88, false)), false);
    }

    public function test_junk_filters_are_ignored_and_status_filter_works(): void
    {
        $admin = $this->admin();
        $user = $this->advertiser();
        $paid = $this->stubInvoice($user, [
            'invoice_number' => 'INV-FILTER-PAID',
            'status' => Invoice::STATUS_PAID,
        ]);
        $failed = $this->stubInvoice($user, [
            'invoice_number' => 'INV-FILTER-FAIL',
            'type' => Invoice::TYPE_PAYMENT_FAILURE,
            'status' => Invoice::STATUS_FAILED,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', [
                'status' => 'not-a-status',
                'type' => 'not-a-type',
            ]))
            ->assertOk()
            ->assertSee('INV-FILTER-PAID', false)
            ->assertSee('INV-FILTER-FAIL', false);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', ['status' => Invoice::STATUS_FAILED]))
            ->assertOk()
            ->assertSee('INV-FILTER-FAIL', false)
            ->assertDontSee('INV-FILTER-PAID', false);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', ['type' => Invoice::TYPE_PAYMENT_FAILURE]))
            ->assertOk()
            ->assertSee('INV-FILTER-FAIL', false)
            ->assertDontSee('INV-FILTER-PAID', false);
    }

    public function test_index_ignores_array_search_and_dates(): void
    {
        $admin = $this->admin();
        $user = $this->advertiser();
        $this->stubInvoice($user, ['invoice_number' => 'INV-ARRAY-1']);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', [
                'search' => ['injected'],
                'from' => ['2026-01-01'],
                'to' => ['2026-12-31'],
                'status' => ['paid'],
                'type' => ['tax_invoice'],
            ]))
            ->assertOk()
            ->assertSee('INV-ARRAY-1', false);
    }

    public function test_regenerate_pdf_and_guests_are_blocked(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($admin)
            ->from(route('admin.invoices.show', $invoice))
            ->post(route('admin.invoices.regenerate-pdf', $invoice))
            ->assertRedirect(route('admin.invoices.show', $invoice))
            ->assertSessionHas('success');

        $this->actingAs($advertiser)
            ->get(route('admin.invoices.index'))
            ->assertForbidden();

        $this->actingAs($advertiser)
            ->get(route('admin.invoices.view', $invoice))
            ->assertForbidden();

        auth()->logout();

        $this->get(route('admin.invoices.index'))
            ->assertRedirect();
    }

    public function test_orders_index_page_renders_invoice_chip(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('order.invoice_url', false)
            ->assertSee("'Invoice'", false);
    }
}
