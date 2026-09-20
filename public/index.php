<?php

use App\Support\LeftoverPublicI18nSlugs;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

try {
    // Register the Composer autoloader...
    require __DIR__.'/../vendor/autoload.php';

    $leftoverPublicI18nSlugs = __DIR__.'/../app/Support/LeftoverPublicI18nSlugs.php';
    if (is_file($leftoverPublicI18nSlugs)) {
        require_once $leftoverPublicI18nSlugs;
        if (class_exists(LeftoverPublicI18nSlugs::class)) {
            LeftoverPublicI18nSlugs::ensureEnglishOnlyMarketingSlugsMethod();
        }
    }

    // Bootstrap Laravel and handle the request...
    /** @var Application $app */
    $app = require_once __DIR__.'/../bootstrap/app.php';

    $app->handleRequest(Request::capture());
} catch (Throwable $e) {
    // Hostinger was returning empty HTTP 500 bodies when boot failed before
    // Laravel's exception renderer registered. Always log; optionally expose.
    $logDir = __DIR__.'/../storage/logs';
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
