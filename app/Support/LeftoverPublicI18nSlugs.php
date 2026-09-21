<?php

namespace App\Support;

/**
 * Leftover local/Hostinger PublicI18n.php often lacks englishOnlyMarketingSlugs().
 * Leftover routes/web.php still calls that method at boot and 500s the whole site.
 *
 * When the on-disk class is missing the method, define a patched copy first so
 * Composer never loads the leftover file. Also persist the method onto disk
 * when writable so leftover artisan/web.php keep working on the next request.
 */
final class LeftoverPublicI18nSlugs
{
    public static function ensureEnglishOnlyMarketingSlugsMethod(?string $sourceFile = null): void
    {
        try {
            $sourceFile ??= __DIR__.DIRECTORY_SEPARATOR.'PublicI18n.php';
            self::persistMissingMethod($sourceFile);

            if (class_exists(PublicI18n::class, false)
                && method_exists(PublicI18n::class, 'englishOnlyMarketingSlugs')) {
                return;
            }

            // Already loaded without the method — PHP cannot add it after the fact.
            // persistMissingMethod() rewrote the leftover file for the next request.
            if (class_exists(PublicI18n::class, false)) {
                return;
            }

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

            // Leftover local PublicI18n.php keeps 500ing artisan at web.php:201
            // until the method exists on disk. Write it back when possible.
            if (is_writable($sourceFile)) {
                @file_put_contents($sourceFile, $patched);
            }

            // Already loaded without the method — PHP cannot add it this request.
            if (class_exists(PublicI18n::class, false)) {
                return;
            }

            $helper = __DIR__.DIRECTORY_SEPARATOR.'EnglishOnlyMarketingSlugs.php';
            if (is_file($helper)) {
                require_once $helper;
            }

            $loadPath = $sourceFile;
            $onDisk = (string) @file_get_contents($sourceFile);
            if (! self::sourceDefinesMethod($onDisk)) {
                $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                    .DIRECTORY_SEPARATOR
                    .'slb_public_i18n_'.md5($patched).'.php';
                if (! is_file($tmp) && file_put_contents($tmp, $patched) === false) {
                    return;
                }
                $loadPath = $tmp;
            }

            require_once $loadPath;
        } catch (\Throwable) {
        }
    }

    /**
     * Write englishOnlyMarketingSlugs() onto leftover PublicI18n.php.
     * Returns true when the file was changed.
     */
    public static function persistMissingMethod(string $sourceFile): bool
    {
        try {
            if (! is_file($sourceFile) || ! is_readable($sourceFile)) {
                return false;
            }

            $src = (string) file_get_contents($sourceFile);
            if ($src === '' || self::sourceDefinesMethod($src)) {
                return false;
            }

            $patched = self::injectMethodSource($src);
            if ($patched === null || $patched === $src) {
                return false;
            }

            if (file_put_contents($sourceFile, $patched) === false) {
                return false;
            }

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($sourceFile, true);
            }

            return true;
        } catch (\Throwable) {
            return false;
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
