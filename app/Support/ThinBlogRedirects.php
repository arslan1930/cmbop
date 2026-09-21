<?php

namespace App\Support;

use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Services\CuratedBlogWriter;
use Illuminate\Support\Facades\Schema;

/**
 * 301 thin BlogSeeder stubs onto the ranking pillars so they stop competing.
 */
class ThinBlogRedirects
{
    /**
     * @return array<string, string> old public slug => catalog slug
     */
    public static function map(): array
    {
        return [
            'how-to-build-high-quality-backlinks-in-2026' => HowToGetBacklinksBlogPost::SLUG,
            'digital-pr-ideas-that-earn-coverage-and-links' => LinkBuildingGuideBlogPost::SLUG,
            'guest-posting-checklist-for-advertisers' => GuestPostingGuideBlogPost::SLUG,
            'choosing-publishers-by-country-and-language' => ChoosePublisherSiteBlogPost::SLUG,
        ];
    }

    /**
     * @return list<string>
     */
    public static function legacySlugs(): array
    {
        return array_keys(self::map());
    }

    public static function targetSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        return self::map()[$slug] ?? null;
    }

    public static function redirectUrl(string $slug, ?string $locale = null): ?string
    {
        $catalog = self::targetSlug($slug);
        if ($catalog === null) {
            return null;
        }

        $locale = $locale ?: 'en';

        try {
            if (class_exists(CuratedBlogWriter::class)) {
                $blog = CuratedBlogWriter::findExisting($catalog);
                if ($blog) {
                    return $blog->canonicalUrl($locale, 'en');
                }
            }
        } catch (\Throwable) {
            // Fall through to the catalog path.
        }

        if (class_exists(PublicI18n::class) && method_exists(PublicI18n::class, 'urlForLocale')) {
            return PublicI18n::urlForLocale('blog/'.$catalog, $locale);
        }

        return '/blog/'.$catalog;
    }

    /**
     * Hide leftover BlogSeeder rows from the index. URLs still 301.
     * Matches both blogs.slug and leftover translation slugs.
     */
    public static function unpublishLegacy(): int
    {
        try {
            if (! Schema::hasTable('blogs')) {
                return 0;
            }

            $slugs = self::legacySlugs();
            if ($slugs === []) {
                return 0;
            }

            $ids = Blog::query()->whereIn('slug', $slugs)->pluck('id')->all();

            if (Schema::hasTable('blog_translations')) {
                $fromTranslations = BlogTranslation::query()
                    ->whereIn('slug', $slugs)
                    ->pluck('blog_id')
                    ->all();
                $ids = array_values(array_unique(array_merge($ids, $fromTranslations)));
            }

            $updated = 0;

            if ($ids !== []) {
                $updated += Blog::query()
                    ->whereIn('id', $ids)
                    ->where('status', 'published')
                    ->update(['status' => 'draft']);
            }

            if (Schema::hasTable('blog_translations')) {
                $updated += BlogTranslation::query()
                    ->where(function ($query) use ($ids, $slugs) {
                        if ($ids !== []) {
                            $query->whereIn('blog_id', $ids);
                        }
                        $query->orWhereIn('slug', $slugs);
                    })
                    ->update(['is_published' => false]);
            }

            return (int) $updated;
        } catch (\Throwable) {
            return 0;
        }
    }
}
