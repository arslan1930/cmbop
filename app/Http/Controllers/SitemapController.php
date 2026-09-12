<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\CuratedBlogSync;
use App\Support\CountryLander;
use App\Support\PublicI18n;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /** @return list<array{path: string, changefreq: string, priority: string}> */
    private function staticPages(): array
    {
        return [
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
    }

    public function index(): Response
    {
        // Production APP_URL is sometimes still loopback. Child locs must
        // use the public origin or GSC cannot fetch locale sitemaps.
        $base = rtrim(app_public_url(), '/');
        $sitemaps = [];

        foreach (PublicI18n::supported() as $locale) {
            $sitemaps[] = [
                'loc' => $base.'/sitemap-'.$locale.'.xml',
            ];
        }

        $xml = view('sitemap-index', compact('sitemaps'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    public function locale(string $locale): Response
    {
        abort_unless(PublicI18n::isSupported($locale), 404);

        // Locale sitemaps join blog_translations — heal skipped migrations.
        CuratedBlogSync::ensurePresent();

        $base = rtrim(app_public_url(), '/');
        $urls = [];

        foreach ($this->staticPages() as $page) {
            $urls[] = $this->urlEntry($page['path'], $locale, $page['changefreq'], $page['priority']);
        }

        // English-only auth entry points appear only on the English sitemap
        if ($locale === PublicI18n::default()) {
            $urls[] = [
                'loc' => $base.'/login',
                'changefreq' => 'monthly',
                'priority' => '0.4',
                'alternates' => [],
            ];
            $urls[] = [
                'loc' => $base.'/register',
                'changefreq' => 'monthly',
                'priority' => '0.5',
                'alternates' => [],
            ];

            foreach (CountryLander::slugs() as $landerPath) {
                $urls[] = $this->urlEntry($landerPath, $locale, 'weekly', '0.8', ['en'], ['en' => $landerPath]);
            }
        }

        $translations = collect();
        try {
            $translations = BlogTranslation::query()
                ->select('blog_translations.*')
                ->join('blogs', 'blogs.id', '=', 'blog_translations.blog_id')
                ->whereIn('blogs.id', Blog::published()->select('blogs.id'))
                ->where('blog_translations.locale', $locale)
                ->where('blog_translations.is_published', true)
                ->orderByDesc('blogs.published_at')
                ->get();
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

        $xml = view('sitemap', compact('urls'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    /**
     * @return array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}
     */
    private function urlEntry(string $path, string $locale, string $changefreq, string $priority, ?array $availableLocales = null, ?array $pathByLocale = null): array
    {
        $alternates = [];
        $altLocales = $availableLocales ?: PublicI18n::supported();
        foreach ($altLocales as $alt) {
            $altPath = ltrim((string) ($pathByLocale[$alt] ?? $path), '/');
            $alternates[] = [
                'hreflang' => PublicI18n::hreflang($alt),
                'href' => PublicI18n::urlForLocale($altPath, $alt),
            ];
        }
        $xDefault = in_array(PublicI18n::default(), $altLocales, true) ? PublicI18n::default() : $locale;
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
}
