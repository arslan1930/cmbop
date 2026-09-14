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
        $this->assertStringContainsString('// Partial / below-minimum while typing', $js);

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

    public function test_recent_activity_rows_keep_title_status_and_amount_apart(): void
    {
        $css = $this->addFundsCss();
        $blade = $this->addFundsView();

        $this->assertStringContainsString('.af-activity-heading', $css);
        $this->assertStringContainsString('.af-activity-doc', $css);
        $this->assertStringContainsString('grid-template-columns: 44px minmax(0, 1fr) minmax(7.5rem, auto)', $css);
        $this->assertStringContainsString('#walletHistory .card-footer', $css);
        $this->assertStringNotContainsString('font-weight: 650', $css);
        $this->assertStringContainsString('af-activity-heading', $blade);
        $this->assertStringContainsString('af-activity-doc', $blade);
        $this->assertStringNotContainsString('btn btn-sm btn-primary" href="${escapeHtml(row.invoice_download_url)}"', $blade);
        $this->assertStringNotContainsString("debit ? 'is-debit'", $blade);
        $this->assertStringContainsString('function activityIconClass', $blade);
        $this->assertStringContainsString('function statusLabel', $blade);
        $this->assertStringContainsString('statusLabel(row.status)', $blade);
        $this->assertStringContainsString("const closed = dead || status === 'refunded'", $blade);
        $this->assertStringContainsString('${closed ? \'is-closed\' : \'\'}', $blade);
        $this->assertStringContainsString('const detailSign = dead ? \'\' : (t.direction === \'debit\' ? \'− \' : (t.direction === \'credit\' ? \'+ \' : \'\'))', $blade);
        $this->assertStringContainsString('.wallet-type-icon.is-purchase', $css);
        $this->assertStringContainsString('.wallet-type-icon.is-refund', $css);
        $this->assertStringContainsString('.wallet-type-icon.is-closed', $css);
        $this->assertStringContainsString('.af-activity-item.is-closed .af-activity-amount', $css);
        $this->assertStringContainsString('.wallet-status--paid', $css);
        $this->assertStringContainsString('.wallet-status--refunded', $css);
        $this->assertStringContainsString('.wallet-status--failed', $css);
        $this->assertStringContainsString('pointer-events: none', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.wallet-status\s*\{[^}]*border-radius:\s*999px/',
            $css
        );
        $this->assertStringNotContainsString('.wallet-type-icon.is-debit', $css);
    }
}
