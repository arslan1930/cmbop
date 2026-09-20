<?php

namespace App\Support;

/**
 * First-party visitor support chat (public + advertiser/publisher).
 * Order chat (advertiser ↔ publisher) is a separate product.
 */
class VisitorSupportChat
{
    public static function enabled(): bool
    {
        if (function_exists('config') && config()->has('services.support_chat.enabled')) {
            return (bool) config('services.support_chat.enabled');
        }

        // Leftover config/services.php has no support_chat key. Do not
        // assume first-party Blade/JS were uploaded; Tawk is the fallback.
        return filter_var(env('SUPPORT_CHAT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function companyName(): string
    {
        $name = trim((string) config('app.name', ''));

        return $name !== '' ? $name : 'SEOLinkBuildings';
    }

    public static function welcomeMessage(): string
    {
        return 'Hi! 👋 How can we help you today?';
    }
}
