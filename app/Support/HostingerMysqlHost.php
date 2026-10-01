<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Hostinger shared MySQL often refuses TCP to 127.0.0.1
 * (SQLSTATE[HY000] [2002] Operation not permitted). PHP's "localhost"
 * uses the unix socket instead. Must run at provider boot — session
 * middleware queries MySQL before the web heal can rewrite .env.
 */
final class HostingerMysqlHost
{
    public static function preferredHost(string $current, bool $onHostinger): ?string
    {
        if (! $onHostinger || $current !== '127.0.0.1') {
            return null;
        }

        return 'localhost';
    }

    public static function applyRuntime(?bool $onHostinger = null): bool
    {
        $onHostinger ??= HostingerMediaPath::looksLikeHostinger();
        $changed = false;

        foreach (['mysql', 'mariadb'] as $name) {
            if (! is_array(config('database.connections.'.$name))) {
                continue;
            }

            $current = (string) config('database.connections.'.$name.'.host');
            $next = self::preferredHost($current, $onHostinger);
            if ($next === null) {
                continue;
            }

            config(['database.connections.'.$name.'.host' => $next]);
            try {
                DB::purge($name);
            } catch (\Throwable) {
                // No live PDO yet.
            }
            $changed = true;
        }

        return $changed;
    }
}
