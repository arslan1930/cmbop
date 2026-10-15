<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Choose the role portal chrome from the active role, not from "has any role".
 * Dual-role users in publisher mode must stay in the publisher sidebar.
 */
class PortalShell
{
    public static function layout(?User $user = null): string
    {
        $role = self::activeRole($user);

        return match ($role) {
            'publisher' => 'publisher.layouts.app',
            'advertiser' => 'advertiser.layouts.app',
            'admin' => 'admin.layouts.app',
            'marketing' => 'marketing.layouts.app',
            default => 'layouts.app',
        };
    }

    public static function isPortal(?User $user = null): bool
    {
        return self::layout($user) !== 'layouts.app';
    }

    public static function styleStack(?User $user = null): string
    {
        return self::isPortal($user) ? 'page-styles' : 'styles';
    }

    public static function contentClass(?User $user = null): string
    {
        return self::isPortal($user) ? 'container-fluid' : 'container py-5';
    }

    public static function isPublisher(?User $user = null): bool
    {
        return self::activeRole($user) === 'publisher';
    }

    public static function activeRole(?User $user = null): ?string
    {
        $user ??= Auth::user();
        if (! $user || ! method_exists($user, 'activeRole')) {
            return null;
        }

        $role = strtolower(trim((string) $user->activeRole()));

        return $role !== '' ? $role : null;
    }
}
