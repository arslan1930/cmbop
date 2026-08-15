<?php

namespace Tests\Feature;

use App\Mail\DepositApproved;
use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Services\Wallet\ManualDepositNotManualException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminDepositsApproveContextTest extends TestCase
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

    private function makeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function walletFor(User $user, float $balance = 0, float $bonus = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'role_id' => Wallet::advertiserRoleId(),
            'balance' => $balance,
            'reserved_balance' => 0,
            'bonus_balance' => $bonus,
            'bonus_reserved' => 0,
            'currency' => 'EUR',
        ]);
    }

    private function depositFor(User $advertiser, array $overrides = []): DepositRequest
    {
        return DepositRequest::create(array_merge([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-CTX-'.uniqid(),
            'amount' => 80,
            'payment_method' => 'bank',
            'status' => 'pending',
        ], $overrides));
    }

    private function relativeSignedUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    public function test_show_includes_the_same_wallet_context_as_email_confirm(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser, 50, 10);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-OLD-50',
            'amount' => 50,
            'payment_method' => 'wise',
            'status' => 'completed',
            'approved_at' => now()->subDays(3),
        ]);

        $deposit = $this->depositFor($advertiser, [
            'reference_code' => 'DEP-NEW-80',
            'amount' => 80,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('wallet.can_approve', true)
            ->assertJsonPath('wallet.is_card', false)
            ->assertJsonPath('wallet.current_balance', 50)
            ->assertJsonPath('wallet.bonus_balance', 10)
            ->assertJsonPath('wallet.incoming_amount', 80)
            ->assertJsonPath('wallet.projected_balance', 130)
            ->assertJsonPath('wallet.possible_duplicate', false)
            ->assertJsonPath('wallet.prior_deposits.0.reference_code', 'DEP-OLD-50');
    }

    public function test_show_flags_a_possible_duplicate_same_amount(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser, 80);

        DepositRequest::create([
            'user_id' => $advertiser->id,
            'reference_code' => 'DEP-DUP-80',
            'amount' => 80,
            'payment_method' => 'bank',
            'status' => 'completed',
            'approved_at' => now()->subDay(),
        ]);

        $deposit = $this->depositFor($advertiser, ['amount' => 80]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('wallet.possible_duplicate', true)
            ->assertJsonPath('wallet.duplicate_matches.0.reference_code', 'DEP-DUP-80');
    }

    public function test_pending_card_show_cannot_be_approved(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser, 20);
        $deposit = $this->depositFor($advertiser, [
            'payment_method' => 'card',
            'amount' => 40,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('wallet.can_approve', false)
            ->assertJsonPath('wallet.is_card', true)
            ->assertJsonPath('wallet.projected_balance', null)
            ->assertJsonPath('wallet.current_balance', 20);
    }

    public function test_approve_refuses_pending_card_and_does_not_credit(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->walletFor($advertiser, 15);
        $deposit = $this->depositFor($advertiser, [
            'payment_method' => 'card',
            'amount' => 40,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', ManualDepositNotManualException::forDeposit()->getMessage());

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(15.0, (float) $wallet->fresh()->balance);
        Mail::assertNotQueued(DepositApproved::class);
    }

    public function test_index_page_renders_shared_wallet_copy_and_hides_card_approve(): void
    {
        $admin = $this->makeUser('admin');

        $html = $this->actingAs($admin)
            ->get(route('admin.deposits'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Wallet snapshot', $html);
        $this->assertStringContainsString('Possible duplicate', $html);
        $this->assertStringContainsString('After this approval', $html);
        $this->assertStringContainsString('wallet.can_approve', $html);
        $this->assertStringContainsString('wallet.is_card', $html);
        $this->assertStringContainsString('credit the wallet twice', $html);
        $this->assertStringContainsString('data.wallet', $html);
    }

    public function test_signed_get_for_card_does_not_offer_confirm(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser);
        $deposit = $this->depositFor($advertiser, ['payment_method' => 'card']);
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Stripe deposit — do not credit here', false)
            ->assertSee('credit the wallet twice', false)
            ->assertSee('Wallet snapshot', false)
            ->assertDontSee('Confirm and credit', false);

        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_signed_post_for_card_does_not_credit(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->walletFor($advertiser, 5);
        $deposit = $this->depositFor($advertiser, [
            'payment_method' => 'card',
            'amount' => 40,
        ]);
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->post($url)
            ->assertRedirect(route('admin.deposits'))
            ->assertSessionHas('error');

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(5.0, (float) $wallet->fresh()->balance);
        Mail::assertNotQueued(DepositApproved::class);
    }

    public function test_show_drops_stripe_and_payout_secrets(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $advertiser->forceFill([
            'stripe_customer_id' => 'cus_secret',
            'payout_bank_account' => 'DE00SECRET',
            'password' => 'hashed-secret',
        ])->save();
        $this->walletFor($advertiser);
        $deposit = $this->depositFor($advertiser, [
            'stripe_session_id' => 'cs_secret',
            'stripe_payment_intent_id' => 'pi_secret',
            'stripe_response' => ['client_secret' => 'should-not-leak'],
        ]);

        $payload = $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deposit.reference_code', $deposit->reference_code)
            ->assertJsonPath('deposit.user.email', $advertiser->email)
            ->assertJsonPath('wallet.can_approve', false)
            ->assertJsonPath('wallet.is_card', true)
            ->json();

        $row = $payload['deposit'];
        $this->assertArrayNotHasKey('stripe_response', $row);
        $this->assertArrayNotHasKey('stripe_session_id', $row);
        $this->assertArrayNotHasKey('stripe_payment_intent_id', $row);
        $this->assertArrayNotHasKey('stripe_customer_id', $row['user']);
        $this->assertArrayNotHasKey('payout_bank_account', $row['user']);
        $this->assertArrayNotHasKey('password', $row['user']);
    }

    public function test_bank_row_with_stripe_id_cannot_be_approved(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $wallet = $this->walletFor($advertiser, 10);
        $deposit = $this->depositFor($advertiser, [
            'payment_method' => 'bank',
            'amount' => 40,
            'stripe_payment_intent_id' => 'pi_mixed_'.uniqid(),
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.deposits.show', $deposit->id))
            ->assertOk()
            ->assertJsonPath('wallet.can_approve', false)
            ->assertJsonPath('wallet.is_card', true);

        $this->actingAs($admin)
            ->postJson(route('admin.deposits.approve', $deposit->id))
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', ManualDepositNotManualException::forDeposit()->getMessage());

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame(10.0, (float) $wallet->fresh()->balance);
        Mail::assertNotQueued(DepositApproved::class);
    }

    public function test_signed_get_for_pending_unknown_method_is_not_already_processed(): void
    {
        $admin = $this->makeUser('admin');
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser);
        $deposit = $this->depositFor($advertiser, ['payment_method' => 'other']);
        $url = $this->relativeSignedUrl(ManualDepositApproveLink::url($deposit));

        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertSee('Cannot credit from this link', false)
            ->assertSee('still', false)
            ->assertDontSee('Deposit already processed', false)
            ->assertDontSee('Confirm and credit', false);
    }

    public function test_rejected_and_approved_mail_render_without_a_user(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $rejected = $this->depositFor($advertiser, [
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);
        $rejected->setRelation('user', null);

        $approved = $this->depositFor($advertiser, [
            'reference_code' => 'DEP-MAIL-OK',
            'status' => 'completed',
            'approved_at' => now(),
        ]);
        $approved->setRelation('user', null);

        $markdown = app(Markdown::class);
        $rejectedHtml = $markdown->render('emails.deposit-rejected', ['deposit' => $rejected]);
        $this->assertStringContainsString('Dear there', $rejectedHtml);
        $this->assertStringContainsString($rejected->reference_code, $rejectedHtml);

        $approvedHtml = $markdown->render('emails.deposit-approved', [
            'deposit' => $approved,
            'isCard' => false,
            'receipt' => null,
            'walletBalance' => 0,
            'balanceUrl' => route('advertiser.balance'),
            'downloadReceiptUrl' => null,
        ]);
        $this->assertStringContainsString('Dear there', $approvedHtml);
        $this->assertStringContainsString($approved->reference_code, $approvedHtml);
    }

    public function test_confirm_page_renders_without_a_user(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $this->walletFor($advertiser, 20);
        $deposit = $this->depositFor($advertiser, ['amount' => 40]);
        $deposit->setRelation('user', null);

        $this->actingAs($this->makeUser('admin'))->withViewErrors([]);

        $html = view('admin.deposits.approve-confirm', [
            'deposit' => $deposit,
            'canApprove' => true,
            'isCard' => false,
            'confirmAction' => 'https://example.test/confirm',
            'currentBalance' => 20.0,
            'incomingAmount' => 40.0,
            'projectedBalance' => 60.0,
            'priorDeposits' => collect(),
            'bonusBalance' => 0.0,
            'possibleDuplicate' => false,
            'duplicateMatches' => collect(),
        ])->render();

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('Confirm and credit', $html);
        $this->assertStringContainsString($deposit->reference_code, $html);
    }

    public function test_submitted_mail_renders_without_a_user(): void
    {
        $advertiser = $this->makeUser('advertiser');
        $deposit = $this->depositFor($advertiser);
        $deposit->setRelation('user', null);

        $html = app(Markdown::class)->render('emails.deposit-request-submitted', [
            'deposit' => $deposit,
            'user' => null,
            'approveUrl' => 'https://example.test/approve',
            'adminUrl' => route('admin.deposits'),
        ]);

        $this->assertStringContainsString('An advertiser', $html);
        $this->assertStringContainsString($deposit->reference_code, $html);
    }
}
