<?php

namespace Tests\Feature;

use App\Mail\OrderStatusChanged;
use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\LegalIdentity;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceLegalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $advertiser;

    private User $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesTableSeeder::class);
        $this->advertiser = $this->userWithRole('advertiser', ['name' => 'Arslan']);
        $this->publisher = $this->userWithRole('publisher', ['name' => 'josen J']);
    }

    private function userWithRole(string $roleName, array $attrs = []): User
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ], $attrs));
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * @return array{invoice: Invoice, originalLines: array<int, array<string, mixed>>, originalSnapshot: array<string, mixed>}
     */
    private function sampleInvoice(): array
    {
        $site = Site::create([
            'publisher_id' => $this->publisher->id,
            'site_name' => 'Inka Magazin',
            'site_url' => 'https://inkamagazin.example',
            'domain' => 'inkamagazin.example',
            'da' => 30,
            'dr' => 30,
            'traffic' => 2000,
            'country' => 'de',
            'language' => 'de',
            'category' => 'Technology',
            'price' => 135.60,
            'publication_time' => 'permanent',
            'link_type' => 'dofollow',
            'description' => str_repeat('Invoice identity fixture. ', 3),
            'verified' => true,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $this->advertiser->id,
            'order_number' => 'ORD-INV-29',
            'reference_code' => 'REF-INV-29',
            'subtotal' => 135.60,
            'tax' => 0,
            'total_amount' => 135.60,
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'wallet',
            'paid_at' => now(),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'content_link' => 'https://docs.example/article.docx',
            'price' => 135.60,
            'live_url' => 'https://inkamagazin.example/guest-post-article',
        ]);

        $lineItems = [[
            'description' => 'Guest post / sponsored placement',
            'publisher_website' => 'https://inkamagazin.example',
            'quantity' => 1,
            'unit_price' => 135.60,
            'line_total' => 135.60,
        ]];
        $snapshot = [
            'company' => 'Topurlz Ltd',
            'address' => 'Customer street 1',
            'city' => 'Manchester',
            'country' => 'GB',
            'postal_code' => 'M1 1AA',
        ];

        $invoice = Invoice::create([
            'user_id' => $this->advertiser->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-2026-000029',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'invoice_date' => now(),
            'customer_name' => $this->advertiser->name,
            'customer_email' => $this->advertiser->email,
            'currency' => 'EUR',
            'subtotal' => 135.60,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 135.60,
            'tax_label' => 'Not VAT registered – no VAT charged',
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'order_number' => $order->order_number,
            'reference_code' => $order->reference_code,
            'line_items' => $lineItems,
            'billing_snapshot' => $snapshot,
        ]);

        return [
            'invoice' => $invoice->fresh(['order.items.site', 'user']),
            'originalLines' => $lineItems,
            'originalSnapshot' => $snapshot,
            'item' => $item,
        ];
    }

    public function test_pdf_and_html_invoices_identify_teqno_as_issuer_without_rewriting_snapshots(): void
    {
        ['invoice' => $invoice, 'originalLines' => $originalLines, 'originalSnapshot' => $originalSnapshot] = $this->sampleInvoice();

        config(['billing.company.legal_name' => 'SEOLinkBuildings Partners with (Teqno LTD)']);

        $pdfHtml = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('SEOLinkBuildings', $pdfHtml);
        $this->assertStringContainsString(LegalIdentity::ISSUER_NAME, $pdfHtml);
        $this->assertStringContainsString(LegalIdentity::FOOTER, $pdfHtml);
        $this->assertStringContainsString('20 Wenlock Road', $pdfHtml);
        $this->assertStringContainsString('17392540', $pdfHtml);
        $this->assertStringContainsString('Not VAT registered – no VAT charged', $pdfHtml);
        $this->assertStringContainsString('Topurlz Ltd', $pdfHtml);
        $this->assertStringContainsString('Inka Magazin', $pdfHtml);
        $this->assertStringContainsString('inkamagazin.example', $pdfHtml);
        $this->assertStringContainsString('https://inkamagazin.example/guest-post-article', $pdfHtml);
        $this->assertStringContainsString('Guest post / sponsored placement', $pdfHtml);
        $this->assertStringContainsString('€135.60', $pdfHtml);
        $this->assertStringContainsString('Website', $pdfHtml);
        $this->assertStringContainsString('Checkout reference', $pdfHtml);
        $this->assertMatchesRegularExpression('/\b(UTC|GMT)\b/', $pdfHtml);
        $this->assertStringNotContainsString('Partners with', $pdfHtml);
        $this->assertStringNotContainsString('Publisher', $pdfHtml);
        $this->assertStringNotContainsString('josen J', $pdfHtml);

        $invoice->refresh();
        $this->assertSame('Topurlz Ltd', data_get($invoice->billing_snapshot, 'company'));
        $this->assertSame($originalSnapshot, $invoice->billing_snapshot);
        $this->assertSame(
            'https://inkamagazin.example',
            data_get($invoice->line_items, '0.publisher_website')
        );
        $this->assertArrayNotHasKey('site_name', $invoice->line_items[0]);
        $this->assertSame($originalLines[0]['publisher_website'], $invoice->line_items[0]['publisher_website']);
        $this->assertSame('0.00', number_format((float) $invoice->tax_amount, 2));
        $this->assertSame('135.60', number_format((float) $invoice->total_amount, 2));

        $showSrc = (string) file_get_contents(resource_path('views/advertiser/billing/show.blade.php'));
        $this->assertStringContainsString('Website', $showSrc);
        $this->assertStringNotContainsString('Publisher', $showSrc);
    }

    public function test_html_order_invoice_keeps_customer_company_and_legal_footer(): void
    {
        $html = view('advertiser.invoice', [
            'invoiceType' => 'order',
            'referenceCode' => 'ORD-INV-29',
            'amount' => 135.60,
            'billingName' => 'Arslan',
            'companyName' => 'Topurlz Ltd',
            'country' => 'GB',
            'state' => '',
            'city' => 'Manchester',
            'address' => 'Customer street 1',
            'postalCode' => 'M1 1AA',
            'vatNumber' => '',
            'userName' => 'Arslan',
            'userEmail' => 'buyer@example.com',
            'userId' => 1,
            'status' => 'completed',
            'paymentMethod' => 'wallet',
            'orderDate' => now(),
            'orderItems' => [
                [
                    'site_name' => 'Inka Magazin',
                    'site_url' => 'https://inkamagazin.example',
                    'live_url' => 'https://inkamagazin.example/guest-post-article',
                    'price' => 135.60,
                    'sensitive_type' => null,
                ],
            ],
            'totalBaseAmount' => 135.60,
            'totalSensitiveAmount' => 0,
        ])->render();

        $this->assertStringContainsString(LegalIdentity::ISSUER_NAME, $html);
        $this->assertStringContainsString(LegalIdentity::FOOTER, $html);
        $this->assertStringContainsString('<strong>Company:</strong> Topurlz Ltd', $html);
        $this->assertStringContainsString('https://inkamagazin.example/guest-post-article', $html);
        $this->assertStringContainsString('Not VAT registered', $html);
        $this->assertStringNotContainsString('Partners with', $html);
        $this->assertStringNotContainsString('josen J', $html);
    }

    public function test_payment_and_order_email_bodies_omit_invoice_partner_wording(): void
    {
        ['invoice' => $invoice] = $this->sampleInvoice();

        $mailHtml = (new PaymentSuccessfulInvoiceMail($invoice))->render();
        $this->assertStringContainsString('€135.60', $mailHtml);
        $this->assertStringNotContainsString(LegalIdentity::FOOTER, $mailHtml);
        $this->assertStringNotContainsString(LegalIdentity::ISSUER_NAME, $mailHtml);
        $this->assertStringNotContainsString('Partners with', $mailHtml);
        $this->assertStringNotContainsString('trading as', $mailHtml);

        $statusHtml = (new OrderStatusChanged(
            $invoice->order->fresh(['user', 'items.site']),
            $this->advertiser,
            'advertiser',
            'status',
            'processing',
            'review',
        ))->render();
        $this->assertStringNotContainsString(LegalIdentity::FOOTER, $statusHtml);
        $this->assertStringNotContainsString(LegalIdentity::ISSUER_NAME, $statusHtml);
        $this->assertStringContainsString('https://inkamagazin.example/guest-post-article', $statusHtml);
        $this->assertStringNotContainsString('Topurlz Ltd', $statusHtml);
    }
}
