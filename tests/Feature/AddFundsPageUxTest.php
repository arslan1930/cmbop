<?php

namespace Tests\Feature;

use App\Models\DepositRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\DepositPaymentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFundsPageUxTest extends TestCase
{
    use RefreshDatabase;

    private function advertiser(): User
    {
        $role = Role::firstOrCreate(['name' => 'advertiser']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'active_role_id' => $role->id,
            'billing_name' => 'Jane Advertiser',
            'company_name' => 'Acme SEO Ltd',
            'country' => 'DE',
            'city' => 'Berlin',
            'address' => 'Main Street 1',
        ]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user->fresh();
    }

    private function addFundsHtml(?User $user = null): string
    {
        return $this->actingAs($user ?? $this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk()
            ->getContent();
    }

    public function test_add_funds_page_renders_200_for_advertiser(): void
    {
        $this->actingAs($this->advertiser())
            ->get(route('advertiser.add-funds'))
            ->assertOk();
    }

    public function test_deposit_max_defaults_to_50000_and_rejects_above(): void
    {
        $this->assertSame(10.0, DepositPaymentConfig::depositMin());
        $this->assertSame(50000.0, DepositPaymentConfig::depositMax());
        $this->assertSame('required|numeric|min:10|max:50000', DepositPaymentConfig::amountRule());

        $user = $this->advertiser();

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50001,
                'payment_method' => 'bank',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->actingAs($user)
            ->postJson(route('advertiser.add-funds.store'), [
                'amount' => 50000,
                'payment_method' => 'bank',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_page_exposes_max_and_inline_amount_error(): void
    {
        $html = $this->addFundsHtml();
        $js = (string) file_get_contents(public_path('assets/js/add-funds.js'));

        $this->assertStringContainsString('depositMax:', $html);
        $this->assertStringContainsString('id="amountError"', $html);
        $this->assertStringContainsString('function formatEuro', $js);
        $this->assertStringContainsString('window.slbFormatMoney', $js);
        $this->assertStringContainsString('function showAmountError', $js);
        $this->assertStringContainsString('Maximum amount is', $js);
        $this->assertStringContainsString("addEventListener('keydown'", $js);
        $this->assertStringContainsString("addEventListener('paste'", $js);
        $this->assertStringContainsString('sanitizeAmountInput', $js);
        $this->assertStringContainsString("['e', 'E', '+', '-', 'Subtract', 'Add']", $js);
        $this->assertStringContainsString('this.value = local.toFixed(2)', $js);
        $this->assertStringContainsString('function amountPayload', $js);
        $this->assertStringContainsString('amount_currency', $js);
        $this->assertStringContainsString('function setSelectedLocal', $js);
    }

    public function test_blur_keeps_field_and_does_not_open_modal_under_minimum(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/add-funds.js'));

        preg_match(
            "/customAmountInput\.addEventListener\('blur',\s*function\s*\(\)\s*\{(.*?)\n\s*\}\);/s",
            $js,
            $blur
        );
        $this->assertNotEmpty($blur[1] ?? null);
        $this->assertStringNotContainsString('Swal.fire', $blur[1]);
        $this->assertStringNotContainsString("this.value = ''", $blur[1]);
        $this->assertStringContainsString('showAmountError', $blur[1]);
        $this->assertStringContainsString('Minimum amount is', $blur[1]);
    }

    public function test_reference_sentence_and_row_stay_hidden_until_invoice(): void
    {
        $html = $this->addFundsHtml();
        $js = (string) file_get_contents(public_path('assets/js/add-funds.js'));

        $this->assertStringContainsString('js-manual-ref-alert d-none', $html);
        $this->assertStringContainsString('id="referenceRow"', $html);
        $this->assertMatchesRegularExpression('/d-none"[^>]*id="referenceRow"|id="referenceRow"[^>]*d-none/', $html);
        $this->assertStringContainsString("referenceRow.classList.toggle('d-none', !referenceCode)", $js);
        $this->assertStringContainsString("el.classList.toggle('d-none', !referenceCode)", $js);
    }

    public function test_bank_accounts_label_eur_vs_gbp(): void
    {
        $html = $this->addFundsHtml();

        $this->assertStringContainsString('EUR account', $html);
        $this->assertStringContainsString('SEPA payers need only the IBAN.', $html);
        $this->assertStringContainsString('Swift/BIC: use when sending from outside SEPA', $html);
        $this->assertSame(1, substr_count($html, 'SEPA payers need only the IBAN.'));
        $this->assertStringNotContainsString('GBP account — send pounds from a UK bank', $html);
        $this->assertStringNotContainsString('Use this GBP account when sending pounds from a UK bank', $html);
        $this->assertStringNotContainsString('GBP · From the UK', $html);
        $this->assertStringNotContainsString('Phone no:', $html);
        $this->assertStringNotContainsString('23-08-01', $html);
        $this->assertStringNotContainsString('32932194', $html);

        $gbp = $this->advertiser();
        $gbp->country = 'GB';
        $gbp->display_currency = 'GBP';
        $gbp->save();
        $gbpHtml = $this->addFundsHtml($gbp);
        $this->assertStringContainsString('GBP account — send pounds from a UK bank', $gbpHtml);
        $this->assertStringContainsString('Use this GBP account when sending pounds from a UK bank', $gbpHtml);
        $this->assertStringContainsString('Teqno Ltd (partners SEOLinkBuildings)', $gbpHtml);
        $this->assertStringNotContainsString('BE40 9059 9538 0863', $gbpHtml);
        $this->assertStringNotContainsString('TRWIBEB1XXX', $gbpHtml);
    }

    public function test_recently_used_ignores_pending_invoices(): void
    {
        $user = $this->advertiser();
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'PEND111',
            'amount' => 40,
            'payment_method' => 'wise',
            'status' => 'pending',
        ]);
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'DONE222',
            'amount' => 50,
            'payment_method' => 'bank',
            'status' => 'completed',
        ]);

        $html = $this->addFundsHtml($user);

        $this->assertStringContainsString('lastUsedMethod: "bank"', $html);
        $this->assertStringNotContainsString('lastUsedMethod: "wise"', $html);
        $this->assertStringContainsString('Recently used', $html);
    }

    public function test_add_funds_shows_teqno_ltd_operator_wording(): void
    {
        config([
            'billing.deposit_payment.seller_name' => 'Teqno LTD',
            'billing.deposit_payment.beneficiary' => 'Teqno LTD',
        ]);
        $html = $this->addFundsHtml();

        $this->assertStringContainsString('Teqno Ltd (partners SEOLinkBuildings)', $html);
        $this->assertStringNotContainsString('SEOLinkBuildings Partners with (Teqno LTD)', $html);
        $this->assertStringNotContainsString('Teqno LTD (operator of SEOLinkBuildings)', $html);
    }

    public function test_chat_and_mobile_layout_hooks(): void
    {
        $html = $this->addFundsHtml();
        $css = (string) file_get_contents(public_path('assets/css/add-funds.css'));
        $shell = (string) file_get_contents(public_path('assets/css/app-shell.css'));

        $this->assertStringContainsString('af-actions-safe', $html);
        $this->assertStringContainsString('id="afStickyPay"', $html);
        $this->assertStringContainsString('id="afMobileSummary"', $html);
        $this->assertStringContainsString('d-none d-lg-block', $html);
        $this->assertStringContainsString('.af-sticky-pay', $css);
        $this->assertStringContainsString('padding-bottom: 8.5rem', $css);
        $this->assertStringContainsString('body.role-shell-advertiser #toggleSidebar', $shell);
    }

    public function test_method_tiles_are_two_by_two_with_equal_icons(): void
    {
        $html = $this->addFundsHtml();
        $css = (string) file_get_contents(public_path('assets/css/add-funds.css'));

        $this->assertStringContainsString('col-12 col-sm-6', $html);
        $this->assertStringNotContainsString('col-xl-4', $html);
        $this->assertStringNotContainsString('payment-option-icon--paypal', $html);
        $this->assertStringContainsString('.payment-option-icon img', $css);
        $this->assertStringContainsString('width: 32px', $css);
    }

    public function test_fee_lines_are_per_method_and_do_not_invent_numbers(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/add-funds.js'));

        $this->assertStringContainsString('Your bank may charge transfer fees', $js);
        $this->assertStringContainsString('SEPA usually 0–2 business days', $js);
        $this->assertStringContainsString('Wise shows its own transfer fee in the Wise app', $js);
        $this->assertStringContainsString('Network fees are yours', $js);
        $this->assertStringContainsString('No extra deposit fee — we cover PayPal processing', $js);
        $this->assertStringNotContainsString('wise: \'SEPA usually 0–2 business days', $js);
        $this->assertDoesNotMatchRegularExpression('/wise:.*\d+(\.\d+)?\s*%/', $js);
        $this->assertDoesNotMatchRegularExpression('/crypto:.*\d+(\.\d+)?\s*%/', $js);
    }

    public function test_invoice_and_withdraw_labels_are_unified(): void
    {
        $user = $this->advertiser();
        DepositRequest::create([
            'user_id' => $user->id,
            'reference_code' => 'LAB333',
            'amount' => 25,
            'payment_method' => 'bank',
            'status' => 'pending',
        ]);

        $html = $this->addFundsHtml($user);

        $this->assertStringContainsString('Download invoice', $html);
        $this->assertStringContainsString('I paid', $html);
        $this->assertStringNotContainsString('>Mark paid<', $html);
        $this->assertStringContainsString('Withdraw wallet', $html);
        $this->assertStringContainsString('id="withdrawOpenBtn"', $html);
        $this->assertStringNotContainsString('Download Invoice', $html);
        $view = (string) file_get_contents(resource_path('views/advertiser/add-funds.blade.php'));
        $this->assertStringContainsString('Withdraw earnings', $view);
    }

    public function test_unlabeled_fields_have_for_and_autocomplete(): void
    {
        $html = $this->addFundsHtml();
        $view = (string) file_get_contents(resource_path('views/advertiser/add-funds.blade.php'));

        $this->assertStringContainsString('for="customAmount"', $html);
        $this->assertStringContainsString('autocomplete="transaction-amount"', $html);
        $this->assertStringContainsString('for="historyType"', $html);
        $this->assertStringContainsString('for="historyStatus"', $html);
        $this->assertStringContainsString('for="historyFrom"', $html);
        $this->assertStringContainsString('for="historyTo"', $html);
        $this->assertStringContainsString('for="billing_name"', $html);
        $this->assertStringContainsString('autocomplete="name"', $html);
        $this->assertStringContainsString('for="company_name"', $html);
        $this->assertStringContainsString('for="country"', $html);
        $this->assertStringContainsString('autocomplete="country"', $html);
        $this->assertStringContainsString('for="state"', $html);
        $this->assertStringContainsString('for="city"', $html);
        $this->assertStringContainsString('for="postal_code"', $html);
        $this->assertStringContainsString('for="address"', $html);
        $this->assertStringContainsString('for="vat_number"', $html);
        $this->assertStringContainsString('for="withdrawAmount"', $view);
        $this->assertStringContainsString('for="withdrawMethod"', $view);
        $this->assertStringContainsString('for="withdrawBusinessName"', $view);
        $this->assertStringContainsString('autocomplete="organization"', $view);
        $this->assertStringContainsString('for="withdrawBankName"', $view);
        $this->assertStringContainsString('for="withdrawPaypalEmail"', $view);
        $this->assertStringContainsString('for="withdrawWiseEmail"', $view);
        $this->assertStringContainsString('for="withdrawWallet"', $view);
    }
}
