<?php

namespace App\Support;

/**
 * Public Tawk.to visitor chat. Order chat (advertiser ↔ publisher) is separate.
 */
class TawkChat
{
    public static function enabled(): bool
    {
        return self::embedSrc() !== null;
    }

    public static function embedSrc(): ?string
    {
        // Leftover config/services.php has no tawk key — still honor .env
        // so live Hostinger can embed without a leftover 500.
        $property = self::configOrEnv('services.tawk.property_id', 'TAWK_PROPERTY_ID');
        $widget = self::configOrEnv('services.tawk.widget_id', 'TAWK_WIDGET_ID');

        if ($property === '' || $widget === '') {
            return null;
        }

        if (! preg_match('/^[a-f0-9]{24}$/i', $property)) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $widget)) {
            return null;
        }

        return 'https://embed.tawk.to/'.$property.'/'.$widget;
    }

    private static function configOrEnv(string $configKey, string $envKey, string $default = ''): string
    {
        if (function_exists('config') && config()->has($configKey)) {
            return trim((string) config($configKey));
        }

        return trim((string) env($envKey, $default));
    }
}
