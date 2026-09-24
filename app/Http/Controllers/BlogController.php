<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\CuratedBlogSync;
use App\Support\GermanMoneyLanders;
use App\Support\ThinBlogRedirects;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class BlogController extends Controller
{
    /**
     * Display a listing of published blog posts.
     */
    public function index()
    {
        try {
            CuratedBlogSync::ensurePresent();
            $requestedLocale = public_locale();

            $listing = Blog::published();
            if (method_exists(Blog::class, 'scopeWithoutLegacyRedirects')) {
                $listing = $listing->withoutLegacyRedirects();
            }

            $blog = $listing
                ->withPublishedLocale($requestedLocale)
                ->orderByDesc('published_at')
                ->paginate(12);

            $blog->getCollection()->transform(
                fn (Blog $post) => $post->applyPublishedLocale($requestedLocale)
            );

            return view('pages.blog', compact('blog'));
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load blog posts right now.')
            );

            $blog = new LengthAwarePaginator([], 0, 12, 1, [
                'path' => request()->url(),
                'pageName' => 'page',
            ]);

            return view('pages.blog', compact('blog'));
        }
    }

    /**
     * Display a single blog post.
     *
     * Locale-prefixed routes include a {locale} parameter. Reading the slug from
     * the route bag avoids Laravel's array_values() controller dispatch binding
     * the locale into a lone $slug argument.
     */
    public function show(Request $request)
    {
        try {
            return $this->renderShow($request);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('blog.index')
                ->with('error', UserFacingError::message($e, 'Unable to load this post right now.'));
        }
    }

    private function renderShow(Request $request)
    {
        CuratedBlogSync::ensurePresent();
        $requestedLocale = public_locale();
        $fallbackLocale = 'en';

        $slug = (string) $request->route('slug');

        try {
            if (class_exists(ThinBlogRedirects::class)) {
                $legacyTarget = ThinBlogRedirects::redirectUrl($slug, $requestedLocale);
                if (is_string($legacyTarget) && $legacyTarget !== '') {
                    $query = $request->getQueryString();

                    return redirect($query ? $legacyTarget.'?'.$query : $legacyTarget, 301);
                }
            }
        } catch (\Throwable) {
            // Missing schema or catalog class: fall through to the normal show path.
        }

        try {
            if ($requestedLocale === 'de'
                && class_exists(GermanMoneyLanders::class)) {
                $legacyDe = GermanMoneyLanders::legacyBlogSlugs()[$slug] ?? null;
                if (is_string($legacyDe) && $legacyDe !== '' && $legacyDe !== $slug) {
                    $target = '/de/blog/'.$legacyDe;
                    $query = $request->getQueryString();

                    return redirect($query ? $target.'?'.$query : $target, 301);
                }
            }
        } catch (\Throwable) {
            // Fall through if the German lander class is missing on leftover deploys.
        }

        $translation = BlogTranslation::query()
            ->where('locale', $requestedLocale)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (! $translation && $requestedLocale !== $fallbackLocale) {
            $translation = BlogTranslation::query()
                ->where('locale', $fallbackLocale)
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();
        }

        // Translation slugs are globally unique. /blog/{de-slug} must still
        // resolve when blogs.slug was renamed or differs from the DE row.
        if (! $translation) {
            $translation = BlogTranslation::query()
                ->where('slug', $slug)
                ->where('is_published', true)
                ->first();
        }

        $blog = null;
        if ($translation) {
            $blog = Blog::published()
                ->with(['translations' => function ($query) {
                    $query->where('is_published', true);
                }])
                ->where('id', $translation->blog_id)
                ->first();
        }

        if (! $blog) {
            // A published translation can belong to a draft. Do not keep that
            // row — otherwise a legacy blogs.slug hit renders the draft body.
            $translation = null;
            $blog = Blog::published()
                ->with(['translations' => function ($query) {
                    $query->where('is_published', true);
                }])
                ->where('slug', $slug)
                ->firstOrFail();
        }

        $display = $blog->displayTranslation($requestedLocale, $fallbackLocale);
        if ($display) {
            $translation = $display;
        }

        if (
            $translation
            && $translation->locale === $requestedLocale
            && is_string($translation->slug)
            && $translation->slug !== ''
            && $translation->slug !== $slug
        ) {
            $target = $blog->canonicalUrl($requestedLocale, $fallbackLocale);
            $query = $request->getQueryString();

            return redirect($query ? $target.'?'.$query : $target, 301);
        }

        if (! $translation) {
            $translation = new BlogTranslation([
                'blog_id' => $blog->id,
                'locale' => $blog->primary_locale ?: $fallbackLocale,
                'title' => $blog->title,
                'slug' => $blog->slug,
                'excerpt' => $blog->excerpt,
                'content' => $blog->content,
                'is_published' => true,
            ]);
        }

        $fallbackUsed = $translation->locale !== $requestedLocale;
        $canonicalUrl = $blog->canonicalUrl($translation->locale, $fallbackLocale);

        // Thin locale copies (English body on /de/blog/…) conflict with hreflang + canonical.
        if ($fallbackUsed) {
            $query = $request->getQueryString();

            return redirect($query ? $canonicalUrl.'?'.$query : $canonicalUrl, 301);
        }
        $availableLocales = $blog->availableLocales();
        if ($availableLocales === []) {
            $availableLocales = [$translation->locale ?: $fallbackLocale];
        }
        $hreflangPath = 'blog/'.$translation->slug;

        $related = Blog::published();
        if (method_exists(Blog::class, 'scopeWithoutLegacyRedirects')) {
            $related = $related->withoutLegacyRedirects();
        }
        $related = $related
            ->withPublishedLocale($requestedLocale)
            ->where('id', '!=', $blog->id)
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        $related->transform(
            fn (Blog $post) => $post->applyPublishedLocale($requestedLocale)
        );

        return view('pages.blog-single', compact(
            'blog',
            'related',
            'translation',
            'requestedLocale',
            'fallbackUsed',
            'canonicalUrl',
            'availableLocales',
            'hreflangPath'
        ));
    }
}
