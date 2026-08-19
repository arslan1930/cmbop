<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublisherBalanceHistoryUiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{publisher_balance?: float, publisher_reserved?: float, publisher_bonus?: float, publisher_debt?: float, advertiser_balance?: float, advertiser_bonus?: float}|array<string, float>  $overrides
     */
    private function publisherWithWallets(array $overrides = [], bool $dualRole = true): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'marketing']);
        $advertiser = Role::firstOrCreate(['name' => 'advertiser']);
        $publisher = Role::firstOrCreate(['name' => 'publisher']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $publisher->id,
        ]);
        $user->roles()->attach($dualRole ? [$publisher->id, $advertiser->id] : [$publisher->id]);

        Wallet::create([
            'user_id' => $user->id,
            'role_id' => $publisher->id,
            'balance' => $overrides['publisher_balance'] ?? 7.64,
            'reserved_balance' => $overrides['publisher_reserved'] ?? 0,
            'bonus_balance' => $overrides['publisher_bonus'] ?? 0,
            'bonus_reserved' => 0,
            'debt_balance' => $overrides['publisher_debt'] ?? 0,
            'currency' => 'EUR',
        ]);

        if ($dualRole) {
            Wallet::create([
                'user_id' => $user->id,
                'role_id' => $advertiser->id,
                'balance' => $overrides['advertiser_balance'] ?? 20,
                'reserved_balance' => 0,
                'bonus_balance' => $overrides['advertiser_bonus'] ?? 20,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]);
        }

        return $user;
    }

    public function test_balance_page_does_not_offer_role_transfers(): void
    {
        $user = $this->publisherWithWallets();

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Publisher earnings', $html);
        $this->assertStringContainsString('Withdrawable', $html);
        $this->assertStringContainsString('€7.64', $html);
        $this->assertStringContainsString('Use for spending', $html);
        $this->assertStringContainsString('id="roleMoveForm"', $html);
        $this->assertStringContainsString('id="roleMoveAmount"', $html);
        $this->assertStringContainsString('id="roleMoveBtn"', $html);
        $this->assertStringContainsString(route('publisher.balance.transfer'), $html);
        $this->assertStringContainsString('Move withdrawable earnings into your advertiser wallet', $html);
        $this->assertStringContainsString('assets/js/publisher-balance.js', $html);
        $this->assertStringNotContainsString('Transfer to Advertiser Wallet', $html);
        $this->assertStringNotContainsString('0% Transfer Fee', $html);
        $this->assertStringNotContainsString('id="transferBtn"', $html);
        $this->assertStringNotContainsString('id="amount"', $html);
        $this->assertStringNotContainsString('function renderTransferHistory', $html);
        $this->assertStringNotContainsString('Transfer History', $html);
        $this->assertStringNotContainsString('Ready to transfer or withdraw', $html);
        $this->assertStringNotContainsString('Internal wallet transfers are no longer offered', $html);
        $this->assertStringNotContainsString('Showing 0 to 0 of 0', $html);
        $this->assertStringNotContainsString('after bonus, amounts on hold, and clawback debt', $html);
    }

    public function test_dual_role_balance_shows_both_cards_and_bonus_is_purchases_only(): void
    {
        $user = $this->publisherWithWallets();

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="publisherBalance"', $html);
        $this->assertStringContainsString('Advertiser (spendable)', $html);
        $this->assertStringContainsString('id="advertiserBalance"', $html);
        $this->assertStringContainsString('€20.00', $html);
        $this->assertStringContainsString('Money', $html);
        $this->assertStringContainsString('Bonus', $html);
        $this->assertStringContainsString('(purchases only)', $html);
        $this->assertStringContainsString(Wallet::PROMOTIONAL_BONUS_MESSAGE, $html);
        $this->assertStringContainsString('Moved earnings arrive here as Money and can be spent in Catalog.', $html);
        $this->assertStringNotContainsString('Publisher earnings cannot be moved into this wallet here', $html);
        $this->assertStringContainsString(route('advertiser.add-funds'), $html);
        $this->assertStringContainsString(route('advertiser.catalog'), $html);
        $this->assertStringContainsString('Clawback debt blocks withdrawals and moves; it does not reduce this number', $html);
    }

    public function test_publisher_only_balance_hides_advertiser_card(): void
    {
        $user = $this->publisherWithWallets([], false);

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Publisher earnings', $html);
        $this->assertStringContainsString('balance-label">Earnings', $html);
        $this->assertStringNotContainsString('Advertiser (spendable)', $html);
        $this->assertStringNotContainsString('Advertiser spendable', $html);
        $this->assertStringNotContainsString('id="advertiserBalance"', $html);
        $this->assertStringNotContainsString('id="addFundsCta"', $html);
        $this->assertStringNotContainsString('id="roleMoveForm"', $html);
        $this->assertStringNotContainsString('Use for spending', $html);
        $this->assertStringContainsString('Catalog spend uses an advertiser wallet', $html);
    }

    public function test_withdraw_cta_is_disabled_below_minimum(): void
    {
        $user = $this->publisherWithWallets();

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*id="withdrawCta"[^>]*disabled/', $html);
        $this->assertStringContainsString('You need at least €20.00 withdrawable balance', $html);
        $this->assertStringContainsString('Available now: €7.64', $html);
        $this->assertStringNotContainsString('Ready to withdraw', $html);
        $this->assertStringContainsString('id="roleMoveForm"', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*id="roleMoveBtn"[^>]*disabled/', $html);
        $this->assertStringContainsString('id="roleMoveAllBtn"', $html);
        $this->assertStringContainsString('data-can-move="1"', $html);
        $this->assertStringContainsString('data-min="0.01"', $html);
        $this->assertStringContainsString('data-max="7.64"', $html);
        $this->assertStringContainsString('The €20 payout minimum does not apply', $html);
    }

    public function test_withdraw_cta_is_disabled_when_publisher_has_debt(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 40,
            'publisher_debt' => 12.5,
        ]);

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Outstanding clawback debt', $html);
        $this->assertStringContainsString('€12.50', $html);
        $this->assertStringContainsString('Debt', $html);
        $this->assertStringContainsString('Withdrawals are blocked while you have outstanding clawback debt', $html);
        $this->assertStringContainsString('Moves are blocked while you have outstanding clawback debt', $html);
        $this->assertStringContainsString('Withdrawals and moves to your advertiser wallet are blocked', $html);
        $this->assertStringNotContainsString('Ready to withdraw', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*id="withdrawCta"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*id="roleMoveBtn"[^>]*disabled/', $html);
    }

    public function test_withdraw_cta_is_enabled_when_withdrawable_meets_minimum(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 25,
        ]);

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ready to withdraw', $html);
        $this->assertStringContainsString(route('publisher.withdraw'), $html);
        $this->assertMatchesRegularExpression('/<a[^>]*id="withdrawCta"/', $html);
        $this->assertStringNotContainsString('You need at least €20.00 withdrawable balance', $html);
    }

    public function test_publisher_header_uses_earnings_not_spendable_and_keeps_advertiser_out_of_chip(): void
    {
        $user = $this->publisherWithWallets();

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('balance-label">Earnings', $html);
        $this->assertStringNotContainsString('balance-label">Spendable', $html);
        $this->assertMatchesRegularExpression('/class="balance-amount">€7\.64/', $html);
        $this->assertStringContainsString('Earnings €7.64', $html);
        $this->assertStringContainsString('Withdrawable €7.64', $html);
        $this->assertStringContainsString('Advertiser spendable €20.00', $html);
        $this->assertStringContainsString('nav-label">Balance', $html);
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/publisher\/balance" class="active"/',
            $html
        );
    }

    public function test_publisher_header_chip_uses_withdrawable_when_bonus_is_present(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 20,
            'publisher_bonus' => 12,
        ]);

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/class="balance-amount">€8\.00/', $html);
        $this->assertDoesNotMatchRegularExpression('/class="balance-amount">€20\.00/', $html);
        $this->assertStringContainsString('Earnings €8.00', $html);
        $this->assertStringContainsString('Withdrawable €8.00', $html);
    }

    public function test_on_hold_chip_renders_when_publisher_has_reserved_funds(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 10,
            'publisher_reserved' => 4.5,
        ]);

        $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->assertSee('On hold', false)
            ->assertSee('€4.50', false);
    }

    public function test_dual_role_publisher_can_open_add_funds_and_catalog_without_403(): void
    {
        $user = $this->publisherWithWallets();
        $this->assertSame('publisher', $user->activeRole());

        $this->actingAs($user)
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->assertSee('Add funds', false)
            ->assertSee('id="publisherRoleStrip"', false)
            ->assertSee('€7.64', false)
            ->assertSee(route('publisher.balance'), false)
            ->assertSee(route('publisher.withdraw'), false);

        $this->assertSame('advertiser', $user->fresh()->activeRole());

        $this->actingAs($user->fresh())
            ->get(route('advertiser.catalog'))
            ->assertOk();
    }

    public function test_publisher_balance_uses_role_name_ids_not_hardcoded_one_and_two(): void
    {
        $user = $this->publisherWithWallets();
        $publisherId = (int) Wallet::publisherRoleId();
        $advertiserId = (int) Wallet::advertiserRoleId();

        $this->assertGreaterThan(2, $publisherId);
        $this->assertGreaterThan(2, $advertiserId);

        $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->assertSee('€7.64', false);
    }

    public function test_publisher_role_transfer_endpoint_is_no_longer_gone(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 25]);

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 5])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('publisher.withdrawable', 20)
            ->assertJsonPath('advertiser.spendable', 25);
    }

    public function test_bonus_only_earnings_disable_the_move_form(): void
    {
        $user = $this->publisherWithWallets([
            'publisher_balance' => 20,
            'publisher_bonus' => 20,
        ]);

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]*id="roleMoveBtn"[^>]*disabled/', $html);
        $this->assertStringContainsString('No withdrawable earnings to move. Bonus credit cannot be moved.', $html);
    }

    public function test_role_move_script_uses_shared_confirm_and_http_helpers(): void
    {
        $js = file_get_contents(public_path('assets/js/publisher-balance.js'));

        $this->assertStringContainsString('window.slbConfirm', $js);
        $this->assertStringContainsString('slbHandleHttpError', $js);
        $this->assertStringNotContainsString('!moveBtn.disabled', $js);
        $this->assertStringNotContainsString('Swal.fire', $js);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.$])(?:window\.)?(alert|confirm)\s*\(/', $js);
    }

    public function test_favicon_partial_points_at_existing_public_assets(): void
    {
        $partial = file_get_contents(resource_path('views/components/favicon.blade.php'));
        $this->assertStringContainsString('assets/brand/web/favicon.svg', $partial);
        $this->assertStringContainsString('assets/img/favicon-32.png', $partial);
        $this->assertStringContainsString('assets/img/apple-touch-icon.png', $partial);
        $this->assertFileExists(public_path('assets/brand/web/favicon.svg'));
        $this->assertFileExists(public_path('assets/img/favicon-32.png'));
        $this->assertFileExists(public_path('assets/img/apple-touch-icon.png'));
        $this->assertFileExists(public_path('favicon.svg'));
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertFileExists(public_path('apple-touch-icon.png'));
    }

    public function test_balance_page_links_withdraw_billing_and_reports(): void
    {
        $user = $this->publisherWithWallets();

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('publisher.withdraw'), $html);
        $this->assertStringContainsString(route('publisher.billing.index'), $html);
        $this->assertStringContainsString(route('publisher.reports'), $html);
        $this->assertStringContainsString('Payout documents', $html);
        $this->assertStringNotContainsString('Pending payout', $html);
    }

    public function test_balance_shows_pending_payout_chip_for_publisher_wallet(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 40]);
        $wallet = Wallet::forPublisher((int) $user->id);

        Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'amount' => 25,
            'fee' => 0,
            'net_amount' => 25,
            'payment_method' => 'paypal',
            'payment_details' => ['email' => 'pay@example.com'],
            'status' => 'pending',
        ], Withdrawal::walletIdAttributes($wallet)));

        $html = $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Pending payout', $html);
        $this->assertStringContainsString('€25.00', $html);
        $this->assertStringContainsString('id="pendingPayoutChip"', $html);
    }

    public function test_balance_activity_lists_role_move_and_hides_advertiser_deposit(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 25]);
        $advertiserWallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('publisher.balance.transfer'), ['amount' => 5])
            ->assertOk()
            ->assertJsonPath('success', true);

        WalletTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $advertiserWallet->id,
            'type' => WalletTransaction::TYPE_DEPOSIT,
            'direction' => 'credit',
            'amount' => 50,
            'description' => 'Advertiser card deposit',
            'reference' => 'DEP-ADV',
        ]);

        $html = $this->actingAs($user->fresh())
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Recent activity', $html);
        $this->assertStringContainsString('Moved to advertiser wallet for spending', $html);
        $this->assertStringNotContainsString('Advertiser card deposit', $html);
        $this->assertStringNotContainsString('DEP-ADV', $html);
    }

    public function test_balance_activity_lists_pending_withdrawal(): void
    {
        $user = $this->publisherWithWallets(['publisher_balance' => 40]);

        $this->actingAs($user)
            ->postJson(route('publisher.withdraw.request'), [
                'amount' => 20,
                'payment_method' => 'paypal',
                'paypal_email' => 'pay@example.com',
                'paypal_email_confirm' => 'pay@example.com',
                'details_confirmed' => '1',
            ])
            ->assertOk();

        $html = $this->actingAs($user->fresh())
            ->get(route('publisher.balance'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Withdrawal request', $html);
        $this->assertMatchesRegularExpression('/WD-\d+/', $html);
    }

    public function test_publisher_only_balance_shows_empty_activity(): void
    {
        $user = $this->publisherWithWallets([], false);

        $this->actingAs($user)
            ->get(route('publisher.balance'))
            ->assertOk()
            ->assertSee('No wallet activity yet. Completed tasks pay out here.', false);
    }

    public function test_legacy_transfer_history_endpoint_is_gone(): void
    {
        $user = $this->publisherWithWallets();

        $this->actingAs($user)
            ->getJson(route('publisher.balance.history'))
            ->assertStatus(410)
            ->assertJsonPath('success', false);
    }
}
