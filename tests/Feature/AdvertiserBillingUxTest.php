<?php

namespace Tests\Feature;

use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdvertiserBillingUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function advertiser(array $overrides = []): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Ada Advertiser',
            'billing_name' => 'Ada Billing',
            'company_name' => 'Ada SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ], $overrides));
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function paidOrder(User $advertiser): Order
    {
        $publisher = User::factory()->create();
        $site = Site::create([
            'publisher_id' => $publisher->id,
            'site_name' => 'Billing UX Site',
            'site_url' => 'https://billing-ux.example',
            'domain' => 'billing-ux.example',
            'da' => 40,
            'dr' => 45,
            'traffic' => 10000,
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
                'order_number' => 'ORD-UX-'.uniqid(),
                'reference_code' => 'REF-UX-'.uniqid(),
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

    public function test_index_is_deposit_aware_and_links_add_funds(): void
    {
        $user = $this->advertiser();
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'UXPEND1',
            'amount' => 40,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);

        $html = $this->actingAs($user)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertSee('deposit_receipt', false)
            ->assertSee('Deposit receipt', false)
            ->assertSee('Add funds', false)
            ->assertSee('Export CSV', false)
            ->assertSee('pending pay-in', false)
            ->assertSee('company name', false)
            ->assertSee('VAT / tax ID', false)
            ->assertDontSee('Invoices appear here automatically after a successful payment.', false)
            ->getContent();

        $this->assertStringContainsString('Reference', $html);
        $this->assertStringNotContainsString('>#{{', $html);
    }

    public function test_deposit_receipt_filter_and_reference_column(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'UXDEP22',
            'amount' => 60,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);
        $receipt = Invoice::query()
            ->where('user_id', $user->id)
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->where('reference_code', $deposit->reference_code)
            ->first();
        $this->assertNotNull($receipt);

        $this->actingAs($user)
            ->get(route('advertiser.billing.index', ['type' => 'deposit_receipt']))
            ->assertOk()
            ->assertSee($receipt->invoice_number, false)
            ->assertSee('UXDEP22', false)
            ->assertDontSee($tax->invoice_number, false);
    }

    public function test_show_uses_snapshot_notes_and_children(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $receipt = Invoice::query()
            ->where('parent_invoice_id', $tax->id)
            ->where('type', Invoice::TYPE_PAYMENT_RECEIPT)
            ->first();

        $this->actingAs($user)
            ->get(route('advertiser.billing.show', $tax))
            ->assertOk()
            ->assertSee('Order details', false)
            ->assertSee($tax->referenceLabel(), false)
            ->assertSee('Billed to', false)
            ->assertSee('Ada SEO Ltd', false)
            ->assertSee('Email me this invoice', false)
            ->assertSee($receipt?->invoice_number ?? 'RCPT', false);

        $deposit = DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'UXSHOW1',
            'amount' => 35,
            'payment_method' => 'wise',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);
        $rct = Invoice::query()
            ->where('reference_code', $deposit->reference_code)
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->firstOrFail();

        $this->actingAs($user)
            ->get(route('advertiser.billing.show', $rct))
            ->assertOk()
            ->assertSee('Wallet top-up', false)
            ->assertSee('UXSHOW1', false)
            ->assertDontSee('Order details', false)
            ->assertSee('not a supply', false);
    }

    public function test_cancelled_tax_invoice_hides_pdf_and_forbids_download(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $tax->update([
            'status' => Invoice::STATUS_CANCELLED,
            'cancel_reason' => 'Issued in error',
        ]);

        $this->actingAs($user)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertDontSee(route('advertiser.billing.download', $tax), false);

        $this->actingAs($user)
            ->get(route('advertiser.billing.show', $tax))
            ->assertOk()
            ->assertSee('Cancelled — PDF unavailable', false)
            ->assertSee('Issued in error', false)
            ->assertDontSee('Download PDF', false)
            ->assertDontSee('Email me this invoice', false);

        $this->assertLeftoverSafeHtmlForbidden(
            $this->actingAs($user)->get(route('advertiser.billing.download', $tax))
        );

        $this->actingAs($user)
            ->getJson(route('advertiser.billing.download', $tax))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This invoice has been cancelled.')
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('App\\Models');

        $this->actingAs($user)
            ->getJson(route('advertiser.billing.view', $tax))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_date_filters_ignore_junk_and_swap_reversed_range(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $tax->update(['invoice_date' => '2026-06-15 12:00:00']);

        $this->actingAs($user)
            ->get(route('advertiser.billing.index', ['from' => 'leftover', 'to' => 'also-bad']))
            ->assertOk()
            ->assertSee($tax->invoice_number, false)
            ->assertDontSee('SQLSTATE', false);

        $this->actingAs($user)
            ->get(route('advertiser.billing.index', [
                'from' => '2026-12-01',
                'to' => '2026-01-01',
            ]))
            ->assertOk()
            ->assertSee($tax->invoice_number, false)
            ->assertSee('value="2026-01-01"', false)
            ->assertSee('value="2026-12-01"', false);
    }

    public function test_missing_invoice_json_does_not_leak_model_class(): void
    {
        $user = $this->advertiser();

        foreach ([
            ['GET', route('advertiser.billing.show', 999999)],
            ['GET', route('advertiser.billing.download', 999999)],
            ['GET', route('advertiser.billing.view', 999999)],
            ['POST', route('advertiser.billing.resend', 999999)],
        ] as [$method, $url]) {
            $this->actingAs($user)
                ->json($method, $url)
                ->assertNotFound()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Invoice not found.')
                ->assertJsonMissingPath('exception')
                ->assertDontSee('SQLSTATE')
                ->assertDontSee('App\\Models')
                ->assertDontSee('No query results');
        }
    }

    public function test_missing_invoice_html_is_leftover_safe(): void
    {
        config(['app.debug' => true]);
        $user = $this->advertiser();

        foreach ([
            ['GET', route('advertiser.billing.show', 999999)],
            ['GET', route('advertiser.billing.download', 999999)],
            ['GET', route('advertiser.billing.view', 999999)],
            ['POST', route('advertiser.billing.resend', 999999)],
        ] as [$method, $url]) {
            $this->assertLeftoverSafeHtmlNotFound(
                $this->actingAs($user)->call($method, $url)
            );
        }
    }

    public function test_foreign_invoice_html_is_leftover_safe(): void
    {
        Mail::fake();
        config(['app.debug' => true]);
        $owner = $this->advertiser(['email' => 'owner-html@example.com']);
        $other = $this->advertiser(['email' => 'other-html@example.com']);
        $order = $this->paidOrder($owner);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);

        foreach ([
            ['GET', route('advertiser.billing.show', $tax)],
            ['GET', route('advertiser.billing.download', $tax)],
            ['GET', route('advertiser.billing.view', $tax)],
            ['POST', route('advertiser.billing.resend', $tax)],
        ] as [$method, $url]) {
            $this->assertLeftoverSafeHtmlForbidden(
                $this->actingAs($other)->call($method, $url)
            );
        }
    }

    public function test_show_survives_dropped_invoices_table(): void
    {
        config(['app.debug' => true]);
        $user = $this->advertiser();
        Schema::dropIfExists('invoices');

        $html = $this->actingAs($user)->get(route('advertiser.billing.show', 1));
        $this->assertContains($html->status(), [404, 500]);
        $html->assertDontSee('SQLSTATE', false)
            ->assertDontSee('App\\Models', false)
            ->assertDontSee('No query results', false);
        if ($html->status() === 404) {
            $html->assertSee('Page not found', false);
        } else {
            $html->assertSee('Something went wrong', false);
        }

        $json = $this->actingAs($user)->getJson(route('advertiser.billing.show', 1));
        $this->assertContains($json->status(), [404, 503, 500]);
        $json->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('App\\Models');
    }

    public function test_owner_can_resend_invoice_email(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);
        Mail::fake();

        $this->actingAs($user)
            ->postJson(route('advertiser.billing.resend', $tax))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('exception');

        Mail::assertQueued(PaymentSuccessfulInvoiceMail::class);
    }

    public function test_other_user_cannot_resend_or_export_foreign_rows(): void
    {
        Mail::fake();
        $owner = $this->advertiser(['email' => 'owner-bill@example.com']);
        $other = $this->advertiser(['email' => 'other-bill@example.com']);
        $order = $this->paidOrder($owner);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);

        $this->actingAs($other)
            ->postJson(route('advertiser.billing.resend', $tax))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot access that invoice.')
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('App\\Models');

        $this->assertLeftoverSafeHtmlForbidden(
            $this->actingAs($other)->get(route('advertiser.billing.show', $tax))
        );

        $csv = $this->actingAs($other)
            ->get(route('advertiser.billing.export'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString($tax->invoice_number, $csv);
    }

    public function test_export_csv_includes_own_deposit_and_tax_rows(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'UXCSV01',
            'amount' => 20,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);
        $receipt = Invoice::query()
            ->where('reference_code', 'UXCSV01')
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->firstOrFail();

        $csv = $this->actingAs($user)
            ->get(route('advertiser.billing.export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('invoice_number', $csv);
        $this->assertStringContainsString($tax->invoice_number, $csv);
        $this->assertStringContainsString($receipt->invoice_number, $csv);
        $this->assertStringContainsString('UXCSV01', $csv);
        $this->assertStringNotContainsString('SQLSTATE', $csv);
    }

    public function test_export_survives_dropped_invoices_table(): void
    {
        $user = $this->advertiser();
        Schema::dropIfExists('invoices');

        $this->actingAs($user)
            ->get(route('advertiser.billing.export'))
            ->assertRedirect(route('advertiser.billing.index'));

        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
    }

    public function test_cancelled_resend_is_leftover_safe(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $tax->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->actingAs($user)
            ->postJson(route('advertiser.billing.resend', $tax))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    public function test_download_and_resend_failures_stay_leftover_safe(): void
    {
        Mail::fake();
        $user = $this->advertiser();
        $order = $this->paidOrder($user);
        $tax = app(BillingDocumentService::class)->handlePaymentPaid($order);
        $sql = new QueryException(
            'sqlite',
            'select * from invoices',
            [],
            new \PDOException('SQLSTATE[HY000]: leftover invoice lookup')
        );

        $this->mock(InvoicePdfGenerator::class, function ($mock) use ($sql) {
            $mock->shouldReceive('generateAndStore')->andThrow($sql);
            $mock->shouldReceive('download')->andThrow($sql);
            $mock->shouldReceive('stream')->andThrow($sql);
        });

        $this->actingAs($user)
            ->getJson(route('advertiser.billing.download', $tax))
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unable to download that invoice.')
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('App\\Models');

        $this->actingAs($user)
            ->getJson(route('advertiser.billing.view', $tax))
            ->assertOk()
            ->assertSee('INVOICE #', false)
            ->assertDontSee('SQLSTATE');

        $this->mock(BillingDocumentService::class, function ($mock) use ($sql) {
            $mock->shouldReceive('resendInvoiceEmail')->andThrow($sql);
        });

        $this->actingAs($user)
            ->postJson(route('advertiser.billing.resend', $tax))
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unable to send that invoice. Please try again.')
            ->assertJsonMissingPath('exception')
            ->assertDontSee('SQLSTATE');
    }

    private function assertLeftoverSafeHtmlNotFound($response): void
    {
        $response->assertNotFound()
            ->assertSee('Page not found', false)
            ->assertDontSee('App\\Models', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('No query results', false);
    }

    private function assertLeftoverSafeHtmlForbidden($response): void
    {
        $response->assertForbidden()
            ->assertSee('Access denied', false)
            ->assertDontSee('App\\Models', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('No query results', false);
    }
}
