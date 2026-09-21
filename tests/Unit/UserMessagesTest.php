<?php

namespace Tests\Unit;

use App\Support\UserMessages;
use Tests\TestCase;

class UserMessagesTest extends TestCase
{
    public function test_catalog_sentences_are_everyday_english(): void
    {
        $keys = [
            'login.invalid',
            'login.throttled',
            'login.unverified',
            'login.suspended',
            'register.throttled',
            'register.unavailable',
            'password.throttled',
            'password.reset_sent',
            'password.reset_success',
            'password.reset_invalid',
            'session.expired',
            'generic.retry',
            'oauth.unavailable',
            'payment.webhook_failed',
            'payment.webhook_event',
            'payment.leftover_credit_failed',
            'payment.paypal_auth',
            'payment.paypal_unreachable',
            'payment.paypal_rejected',
            'payment.paypal_not_completed',
            'payment.order_failed',
            'payment.stripe_checkout_failed',
            'payment.stripe_not_configured',
            'payment.verification_failed',
            'payment.wallet_failed',
            'payment.cards_not_configured',
            'payment.pay_again_failed',
            'payment.paypal_refund_failed',
            'payment.paypal_return_url',
            'payment.stripe_schema_missing',
            'payment.bonus_reserve_failed',
            'payment.bonus_order_failed',
            'payment.place_order_failed',
            'payment.order_missing_after_pay',
            'payment.billing_fetch_failed',
            'payment.wallet_unavailable',
            'payment.feature_cards_not_configured',
            'moderation.quality',
            'moderation.quality_links',
            'moderation.quality_shortener',
            'moderation.contact_article',
            'cron.disabled',
            'cron.forbidden',
        ];

        foreach ($keys as $key) {
            $line = UserMessages::get($key);
            $this->assertNotSame('', $line, $key);
            $this->assertNotSame('errors.'.$key, $line, $key);
            $this->assertDoesNotMatchRegularExpression('/SQLSTATE|stack trace|Exception/i', $line);
        }
    }

    public function test_unknown_key_falls_back_without_leaking_the_key(): void
    {
        $this->assertSame(
            UserMessages::get('generic.retry'),
            UserMessages::get('this.key.does.not.exist')
        );
    }

    public function test_user_message_helper_is_leftover_safe(): void
    {
        $this->assertTrue(function_exists('user_message'));
        $this->assertSame(
            UserMessages::get('login.invalid'),
            user_message('login.invalid', 'Invalid email or password.')
        );
        $this->assertSame(
            UserMessages::get('generic.retry'),
            user_message('this.key.does.not.exist', UserMessages::get('generic.retry'))
        );
    }
}
