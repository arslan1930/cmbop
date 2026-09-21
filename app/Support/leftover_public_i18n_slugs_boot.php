<?php

use App\Support\LeftoverPublicI18nSlugs;

/**
 * Composer files autoload runs this before leftover LanguageHelper can
 * class_exists(PublicI18n). Patch leftover PublicI18n.php so leftover
 * routes/web.php can call englishOnlyMarketingSlugs() at route boot.
 */
$leftover = __DIR__.DIRECTORY_SEPARATOR.'LeftoverPublicI18nSlugs.php';
if (is_file($leftover)) {
    require_once $leftover;
    if (class_exists(LeftoverPublicI18nSlugs::class)) {
        LeftoverPublicI18nSlugs::ensureEnglishOnlyMarketingSlugsMethod();
    }
}
