<?php

namespace App\Support;

use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Growth → Blogs list filters and editor locale tabs.
 */
class AdminBlog
{
    /**
     * @return list<string>
     */
    public static function publicLocales(): array
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'supported')) {
            return PublicI18n::supported();
        }

        return ['en'];
    }

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::publicLocales(), true);
    }

    public static function shortLabel(string $locale): string
    {
        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'shortLabel')) {
            return PublicI18n::shortLabel($locale);
        }

        return $locale === 'en' ? 'UK' : strtoupper($locale);
    }

    /**
     * Tabs to render: English, existing rows, primary, old input, ?add_locale=.
     *
     * @return list<string>
     */
    public static function formLocales(?Blog $blog, Request $request): array
    {
        $wanted = ['en'];

        $primary = self::normalizeLocale($request->input('primary_locale') ?: old('primary_locale') ?: $blog?->primary_locale);
        if ($primary !== '') {
            $wanted[] = $primary;
        }

        $add = self::normalizeLocale($request->query('add_locale') ?: old('add_locale'));
        if ($add !== '') {
            $wanted[] = $add;
        }

        if ($blog) {
            $translations = $blog->relationLoaded('translations')
                ? $blog->translations
                : $blog->translations()->get();
            foreach ($translations as $row) {
                $code = self::normalizeLocale($row->locale ?? '');
                if ($code !== '') {
                    $wanted[] = $code;
                }
            }
        }

        foreach (array_keys((array) old('translations', $request->input('translations', []))) as $code) {
            $code = self::normalizeLocale($code);
            if ($code !== '') {
                $wanted[] = $code;
            }
        }

        $wanted = array_values(array_unique($wanted));

        return array_values(array_filter(
            self::publicLocales(),
            fn (string $locale) => in_array($locale, $wanted, true)
        ));
    }

    /**
     * @param  list<string>  $active
     * @return list<string>
     */
    public static function addableLocales(array $active): array
    {
        return array_values(array_filter(
            self::publicLocales(),
            fn (string $locale) => ! in_array($locale, $active, true)
        ));
    }

    public static function normalizeLocale(mixed $value): string
    {
        $value = strtolower(search_text($value));

        return self::isSupported($value) ? $value : '';
    }

    public static function normalizeStatus(mixed $value): string
    {
        $value = search_text($value);

        return in_array($value, ['draft', 'published'], true) ? $value : '';
    }

    /**
     * Create-form intent wins; otherwise status, otherwise draft.
     */
    public static function resolveStoreStatus(Request $request): string
    {
        $intent = search_text($request->input('intent'));
        if ($intent === 'publish') {
            return 'published';
        }
        if ($intent === 'draft') {
            return 'draft';
        }

        return self::normalizeStatus($request->input('status')) ?: 'draft';
    }

    /**
     * Client-only public path shape (no uniqueness check).
     */
    public static function publicBlogPathHint(string $locale, string $slug): string
    {
        $locale = self::normalizeLocale($locale) ?: 'en';
        $slug = trim($slug, '/');
        if ($slug === '') {
            $slug = 'your-slug';
        }

        if ($locale === 'en') {
            return '/blog/'.$slug;
        }

        return '/'.$locale.'/blog/'.$slug;
    }

    /**
     * @return array<string, string|int>
     */
    public static function rememberReturnQuery(Request $request): array
    {
        $query = self::indexQuery($request);
        try {
            $request->session()->put('admin_blogs_return', $query);
        } catch (\Throwable) {
        }

        return $query;
    }

    /**
     * @return array<string, string|int>
     */
    public static function storedReturnQuery(Request $request): array
    {
        try {
            $stored = $request->session()->get('admin_blogs_return');
        } catch (\Throwable) {
            return [];
        }
        if (! is_array($stored)) {
            return [];
        }

        return self::indexQuery(Request::create('/', 'GET', $stored));
    }

    public static function listUrl(mixed $query = []): string
    {
        $query = is_array($query) ? self::indexQuery(Request::create('/', 'GET', $query)) : [];

        return route('admin.blogs.index', $query);
    }

    public static function normalizeKind(mixed $value): string
    {
        $value = search_text($value);

        return in_array($value, ['curated', 'custom'], true) ? $value : '';
    }

    public static function normalizeSort(mixed $value): string
    {
        $value = search_text($value);

        return in_array($value, ['published', 'title'], true) ? $value : 'newest';
    }

    public static function normalizeIncomplete(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(search_text($value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array{q: string, status: string, locale: string, kind: string, sort: string, incomplete: bool}
     */
    public static function listFilters(Request $request): array
    {
        return [
            'q' => search_text($request->input('q')),
            'status' => self::normalizeStatus($request->input('status')),
            'locale' => self::normalizeLocale($request->input('locale')),
            'kind' => self::normalizeKind($request->input('kind')),
            'sort' => self::normalizeSort($request->input('sort')),
            'incomplete' => self::normalizeIncomplete($request->input('missing_translations')),
        ];
    }

    /**
     * Query string to return to the filtered index.
     *
     * @return array<string, string|int>
     */
    public static function indexQuery(Request $request): array
    {
        $filters = self::listFilters($request);
        $query = array_filter([
            'q' => $filters['q'] !== '' ? $filters['q'] : null,
            'status' => $filters['status'] !== '' ? $filters['status'] : null,
            'locale' => $filters['locale'] !== '' ? $filters['locale'] : null,
            'kind' => $filters['kind'] !== '' ? $filters['kind'] : null,
            'sort' => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
            'missing_translations' => $filters['incomplete'] ? 1 : null,
        ]);

        $page = (int) (filter_number($request->input('page')) ?? 0);
        if ($page > 1) {
            $query['page'] = $page;
        }

        return $query;
    }

    public static function applyListSort($query, string $sort): void
    {
        if ($sort === 'published' && self::columnExists('blogs', 'published_at')) {
            $query->orderByRaw('published_at IS NULL')->orderByDesc('published_at')->orderByDesc('id');

            return;
        }
        if ($sort === 'title') {
            $query->orderBy('title')->orderByDesc('id');

            return;
        }

        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Incomplete = primary unpublished/missing, EN unpublished/missing, or any saved locale unpublished.
     */
    public static function constrainIncomplete($query): void
    {
        $publishedReady = self::columnExists('blog_translations', 'is_published');

        $query->where(function ($outer) use ($publishedReady) {
            $outer->whereDoesntHave('translations', function ($en) use ($publishedReady) {
                $en->where('locale', 'en');
                if ($publishedReady) {
                    $en->where('is_published', true);
                }
            });
            if (self::columnExists('blogs', 'primary_locale')) {
                $outer->orWhere(function ($primary) use ($publishedReady) {
                    $primary->whereNotNull('primary_locale')
                        ->where('primary_locale', '!=', '')
                        ->where('primary_locale', '!=', 'en')
                        ->whereDoesntHave('translations', function ($row) use ($publishedReady) {
                            $row->whereColumn('blog_translations.locale', 'blogs.primary_locale');
                            if ($publishedReady) {
                                $row->where('is_published', true);
                            }
                        });
                });
            }
            if ($publishedReady) {
                $outer->orWhereHas('translations', function ($unpublished) {
                    $unpublished->where('is_published', false);
                });
            }
        });
    }

    public static function applySearch($query, string $q, bool $translationsAvailable): void
    {
        if ($q === '') {
            return;
        }

        $like = like_contains($q);
        $blogColumns = array_values(array_filter(
            ['title', 'slug', 'author'],
            fn (string $column) => self::columnExists('blogs', $column)
        ));
        $translationColumns = array_values(array_filter(
            ['title', 'slug'],
            fn (string $column) => $translationsAvailable && self::columnExists('blog_translations', $column)
        ));
        if ($blogColumns === [] && $translationColumns === []) {
            return;
        }

        $query->where(function ($inner) use ($like, $blogColumns, $translationColumns) {
            foreach ($blogColumns as $i => $column) {
                $sql = 'blogs.'.$column.' LIKE ? ESCAPE ?';
                if ($i === 0) {
                    $inner->whereRaw($sql, [$like, '\\']);
                } else {
                    $inner->orWhereRaw($sql, [$like, '\\']);
                }
            }
            if ($translationColumns !== []) {
                $inner->orWhereHas('translations', function ($translations) use ($like, $translationColumns) {
                    foreach ($translationColumns as $i => $column) {
                        $sql = $column.' LIKE ? ESCAPE ?';
                        if ($i === 0) {
                            $translations->whereRaw($sql, [$like, '\\']);
                        } else {
                            $translations->orWhereRaw($sql, [$like, '\\']);
                        }
                    }
                });
            }
        });
    }

    public static function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
