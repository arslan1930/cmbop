<?php

namespace Tests\Feature;

use App\Mail\PaymentSuccessfulInvoiceMail;
use App\Mail\WithdrawalRequestNotification;
use App\Mail\WithdrawalStatusUpdated;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\InAppNotificationService;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawalEmailMarkPaidCtaWiringTest extends TestCase
{
    use RefreshDatabase;

    private function publisher(): User
    {
        $role = Role::firstOrCreate(['name' => 'publisher']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    public function test_withdrawal_request_mail_primary_cta_is_signed_mark_paid_confirm(): void
    {
        $publisher = $this->publisher();
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 120,
            'fee' => 0,
            'net_amount' => 120,
            'payment_method' => 'bank',
            'payment_details' => [
                'bank_name' => 'Test Bank',
                'account_holder' => 'Pub',
                'account_number' => 'BE00',
            ],
            'status' => 'pending',
        ]);

        $mail = new WithdrawalRequestNotification($withdrawal, $publisher);
        $built = $mail->build();

        $this->assertSame('withdrawal_request', $mail->notificationType);
        $this->assertStringContainsString(
            '/admin/withdrawals/'.$withdrawal->id.'/mark-paid-confirm',
            (string) $built->viewData['markPaidUrl']
        );
        $this->assertStringContainsString('signature=', (string) $built->viewData['markPaidUrl']);

        $html = $mail->render();
        $this->assertStringContainsString('Mark paid (confirm)', $html);
        $this->assertStringContainsString('payout queue', strtolower(strip_tags($html)));
    }

    public function test_email_catalog_copy_mentions_mark_paid_confirm(): void
    {
        $catalog = EmailCatalog::all();
        $this->assertStringContainsString(
            'mark-paid',
            strtolower((string) ($catalog['withdrawal_request']['description'] ?? ''))
        );

        $preview = EmailCatalog::makeMailable('withdrawal_request');
        $this->assertInstanceOf(WithdrawalRequestNotification::class, $preview);
        $html = $preview->render();
        $this->assertStringContainsString('mark-paid-confirm', $html);
        $this->assertStringContainsString('signature=', $html);

        $status = EmailCatalog::makeMailable('withdrawal_status');
        $this->assertNotNull($status);
        $statusHtml = $status->render();
        $this->assertStringContainsString('payout documents', strtolower(strip_tags($statusHtml)));
        $this->assertStringNotContainsString('TypeError', $statusHtml);
    }

    public function test_email_catalog_withdrawal_preview_does_not_use_live_withdrawal(): void
    {
        $publisher = $this->publisher();
        $live = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 88,
            'fee' => 0,
            'net_amount' => 88,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'live-pub@example.com'],
            'status' => 'pending',
        ]);

        $preview = EmailCatalog::makeMailable('withdrawal_request');
        $this->assertInstanceOf(WithdrawalRequestNotification::class, $preview);
        $html = $preview->render();

        $this->assertStringNotContainsString('/admin/withdrawals/'.$live->id.'/mark-paid-confirm', $html);
        $this->assertStringContainsString('/admin/withdrawals/0/mark-paid-confirm', $html);
        $this->assertStringNotContainsString('live-pub@example.com', $html);
        $this->assertStringNotContainsString($publisher->email, $html);
        $this->assertStringContainsString('sample@example.com', $html);
    }

    public function test_email_catalog_status_preview_does_not_reconcile_live_statement(): void
    {
        $publisher = $this->publisher();
        $other = User::factory()->create([
            'name' => 'Leftover Payee',
            'email' => 'leftover-payee@example.com',
            'email_verified_at' => now(),
        ]);
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 75,
            'fee' => 0,
            'net_amount' => 75,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'owner@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ]);
        $statement = Invoice::create([
            'user_id' => $other->id,
            'customer_name' => $other->name,
            'customer_email' => $other->email,
            'invoice_number' => 'PAY-PREVIEW-SIDE-EFFECT',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'subtotal' => 75,
            'total_amount' => 75,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Payout', 'line_total' => 75]],
            'reference_code' => 'WD-'.$withdrawal->id,
            'meta' => ['withdrawal_id' => $withdrawal->id],
        ]);

        $status = EmailCatalog::makeMailable('withdrawal_status');
        $this->assertNotNull($status);
        $status->render();

        $statement->refresh();
        $this->assertSame($other->id, (int) $statement->user_id);
        $this->assertSame('Leftover Payee', $statement->customer_name);
        $this->assertSame('leftover-payee@example.com', $statement->customer_email);
    }

    public function test_email_catalog_invoice_preview_does_not_use_live_tax_invoice(): void
    {
        $advertiser = User::factory()->create([
            'name' => 'Live Invoice Customer',
            'email' => 'live-invoice@example.com',
            'email_verified_at' => now(),
        ]);
        Invoice::create([
            'user_id' => $advertiser->id,
            'customer_name' => $advertiser->name,
            'customer_email' => $advertiser->email,
            'invoice_number' => 'INV-LIVE-SECRET-99',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'subtotal' => 199,
            'total_amount' => 199,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Live order', 'line_total' => 199]],
        ]);

        $preview = EmailCatalog::makeMailable('payment_successful_invoice');
        $this->assertInstanceOf(PaymentSuccessfulInvoiceMail::class, $preview);
        $html = $preview->render();

        $this->assertStringNotContainsString('INV-LIVE-SECRET-99', $html);
        $this->assertStringNotContainsString('live-invoice@example.com', $html);
        $this->assertStringNotContainsString('Live Invoice Customer', $html);
        $this->assertStringContainsString('INV-PREVIEW-000001', $html);
    }

    public function test_email_center_keeps_payout_preview_hops_on_this_host(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $admin->roles()->attach($role->id);

        $html = $this->actingAs($admin->fresh())
            ->get(route('admin.emails.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('admin.emails.test', [], false).'"', $html);
        $this->assertStringContainsString('action="'.route('admin.emails.settings', [], false).'"', $html);
        $this->assertStringContainsString('href="'.route('admin.emails.preview', 'withdrawal_request', false).'"', $html);
        $this->assertStringNotContainsString('action="'.route('admin.emails.test').'"', $html);
        $this->assertStringNotContainsString('href="'.route('admin.emails.preview', 'withdrawal_request').'"', $html);
    }

    public function test_email_catalog_order_preview_does_not_use_live_order(): void
    {
        $advertiser = User::factory()->create([
            'name' => 'Live Order Buyer',
            'email' => 'live-order@example.com',
            'email_verified_at' => now(),
        ]);
        Order::create([
            'user_id' => $advertiser->id,
            'order_number' => 'ORD-LIVE-SECRET-77',
            'reference_code' => 'REF-LIVE-SECRET-77',
            'total_amount' => 88,
            'subtotal' => 88,
            'tax' => 0,
            'payment_status' => 'paid',
            'status' => 'completed',
            'payment_method' => 'wallet',
        ]);

        $preview = EmailCatalog::makeMailable('order_payment_confirmed');
        $this->assertNotNull($preview);
        $html = $preview->render();

        $this->assertStringNotContainsString('ORD-LIVE-SECRET-77', $html);
        $this->assertStringNotContainsString('REF-LIVE-SECRET-77', $html);
        $this->assertStringNotContainsString('live-order@example.com', $html);
        $this->assertStringNotContainsString('Live Order Buyer', $html);
        $this->assertStringContainsString('ORD-PREVIEW', $html);
    }

    public function test_paid_status_email_and_bell_do_not_reconcile_leftover_statement(): void
    {
        $publisher = $this->publisher();
        $other = User::factory()->create([
            'name' => 'Leftover Notify Payee',
            'email' => 'leftover-notify@example.com',
            'email_verified_at' => now(),
        ]);
        $withdrawal = Withdrawal::create([
            'user_id' => $publisher->id,
            'amount' => 60,
            'fee' => 0,
            'net_amount' => 60,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'owner@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ]);
        $statement = Invoice::create([
            'user_id' => $other->id,
            'customer_name' => $other->name,
            'customer_email' => $other->email,
            'invoice_number' => 'PAY-NOTIFY-SIDE-EFFECT',
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'subtotal' => 60,
            'total_amount' => 60,
            'invoice_date' => now(),
            'line_items' => [['description' => 'Payout', 'line_total' => 60]],
            'pdf_path' => 'payouts/leftover-notify.pdf',
            'reference_code' => 'WD-'.$withdrawal->id,
            'meta' => ['withdrawal_id' => $withdrawal->id],
        ]);

        (new WithdrawalStatusUpdated($withdrawal, 'pending', 'completed', null))->render();
        app(InAppNotificationService::class)->notifyWithdrawalPaid($withdrawal);

        $statement->refresh();
        $this->assertSame($other->id, (int) $statement->user_id);
        $this->assertSame('Leftover Notify Payee', $statement->customer_name);
        $this->assertSame('leftover-notify@example.com', $statement->customer_email);
        $this->assertSame('payouts/leftover-notify.pdf', $statement->pdf_path);
    }
}
