<?php

namespace App\Services\Api;

/**
 * Shared HMAC for partner and advertiser webhooks.
 * Header: SLB-Signature: v1=… over "{timestamp}.{raw body}".
 */
class WebhookSignature
{
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
