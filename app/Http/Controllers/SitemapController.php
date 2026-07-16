<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $base = rtrim(config('app.url'), '/');
        $urls = [
            ['loc' => $base.'/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => $base.'/contact', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => $base.'/privacy-policy', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => $base.'/terms-of-services', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => $base.'/blog', 'changefreq' => 'weekly', 'priority' => '0.7'],
            ['loc' => $base.'/login', 'changefreq' => 'monthly', 'priority' => '0.4'],
            ['loc' => $base.'/register', 'changefreq' => 'monthly', 'priority' => '0.5'],
        ];

        $posts = Blog::query()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at']);

        foreach ($posts as $post) {
            $urls[] = [
                'loc' => $base.'/blog/'.$post->slug,
                'lastmod' => optional($post->updated_at ?? $post->published_at)?->toAtomString(),
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        $xml = view('sitemap', compact('urls'))->render();

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
