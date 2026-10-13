<?php

namespace App\Support;

use App\Models\BlogTranslation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;

/**
 * Thin locale copies are 200 for humans (language switcher, bookmarked
 * /us/about, /at/ueber-uns, /pt/sobre-nos) but must not claim to be
 * indexable translations. Google already picked the original URL.
 *
 * Rule: a prefixed locale page is indexable only when its body copy
 * differs from the parent locale (en, or de for AT/CH). No machine
 * translation — uniqueness is measured from the shipped messages files
 * and published blog translations. Money landers stay self-canonical.
 */
class LocaleIndexability
{
    /**
     * Locale whose copy this one falls back to when it has no unique page.
     */
    public static function parentLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return match ($locale) {
            'at', 'ch' => 'de',
            default => class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'default')
                ? PublicI18n::default()
                : 'en',
        };
    }

    /**
     * English path key (about, marketplace, '') for a public request.
     */
    public static function englishPath(Request $request): string
    {
        $path = '';
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'pathWithoutLocale')) {
            $path = PublicI18n::pathWithoutLocale($request);
        } else {
            $path = ltrim($request->path(), '/');
            $first = explode('/', $path, 2)[0] ?? '';
            if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isPrefixed') && PublicI18n::isPrefixed($first)) {
                $path = explode('/', $path, 2)[1] ?? '';
            }
        }

        if (class_exists(LocalizedPublicPath::class) && method_exists(LocalizedPublicPath::class, 'canonicalize')) {
            $path = LocalizedPublicPath::canonicalize($path);
        }

        return ltrim((string) $path, '/');
    }

    /**
     * @return list<string>
     */
    public static function indexableLocales(string $englishPath): array
    {
        $englishPath = ltrim($englishPath, '/');
        $supported = class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'supported')
            ? PublicI18n::supported()
            : ['en'];

        $out = [];
        foreach ($supported as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }
            if (self::isIndexable($locale, $englishPath)) {
                $out[] = $locale;
            }
        }

        if ($out === []) {
            $default = class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'default')
                ? PublicI18n::default()
                : 'en';

            return [$default];
        }

        return $out;
    }

    public static function isIndexable(string $locale, string $englishPath): bool
    {
        $locale = strtolower(trim($locale));
        $englishPath = ltrim($englishPath, '/');
        $default = class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'default')
            ? PublicI18n::default()
            : 'en';

        if ($locale === '' || ($locale === $default && ! str_starts_with($englishPath, 'blog/'))) {
            return true;
        }

        $first = $englishPath === '' ? '' : explode('/', $englishPath, 2)[0];

        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'englishOnlyMarketingSlugs')) {
            try {
                if ($first !== '' && in_array($first, PublicI18n::englishOnlyMarketingSlugs(), true)) {
                    return $locale === $default;
                }
            } catch (\Throwable) {
            }
        }

        if ($first !== '' && class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'moneyLanderLocales')) {
            try {
                $money = PublicI18n::moneyLanderLocales($first);
                if ($money !== []) {
                    return in_array($locale, $money, true);
                }
            } catch (\Throwable) {
            }
        }

        if (str_starts_with($englishPath, 'blog/')) {
            return self::localeHasPublishedBlogSlug($locale, substr($englishPath, 5));
        }

        if ($englishPath === 'blog') {
            return $locale === $default || self::localeHasPublishedBlog($locale);
        }

        if ($englishPath === 'newsletter') {
            return false;
        }

        return self::hasUniquePageCopy($locale, $englishPath);
    }

    public static function isThinDuplicate(Request $request): bool
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isPublicAuthEntryPath')) {
            if (PublicI18n::isPublicAuthEntryPath($request)) {
                return false;
            }
        }

        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isEnglishOnlyPath')) {
            if (PublicI18n::isEnglishOnlyPath($request)) {
                return false;
            }
        }

        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isPublicMarketingPath')) {
            if (! PublicI18n::isPublicMarketingPath($request)) {
                return false;
            }
        }

        $locale = app()->getLocale();
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'isSupported') && ! PublicI18n::isSupported($locale)) {
            return false;
        }

        return ! self::isIndexable($locale, self::englishPath($request));
    }

    /**
     * URL Google should index for this request. Thin copies point at the
     * parent locale (UK English, or /de/* for AT/CH German clones).
     */
    public static function canonicalUrl(Request $request): string
    {
        $path = self::englishPath($request);
        $locale = app()->getLocale();
        if (! self::isThinDuplicate($request)) {
            return class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'urlForLocale')
                ? PublicI18n::urlForLocale($path, $locale)
                : url($request->path() === '/' ? '/' : '/'.$request->path());
        }

        $parent = self::parentLocale($locale);

        return class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'urlForLocale')
            ? PublicI18n::urlForLocale($path, $parent)
            : url('/'.ltrim($path, '/'));
    }

    /**
     * Body/H1 key must differ from the parent locale. A translated nav or
     * meta-only overlay is not enough — that is the GSC duplicate.
     */
    public static function hasUniquePageCopy(string $locale, string $englishPath): bool
    {
        $parent = self::parentLocale($locale);
        if ($locale === $parent) {
            return true;
        }

        // /us is a UK-English clone (same language, drifted leftovers).
        // Do not treat a stale title as a regional variant.
        if ($locale === 'us') {
            return false;
        }

        $key = self::uniquenessKey($englishPath);
        if ($key === null) {
            return false;
        }

        // AT/CH overlays only list keys that differ from DE. Missing key
        // means the German (or English) parent body is reused.
        if (! Lang::has('messages.'.$key, $locale, false)) {
            return false;
        }

        $here = self::message($key, $locale);
        $there = self::message($key, $parent);

        if ($here === '' || $there === '' || $here === $there) {
            return false;
        }

        if (str_starts_with($here, 'messages.')) {
            return false;
        }

        return true;
    }

    private static function uniquenessKey(string $englishPath): ?string
    {
        return match (ltrim($englishPath, '/')) {
            '' => 'hero_support',
            'marketplace' => 'marketplace_title',
            'pricing' => 'pricing_page_title',
            'about' => 'about_title',
            'faq' => 'faq_title',
            'how-it-works' => 'how_page_title',
            'become-a-publisher' => 'become_publisher_title',
            'why-choose-us' => 'why_choose_title',
            'contact' => 'contact_hero_title',
            'privacy-policy' => 'privacy_policy',
            'terms-of-services' => 'terms_of_service',
            'cookie-policy' => 'cookie_policy',
            'refund-policy' => 'refund_policy',
            default => null,
        };
    }

    private static function message(string $key, string $locale): string
    {
        try {
            $value = trans('messages.'.$key, [], $locale);

            return is_string($value) ? trim($value) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function localeHasPublishedBlog(string $locale): bool
    {
        try {
            if (! class_exists(BlogTranslation::class) || ! Schema::hasTable('blog_translations')) {
                return false;
            }

            return BlogTranslation::query()
                ->where('locale', $locale)
                ->where('is_published', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function localeHasPublishedBlogSlug(string $locale, string $slug): bool
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return false;
        }

        try {
            if (! class_exists(BlogTranslation::class) || ! Schema::hasTable('blog_translations')) {
                return false;
            }

            return BlogTranslation::query()
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->where('is_published', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
