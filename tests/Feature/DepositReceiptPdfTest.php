<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\DepositReceiptService;
use App\Services\Billing\InvoiceNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wallet top-ups used to be served as an HTML page. They are now proper PDF
 * receipts in their own RCT- series, with no tax line, because a deposit is
 * money on account rather than a supply of services.
 */
class DepositReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'name' => 'Dana Depositor',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function deposit(User $user, string $status = 'completed', float $amount = 250): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'DEP-'.uniqid(),
            'amount' => $amount,
            'payment_method' => 'bank',
            'status' => $status,
            'approved_at' => $status === 'completed' ? now() : null,
            'paid_at' => $status === 'completed' ? now() : null,
        ]);
    }

    public function test_receipt_numbers_use_their_own_series_and_do_not_consume_invoice_numbers(): void
    {
        $numbers = app(InvoiceNumberGenerator::class);

        $this->assertSame('RCT-2099-000001', $numbers->nextReceipt(2099));
        $this->assertSame('RCT-2099-000002', $numbers->nextReceipt(2099));

        // The sales series must be untouched by receipts.
        $this->assertSame('INV-2099-000001', $numbers->next(2099));
        $this->assertSame('RCT-2099-000003', $numbers->nextReceipt(2099));
    }

    public function test_settled_deposit_issues_a_receipt_pdf(): void
    {
        $user = $this->advertiser();
        $deposit = $this->deposit($user);

        $receipt = Invoice::where('user_id', $user->id)
            ->where('type', Invoice::TYPE_DEPOSIT_RECEIPT)
            ->first();

        $this->assertNotNull($receipt, 'Settling a deposit must issue a receipt.');
        $this->assertStringStartsWith('RCT-', $receipt->invoice_number);
        $this->assertSame($deposit->reference_code, $receipt->reference_code);
        $this->assertSame(250.0, (float) $receipt->total_amount);
        $this->assertSame(Invoice::STATUS_PAID, $receipt->status);
        $this->assertNotNull($receipt->pdf_path);
        Storage::disk('local')->assertExists($receipt->pdf_path);
    }

    public function test_receipt_carries_no_tax_line(): void
    {
        // Even with tax switched on globally, a top-up is not a taxable supply.
        config(['billing.tax.enabled' => true, 'billing.tax.rate' => 21, 'billing.tax.label' => 'VAT']);

        $user = $this->advertiser();
        $this->deposit($user, 'completed', 100);

        $receipt = Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->firstOrFail();

        $this->assertSame(0.0, (float) $receipt->tax_amount);
        $this->assertSame(0.0, (float) $receipt->tax_rate);
        $this->assertNull($receipt->tax_label);
        $this->assertSame(100.0, (float) $receipt->subtotal);
        $this->assertSame(100.0, (float) $receipt->total_amount);

        $html = view('billing.pdf.invoice', [
            'invoice' => $receipt,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => '€',
        ])->render();

        $this->assertStringContainsString('Deposit Receipt', $html);
        $this->assertStringNotContainsString('VAT (', $html);
        $this->assertStringNotContainsString('<td class="label">VAT', $html);
        $this->assertStringContainsString('no VAT is charged', $html);
    }

    public function test_pending_deposit_gets_no_receipt_until_it_settles(): void
    {
        $user = $this->advertiser();
        $deposit = $this->deposit($user, 'pending');

        $this->assertSame(0, Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->count());

        $deposit->update(['status' => 'completed', 'paid_at' => now()]);

        $this->assertSame(1, Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->count());
    }

    public function test_receipt_issuing_is_idempotent(): void
    {
        $user = $this->advertiser();
        $deposit = $this->deposit($user);
        $service = app(DepositReceiptService::class);

        $first = $service->issue($deposit);
        $second = $service->issue($deposit->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->count());
    }

    public function test_deposit_invoice_route_serves_the_pdf_instead_of_html(): void
    {
        $user = $this->advertiser();
        $deposit = $this->deposit($user);
        $receipt = Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->firstOrFail();

        $view = $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertRedirect(route('advertiser.billing.view', $receipt));
        $this->assertStringContainsString(
            route('advertiser.billing.view', $receipt, false),
            (string) $view->headers->get('Location')
        );

        $download = $this->actingAs($user)
            ->get(route('advertiser.invoice', ['referenceCode' => $deposit->reference_code, 'download' => 1]))
            ->assertRedirect(route('advertiser.billing.download', $receipt));
        $this->assertStringContainsString(
            route('advertiser.billing.download', $receipt, false),
            (string) $download->headers->get('Location')
        );
    }

    public function test_pending_deposit_still_shows_payment_instructions(): void
    {
        $user = $this->advertiser();
        $deposit = $this->deposit($user, 'pending');

        $this->actingAs($user)
            ->get(route('advertiser.invoice', $deposit->reference_code))
            ->assertOk();
    }

    public function test_receipt_downloads_as_a_pdf(): void
    {
        $user = $this->advertiser();
        $this->deposit($user);
        $receipt = Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->firstOrFail();

        $response = $this->actingAs($user)->get(route('advertiser.billing.download', $receipt));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString($receipt->invoice_number.'.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_receipt_appears_in_the_advertiser_billing_list(): void
    {
        $user = $this->advertiser();
        $this->deposit($user);
        $receipt = Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->firstOrFail();

        $this->actingAs($user)
            ->get(route('advertiser.billing.index'))
            ->assertOk()
            ->assertSee($receipt->invoice_number);
    }

    public function test_another_advertiser_cannot_download_the_receipt(): void
    {
        $user = $this->advertiser();
        $this->deposit($user);
        $receipt = Invoice::where('type', Invoice::TYPE_DEPOSIT_RECEIPT)->firstOrFail();

        $this->actingAs($this->advertiser())
            ->get(route('advertiser.billing.download', $receipt))
            ->assertForbidden();
    }
}
