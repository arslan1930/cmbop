<?php

namespace App\Support;

/**
 * Leftover local/Hostinger PublicI18n.php often lacks englishOnlyMarketingSlugs().
 * Leftover routes/web.php still calls that method at boot and 500s the whole site.
 *
 * When the on-disk class is missing the method, define a patched copy first so
 * Composer never loads the leftover file.
 */
final class LeftoverPublicI18nSlugs
{
    public static function ensureEnglishOnlyMarketingSlugsMethod(?string $sourceFile = null): void
    {
        try {
            if (class_exists(PublicI18n::class, false)
                && method_exists(PublicI18n::class, 'englishOnlyMarketingSlugs')) {
                return;
            }

            // Already loaded without the method — PHP cannot add it after the fact.
            if (class_exists(PublicI18n::class, false)) {
                return;
            }

            $sourceFile ??= __DIR__.DIRECTORY_SEPARATOR.'PublicI18n.php';
            if (! is_file($sourceFile)) {
                return;
            }

            $src = (string) file_get_contents($sourceFile);
            if ($src === '') {
                return;
            }

            if (self::sourceDefinesMethod($src)) {
                return;
            }

            $patched = self::injectMethodSource($src);
            if ($patched === null || $patched === $src) {
                return;
            }

            $helper = __DIR__.DIRECTORY_SEPARATOR.'EnglishOnlyMarketingSlugs.php';
            if (is_file($helper)) {
                require_once $helper;
            }

            $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR
                .'slb_public_i18n_'.md5($patched).'.php';
            if (! is_file($tmp) && file_put_contents($tmp, $patched) === false) {
                return;
            }

            require_once $tmp;
        } catch (\Throwable) {
        }
    }

    public static function sourceDefinesMethod(string $src): bool
    {
        return preg_match('/function\s+englishOnlyMarketingSlugs\s*\(/', $src) === 1;
    }

    public static function injectMethodSource(string $src): ?string
    {
        if (self::sourceDefinesMethod($src)) {
            return $src;
        }

        $method = <<<'PHP'

    public static function englishOnlyMarketingSlugs(): array
    {
        if (class_exists(\App\Support\EnglishOnlyMarketingSlugs::class)
            && method_exists(\App\Support\EnglishOnlyMarketingSlugs::class, 'all')) {
            try {
                return \App\Support\EnglishOnlyMarketingSlugs::all();
            } catch (\Throwable) {
            }
        }

        return ['guest-post-prices-europe'];
    }

PHP;

        $patched = preg_replace(
            '/class\s+PublicI18n(?:\s+extends\s+\S+)?(?:\s+implements\s+[^{]+)?\s*\{/',
            '$0'.$method,
            $src,
            1,
            $count
        );
        if (is_string($patched) && $count === 1) {
            return $patched;
        }

        $patched = preg_replace('/}\s*$/', $method."}\n", $src, 1, $count);
        if (! is_string($patched) || $count !== 1) {
            return null;
        }

        return $patched;
    }
}
