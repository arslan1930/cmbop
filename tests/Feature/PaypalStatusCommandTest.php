<?php

namespace Tests\Feature;

use App\Support\UserMessages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaypalStatusCommandTest extends TestCase
{
    private function enablePaypal(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.webhook_id' => 'WH-TEST-1',
            'services.paypal.base_url' => null,
        ]);
    }

    public function test_status_reports_oauth_ok(): void
    {
        $this->enablePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $this->assertSame(0, Artisan::call('paypal:status'));
        $output = Artisan::output();
        $this->assertStringContainsString('sandbox', $output);
        $this->assertStringContainsString('api-m.sandbox.paypal.com', $output);
        $this->assertStringContainsString('OAuth: ok', $output);
        $this->assertStringNotContainsString('paypal-secret-test', $output);
    }

    public function test_status_reports_oauth_401_without_secret(): void
    {
        $this->enablePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
        ]);

        $this->assertSame(1, Artisan::call('paypal:status'));
        $output = Artisan::output();
        $this->assertStringContainsString(UserMessages::get('payment.paypal_auth'), $output);
        $this->assertStringContainsString('wrap the secret in single quotes', $output);
        $this->assertStringNotContainsString('paypal-secret-test', $output);
    }

    public function test_status_tells_you_when_keys_are_live_but_mode_is_sandbox(): void
    {
        $this->enablePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'error' => 'invalid_client',
            ], 401),
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_live',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $this->assertSame(1, Artisan::call('paypal:status'));
        $output = Artisan::output();
        $this->assertStringContainsString(UserMessages::get('payment.paypal_auth_live_keys'), $output);
        $this->assertStringContainsString('PAYPAL_MODE=live', $output);
        $this->assertStringNotContainsString('paypal-secret-test', $output);
    }

    public function test_status_fails_when_unconfigured(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => '',
            'services.paypal.secret' => '',
            'services.paypal.webhook_id' => '',
        ]);

        $this->assertSame(1, Artisan::call('paypal:status'));
        $this->assertStringContainsString('not configured', Artisan::output());
    }
}
