<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\CuratedBlogSync;
use App\Services\Marketing\GuestPostPriceIndex;
use App\Support\AustrianMoneyLanders;
use App\Support\CountryLander;
use App\Support\DutchMoneyLanders;
use App\Support\FrenchMoneyLanders;
use App\Support\GermanMoneyLanders;
use App\Support\ItalianMoneyLanders;
use App\Support\LocalizedPublicPath;
use App\Support\MoneyLanderCatalog;
use App\Support\PortugueseMoneyLanders;
use App\Support\PublicI18n;
use App\Support\RomanianMoneyLanders;
use App\Support\SpanishMoneyLanders;
use App\Support\SwissMoneyLanders;
use App\Support\ThinBlogRedirects;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /** @return list<array{path: string, changefreq: string, priority: string}> */
    private function staticPages(): array
    {
        $pages = [
            ['path' => '', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['path' => 'marketplace', 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['path' => 'pricing', 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['path' => 'how-it-works', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['path' => 'why-choose-us', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['path' => 'become-a-publisher', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['path' => 'about', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['path' => 'faq', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['path' => 'contact', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['path' => 'blog', 'changefreq' => 'weekly', 'priority' => '0.7'],
            ['path' => 'privacy-policy', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['path' => 'terms-of-services', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['path' => 'cookie-policy', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['path' => 'refund-policy', 'changefreq' => 'yearly', 'priority' => '0.3'],
        ];

        if (! class_exists(LocalizedPublicPath::class) || ! method_exists(LocalizedPublicPath::class, 'map')) {
            return $pages;
        }

        $known = [];
        foreach ($pages as $page) {
            $known[$page['path']] = true;
        }

        foreach (LocalizedPublicPath::map() as $localeMap) {
            if (! is_array($localeMap)) {
                continue;
            }
            foreach (array_keys($localeMap) as $path) {
                if (! is_string($path) || $path === '' || $path === 'newsletter' || isset($known[$path])) {
                    continue;
                }
                $pages[] = ['path' => $path, 'changefreq' => 'monthly', 'priority' => '0.6'];
                $known[$path] = true;
            }
        }

        return $pages;
    }

    public function index(): Response
    {
        // Production APP_URL is sometimes still loopback. Child locs must
        // use the public origin or GSC cannot fetch locale sitemaps.
        $base = rtrim(app_public_url(), '/');
        $sitemaps = [];

        foreach ($this->supportedLocales() as $locale) {
            $sitemaps[] = [
                'loc' => $base.'/sitemap-'.$locale.'.xml',
            ];
        }

        $xml = view('sitemap-index', compact('sitemaps'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    public function locale(string $locale): Response
    {
        abort_unless(in_array($locale, $this->supportedLocales(), true), 404);

        // Locale sitemaps join blog_translations — heal skipped migrations.
        CuratedBlogSync::ensurePresent();

        $base = rtrim(app_public_url(), '/');
        $urls = [];

        foreach ($this->staticPages() as $page) {
            $urls[] = $this->urlEntry($page['path'], $locale, $page['changefreq'], $page['priority']);
        }

        $moneyClass = $this->moneyLanderClasses()[$locale] ?? null;
        if (is_string($moneyClass) && class_exists($moneyClass) && method_exists($moneyClass, 'slugs')) {
            try {
                $moneySlugs = $moneyClass::slugs();
            } catch (\Throwable) {
                $moneySlugs = [];
            }
            foreach ($moneySlugs as $slug) {
                if (! is_string($slug) || $slug === '') {
                    continue;
                }
                [$locales, $paths] = $this->moneyLanderSitemapCluster($slug, [$locale]);
                $entry = $this->urlEntry($slug, $locale, 'weekly', '0.85', $locales, $paths);
                $urls[] = $this->withMoneyLanderXDefault($entry, $slug, $paths);
            }
        }

        // Auth stays noindex — do not list login/register in sitemaps.
        if ($locale === $this->defaultLocale()) {
            if (class_exists(CountryLander::class)) {
                foreach (CountryLander::slugs() as $landerPath) {
                    $urls[] = $this->urlEntry($landerPath, $locale, 'weekly', '0.8', ['en'], ['en' => $landerPath]);
                }
            }

            if (class_exists(GuestPostPriceIndex::class)) {
                $priceIndex = GuestPostPriceIndex::SLUG;
                $urls[] = $this->urlEntry($priceIndex, $locale, 'weekly', '0.8', ['en'], ['en' => $priceIndex]);
            }
        }

        $translations = collect();
        try {
            $published = Blog::published();
            if (method_exists(Blog::class, 'scopeWithoutLegacyRedirects')) {
                $published = $published->withoutLegacyRedirects();
            }

            $query = BlogTranslation::query()
                ->select('blog_translations.*')
                ->join('blogs', 'blogs.id', '=', 'blog_translations.blog_id')
                ->whereIn('blogs.id', $published->select('blogs.id'))
                ->where('blog_translations.locale', $locale)
                ->where('blog_translations.is_published', true);

            if (class_exists(ThinBlogRedirects::class) && method_exists(ThinBlogRedirects::class, 'legacySlugs')) {
                $legacy = ThinBlogRedirects::legacySlugs();
                if ($legacy !== []) {
                    $query->whereNotIn('blog_translations.slug', $legacy)
                        ->whereNotIn('blogs.slug', $legacy);
                }
            }

            $translations = $query->orderByDesc('blogs.published_at')->get();
        } catch (\Throwable) {
            // Static money pages still ship if translations are mid-heal.
        }

        foreach ($translations as $translation) {
            $path = 'blog/'.$translation->slug;
            $slugsByLocale = BlogTranslation::query()
                ->where('blog_id', $translation->blog_id)
                ->where('is_published', true)
                ->pluck('slug', 'locale')
                ->all();
            $availableLocales = array_keys($slugsByLocale);
            $pathByLocale = [];
            foreach ($slugsByLocale as $altLocale => $slug) {
                $pathByLocale[$altLocale] = 'blog/'.$slug;
            }

            $entry = $this->urlEntry($path, $locale, 'monthly', '0.6', $availableLocales, $pathByLocale);
            $entry['lastmod'] = optional($translation->updated_at)?->toAtomString();
            $urls[] = $entry;
        }

        $urls = $this->uniqueUrls($urls);

        $xml = view('sitemap', compact('urls'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    /**
     * Every money-lander class that can publish indexable URLs.
     * The catalog is the source of truth and also picks up a new
     * *MoneyLanders.php file. The class_exists checks keep a missing
     * class from taking down the sitemap.
     *
     * @return array<string, class-string>
     */
    private function moneyLanderClasses(): array
    {
        $classes = [];
        if (class_exists(MoneyLanderCatalog::class) && method_exists(MoneyLanderCatalog::class, 'classes')) {
            foreach (MoneyLanderCatalog::classes() as $locale => $class) {
                if (! is_string($locale) || $locale === '' || ! is_string($class)) {
                    continue;
                }
                if (class_exists($class) && method_exists($class, 'slugs')) {
                    $classes[$locale] = $class;
                }
            }
        }

        if (! isset($classes['it']) && class_exists(ItalianMoneyLanders::class) && method_exists(ItalianMoneyLanders::class, 'slugs')) {
            $classes['it'] = ItalianMoneyLanders::class;
        }
        if (! isset($classes['de']) && class_exists(GermanMoneyLanders::class) && method_exists(GermanMoneyLanders::class, 'slugs')) {
            $classes['de'] = GermanMoneyLanders::class;
        }
        if (! isset($classes['at']) && class_exists(AustrianMoneyLanders::class) && method_exists(AustrianMoneyLanders::class, 'slugs')) {
            $classes['at'] = AustrianMoneyLanders::class;
        }
        if (! isset($classes['ch']) && class_exists(SwissMoneyLanders::class) && method_exists(SwissMoneyLanders::class, 'slugs')) {
            $classes['ch'] = SwissMoneyLanders::class;
        }
        if (! isset($classes['es']) && class_exists(SpanishMoneyLanders::class) && method_exists(SpanishMoneyLanders::class, 'slugs')) {
            $classes['es'] = SpanishMoneyLanders::class;
        }
        if (! isset($classes['pt']) && class_exists(PortugueseMoneyLanders::class) && method_exists(PortugueseMoneyLanders::class, 'slugs')) {
            $classes['pt'] = PortugueseMoneyLanders::class;
        }
        if (! isset($classes['ro']) && class_exists(RomanianMoneyLanders::class) && method_exists(RomanianMoneyLanders::class, 'slugs')) {
            $classes['ro'] = RomanianMoneyLanders::class;
        }
        if (! isset($classes['fr']) && class_exists(FrenchMoneyLanders::class) && method_exists(FrenchMoneyLanders::class, 'slugs')) {
            $classes['fr'] = FrenchMoneyLanders::class;
        }
        if (! isset($classes['nl']) && class_exists(DutchMoneyLanders::class) && method_exists(DutchMoneyLanders::class, 'slugs')) {
            $classes['nl'] = DutchMoneyLanders::class;
        }

        return $classes;
    }

    /**
     * @param  array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}  $entry
     * @param  array<string, string>  $paths
     * @return array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}
     */
    private function withMoneyLanderXDefault(array $entry, string $slug, array $paths): array
    {
        if (! class_exists(PublicI18n::class)
            || ! method_exists(PublicI18n::class, 'moneyLanderXDefault')
            || ! method_exists(PublicI18n::class, 'urlForLocale')) {
            return $entry;
        }

        $xDefault = PublicI18n::moneyLanderXDefault($slug);
        $xPath = ltrim((string) ($paths[$xDefault] ?? $slug), '/');
        foreach ($entry['alternates'] as $index => $alternate) {
            if (($alternate['hreflang'] ?? '') !== 'x-default') {
                continue;
            }
            $entry['alternates'][$index]['href'] = PublicI18n::urlForLocale($xPath, $xDefault);
        }

        return $entry;
    }

    /**
     * @param  list<array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}>  $urls
     * @return list<array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}>
     */
    private function uniqueUrls(array $urls): array
    {
        $seen = [];
        $unique = [];
        foreach ($urls as $entry) {
            $loc = (string) ($entry['loc'] ?? '');
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $unique[] = $entry;
        }

        return $unique;
    }

    /**
     * @param  list<string>  $fallback
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function moneyLanderSitemapCluster(string $slug, array $fallback): array
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'moneyLanderLocales')) {
            $locales = PublicI18n::moneyLanderLocales($slug);
            if ($locales !== []) {
                $paths = method_exists(PublicI18n::class, 'moneyLanderPathByLocale')
                    ? PublicI18n::moneyLanderPathByLocale($slug)
                    : [];
                if ($paths === []) {
                    $paths = [];
                    foreach ($locales as $locale) {
                        $paths[$locale] = $slug;
                    }
                }

                return [$locales, $paths];
            }
        }

        $paths = [];
        foreach ($fallback as $locale) {
            $paths[$locale] = $slug;
        }

        return [$fallback, $paths];
    }

    /**
     * @return array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}
     */
    private function urlEntry(string $path, string $locale, string $changefreq, string $priority, ?array $availableLocales = null, ?array $pathByLocale = null): array
    {
        if (! class_exists(PublicI18n::class)
            || ! method_exists(PublicI18n::class, 'urlForLocale')
            || ! method_exists(PublicI18n::class, 'hreflang')) {
            $path = ltrim($path, '/');

            return [
                'loc' => $path === '' ? url('/') : url($path),
                'changefreq' => $changefreq,
                'priority' => $priority,
                'alternates' => [],
            ];
        }

        $alternates = [];
        $altLocales = $availableLocales ?: (
            method_exists(PublicI18n::class, 'supported')
                ? PublicI18n::supported()
                : $this->supportedLocales()
        );
        foreach ($altLocales as $alt) {
            $altPath = ltrim((string) ($pathByLocale[$alt] ?? $path), '/');
            $alternates[] = [
                'hreflang' => PublicI18n::hreflang($alt),
                'href' => PublicI18n::urlForLocale($altPath, $alt),
            ];
        }
        $defaultLocale = method_exists(PublicI18n::class, 'default')
            ? PublicI18n::default()
            : $this->defaultLocale();
        $xDefault = in_array($defaultLocale, $altLocales, true) ? $defaultLocale : $locale;
        $xDefaultPath = ltrim((string) ($pathByLocale[$xDefault] ?? $path), '/');
        $alternates[] = [
            'hreflang' => 'x-default',
            'href' => PublicI18n::urlForLocale($xDefaultPath, $xDefault),
        ];

        return [
            'loc' => PublicI18n::urlForLocale($path, $locale),
            'changefreq' => $changefreq,
            'priority' => $priority,
            'alternates' => $alternates,
        ];
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'supported')) {
            $fromSupported = PublicI18n::supported();
            if (is_array($fromSupported) && $fromSupported !== []) {
                return array_values(array_filter(
                    $fromSupported,
                    static fn ($locale) => is_string($locale) && $locale !== ''
                ));
            }
        }

        return array_values(array_filter(
            (array) config('i18n.supported', ['en']),
            static fn ($locale) => is_string($locale) && $locale !== ''
        ));
    }

    private function defaultLocale(): string
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'default')) {
            return PublicI18n::default();
        }

        return (string) config('i18n.default', 'en');
    }
}
