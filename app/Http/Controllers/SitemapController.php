<?php

namespace App\Http\Controllers;

use App\Models\Blog;
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
        $base = rtrim(config('app.url'), '/');
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

        $base = rtrim(config('app.url'), '/');
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
        }

        $posts = Blog::published()
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at']);

        foreach ($posts as $post) {
            $path = 'blog/'.$post->slug;
            $entry = $this->urlEntry($path, $locale, 'monthly', '0.6');
            $entry['lastmod'] = optional($post->updated_at ?? $post->published_at)?->toAtomString();
            $urls[] = $entry;
        }

        $xml = view('sitemap', compact('urls'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    /**
     * @return array{loc: string, changefreq: string, priority: string, alternates: list<array{hreflang: string, href: string}>}
     */
    private function urlEntry(string $path, string $locale, string $changefreq, string $priority): array
    {
        $alternates = [];
        foreach (PublicI18n::supported() as $alt) {
            $alternates[] = [
                'hreflang' => $alt,
                'href' => PublicI18n::urlForLocale($path, $alt),
            ];
        }
        $alternates[] = [
            'hreflang' => 'x-default',
            'href' => PublicI18n::urlForLocale($path, PublicI18n::default()),
        ];

        return [
            'loc' => PublicI18n::urlForLocale($path, $locale),
            'changefreq' => $changefreq,
            'priority' => $priority,
            'alternates' => $alternates,
        ];
    }
}
