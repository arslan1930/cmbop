<?php

namespace Tests\Feature;

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

class AdminInvoiceUiTest extends TestCase
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
            'site_name' => 'UI Test Site',
            'site_url' => 'https://ui-test.example',
            'domain' => 'ui-test.example',
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

    public function test_type_filter_includes_deposit_receipts(): void
    {
        $admin = $this->admin();
        $user = $this->advertiser();
        $deposit = $this->stubInvoice($user, [
            'invoice_number' => 'RCT-2099-000042',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'reference_code' => 'DEP-ABC',
            'customer_name' => 'Dana Depositor',
        ]);
        $tax = $this->stubInvoice($user, [
            'invoice_number' => 'INV-2099-000099',
            'type' => Invoice::TYPE_TAX_INVOICE,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', ['type' => 'deposit_receipt']))
            ->assertOk()
            ->assertSee('RCT-2099-000042', false)
            ->assertSee('Deposit Receipt', false)
            ->assertDontSee('INV-2099-000099', false)
            ->assertSee('DEP-ABC', false)
            ->assertDontSee('#DEP-ABC', false);
    }

    public function test_search_matches_customer_name(): void
    {
        $admin = $this->admin();
        $alice = $this->advertiser('Alice UniqueName');
        $bob = $this->advertiser('Bob Other');
        $this->stubInvoice($alice, ['invoice_number' => 'INV-ALICE-1', 'customer_name' => 'Alice UniqueName']);
        $this->stubInvoice($bob, ['invoice_number' => 'INV-BOB-1', 'customer_name' => 'Bob Other']);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', ['search' => 'UniqueName']))
            ->assertOk()
            ->assertSee('INV-ALICE-1', false)
            ->assertDontSee('INV-BOB-1', false);
    }

    public function test_date_range_filters_invoice_date(): void
    {
        $admin = $this->admin();
        $user = $this->advertiser();
        $this->stubInvoice($user, [
            'invoice_number' => 'INV-OLD-1',
            'invoice_date' => now()->subDays(10),
        ]);
        $this->stubInvoice($user, [
            'invoice_number' => 'INV-NEW-1',
            'invoice_date' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.index', [
                'from' => now()->subDays(2)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('INV-NEW-1', false)
            ->assertDontSee('INV-OLD-1', false);
    }

    public function test_index_links_customer_and_order(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $html = $this->actingAs($admin)
            ->get(route('admin.invoices.index'))
            ->assertOk()
            ->assertSee($invoice->invoice_number, false)
            ->getContent();

        $this->assertStringContainsString(route('admin.users.index', ['user' => $advertiser->id]), $html);
        $this->assertStringContainsString(route('admin.orders.show', $order->id), $html);
        $this->assertStringContainsString('text-bg-success', $html);
    }

    public function test_view_pdf_streams_without_incrementing_download_count(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($admin)
            ->get(route('admin.invoices.view', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(0, (int) $invoice->fresh()->download_count);
    }

    public function test_cancelled_invoice_can_view_and_download_pdf(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
        app(BillingDocumentService::class)->cancelInvoice($invoice, $admin, 'Audit keep');

        $this->actingAs($admin)
            ->get(route('admin.invoices.view', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($admin)
            ->get(route('admin.invoices.download', $invoice))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Resend email', false)
            ->assertSee('Audit keep', false);
    }

    public function test_show_page_has_related_docs_totals_and_cancel_reason_field(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $order = $this->paidOrder($advertiser);
        $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $receipt = Invoice::query()
            ->where('parent_invoice_id', $invoice->id)
            ->where('type', Invoice::TYPE_PAYMENT_RECEIPT)
            ->first();

        $this->assertNotNull($receipt);

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('View PDF', false)
            ->assertSee('name="reason"', false)
            ->assertSee('Billing snapshot', false)
            ->assertSee('Subtotal', false)
            ->assertSee($receipt->invoice_number, false)
            ->assertSee(route('admin.invoices.show', $receipt), false)
            ->assertSee(route('admin.users.index', ['user' => $advertiser->id], false), false)
            ->assertSee(route('admin.orders.show', $order->id, false), false);
    }

    public function test_deposit_show_uses_reference_and_empty_line_fallback(): void
    {
        $admin = $this->admin();
        $user = $this->advertiser();
        $receipt = $this->stubInvoice($user, [
            'invoice_number' => 'RCT-UI-1',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'reference_code' => 'DEP-UI-1',
            'meta' => ['deposit_request_id' => 77],
            'line_items' => [[
                'description' => 'Wallet top-up',
                'reference' => 'DEP-UI-1',
                'line_total' => 50,
            ]],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', $receipt))
            ->assertOk()
            ->assertSee('DEP-UI-1', false)
            ->assertSee('Wallet top-up', false)
            ->assertSee(route('admin.deposits', ['search' => 'DEP-UI-1']), false)
            ->assertDontSee('Cancel invoice', false);
    }
}
