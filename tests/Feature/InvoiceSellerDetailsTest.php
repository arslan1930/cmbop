<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\InvoicePdfGenerator;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceSellerDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
    }

    public function test_final_order_invoice_shows_partners_with_topurlz_seller_block(): void
    {
        $html = view('advertiser.invoice', [
            'invoiceType' => 'order',
            'referenceCode' => 'ORDTEST1',
            'amount' => 120,
            'billingName' => 'Buyer Name',
            'companyName' => 'Buyer Co',
            'country' => 'DE',
            'state' => '',
            'city' => 'Berlin',
            'address' => 'Street 1',
            'postalCode' => '10115',
            'vatNumber' => '',
            'userName' => 'Buyer Name',
            'userEmail' => 'buyer@example.com',
            'userId' => 1,
            'status' => 'completed',
            'paymentMethod' => 'wallet',
            'orderDate' => now(),
            'orderItems' => [
                [
                    'site_name' => 'Example Site',
                    'site_url' => 'https://example.de',
                    'price' => 120,
                    'sensitive_type' => null,
                ],
            ],
            'totalBaseAmount' => 120,
            'totalSensitiveAmount' => 0,
        ])->render();

        $this->assertStringContainsString('SEOLinkBuildings Partners with (Topurlz LTD)', $html);
        $this->assertStringContainsString('20 Wenlock Road, London, England, N1 7GU', $html);
        $this->assertStringContainsString('Registration No:', $html);
        $this->assertStringContainsString('16607074', $html);
        $this->assertStringContainsString('support@seolinkbuildings.com', $html);
        $this->assertStringContainsString('Not VAT registered', $html);
        $this->assertStringNotContainsString('Beneficiary:', $html);
        $this->assertStringNotContainsString('BE04905543949331', $html);
    }

    public function test_deposit_invoice_shows_partner_seller_and_bank_beneficiary(): void
    {
        $html = view('advertiser.invoice', [
            'invoiceType' => 'deposit',
            'referenceCode' => 'DEPTEST1',
            'amount' => 50,
            'billingName' => 'Buyer Name',
            'companyName' => '',
            'country' => 'DE',
            'state' => '',
            'city' => 'Berlin',
            'address' => 'Street 1',
            'postalCode' => '10115',
            'vatNumber' => '',
            'userName' => 'Buyer Name',
            'userEmail' => 'buyer@example.com',
            'userId' => 1,
            'status' => 'pending',
            'paymentMethod' => 'bank',
            'orderDate' => now(),
            'orderItems' => [],
            'totalBaseAmount' => 0,
            'totalSensitiveAmount' => 0,
            'deposit' => null,
            'canMarkPaid' => false,
            'userMarkedPaid' => false,
            'markPaidUrl' => null,
        ])->render();

        $this->assertStringContainsString('SEOLinkBuildings Partner', $html);
        $this->assertStringContainsString('Beneficiary:', $html);
        $this->assertStringContainsString('Teqno Ltd', $html);
        $this->assertStringContainsString('TRWIBEB1XXX', $html);
        $this->assertStringContainsString('BE40 9059 9538 0863', $html);
        $this->assertStringContainsString('+447445152374', $html);
        $this->assertStringContainsString('16607074', $html);
        $this->assertStringContainsString('Not VAT registered', $html);
        $this->assertStringNotContainsString('SEOLinkBuildings Partners with (Topurlz LTD)', $html);
    }

    public function test_pdf_tax_invoice_shows_registration_and_vat_note(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-000002',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'order_number' => 'ORD-TEST-2',
            'line_items' => [
                ['description' => 'Guest post', 'quantity' => 1, 'unit_price' => 100, 'total' => 100],
            ],
            'billing_snapshot' => [],
        ]);

        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('SEOLinkBuildings Partners with (Topurlz LTD)', $html);
        $this->assertStringContainsString('Registration No: 16607074', $html);
        $this->assertStringContainsString('Not VAT registered', $html);
        $this->assertStringContainsString('20 Wenlock Road', $html);
        $this->assertStringContainsString('https://seolinkbuildings.com', $html);
        $this->assertStringNotContainsString('localhost', $html);
    }

    public function test_pdf_invoice_does_not_print_leftover_localhost_or_app_name(): void
    {
        config([
            'app.url' => 'http://localhost:8000',
            'app.name' => 'Seolinkbuildings',
            'billing.company.name' => 'Seolinkbuildings',
            'billing.company.website_url' => 'http://localhost:8000',
            'email_notifications.brand.website_url' => 'http://localhost:8000',
        ]);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'RCT-2026-000002',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'currency' => 'EUR',
            'subtotal' => 25,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 25,
            'payment_method' => 'wise',
            'payment_status' => 'paid',
            'reference_code' => '337156',
            'transaction_id' => '337156',
            'line_items' => [
                [
                    'description' => 'Wallet top-up',
                    'reference' => '337156',
                    'quantity' => 1,
                    'unit_price' => 25,
                    'line_total' => 25,
                ],
            ],
            'billing_snapshot' => [],
        ]);

        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => [
                'name' => 'Seolinkbuildings',
                'website_url' => 'http://localhost:8000',
            ],
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('SEOLinkBuildings', $html);
        $this->assertStringContainsString('https://seolinkbuildings.com', $html);
        $this->assertStringNotContainsString('http://localhost:8000', $html);
        $this->assertStringNotContainsString('localhost', $html);
        $this->assertStringContainsString('337156', $html);
        $this->assertStringContainsString('>Ref</', $html);
        $this->assertStringNotContainsString('Txn', $html);
        $this->assertStringContainsString('<tfoot>', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringContainsString('text-align: right', $html);
        $this->assertStringNotContainsString('table class="totals"', $html);
        $this->assertSame('https://seolinkbuildings.com', brand_public_origin());
        $this->assertSame('https://seolinkbuildings.com', mail_brand_website_url());
    }

    public function test_pdf_line_items_accept_legacy_total_key(): void
    {
        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-000003',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'order_number' => 'ORD-TEST-3',
            'line_items' => [
                ['description' => 'Guest post', 'quantity' => 1, 'unit_price' => 100, 'total' => 100],
            ],
            'billing_snapshot' => [],
        ]);

        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('€100.00', $html);
        $this->assertStringNotContainsString('€0.00', $html);
    }

    public function test_ensure_customer_pdf_rewrites_stored_file_that_still_prints_localhost(): void
    {
        Storage::fake('local');

        config([
            'app.url' => 'http://localhost:8000',
            'app.name' => 'Laravel leftover',
            'billing.company.website_url' => 'http://localhost:8000',
            'billing.company.name' => 'Seolinkbuildings',
        ]);

        $role = Role::where('name', 'advertiser')->firstOrFail();
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        $relative = 'invoices/leftover-localhost.pdf';
        Storage::disk('local')->put($relative, '%PDF-1.4 leftover http://localhost:8000 http://127.0.0.1:8000');

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => 'RCT-2026-000099',
            'type' => Invoice::TYPE_DEPOSIT_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'currency' => 'EUR',
            'subtotal' => 25,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 25,
            'payment_method' => 'wise',
            'payment_status' => 'paid',
            'reference_code' => '337156',
            'transaction_id' => '337156',
            'pdf_disk' => 'local',
            'pdf_path' => $relative,
            'line_items' => [
                [
                    'description' => 'Wallet top-up',
                    'quantity' => 1,
                    'unit_price' => 25,
                    'line_total' => 25,
                ],
            ],
            'billing_snapshot' => [],
        ]);

        $healed = app(InvoicePdfGenerator::class)->ensureCustomerPdf($invoice->fresh());
        $binary = (string) Storage::disk('local')->get($healed->pdf_path);

        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertGreaterThan(1000, strlen($binary));
        $this->assertStringNotContainsString('leftover http://localhost', $binary);
        $this->assertStringNotContainsString('http://localhost:8000', $binary);
        $this->assertStringNotContainsString('http://127.0.0.1:8000', $binary);

        $html = view('billing.pdf.invoice', [
            'invoice' => $healed,
            'currencySymbol' => '€',
        ])->render();
        $this->assertStringContainsString('https://seolinkbuildings.com', $html);
        $this->assertStringContainsString('SEOLinkBuildings', $html);
        $this->assertStringNotContainsString('localhost', $html);
    }
}
