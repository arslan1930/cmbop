<?php

namespace Tests\Unit;

use App\Services\StripePaymentService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StripePaymentServiceTest extends TestCase
{
    public function test_to_cents_rounds_common_euro_amounts(): void
    {
        $this->assertSame(1999, StripePaymentService::toCents(19.99));
        $this->assertSame(1000, StripePaymentService::toCents(10));
        $this->assertSame(1, StripePaymentService::toCents(0.01));
        $this->assertSame(11500, StripePaymentService::toCents(115.00));
    }

    public function test_to_cents_avoids_float_truncation_bugs(): void
    {
        // Classic float pitfall: (int) (19.99 * 100) can become 1998
        $this->assertSame(1999, StripePaymentService::toCents(19.99));
        $this->assertSame(299, StripePaymentService::toCents(2.99));
    }

    public function test_from_cents_returns_two_decimal_euros(): void
    {
        $this->assertSame(19.99, StripePaymentService::fromCents(1999));
        $this->assertSame(10.0, StripePaymentService::fromCents(1000));
        $this->assertSame(0.0, StripePaymentService::fromCents(null));
    }

    public function test_to_cents_and_from_cents_round_trip(): void
    {
        foreach ([0.01, 1.0, 10.5, 19.99, 115.15, 999.99] as $amount) {
            $this->assertSame(
                round($amount, 2),
                StripePaymentService::fromCents(StripePaymentService::toCents($amount))
            );
        }
    }

    public function test_checkout_helper_urls_use_live_advertiser_routes(): void
    {
        $this->assertTrue(Route::has('advertiser.checkout.process'));
        $this->assertTrue(Route::has('advertiser.checkout'));
        $this->assertTrue(Route::has('advertiser.checkout.success'));
        $this->assertTrue(Route::has('advertiser.add-funds'));

        // Legacy names that previously 500'd if helpers were reused.
        $this->assertFalse(Route::has('checkout.stripe.success'));
        $this->assertFalse(Route::has('wallet.deposit.success'));
        $this->assertFalse(Route::has('wallet.deposit.cancel'));

        $ref = 'REF-STRIPE-HELPER-1';
        $orderSuccess = StripePaymentService::orderCheckoutSuccessUrl($ref);
        $orderCancel = StripePaymentService::orderCheckoutCancelUrl($ref);
        $walletSuccess = StripePaymentService::walletDepositSuccessUrl(25.5, $ref);
        $walletCancel = StripePaymentService::walletDepositCancelUrl();

        $this->assertStringContainsString('/advertiser/checkout/process', $orderSuccess);
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $orderSuccess);
        $this->assertStringContainsString('ref='.urlencode($ref), $orderSuccess);

        $this->assertStringContainsString('/advertiser/checkout', $orderCancel);
        $this->assertStringContainsString('canceled=1', $orderCancel);
        $this->assertStringContainsString('ref='.urlencode($ref), $orderCancel);

        $this->assertStringContainsString('/advertiser/checkout-success', $walletSuccess);
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $walletSuccess);
        $this->assertStringContainsString('amount=25.50', $walletSuccess);
        $this->assertStringContainsString('ref='.urlencode($ref), $walletSuccess);

        $this->assertStringContainsString('/advertiser/add-funds', $walletCancel);
        $this->assertStringContainsString('cancelled=1', $walletCancel);
    }
}
