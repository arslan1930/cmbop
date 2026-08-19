<?php

return [

    'login' => [
        'invalid' => 'Invalid email or password.',
        'throttled' => 'Too many login attempts. Please try again later.',
        'unverified' => 'Your email is not verified.',
        'success' => 'Login successful!',
    ],

    'register' => [
        'throttled' => 'Too many registration attempts. Please try again later.',
        'validation' => 'Please fix the highlighted fields and try again.',
        'unavailable' => 'Registration is temporarily unavailable. Please contact support.',
        'failed' => 'Something went wrong. Please try again.',
    ],

    'password' => [
        'throttled' => 'Too many attempts. Please try again later.',
        'reset_throttled' => 'Too many attempts. Try again later.',
        'reset_sent' => 'If an account with this email exists, a password reset link has been sent.',
        'reset_success' => 'Password has been reset successfully.',
        'reset_invalid' => 'Invalid token or email.',
    ],

    'session' => [
        'expired' => 'Your session expired. Refresh the page and try again.',
    ],

    'generic' => [
        'retry' => 'Something went wrong. Please try again.',
        'unavailable' => 'This action is temporarily unavailable. Please try again later.',
    ],

    'oauth' => [
        'unavailable' => 'Google sign-in is not available. Please use email and password.',
        'temporary' => 'Google sign-in is temporarily unavailable. Please try again or use email and password.',
        'cancelled' => 'Google sign-in was cancelled. You can try again or use email and password.',
        'failed' => 'Google sign-in failed. Please try again or use email and password.',
        'no_email' => 'Google did not share an email address. Please use another sign-in method.',
    ],

    'payment' => [
        'webhook_unavailable' => 'Webhook not configured',
        'webhook_signature' => 'Invalid signature',
        'webhook_event' => 'This payment update could not be read.',
        'webhook_failed' => 'Payment could not be recorded. Please try again later.',
        'leftover_credit_failed' => 'The leftover card credit could not be applied. Try again or contact support.',
        'leftover_credit_applied' => 'This leftover was paid using the card credit already in your wallet.',
        'paypal_auth' => 'PayPal rejected these credentials. Use sandbox keys with sandbox mode, or live keys with live mode.',
        'paypal_auth_live_keys' => 'These PayPal keys are live keys, but PAYPAL_MODE is sandbox. Set PAYPAL_MODE=live, then php artisan config:clear.',
        'paypal_auth_sandbox_keys' => 'These PayPal keys are sandbox keys, but PAYPAL_MODE is live. Set PAYPAL_MODE=sandbox, then php artisan config:clear.',
        'paypal_unavailable' => 'PayPal is temporarily unavailable. Please try again or use another payment method.',
    ],

    'cron' => [
        'disabled' => 'Scheduler is not configured.',
        'forbidden' => 'Forbidden.',
    ],

];
