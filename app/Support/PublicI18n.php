<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class PublicI18n
{
    public static function supported(): array
    {
        return config('i18n.supported', ['en', 'de', 'fr', 'nl']);
    }

    public static function prefixed(): array
    {
        return config('i18n.prefixed', ['de', 'fr', 'nl']);
    }

    public static function default(): string
    {
        return config('i18n.default', 'en');
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    public static function isPrefixed(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::prefixed(), true);
    }

    /**
     * @return array{0: ?string, 1: list<string>}
     */
    public static function splitPath(Request $request): array
    {
        $segments = $request->segments();
        $locale = null;

        if (! empty($segments) && self::isPrefixed($segments[0])) {
            $locale = $segments[0];
            array_shift($segments);
        }

        return [$locale, array_values($segments)];
    }

    public static function pathWithoutLocale(Request $request): string
    {
        [, $segments] = self::splitPath($request);

        return implode('/', $segments);
    }

    public static function firstPathSegment(Request $request): string
    {
        [, $segments] = self::splitPath($request);

        return $segments[0] ?? '';
    }

    public static function isEnglishOnlyPath(Request $request): bool
    {
        $first = self::firstPathSegment($request);
        if ($first === '') {
            return false;
        }

        foreach (config('i18n.english_only_paths', []) as $prefix) {
            if ($first === $prefix) {
                return true;
            }
        }

        return false;
    }

    public static function isPublicMarketingPath(Request $request): bool
    {
        if (self::isEnglishOnlyPath($request)) {
            return false;
        }

        $first = self::firstPathSegment($request);
        $public = array_values(array_filter(config('i18n.public_paths', [])));

        // Home
        if ($first === '') {
            return true;
        }

        return in_array($first, $public, true);
    }

    public static function shouldShowLanguageSwitcher(Request $request): bool
    {
        return self::isPublicMarketingPath($request);
    }

    public static function urlForLocale(string $path, ?string $locale = null): string
    {
        $locale = $locale ?? App::getLocale();
        $path = ltrim((string) $path, '/');

        if (! self::isSupported($locale) || $locale === self::default()) {
            return $path === '' ? url('/') : url($path);
        }

        return $path === '' ? url($locale) : url($locale.'/'.$path);
    }

    public static function switchUrl(Request $request, string $targetLocale): string
    {
        $path = self::pathWithoutLocale($request);

        if (self::isEnglishOnlyPath($request)) {
            return self::urlForLocale('', $targetLocale);
        }

        return self::urlForLocale($path, $targetLocale);
    }

    /**
     * @return list<array{hreflang: string, href: string}>
     */
    public static function hreflangTags(Request $request): array
    {
        if (! self::isPublicMarketingPath($request)) {
            return [];
        }

        $path = self::pathWithoutLocale($request);
        $tags = [];

        foreach (self::supported() as $locale) {
            $tags[] = [
                'hreflang' => $locale,
                'href' => self::urlForLocale($path, $locale),
            ];
        }

        $tags[] = [
            'hreflang' => 'x-default',
            'href' => self::urlForLocale($path, self::default()),
        ];

        return $tags;
    }

    public static function preferredFromBrowser(Request $request): ?string
    {
        $preferred = $request->getPreferredLanguage(self::supported());

        return self::isSupported($preferred) ? $preferred : null;
    }

    public static function rememberedPublicLocale(Request $request): string
    {
        $cookie = $request->cookie(config('i18n.cookie', 'public_locale'));

        return self::isSupported($cookie) ? $cookie : self::default();
    }
}
