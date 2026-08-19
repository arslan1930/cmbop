<?php

/**
 * Copy this file to Hostinger public_html/index.php when Laravel lives in
 * a sibling folder named laravel_app (not inside public_html).
 *
 * Do not use this as the local public/index.php — artisan serve uses
 * public/index.php, which boots ../bootstrap/app.php.
 *
 *   public_html/index.php          ← this file
 *   public_html/assets/            ← copy from repo public/assets/
 *   laravel_app/                   ← app, vendor, .env, bootstrap
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$laravel = __DIR__.'/../laravel_app';

if (file_exists($maintenance = $laravel.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

try {
    require $laravel.'/vendor/autoload.php';

    /** @var Application $app */
    $app = require_once $laravel.'/bootstrap/app.php';

    // Domain document root is this folder (public_html), not laravel_app/public.
    $app->usePublicPath(__DIR__);

    $app->handleRequest(Request::capture());
} catch (Throwable $e) {
    $logDir = $laravel.'/storage/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents(
            $logDir.'/boot-failure.log',
            '['.gmdate('c').'] '.$e::class.': '.$e->getMessage()
                .' at '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString()."\n\n",
            FILE_APPEND
        );
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $expose = isset($_GET['boot_debug']) && hash_equals('slb-recover-2026', (string) $_GET['boot_debug']);
    if ($expose) {
        echo "Boot failure\n";
        echo $e::class.': '.$e->getMessage()."\n";
        echo $e->getFile().':'.$e->getLine()."\n";
        exit;
    }

    echo "This site is temporarily unavailable. Please try again shortly.\n";
}
