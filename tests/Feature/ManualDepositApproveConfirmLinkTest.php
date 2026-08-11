<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\ManualDepositApproveLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ManualDepositApproveConfirmLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'billing.deposit_approve_link_expire_minutes' => 60 * 24 * 7,
        ]);
        URL::forceRootUrl('http://127.0.0.1:8000');
    }

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($role->id);
        $user->active_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    private function walletFor(User $user): Wallet
    {
        $roleId = Wallet::advertiserRoleId();

        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'balance' => 0,
            'reserved_balance' => 0,
            'bonus_balance' => 0,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function pendingDeposit(User $user, float $amount = 80): DepositRequest
    {
        return DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'DEP-LINK-'.uniqid(),
            'amount' => $amount,
            'payment_method' => 'bank',
            'status' => 'pending',
            'user_marked_paid_at' => now(),
            'user_payment_note' => 'Wise REF-ABC',
        ]);
    }

    /** Strip public origin so the test HTTP client can follow the path + query. */
    private function relativeSignedUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    public function test_approve_link_is_temporary_signed_public_url(): void
    {
        $deposit = $this->pendingDeposit($this->advertiser());

        $url = ManualDepositApproveLink::url($deposit);

        $this->assertStringContainsString('/admin/deposits/'.$deposit->id.'/approve-confirm', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST));
    }

    public function test_signed_get_shows_confirm_ui_without_crediting(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 80);

        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Confirm deposit approval', false)
            ->assertSee('€80.00', false)
            ->assertSee('REF'.$deposit->reference_code, false)
            ->assertSee('Confirm and credit', false);

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        Mail::assertNothingQueued();
    }

    public function test_signed_post_credits_wallet_via_service(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 80);

        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->post($url, ['admin_notes' => 'Matched on Wise'])
            ->assertRedirect(route('admin.deposits'))
            ->assertSessionHas('success');

        $this->assertSame('completed', $deposit->fresh()->status);
        $this->assertSame('Matched on Wise', $deposit->fresh()->admin_notes);
        $this->assertSame(80.0, (float) $wallet->fresh()->balance);
        Mail::assertQueued(DepositApproved::class, 1);
    }

    public function test_get_without_valid_signature_redirects_with_error(): void
    {
        $admin = $this->admin();
        $deposit = $this->pendingDeposit($this->advertiser());

        $this->actingAs($admin)
            ->get(route('admin.deposits.approve-confirm.show', $deposit))
            ->assertRedirect(route('admin.deposits'))
            ->assertSessionHas('error');
    }

    public function test_guest_cannot_open_confirm_page(): void
    {
        $deposit = $this->pendingDeposit($this->advertiser());
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->get($url)->assertRedirect();
    }

    public function test_non_admin_cannot_confirm(): void
    {
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser);
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($advertiser)
            ->get($url)
            ->assertForbidden();
    }

    public function test_unsigned_post_does_not_credit(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 50);

        $this->actingAs($admin)
            ->post(route('admin.deposits.approve-confirm', $deposit), [
                'admin_notes' => 'Should fail',
            ])
            ->assertRedirect(route('admin.deposits'))
            ->assertSessionHas('error');

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
    }

    public function test_already_processed_deposit_shows_status_on_get(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser);
        $deposit->update(['status' => 'completed', 'approved_at' => now()]);

        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Deposit already processed', false)
            ->assertDontSee('Confirm and credit', false);
    }

    public function test_double_confirm_post_does_not_double_credit(): void
    {
        $admin = $this->admin();
        $advertiser = $this->advertiser();
        $wallet = $this->walletFor($advertiser);
        $deposit = $this->pendingDeposit($advertiser, 60);

        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)->post($url)->assertRedirect(route('admin.deposits'));
        $this->actingAs($admin)
            ->post($url)
            ->assertRedirect(route('admin.deposits'))
            ->assertSessionHas('error');

        $this->assertSame(60.0, (float) $wallet->fresh()->balance);
        Mail::assertQueued(DepositApproved::class, 1);
    }
}
