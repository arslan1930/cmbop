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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        $lastmods = $this->sitemapIndexLastmods();

        foreach ($this->supportedLocales() as $locale) {
            $entry = [
                'loc' => $base.'/sitemap-'.$locale.'.xml',
            ];
            if (! empty($lastmods[$locale])) {
                $entry['lastmod'] = $lastmods[$locale];
            }
            $sitemaps[] = $entry;
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
                $entry = $this->withLastmod($entry, $this->moneyPageLastmod($moneyClass));
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
            // Do not use withoutLegacyRedirects here. That scope drops an entire
            // post when any translation row still has an old slug, which would
            // omit the live language versions from this sitemap.
            $published = Blog::published();

            $query = BlogTranslation::query()
                ->select('blog_translations.*')
                ->join('blogs', 'blogs.id', '=', 'blog_translations.blog_id')
                ->whereIn('blogs.id', $published->select('blogs.id'))
                ->where('blog_translations.locale', $locale)
                ->where('blog_translations.is_published', true);

            $legacy = $this->legacyBlogSlugs();
            if ($legacy !== []) {
                $query->whereNotIn('blog_translations.slug', $legacy)
                    ->whereNotIn('blogs.slug', $legacy);
            }

            $translations = $query->orderByDesc('blogs.published_at')->get();
        } catch (\Throwable) {
            // Static money pages still ship if translations are mid-heal.
        }

        foreach ($translations as $translation) {
            $path = 'blog/'.$translation->slug;
            $alternateQuery = BlogTranslation::query()
                ->where('blog_id', $translation->blog_id)
                ->where('is_published', true);
            $legacy = $this->legacyBlogSlugs();
            if ($legacy !== []) {
                $alternateQuery->whereNotIn('slug', $legacy);
            }
            $slugsByLocale = $alternateQuery->pluck('slug', 'locale')->all();
            $availableLocales = array_keys($slugsByLocale);
            $pathByLocale = [];
            foreach ($slugsByLocale as $altLocale => $slug) {
                $pathByLocale[$altLocale] = 'blog/'.$slug;
            }

            $entry = $this->urlEntry($path, $locale, 'monthly', '0.6', $availableLocales, $pathByLocale);
            $postLastmod = optional($translation->updated_at)?->toAtomString();
            if (is_string($postLastmod) && $postLastmod !== '') {
                $entry['lastmod'] = $postLastmod;
            }
            $urls[] = $entry;
        }

        $urls = $this->stampBlogIndex($urls, $locale, $translations);

        $urls = $this->appendMissingIndexableUrls($urls, $locale);
        $urls = $this->uniqueUrls($urls);

        $xml = view('sitemap', compact('urls'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    /**
     * Newest published translation per locale, for the sitemap index lastmod.
     *
     * @return array<string, string>
     */
    private function sitemapIndexLastmods(): array
    {
        try {
            $rows = BlogTranslation::query()
                ->where('is_published', true)
                ->whereNotNull('updated_at')
                ->selectRaw('locale, MAX(updated_at) as lastmod')
                ->groupBy('locale')
                ->get();
        } catch (\Throwable) {
            $rows = collect();
        }

        $lastmods = [];
        foreach ($rows as $row) {
            $locale = (string) ($row->locale ?? '');
            $lastmod = $row->lastmod ?? null;
            if ($locale === '' || $lastmod === null || $lastmod === '') {
                continue;
            }
            try {
                $lastmods[$locale] = Carbon::parse($lastmod)->toAtomString();
            } catch (\Throwable) {
                continue;
            }
        }

        foreach ($this->supportedLocales() as $locale) {
            $stamps = [];
            if (! empty($lastmods[$locale])) {
                $stamps[] = $lastmods[$locale];
            }
            foreach ($this->staticPages() as $page) {
                $stamps[] = $this->pathViewLastmod($page['path']);
            }
            if ($locale === $this->defaultLocale()) {
                $stamps[] = $this->pathViewLastmod('guest-posts-germany');
                if (class_exists(GuestPostPriceIndex::class)) {
                    $stamps[] = $this->pathViewLastmod(GuestPostPriceIndex::SLUG);
                }
            }
            $moneyClass = $this->moneyLanderClasses()[$locale] ?? null;
            $stamps[] = $this->moneyPageLastmod(is_string($moneyClass) ? $moneyClass : null);
            $latest = $this->latestStamp($stamps);
            if ($latest !== null) {
                $lastmods[$locale] = $latest;
            }
        }

        return $lastmods;
    }

    /**
     * The blog index changes when a post in that language is updated.
     *
     * @param  list<array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>, lastmod?: string|null}>  $urls
     * @param  Collection<int, BlogTranslation>  $translations
     * @return list<array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>, lastmod?: string|null}>
     */
    private function stampBlogIndex(array $urls, string $locale, $translations): array
    {
        $stamps = [$this->pathViewLastmod('blog')];
        foreach ($translations as $translation) {
            $stamps[] = optional($translation->updated_at)?->toAtomString();
        }
        $latest = $this->latestStamp($stamps);
        if ($latest === null || ! class_exists(PublicI18n::class) || ! method_exists(PublicI18n::class, 'urlForLocale')) {
            return $urls;
        }

        $blogIndex = PublicI18n::urlForLocale('blog', $locale);
        foreach ($urls as $index => $entry) {
            if (($entry['loc'] ?? '') === $blogIndex) {
                $urls[$index] = $this->withLastmod($entry, $latest);
            }
        }

        return $urls;
    }

    /**
     * Old blog slugs that 301 onto a pillar. They must not be sitemap locs.
     *
     * @return list<string>
     */
    private function legacyBlogSlugs(): array
    {
        if (! class_exists(ThinBlogRedirects::class) || ! method_exists(ThinBlogRedirects::class, 'legacySlugs')) {
            return [];
        }

        $slugs = ThinBlogRedirects::legacySlugs();

        return array_values(array_filter($slugs, static fn ($slug) => is_string($slug) && $slug !== ''));
    }

    /**
     * Second pass so a live canonical cannot be omitted if an earlier query
     * was narrowed. Redirect aliases stay out; each real page is a loc.
     *
     * @param  list<array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}>  $urls
     * @return list<array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}>
     */
    private function appendMissingIndexableUrls(array $urls, string $locale): array
    {
        $have = [];
        foreach ($urls as $entry) {
            $loc = (string) ($entry['loc'] ?? '');
            if ($loc !== '') {
                $have[$loc] = true;
            }
        }

        $push = function (array $entry) use (&$urls, &$have): void {
            $loc = (string) ($entry['loc'] ?? '');
            if ($loc === '' || isset($have[$loc])) {
                return;
            }
            $have[$loc] = true;
            $urls[] = $entry;
        };

        foreach ($this->staticPages() as $page) {
            $push($this->urlEntry($page['path'], $locale, $page['changefreq'], $page['priority']));
        }

        $moneyClass = $this->moneyLanderClasses()[$locale] ?? null;
        if (is_string($moneyClass) && method_exists($moneyClass, 'slugs')) {
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
                $push($this->withMoneyLanderXDefault(
                    $this->withLastmod(
                        $this->urlEntry($slug, $locale, 'weekly', '0.85', $locales, $paths),
                        $this->moneyPageLastmod($moneyClass)
                    ),
                    $slug,
                    $paths
                ));
            }
        }

        if ($locale === $this->defaultLocale()) {
            if (class_exists(CountryLander::class)) {
                foreach (CountryLander::slugs() as $landerPath) {
                    $push($this->urlEntry($landerPath, $locale, 'weekly', '0.8', ['en'], ['en' => $landerPath]));
                }
            }
            if (class_exists(GuestPostPriceIndex::class)) {
                $priceIndex = GuestPostPriceIndex::SLUG;
                $push($this->urlEntry($priceIndex, $locale, 'weekly', '0.8', ['en'], ['en' => $priceIndex]));
            }
        }

        return $urls;
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

            $entry = [
                'loc' => $path === '' ? url('/') : url($path),
                'changefreq' => $changefreq,
                'priority' => $priority,
                'alternates' => [],
            ];
            $lastmod = $this->pathViewLastmod($path);
            if ($lastmod !== null) {
                $entry['lastmod'] = $lastmod;
            }

            return $entry;
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

        $entry = [
            'loc' => PublicI18n::urlForLocale($path, $locale),
            'changefreq' => $changefreq,
            'priority' => $priority,
            'alternates' => $alternates,
        ];
        $lastmod = $this->pathViewLastmod($path);
        if ($lastmod !== null) {
            $entry['lastmod'] = $lastmod;
        }

        return $entry;
    }

    /**
     * When the page template or money-page class was last changed.
     * Blog posts replace this with the translation's updated_at.
     */
    private function pathViewLastmod(string $path): ?string
    {
        $path = trim($path, '/');
        $relative = match (true) {
            $path === '' => 'home.blade.php',
            $path === 'blog' => 'pages/blog.blade.php',
            str_starts_with($path, 'blog/') => 'pages/blog-single.blade.php',
            str_starts_with($path, 'guest-posts-') => 'pages/guest-posts-country.blade.php',
            $path === (class_exists(GuestPostPriceIndex::class) ? GuestPostPriceIndex::SLUG : 'guest-post-prices-europe') => 'pages/guest-post-prices-europe.blade.php',
            default => 'pages/'.$path.'.blade.php',
        };

        return $this->fileLastmod(resource_path('views/'.$relative));
    }

    private function moneyPageLastmod(?string $class): ?string
    {
        $stamps = array_filter([
            $this->fileLastmod(resource_path('views/pages/money-lander.blade.php')),
        ]);
        if (! is_string($class) || ! class_exists($class)) {
            return $this->latestStamp($stamps);
        }

        try {
            $ref = new \ReflectionClass($class);
            $file = $ref->getFileName();
            if (is_string($file) && $file !== '') {
                $stamps[] = $this->fileLastmod($file);
            }
            $blade = 'pages/'.Str::kebab(rtrim($ref->getShortName(), 's')).'.blade.php';
            $stamps[] = $this->fileLastmod(resource_path('views/'.$blade));
        } catch (\Throwable) {
            // The shared money template stamp still applies.
        }

        return $this->latestStamp($stamps);
    }

    /**
     * @param  array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>, lastmod?: string}  $entry
     * @return array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>, lastmod?: string}
     */
    private function withLastmod(array $entry, ?string $lastmod): array
    {
        if ($lastmod === null || $lastmod === '') {
            return $entry;
        }
        $current = $entry['lastmod'] ?? null;
        if (! is_string($current) || $current === '' || $lastmod > $current) {
            $entry['lastmod'] = $lastmod;
        }

        return $entry;
    }

    /**
     * @param  list<string|null>  $stamps
     */
    private function latestStamp(array $stamps): ?string
    {
        $stamps = array_values(array_filter($stamps, static fn ($stamp) => is_string($stamp) && $stamp !== ''));
        if ($stamps === []) {
            return null;
        }
        rsort($stamps);

        return $stamps[0];
    }

    private function fileLastmod(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $mtime = filemtime($path);
        if ($mtime === false) {
            return null;
        }

        return Carbon::createFromTimestamp($mtime)->utc()->toAtomString();
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
