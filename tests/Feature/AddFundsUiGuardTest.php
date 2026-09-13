<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Source guards for Add Funds UX bugs that are easy to reintroduce in Blade/JS.
 */
class AddFundsUiGuardTest extends TestCase
{
    private function addFundsView(): string
    {
        return (string) file_get_contents(resource_path('views/advertiser/add-funds.blade.php'));
    }

    private function addFundsJs(): string
    {
        return (string) file_get_contents(public_path('assets/js/add-funds.js'));
    }

    private function addFundsCss(): string
    {
        return (string) file_get_contents(public_path('assets/css/add-funds.css'));
    }

    public function test_custom_amount_does_not_alert_and_clear_while_typing_below_minimum(): void
    {
        $js = $this->addFundsJs();

        // The old handler fired Swal + cleared the field on every keystroke when
        // parseFloat(value) < 10, so typing "100" died on the first "1".
        $this->assertStringContainsString("addEventListener('blur'", $js);
        $this->assertStringContainsString('// Partial / below-minimum', $js);

        preg_match(
            "/customAmountInput\.addEventListener\('input',\s*function\s*\(\)\s*\{(.*?)\n\s*\}\);/s",
            $js,
            $inputHandler
        );
        $this->assertNotEmpty($inputHandler[1] ?? null, 'Expected a customAmount input listener.');

        $body = $inputHandler[1];
        $this->assertStringNotContainsString('Swal.fire', $body);
        $this->assertStringNotContainsString("this.value = ''", $body);
        $this->assertStringContainsString('amount >= 10', $body);
        $this->assertStringContainsString('amount <= maxDeposit', $body);
    }

    public function test_billing_modal_client_validation_requires_company_name(): void
    {
        $js = $this->addFundsJs();

        $this->assertStringContainsString('formData.company_name', $js);
        $this->assertMatchesRegularExpression(
            "/!String\(formData\.company_name \|\| ''\)\.trim\(\)/",
            $js,
            'Client billing validation must require company_name like the server.'
        );
    }

    public function test_no_third_party_wise_qr_cdn_and_same_origin_endpoint(): void
    {
        $view = $this->addFundsView();
        $js = $this->addFundsJs();

        $this->assertStringNotContainsString('api.qrserver.com', $view);
        $this->assertStringNotContainsString('api.qrserver.com', $js);
        $this->assertStringContainsString("route('advertiser.add-funds.wise-qr', absolute: false)", $view);
        $this->assertStringContainsString('wiseQr:', $view);
        $this->assertStringContainsString('function syncWiseQr', $js);
        $this->assertStringContainsString('AddFundsBoot', $view);
        $this->assertStringContainsString('createPaypal', $view);
        $this->assertStringContainsString('data-method="{{ $methodKey }}"', $view);
        $this->assertStringContainsString("'paypal'", $view);
        $this->assertStringContainsString('payment-card-brands', $view);
        $this->assertStringContainsString('Recently used', $view);
        $this->assertStringContainsString('add_funds.paypal', $view);
        $this->assertStringContainsString('$methodReady && ! empty($meta[\'new_key\'])', $view);
        $this->assertStringContainsString(':badge-key=', $view);
        $this->assertStringNotContainsString(':key="$meta[\'new_key\']"', $view);
        $this->assertStringContainsString('lastUsedMethod', $view);
        $this->assertStringNotContainsString('fab fa-stripe', $view);
        $this->assertStringNotContainsString('PayPal coming soon', $view);
        $this->assertStringContainsString("prefillMethod === 'card' && !stripeReady", $js);
        $this->assertStringContainsString("prefillMethod === 'paypal' && !paypalReady", $js);
        $this->assertStringContainsString("opt.getAttribute('aria-disabled') === 'true'", $js);
        $this->assertStringContainsString('assets/js/add-funds.js', $view);
        $this->assertStringContainsString('assets/css/add-funds.css', $view);
        $this->assertStringNotContainsString('#9333ea', $view);
        $this->assertStringNotContainsString('balance.blade.php', $view);
        $this->assertStringNotContainsString('XXXXXXXX', $view);
        $this->assertStringContainsString('invoiceReadyBar', $view);
        $this->assertStringContainsString('data-invoice-ready="0"', $view);
        $this->assertStringContainsString('Your transfer reference is created with the invoice', $view);
        $this->assertStringContainsString('function applyInvoice', $js);
        $this->assertStringContainsString('function applyCoverCheckoutAmount', $js);
        $this->assertStringContainsString('coverCheckoutBtn', $js);
        $this->assertStringContainsString('checkoutNeeded', $view);
        $this->assertStringContainsString('checkoutShortfallBanner', $view);
        $this->assertStringContainsString('invoiceReadyCancel', $view);
        $this->assertStringContainsString('cancel-deposit-btn', $view);
        $this->assertStringContainsString('function cancelDepositInvoice', $js);
        $this->assertStringContainsString('add-funds.cancel', $view);
        $this->assertStringContainsString('AddFundsCheckout::query', (string) file_get_contents(resource_path('views/advertiser/checkout.blade.php')));
        $this->assertStringContainsString('function stampServerReference', $js);
        $this->assertStringContainsString('if (!invoiceLocked)', $js);
        $this->assertStringNotContainsString('function updateReferenceCode', $js);
        $this->assertStringNotContainsString('function generateReferenceCode', $js);
        $this->assertDoesNotMatchRegularExpression(
            '/payment option click[\s\S]{0,400}syncWiseQr/i',
            $js
        );
        $this->assertStringContainsString('payments/usdt.svg', $view);
        $this->assertStringNotContainsString('fa-bitcoin', $view);
        $this->assertStringContainsString('function copyPayText', $js);
        $this->assertStringContainsString('document.execCommand(\'copy\')', $js);
        $this->assertStringContainsString('afCopyStatus', $view);
        $this->assertStringContainsString('data-copy=', $view);
        $this->assertStringContainsString('aria-labelledby="billingInfoModalLabel"', $view);
        $this->assertStringContainsString('for="company_name"', $view);
        $this->assertStringContainsString('autocomplete="organization"', $view);
        $this->assertStringNotContainsString('State/Province <span class="text-danger">*</span>', $view);
        $this->assertStringContainsString('€10–€100,000', $view);
        $this->assertStringContainsString('max="100000"', $view);
        $this->assertStringContainsString('aria-pressed="false"', $view);
        $this->assertStringContainsString("e.key !== 'Enter' && e.key !== ' '", $js);
        $this->assertStringContainsString('function selectPaymentOption', $js);
        $this->assertStringContainsString('Maximum amount is €100,000.', $js);
        $this->assertStringNotContainsString('new Chart(', $view);
        $this->assertStringNotContainsString('walletChart', $view);
        $this->assertStringNotContainsString('crosshairPlugin', $view);
        $this->assertStringNotContainsString('continueAddFundsBtn', $view);
    }

    public function test_recently_used_is_quiet_corner_text_not_a_brand_pill(): void
    {
        $css = $this->addFundsCss();

        $this->assertStringContainsString('.payment-option-recent', $css);
        $this->assertStringContainsString('position: absolute', $css);
        $this->assertStringContainsString('top: 8px', $css);
        $this->assertStringContainsString('left: 8px', $css);
        $this->assertStringContainsString('color: #9ca3af', $css);
        $this->assertStringContainsString('background: transparent', $css);
        $this->assertStringNotContainsString('#c8ebe9', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.payment-option-recent\s*\{[^}]*border-radius:\s*999px/',
            $css
        );
    }
}
