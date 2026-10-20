<?php

namespace Tests\Unit;

use App\Services\Partners\PartnerWebhookService;
use Tests\TestCase;

class PartnerWebhookSignatureTest extends TestCase
{
    public function test_signature_is_hmac_sha256_of_secret_and_timestamp_dot_body(): void
    {
        $secret = 'whsec_testsecret';
        $timestamp = '1710000000';
        $body = '{"type":"webhook.test","livemode":false}';
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertSame($expected, app(PartnerWebhookService::class)->sign($secret, $timestamp, $body));
        $this->assertSame($expected, 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret));
    }

    public function test_partner_api_docs_describe_webhook_timestamp_header_and_hmac(): void
    {
        $docs = (string) file_get_contents(base_path('docs/partner-dashboard.md'));
        $this->assertStringContainsString('SLB-Webhook-Timestamp', $docs);
        $this->assertStringContainsString("HMAC_SHA256(secret, timestamp + '.' + body)", $docs);
    }
}
