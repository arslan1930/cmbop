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
        return 'Hi! How can we help with guest posts, wallet, or your sites?';
    }

    public static function statusLabel(): string
    {
        $provider = 'local';
        if (function_exists('config')) {
            $provider = strtolower(trim((string) config('services.support_chat.provider', 'local')));
        }

        return match ($provider) {
            'openai', 'http' => 'Usually replies in a few minutes',
            default => 'Usually replies by email',
        };
    }

    public static function supportRole(): string
    {
        if (! function_exists('auth') || ! auth()->check()) {
            return 'guest';
        }

        $roleUser = auth()->user();
        $roleName = '';
        if (is_object($roleUser) && method_exists($roleUser, 'activeRoleModel')) {
            $activeRole = $roleUser->activeRoleModel();
            $roleName = strtolower(trim((string) ($activeRole?->name ?? '')));
        }

        return in_array($roleName, ['advertiser', 'publisher'], true) ? $roleName : 'guest';
    }

    /**
     * @return list<string>
     */
    public static function questionsForRole(?string $role = null): array
    {
        $role = $role ?? self::supportRole();

        return match ($role) {
            'advertiser' => [
                'How do I place an order?',
                'How does the wallet work?',
                'Where do I track a live URL?',
            ],
            'publisher' => [
                'How do I add a website?',
                'When do payouts arrive?',
                'How do I accept an order?',
            ],
            default => [
                'How do I create an account?',
                'How does the marketplace work?',
                'What does a placement cost?',
            ],
        };
    }
}
