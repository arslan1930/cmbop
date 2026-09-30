<?php

namespace Tests\Feature;

use App\Support\UserMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EverydayErrorCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_json_uses_the_error_catalog(): void
    {
        $this->postJson(route('login.post'), [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', UserMessages::get('login.invalid'));
    }

    public function test_stripe_webhook_not_configured_uses_catalog(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $this->postJson('/api/stripe/webhook', [])
            ->assertStatus(503)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_unavailable'));
    }

    public function test_stripe_webhook_bad_signature_uses_catalog(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_copy']);

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't=1,v1=bad',
            ],
            '{}'
        )
            ->assertStatus(400)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_signature'));
    }

    public function test_stripe_webhook_failure_uses_catalog_not_exception_text(): void
    {
        $secret = 'whsec_test_copy';
        config(['services.stripe.webhook_secret' => $secret]);
        $payload = 'not-json';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't='.$timestamp.',v1='.$signature,
            ],
            $payload
        )
            ->assertStatus(500)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_failed'))
            ->assertJsonMissingPath('exception')
            ->assertDontSee('Invalid payload');
    }

    public function test_paypal_webhook_unreadable_event_uses_catalog(): void
    {
        $this->enablePaypalForCopyTests();

        $this->postPaypalWebhook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])
            ->assertStatus(400)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_event'));
    }

    public function test_http_cron_disabled_uses_catalog(): void
    {
        config(['app.cron_secret' => 'short']);

        $this->postJson('/cron/run')
            ->assertNotFound()
            ->assertJsonPath('message', UserMessages::get('cron.disabled'))
            ->assertDontSee('CRON_SECRET')
            ->assertDontSee('short');
    }

    public function test_http_cron_wrong_key_uses_catalog(): void
    {
        $secret = str_repeat('s', 40);
        $wrong = str_repeat('x', 40);
        config(['app.cron_secret' => $secret]);

        $this->withHeaders(['X-Cron-Key' => $wrong])
            ->postJson('/cron/run')
            ->assertForbidden()
            ->assertJsonPath('message', UserMessages::get('cron.forbidden'))
            ->assertDontSee('CRON_SECRET')
            ->assertDontSee($secret);
    }

    public function test_paypal_webhook_failure_uses_catalog_not_exception_text(): void
    {
        $this->enablePaypalForCopyTests();

        $this->postPaypalWebhook([
            'id' => 'WH-EMPTY',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [],
        ])
            ->assertStatus(500)
            ->assertJsonPath('error', UserMessages::get('payment.webhook_failed'))
            ->assertJsonMissingPath('exception')
            ->assertDontSee('PayPal webhook did not include');
    }

    private function enablePaypalForCopyTests(): void
    {
        config([
            'services.paypal.enabled' => true,
            'services.paypal.mode' => 'sandbox',
            'services.paypal.client_id' => 'paypal-client-test',
            'services.paypal.secret' => 'paypal-secret-test',
            'services.paypal.webhook_id' => 'WH-TEST-COPY',
            'services.paypal.base_url' => null,
        ]);

        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'tok_test',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
            '*/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postPaypalWebhook(array $event): TestResponse
    {
        return $this->call(
            'POST',
            '/api/paypal/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
                'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-1',
                'HTTP_PAYPAL_TRANSMISSION_ID' => 'tx-copy',
                'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
                'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-08-18T12:00:00Z',
            ],
            json_encode($event, JSON_THROW_ON_ERROR)
        );
    }
}
