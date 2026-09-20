<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Brand ccTLDs are DNS aliases, not separate sites.
 * They 301 onto seolinkbuildings.com (path-prefix locales / English landers).
 */
final class CountryHost
{
    public const APEX = 'seolinkbuildings.com';

    /**
     * Bare hostname → public locale (en = unprefixed UK English).
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        $fallback = [
            'seolinkbuildings.de' => 'de',
            'seolinkbuildings.fr' => 'fr',
            'seolinkbuildings.it' => 'it',
            'seolinkbuildings.es' => 'es',
            'seolinkbuildings.nl' => 'nl',
            'seolinkbuildings.co.uk' => 'en',
            'seolinkbuildings.uk' => 'en',
            'seolinkbuildings.ch' => 'ch',
            'seolinkbuildings.at' => 'at',
            'seolinkbuildings.se' => 'se',
            'seolinkbuildings.no' => 'no',
            'seolinkbuildings.dk' => 'dk',
            'seolinkbuildings.hu' => 'hu',
            'seolinkbuildings.bg' => 'bg',
            'seolinkbuildings.gr' => 'gr',
            'seolinkbuildings.ro' => 'ro',
            'seolinkbuildings.pl' => 'pl',
        ];

        $fromConfig = (array) config('i18n.country_hosts', []);
        $merged = [];
        foreach (array_merge($fallback, $fromConfig) as $host => $locale) {
            if (! is_string($host) || ! is_string($locale)) {
                continue;
            }
            $host = strtolower(trim($host));
            $locale = strtolower(trim($locale));
            if ($host === '' || $locale === '') {
                continue;
            }
            $merged[$host] = $locale;
        }

        return $merged;
    }

    public static function localeForHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if ($host === '' || $host === self::APEX || $host === 'www.'.self::APEX) {
            return null;
        }

        $bare = preg_replace('/^www\./', '', $host) ?? $host;
        if ($bare === self::APEX) {
            return null;
        }

        $map = self::map();

        return $map[$bare] ?? $map[$host] ?? null;
    }

    /**
     * Absolute https://seolinkbuildings.com… target. Never points at the ccTLD.
     */
    public static function apexUrl(Request $request, string $locale): string
    {
        $path = $request->getPathInfo();
        if (! is_string($path) || $path === '') {
            $path = '/';
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $query = $request->getQueryString();
        $suffix = is_string($query) && $query !== '' ? '?'.$query : '';

        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        $first = $segments[0] ?? '';

        if ($first !== '' && class_exists(PublicI18n::class)) {
            try {
                if (method_exists(PublicI18n::class, 'isEnglishOnlyPath')
                    && PublicI18n::isEnglishOnlyPath(Request::create('/'.$first, 'GET'))) {
                    return 'https://'.self::APEX.$path.$suffix;
                }
                if (method_exists(PublicI18n::class, 'isEnglishOnlyMarketingPath')
                    && PublicI18n::isEnglishOnlyMarketingPath(Request::create($path, 'GET'))) {
                    return 'https://'.self::APEX.$path.$suffix;
                }
                if (method_exists(PublicI18n::class, 'isPrefixed') && PublicI18n::isPrefixed($first)) {
                    return 'https://'.self::APEX.$path.$suffix;
                }
            } catch (\Throwable) {
            }
        }

        $prefixed = class_exists(PublicI18n::class)
            && method_exists(PublicI18n::class, 'isPrefixed')
            && PublicI18n::isPrefixed($locale);

        if (! $prefixed) {
            return 'https://'.self::APEX.($path === '/' ? '/' : $path).$suffix;
        }

        $relative = ltrim($path, '/');
        if (class_exists(LocalizedPublicPath::class) && method_exists(LocalizedPublicPath::class, 'localize')) {
            try {
                $relative = LocalizedPublicPath::localize($relative, $locale);
            } catch (\Throwable) {
            }
        }

        $targetPath = $relative === '' ? '/'.$locale : '/'.$locale.'/'.$relative;

        return 'https://'.self::APEX.$targetPath.$suffix;
    }
}
