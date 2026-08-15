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
            if (str_starts_with($url, '//')) {
                return false;
            }

            if (str_contains($url, '\\') || str_contains($url, "\0") || str_contains($url, '..')) {
                return false;
            }

            return ! preg_match('/[\s<>"\']/', $url);
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
}
