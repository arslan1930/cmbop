<?php

namespace Tests\Feature;

use App\Mail\WithdrawalRequestNotification;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Withdrawal;
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
}
