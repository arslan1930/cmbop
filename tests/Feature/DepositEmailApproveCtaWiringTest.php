<?php

namespace Tests\Feature;

use App\Mail\DepositMarkedPaid;
use App\Mail\DepositRequestSubmitted;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\EmailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositEmailApproveCtaWiringTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function pendingDeposit(User $user): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'DEP-CTA-'.uniqid(),
            'amount' => 120,
            'payment_method' => 'wise',
            'status' => 'pending',
            'user_marked_paid_at' => now(),
        ])->fresh('user');
    }

    public function test_marked_paid_primary_cta_is_signed_approve_confirm(): void
    {
        $deposit = $this->pendingDeposit($this->advertiser());
        $mail = new DepositMarkedPaid($deposit);
        $built = $mail->build();

        $this->assertStringContainsString(
            '/admin/deposits/'.$deposit->id.'/approve-confirm',
            (string) $built->viewData['approveUrl']
        );
        $this->assertStringContainsString('signature=', (string) $built->viewData['approveUrl']);
        $this->assertStringContainsString('/admin/deposits', (string) $built->viewData['adminUrl']);

        $html = $mail->render();
        $this->assertStringContainsString('Approve &amp; credit wallet', $html);
        $this->assertStringContainsString('confirm page', strtolower(strip_tags($html)));
    }

    public function test_submitted_mail_also_offers_signed_approve_confirm(): void
    {
        $deposit = $this->pendingDeposit($this->advertiser());
        $mail = new DepositRequestSubmitted($deposit);
        $built = $mail->build();

        $this->assertSame('deposit_submitted', $mail->notificationType);
        $this->assertStringContainsString(
            '/admin/deposits/'.$deposit->id.'/approve-confirm',
            (string) $built->viewData['approveUrl']
        );

        $html = $mail->render();
        $this->assertStringContainsString('Review &amp; approve', $html);
        $this->assertStringContainsString('REF'.$deposit->reference_code, $html);
    }

    public function test_email_catalog_copy_mentions_approve_confirm_cta(): void
    {
        $catalog = EmailCatalog::all();

        $this->assertStringContainsString(
            'approve-confirm',
            strtolower((string) ($catalog['deposit_marked_paid']['description'] ?? ''))
        );
        $this->assertStringContainsString(
            'approve-confirm',
            strtolower((string) ($catalog['deposit_submitted']['description'] ?? ''))
        );

        $preview = EmailCatalog::makeMailable('deposit_marked_paid');
        $this->assertInstanceOf(DepositMarkedPaid::class, $preview);
        $html = $preview->render();
        $this->assertStringContainsString('approve-confirm', $html);
        $this->assertStringContainsString('signature=', $html);
    }
}
