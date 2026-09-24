<?php

namespace App\Support;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\Marketing\GuestPostPriceIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

class PublicI18n
{
    /**
     * Country landers and the Europe price index stay English-only.
     * Prefixed locales 301 these slugs onto the unprefixed English URL.
     * Kept near the class open so leftover File Manager truncations still keep it.
     *
     * @return list<string>
     */
    public static function englishOnlyMarketingSlugs(): array
    {
        if (class_exists(EnglishOnlyMarketingSlugs::class) && method_exists(EnglishOnlyMarketingSlugs::class, 'all')) {
            try {
                $fromHelper = EnglishOnlyMarketingSlugs::all();
                if (is_array($fromHelper) && $fromHelper !== []) {
                    return array_values(array_unique(array_filter(
                        $fromHelper,
                        static fn ($slug) => is_string($slug) && trim($slug) !== ''
                    )));
                }
            } catch (\Throwable) {
            }
        }

        $slugs = ['guest-post-prices-europe'];

        try {
            if (
                class_exists(GuestPostPriceIndex::class)
                && defined(GuestPostPriceIndex::class.'::SLUG')
            ) {
                $indexSlug = trim((string) GuestPostPriceIndex::SLUG);
                if ($indexSlug !== '') {
                    $slugs = [$indexSlug];
                }
            }
        } catch (\Throwable) {
        }

        try {
            if (class_exists(CountryLander::class) && method_exists(CountryLander::class, 'slugs')) {
                foreach (CountryLander::slugs() as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug !== '') {
                        $slugs[] = $slug;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($slugs));
    }

    public static function supported(): array
    {
        return self::configuredLocales('supported', [
            'en', 'de', 'fr', 'nl', 'es', 'it', 'pt', 'us',
            'at', 'ch', 'ro', 'gr', 'dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl',
        ]);
    }

    public static function prefixed(): array
    {
        return self::configuredLocales('prefixed', [
            'de', 'fr', 'nl', 'es', 'it', 'pt', 'us',
            'at', 'ch', 'ro', 'gr', 'dk', 'se', 'no', 'bg', 'hu', 'ee', 'pl',
        ]);
    }

    public static function prefixedPattern(): string
    {
        return implode('|', self::prefixed());
    }

    public static function supportedPattern(): string
    {
        return implode('|', self::supported());
    }

    /**
     * Union config + hardcoded fallback so leftover Hostinger i18n.php
     * with a short list cannot hide locales that this deploy still ships.
     *
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private static function configuredLocales(string $key, array $fallback): array
    {
        $fromConfig = (array) config('i18n.'.$key, []);
        $merged = [];
        foreach (array_merge($fallback, $fromConfig) as $code) {
            if (! is_string($code)) {
                continue;
            }
            $code = strtolower(trim($code));
            if ($code === '' || isset($merged[$code])) {
                continue;
            }
            $merged[$code] = $code;
        }

        return array_values($merged);
    }

    /**
     * BCP 47 tag for hreflang / html lang (en = UK, us = US).
     */
    public static function hreflang(string $locale): string
    {
        return match ($locale) {
            'en' => 'en-GB',
            'us' => 'en-US',
            'at' => 'de-AT',
            'ch' => 'de-CH',
            'gr' => 'el-GR',
            'dk' => 'da-DK',
            'se' => 'sv-SE',
            'no' => 'nb-NO',
            'ee' => 'et-EE',
            'pl' => 'pl-PL',
            'pt' => 'pt-PT',
            default => $locale,
        };
    }

    public static function htmlLang(?string $locale = null): string
    {
        return self::hreflang($locale ?? App::getLocale());
    }

    /**
     * Open Graph locale (underscore form).
     */
    public static function ogLocale(?string $locale = null): string
    {
        $locale = $locale ?? App::getLocale();

        return match ($locale) {
            'en' => 'en_GB',
            'us' => 'en_US',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'nl' => 'nl_NL',
            'es' => 'es_ES',
            'pt' => 'pt_PT',
            'it' => 'it_IT',
            'at' => 'de_AT',
            'ch' => 'de_CH',
            'ro' => 'ro_RO',
            'gr' => 'el_GR',
            'dk' => 'da_DK',
            'se' => 'sv_SE',
            'no' => 'nb_NO',
            'bg' => 'bg_BG',
            'hu' => 'hu_HU',
            'ee' => 'et_EE',
            'pl' => 'pl_PL',
            default => $locale.'_'.strtoupper($locale),
        };
    }

    /**
     * Laravel messages locale. AT/CH reuse German copy.
     */
    public static function messagesFallback(string $locale): ?string
    {
        return match ($locale) {
            'at', 'ch' => 'de',
            default => null,
        };
    }

    /**
     * Homepage catalog teasers for this public locale.
     *
     * @return list<string>
     */
    public static function catalogTeaserCountries(string $locale): array
    {
        return match ($locale) {
            'at' => ['at'],
            'ch' => ['ch'],
            'ro' => ['ro'],
            'gr' => ['gr'],
            'dk' => ['dk'],
            'se' => ['se'],
            'no' => ['no'],
            'bg' => ['bg'],
            'hu' => ['hu'],
            'ee' => ['ee'],
            'pl' => ['pl'],
            'it' => ['it'],
            'pt' => ['pt'],
            default => ['de'],
        };
    }

    /**
     * Locales that own an indexable money-lander URL for this slug.
     *
     * @return list<string>
     */
    public static function moneyLanderLocales(string $slug): array
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return [];
        }

        $locales = [];
        if (class_exists(ItalianMoneyLanders::class) && ItalianMoneyLanders::isSlug($slug)) {
            $locales[] = 'it';
        }
        if (class_exists(GermanMoneyLanders::class) && GermanMoneyLanders::isSlug($slug)) {
            $locales[] = 'de';
        }
        if (class_exists(AustrianMoneyLanders::class) && AustrianMoneyLanders::isSlug($slug)) {
            $locales[] = 'at';
        }
        if (class_exists(PortugueseMoneyLanders::class) && PortugueseMoneyLanders::isSlug($slug)) {
            $locales[] = 'pt';
        }

        return $locales;
    }

    public static function moneyLanderXDefault(string $slug): string
    {
        $locales = self::moneyLanderLocales($slug);
        if (in_array('it', $locales, true)) {
            return 'it';
        }
        if (in_array('de', $locales, true)) {
            return 'de';
        }
        if (in_array('at', $locales, true)) {
            return 'at';
        }
        if (in_array('pt', $locales, true)) {
            return 'pt';
        }

        return self::default();
    }

    /**
     * @return array<string, string>
     */
    public static function moneyLanderPathByLocale(string $slug): array
    {
        $paths = [];
        foreach (self::moneyLanderLocales($slug) as $locale) {
            $paths[$locale] = trim($slug, '/');
        }

        return $paths;
    }

    public static function moneyLanderUrl(string $slug, string $locale): ?string
    {
        $slug = trim($slug, '/');
        $locale = strtolower(trim($locale));
        if ($slug === '' || ! in_array($locale, self::moneyLanderLocales($slug), true)) {
            return null;
        }

        return url('/'.$locale.'/'.$slug);
    }

    /**
     * Map an Accept-Language / BCP 47 tag onto a supported public locale.
     */
    public static function fromBrowserTag(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim($tag)));
        if ($normalized === '') {
            return null;
        }

        $aliases = [
            'en-us' => 'us',
            'en-gb' => 'en',
            'en-uk' => 'en',
            'eng' => 'en',
            'uk' => 'en',
            'es-es' => 'es',
            'es-mx' => 'es',
            'es-ar' => 'es',
            'es-co' => 'es',
            'es-cl' => 'es',
            'it-it' => 'it',
            'de-at' => 'at',
            'de-ch' => 'ch',
            'de-de' => 'de',
            'el' => 'gr',
            'el-gr' => 'gr',
            'da' => 'dk',
            'da-dk' => 'dk',
            'sv' => 'se',
            'sv-se' => 'se',
            'nb' => 'no',
            'nb-no' => 'no',
            'nn' => 'no',
            'nn-no' => 'no',
            'no-no' => 'no',
            'et' => 'ee',
            'et-ee' => 'ee',
            'ro-ro' => 'ro',
            'bg-bg' => 'bg',
            'hu-hu' => 'hu',
            'pl' => 'pl',
            'pl-pl' => 'pl',
            'pol' => 'pl',
            'pt' => 'pt',
            'pt-pt' => 'pt',
            'por' => 'pt',
        ];

        if (isset($aliases[$normalized]) && self::isSupported($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        if (self::isSupported($normalized)) {
            return $normalized;
        }

        $base = explode('-', $normalized)[0];
        if ($base === 'en' && self::isSupported('en')) {
            return 'en';
        }

        if (isset($aliases[$base]) && self::isSupported($aliases[$base])) {
            return $aliases[$base];
        }

        return self::isSupported($base) ? $base : null;
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

    /**
     * Country landers / Europe price index: English URL only, not a locale cluster.
     */
    public static function isEnglishOnlyMarketingPath(Request $request): bool
    {
        $first = self::firstPathSegment($request);
        if ($first === '') {
            return false;
        }

        try {
            if (method_exists(self::class, 'englishOnlyMarketingSlugs')) {
                return in_array($first, self::englishOnlyMarketingSlugs(), true);
            }
        } catch (\Throwable) {
            return $first === 'guest-post-prices-europe';
        }

        return $first === 'guest-post-prices-europe';
    }

    /**
     * Guest auth entry points (English-only; not a translated marketing cluster).
     */
    public static function isPublicAuthEntryPath(Request $request): bool
    {
        return in_array(self::firstPathSegment($request), [
            'login',
            'register',
            'forgot-password',
            'reset-password',
            'email',
            'auth',
        ], true);
    }

    /**
     * Blog page 2+ is a listing chrome duplicate — keep crawlable via rel=next/prev,
     * but do not index or attach a full hreflang cluster.
     */
    public static function isPaginatedBlogIndex(Request $request): bool
    {
        $page = (int) $request->query('page', 1);
        if ($page <= 1) {
            return false;
        }

        $path = self::pathWithoutLocale($request);
        if (class_exists(LocalizedPublicPath::class)) {
            $path = LocalizedPublicPath::canonicalize($path);
        }

        return $path === 'blog';
    }

    public static function robotsContent(Request $request): string
    {
        if (self::isPublicAuthEntryPath($request)) {
            return 'noindex, nofollow';
        }

        // Paginated blog indexes share the same title/description as page 1.
        // Posts are already in the sitemap; page 2+ should not join the hreflang cluster.
        if (method_exists(self::class, 'isPaginatedBlogIndex') && self::isPaginatedBlogIndex($request)) {
            return 'noindex, follow';
        }

        return 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    }

    public static function isPublicMarketingPath(Request $request): bool
    {
        if (self::isEnglishOnlyPath($request)) {
            return false;
        }

        $first = self::firstPathSegment($request);
        $public = class_exists(LocalizedPublicPath::class)
            ? LocalizedPublicPath::allFirstSegments()
            : array_values(array_filter(
                config('i18n.public_paths', []),
                fn ($path) => is_string($path) && $path !== ''
            ));

        // Home
        if ($first === '') {
            return true;
        }

        if (class_exists(ItalianMoneyLanders::class) && ItalianMoneyLanders::isPublicSegment($first)) {
            return true;
        }

        if (class_exists(GermanMoneyLanders::class) && GermanMoneyLanders::isPublicSegment($first)) {
            return true;
        }

        if (class_exists(AustrianMoneyLanders::class) && AustrianMoneyLanders::isPublicSegment($first)) {
            return true;
        }

        if (class_exists(PortugueseMoneyLanders::class) && PortugueseMoneyLanders::isPublicSegment($first)) {
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
        if (class_exists(LocalizedPublicPath::class)) {
            $path = LocalizedPublicPath::localize($path, $locale);
        }

        if (! self::isSupported($locale) || $locale === self::default()) {
            return $path === '' ? url('/') : url($path);
        }

        return $path === '' ? url($locale) : url($locale.'/'.$path);
    }

    public static function switchUrl(Request $request, string $targetLocale): string
    {
        $path = self::pathWithoutLocale($request);
        if (class_exists(LocalizedPublicPath::class)) {
            $path = LocalizedPublicPath::canonicalize($path);
        }

        if (self::isEnglishOnlyPath($request)
            || (method_exists(self::class, 'isEnglishOnlyMarketingPath') && self::isEnglishOnlyMarketingPath($request))) {
            if (method_exists(self::class, 'isEnglishOnlyMarketingPath')
                && self::isEnglishOnlyMarketingPath($request)
                && $targetLocale === self::default()) {
                return $path === '' ? url('/') : url($path);
            }

            return self::urlForLocale('', $targetLocale);
        }

        $first = $path === '' ? '' : explode('/', $path, 2)[0];
        $moneyLocales = method_exists(self::class, 'moneyLanderLocales')
            ? self::moneyLanderLocales($first)
            : [];
        if ($moneyLocales !== []) {
            if (method_exists(self::class, 'moneyLanderUrl')) {
                $moneyUrl = self::moneyLanderUrl($first, $targetLocale);
                if (is_string($moneyUrl) && $moneyUrl !== '') {
                    return $moneyUrl;
                }
            }

            return self::urlForLocale('', $targetLocale);
        }
        $italianSlug = class_exists(ItalianMoneyLanders::class) && ItalianMoneyLanders::isSlug($first);
        $germanSlug = class_exists(GermanMoneyLanders::class) && GermanMoneyLanders::isSlug($first);
        if ($italianSlug && $germanSlug) {
            if ($targetLocale === 'it') {
                return url('/it/'.$first);
            }
            if ($targetLocale === 'de') {
                return url('/de/'.$first);
            }

            return self::urlForLocale('', $targetLocale);
        }
        if ($italianSlug) {
            if ($targetLocale === 'it') {
                return url('/it/'.$first);
            }

            return self::urlForLocale('', $targetLocale);
        }
        if ($germanSlug) {
            if ($targetLocale === 'de') {
                return url('/de/'.$first);
            }

            return self::urlForLocale('', $targetLocale);
        }

        if (preg_match('#^blog/([^/]+)$#', $path, $matches) === 1) {
            $path = self::blogPathForLocale($matches[1], $targetLocale);
        }

        return self::urlForLocale($path, $targetLocale);
    }

    /**
     * Swap /blog/{slug} to the published slug for $targetLocale when we know the post.
     */
    public static function blogPathForLocale(string $slug, string $targetLocale): string
    {
        try {
            if (! Schema::hasTable('blog_translations')) {
                return 'blog/'.$slug;
            }

            $hit = BlogTranslation::query()
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();

            $blog = null;
            if ($hit) {
                $blog = Blog::published()
                    ->with(['translations' => function ($query) {
                        $query->where('is_published', true);
                    }])
                    ->where('id', $hit->blog_id)
                    ->first();
            }

            if (! $blog) {
                $blog = Blog::published()
                    ->with(['translations' => function ($query) {
                        $query->where('is_published', true);
                    }])
                    ->where('slug', $slug)
                    ->first();
            }

            if (! $blog) {
                return 'blog';
            }

            $display = $blog->translationFor($targetLocale, null);
            if ($display && filled($display->slug)) {
                return 'blog/'.$display->slug;
            }

            return 'blog';
        } catch (\Throwable) {
            return 'blog/'.$slug;
        }
    }

    /**
     * @return list<array{hreflang: string, href: string}>
     */
    public static function hreflangTags(
        Request $request,
        ?string $xDefaultLocale = null,
        ?array $locales = null,
        ?string $pathOverride = null,
        ?array $pathByLocale = null
    ): array {
        if (! self::isPublicMarketingPath($request)) {
            if (! self::isPublicAuthEntryPath($request)) {
                return [];
            }

            $authPath = ltrim(self::pathWithoutLocale($request), '/');
            $href = url('/'.$authPath);

            return [
                ['hreflang' => self::hreflang(self::default()), 'href' => $href],
                ['hreflang' => 'x-default', 'href' => $href],
            ];
        }

        $path = $pathOverride !== null ? ltrim($pathOverride, '/') : self::pathWithoutLocale($request);
        if (class_exists(LocalizedPublicPath::class)) {
            $path = LocalizedPublicPath::canonicalize($path);
        }

        // noindex paginated listings must not emit a cluster that points at page 1.
        if (method_exists(self::class, 'isPaginatedBlogIndex')
            && self::isPaginatedBlogIndex($request)
            && ($path === 'blog' || str_ends_with($path, '/blog'))) {
            return [];
        }

        $first = $path === '' ? '' : explode('/', $path, 2)[0];
        if ($locales === null && $first !== ''
            && method_exists(self::class, 'englishOnlyMarketingSlugs')
            && in_array($first, self::englishOnlyMarketingSlugs(), true)) {
            $locales = [self::default()];
            $xDefaultLocale = self::default();
        }
        if ($locales === null && $first !== ''
            && method_exists(self::class, 'moneyLanderLocales')) {
            $moneyLocales = self::moneyLanderLocales($first);
            if ($moneyLocales !== []) {
                $locales = $moneyLocales;
                $xDefaultLocale = method_exists(self::class, 'moneyLanderXDefault')
                    ? self::moneyLanderXDefault($first)
                    : ($moneyLocales[0] ?? self::default());
            }
        }
        if ($locales === null && $first !== ''
            && class_exists(ItalianMoneyLanders::class)
            && ItalianMoneyLanders::isSlug($first)
            && class_exists(GermanMoneyLanders::class)
            && GermanMoneyLanders::isSlug($first)) {
            $locales = ['it', 'de'];
            $xDefaultLocale = 'it';
        } elseif ($locales === null && $first !== ''
            && class_exists(ItalianMoneyLanders::class)
            && ItalianMoneyLanders::isSlug($first)) {
            $locales = ['it'];
            $xDefaultLocale = 'it';
        } elseif ($locales === null && $first !== ''
            && class_exists(GermanMoneyLanders::class)
            && GermanMoneyLanders::isSlug($first)) {
            $locales = ['de'];
            $xDefaultLocale = 'de';
        }

        $tags = [];
        $targetLocales = $locales ?: self::supported();
        $targetLocales = array_values(array_filter($targetLocales, fn ($locale) => self::isSupported($locale)));

        if ($targetLocales === []) {
            $targetLocales = self::supported();
        }

        foreach ($targetLocales as $locale) {
            $fallbackPath = class_exists(LocalizedPublicPath::class)
                ? LocalizedPublicPath::localize($path, $locale)
                : $path;
            $localePath = ltrim((string) ($pathByLocale[$locale] ?? $fallbackPath), '/');
            $tags[] = [
                'hreflang' => self::hreflang($locale),
                'href' => self::urlForLocale($localePath, $locale),
            ];
        }

        $xDefault = self::isSupported($xDefaultLocale) ? $xDefaultLocale : self::default();
        $xDefaultFallback = class_exists(LocalizedPublicPath::class)
            ? LocalizedPublicPath::localize($path, $xDefault)
            : $path;
        $xDefaultPath = ltrim((string) ($pathByLocale[$xDefault] ?? $xDefaultFallback), '/');

        $tags[] = [
            'hreflang' => 'x-default',
            'href' => self::urlForLocale($xDefaultPath, $xDefault),
        ];

        return $tags;
    }

    /**
     * Short admin / fallback label (en → UK).
     */
    public static function shortLabel(string $locale): string
    {
        return $locale === 'en' ? 'UK' : strtoupper($locale);
    }

    public static function preferredFromBrowser(Request $request): ?string
    {
        foreach ($request->getLanguages() as $tag) {
            $mapped = self::fromBrowserTag($tag);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    public static function rememberedPublicLocale(Request $request): string
    {
        $cookie = $request->cookie(config('i18n.cookie', 'public_locale'));

        return self::isSupported($cookie) ? $cookie : self::default();
    }
}
