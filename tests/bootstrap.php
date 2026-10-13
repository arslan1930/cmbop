<?php

use Illuminate\Support\Env;

/**
 * PHPUnit bootstrap.
 *
 * Agent/Cloud shells often export APP_ENV=local and a leftover DB_DATABASE
 * file. PHPUnit <env force="true"> updates getenv()/$_ENV but not always
 * $_SERVER, and Laravel's Env repository reads $_SERVER first — so the app
 * boots as local, CSRF stays on (postJson 419), and leftover tests can drop
 * tables on the browser sqlite. Pin the isolated test values on all three.
 *
 * Laravel's LoadEnvironmentVariables still asks phpdotenv to read a file.
 * With APP_ENV=testing it prefers .env.testing; if that file is missing it
 * falls back to .env and file_get_contents() warns, which PHPUnit marks on
 * every test. Recreate a minimal .env.testing when it is absent (Cloud
 * snapshots and fresh checkouts without .env).
 */
// Dompdf invoice generation plus a long suite OOMs at the CLI default (512M).
if (function_exists('ini_set')) {
    ini_set('memory_limit', '1024M');
}

$base = dirname(__DIR__);
foreach ([
    'APP_ENV' => 'testing',
    'APP_URL' => 'http://127.0.0.1:8000',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'MAIL_QUEUE_AUTO_DRAIN' => 'false',
    'LOG_CHANNEL' => 'null',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$testingEnv = $base.DIRECTORY_SEPARATOR.'.env.testing';

if (! is_file($testingEnv)) {
    $written = file_put_contents($testingEnv, <<<'ENV'
# Loaded when APP_ENV=testing (phpunit.xml). Must exist so phpdotenv does
# not file_get_contents() a missing .env and mark every test as a warning.
# phpunit.xml <env> values win over this file via safeLoad.
APP_ENV=testing
APP_KEY=base64:OKLOoObWwo0Pl5b6d2wMbaqaly/aZLa0ngvaltxwC4A=
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=4
BROADCAST_CONNECTION=null
CACHE_STORE=array
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
DB_URL=
MAIL_MAILER=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
CRON_SECRET=
PULSE_ENABLED=false
TELESCOPE_ENABLED=false
NIGHTWATCH_ENABLED=false
SITE_SCREENSHOT_PROVIDER=none
LOG_CHANNEL=null
MEDIA_PATH=

ENV);

    if ($written === false) {
        fwrite(STDERR, "Unable to write {$testingEnv}; PHPUnit will warn on a missing .env.\n");
    }
}

require $base.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

if (class_exists(Env::class)) {
    Env::enablePutenv();
}
