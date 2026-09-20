<?php

use App\Support\LeftoverPublicI18nSlugs;

/**
 * Runs from Composer files autoload (and artisan / public/index.php) before
 * leftover bootstrap/app.php loads routes/web.php. Heals leftover PublicI18n.php
 * that is missing englishOnlyMarketingSlugs().
 *
 * Leftover europe-pl composer.json autoloads this filename. Keep it as well as
 * leftover_public_i18n_slugs_boot.php so mixed Hostinger files cannot 500 dump-autoload.
 */
$leftover = __DIR__.DIRECTORY_SEPARATOR.'LeftoverPublicI18nSlugs.php';
if (! is_file($leftover)) {
    return;
}

require_once $leftover;

if (class_exists(LeftoverPublicI18nSlugs::class)) {
    LeftoverPublicI18nSlugs::ensureEnglishOnlyMarketingSlugsMethod();
}
