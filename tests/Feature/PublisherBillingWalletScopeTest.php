<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherBillingWalletScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Wallet|null, 2: Wallet|null}
     */
    private function publisher(bool $dualRole = false): array
    {
        $publisherRole = Role::firstOrCreate(['name' => 'publisher']);
        $advertiserRole = Role::firstOrCreate(['name' => 'advertiser']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisherRole->id,
        ]);
        $user->roles()->attach($dualRole ? [$publisherRole->id, $advertiserRole->id] : [$publisherRole->id]);

        $publisherWallet = Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisherRole->id,
            'balance' => 80,
            'bonus_balance' => 0,
            'reserved_balance' => 0,
            'currency' => 'EUR',
        ]);

        $advertiserWallet = null;
        if ($dualRole) {
            $advertiserWallet = Wallet::create([
                'user_id' => $user->id,
                'role_id' => $advertiserRole->id,
                'balance' => 50,
                'bonus_balance' => 0,
                'reserved_balance' => 0,
                'currency' => 'EUR',
            ]);
        }

        return [$user->fresh(), $publisherWallet, $advertiserWallet];
    }

    private function statement(User $user, Withdrawal $withdrawal, string $number): Invoice
    {
        return Invoice::create([
            'invoice_number' => $number,
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $user->id,
            'reference_code' => 'WD-'.$withdrawal->id,
            'transaction_id' => 'WD-'.$withdrawal->id,
            'currency' => 'EUR',
            'subtotal' => (float) $withdrawal->amount,
            'tax_amount' => 0,
            'discount_amount' => (float) ($withdrawal->fee ?? 0),
            'total_amount' => (float) ($withdrawal->net_amount ?? $withdrawal->amount),
            'payment_method' => $withdrawal->payment_method,
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'line_items' => [],
            'pdf_disk' => 'local',
            'meta' => [
                'withdrawal_id' => $withdrawal->id,
                'document' => 'withdrawal_payout',
            ],
        ]);
    }

    private function paidWithdrawal(User $user, float $amount, ?Wallet $wallet): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => $amount,
            'fee' => 0,
            'net_amount' => $amount,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'completed',
            'processed_at' => now(),
        ], Withdrawal::walletIdAttributes($wallet)));
    }

    public function test_dual_role_index_hides_advertiser_wallet_statement(): void
    {
        [$user, $publisherWallet, $advertiserWallet] = $this->publisher(true);
        $publisherPaid = $this->paidWithdrawal($user, 25, $publisherWallet);
        $advertiserPaid = $this->paidWithdrawal($user, 100, $advertiserWallet);
        $publisherDoc = $this->statement($user, $publisherPaid, 'PAY-2026-000001');
        $advertiserDoc = $this->statement($user, $advertiserPaid, 'PAY-2026-000002');

        $html = $this->actingAs($user)
            ->get(route('publisher.billing.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($publisherDoc->invoice_number, $html);
        $this->assertStringContainsString('>WD-'.$publisherPaid->id.'</a>', $html);
        $this->assertStringContainsString(route('publisher.withdraw', absolute: false), $html);
        $this->assertStringNotContainsString($advertiserDoc->invoice_number, $html);
        $this->assertStringNotContainsString('WD-'.$advertiserPaid->id, $html);
    }

    public function test_dual_role_cannot_open_advertiser_wallet_statement(): void
    {
        [$user, $publisherWallet, $advertiserWallet] = $this->publisher(true);
        $publisherPaid = $this->paidWithdrawal($user, 25, $publisherWallet);
        $advertiserPaid = $this->paidWithdrawal($user, 100, $advertiserWallet);
        $publisherDoc = $this->statement($user, $publisherPaid, 'PAY-2026-000011');
        $advertiserDoc = $this->statement($user, $advertiserPaid, 'PAY-2026-000012');

        $this->actingAs($user)
            ->get(route('publisher.billing.show', $publisherDoc))
            ->assertOk()
            ->assertSee($publisherDoc->invoice_number, false)
            ->assertSee(route('publisher.withdraw', absolute: false), false)
            ->assertSee('WD-'.$publisherPaid->id, false)
            ->assertSee('Open withdrawals', false);

        $this->actingAs($user)
            ->get(route('publisher.billing.show', $advertiserDoc))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('publisher.billing.view', $advertiserDoc))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('publisher.billing.download', $advertiserDoc))
            ->assertNotFound();
    }

    public function test_publisher_only_leftover_null_wallet_statement_is_listed(): void
    {
        [$user] = $this->publisher(false);
        $leftover = $this->paidWithdrawal($user, 40, null);
        $doc = $this->statement($user, $leftover, 'PAY-2026-000021');

        $this->assertNull($leftover->wallet_id);

        $this->actingAs($user)
            ->get(route('publisher.billing.index'))
            ->assertOk()
            ->assertSee($doc->invoice_number, false)
            ->assertSee('WD-'.$leftover->id, false);

        $this->actingAs($user)
            ->get(route('publisher.billing.show', $doc))
            ->assertOk();
    }

    public function test_dual_role_leftover_null_wallet_statement_is_hidden(): void
    {
        [$user] = $this->publisher(true);
        $leftover = $this->paidWithdrawal($user, 40, null);
        $doc = $this->statement($user, $leftover, 'PAY-2026-000031');

        $html = $this->actingAs($user)
            ->get(route('publisher.billing.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($doc->invoice_number, $html);
        $this->assertStringContainsString('Statements appear after a payout is marked paid', $html);
        $this->assertStringContainsString('In-flight requests are on', $html);

        $this->actingAs($user)
            ->get(route('publisher.billing.show', $doc))
            ->assertNotFound();
    }

    public function test_other_publisher_still_forbidden(): void
    {
        [$owner, $wallet] = $this->publisher(false);
        [$other] = $this->publisher(false);
        $paid = $this->paidWithdrawal($owner, 30, $wallet);
        $doc = $this->statement($owner, $paid, 'PAY-2026-000041');

        $this->actingAs($other)
            ->get(route('publisher.billing.show', $doc))
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('publisher.billing.download', $doc))
            ->assertForbidden();
    }

    public function test_non_payout_invoice_still_not_found(): void
    {
        [$user] = $this->publisher(false);
        $tax = Invoice::create([
            'invoice_number' => 'INV-TEST-WALLET-1',
            'type' => Invoice::TYPE_TAX_INVOICE,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $user->id,
            'currency' => 'EUR',
            'subtotal' => 10,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 10,
            'invoice_date' => now(),
            'customer_name' => $user->name,
            'customer_email' => $user->email,
            'line_items' => [],
            'pdf_disk' => 'local',
        ]);

        $this->actingAs($user)
            ->get(route('publisher.billing.show', $tax))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('publisher.billing.download', $tax))
            ->assertNotFound();
    }
}
