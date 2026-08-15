<?php

namespace App\Support;

class PromotionUrl
{
    public static function isSafe(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return true;
        }

        if (str_starts_with($url, '/')) {
            return self::isSafeRelativePath($url);
        }

        return CampaignHtml::isSafeHttpUrl($url);
    }

    public static function normalizeForStorage(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! self::isSafe($url)) {
            return null;
        }

        return $url;
    }

    public static function href(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! self::isSafe($url)) {
            return null;
        }

        // Keep site paths relative so click redirects are not rebuilt from the
        // request host (trustProxies=* would honor X-Forwarded-Host).
        return $url;
    }

    /**
     * @return \Closure(string, mixed, \Closure): void
     */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! filled($value)) {
                return;
            }

            if (! self::isSafe(scalar_text($value))) {
                $fail('The :attribute must be an http(s) link or a site path like /advertiser/catalog.');
            }
        };
    }

    private static function isSafeRelativePath(string $url): bool
    {
        if (str_starts_with($url, '//')) {
            return false;
        }

        $decoded = self::decodeFully($url);
        if (str_starts_with($decoded, '//')) {
            return false;
        }

        $path = parse_url('http://local.invalid'.$decoded, PHP_URL_PATH);
        $path = is_string($path) ? $path : $decoded;

        if (str_contains($path, '\\') || str_contains($path, "\0") || str_contains($path, '..')) {
            return false;
        }

        return ! preg_match('/[\s<>"\']/', $decoded);
    }

    /**
     * Relative public-disk path safe to prefix with /storage/.
     * Rejects encoded traversal that a single rawurldecode would miss.
     */
    public static function safePublicStoragePath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', self::decodeFully($path));
        if ($normalized === ''
            || str_contains($normalized, '..')
            || str_starts_with($normalized, '/')
            || str_contains($normalized, "\0")
            || preg_match('/%(?:2e|2f|5c)/i', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private static function decodeFully(string $url): string
    {
        $current = str_replace('+', '%2B', $url);
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($current);
            if ($next === $current) {
                break;
            }
            $current = $next;
        }

        return $current;
    }
}
