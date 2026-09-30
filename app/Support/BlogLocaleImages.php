<?php

namespace App\Support;

use App\Models\Blog;
use App\Models\Site;

/**
 * Locale diagrams for translated blog posts.
 *
 * English photos stay the default. When public/assets/img/blog/{name}-{locale}.svg
 * exists, that authored graphic is used instead. Product screenshots of the
 * English dashboard are left alone: there is no localized UI to photograph.
 */
class BlogLocaleImages
{
    /**
     * Basename to publish. Falls back to the English file when no locale SVG exists.
     */
    public static function inlineFilename(string $englishFilename, string $locale): string
    {
        $englishFilename = basename($englishFilename);
        $locale = strtolower($locale);
        if ($englishFilename === '' || $englishFilename === '.' || $englishFilename === '..' || $locale === '' || $locale === 'en') {
            return $englishFilename;
        }

        $localized = self::localizedName($englishFilename, $locale);
        if ($localized === null) {
            return $englishFilename;
        }

        $source = public_path(BlogInlineImages::PUBLIC_DIR.'/'.$localized);
        if (is_file($source)) {
            return $localized;
        }

        return $englishFilename;
    }

    public static function publicUrl(string $englishFilename, string $locale): string
    {
        return BlogInlineImages::publicUrl(self::inlineFilename($englishFilename, $locale));
    }

    /**
     * Root-relative featured URL for this post in $locale, or null when the blog has none.
     */
    public static function featuredPublicUrl(Blog $blog, ?string $locale): ?string
    {
        $basename = self::featuredBasename($blog);
        if ($basename === null) {
            return $blog->publicFeaturedImageUrl();
        }

        $file = self::inlineFilename($basename, (string) $locale);
        if ($file === $basename) {
            return $blog->publicFeaturedImageUrl();
        }

        $storage = 'blogs/featured/'.$file;
        if (! BlogInlineImages::publishFeatured($storage, BlogInlineImages::PUBLIC_DIR.'/'.$file)) {
            return $blog->publicFeaturedImageUrl();
        }

        return Site::publicDiskUrl($storage);
    }

    public static function featuredBasename(Blog $blog): ?string
    {
        $path = $blog->featured_image;
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $parsed = parse_url(trim($path), PHP_URL_PATH);
        $base = basename(is_string($parsed) && $parsed !== '' ? $parsed : trim($path));
        if ($base === '' || $base === '.' || $base === '..') {
            return null;
        }

        return $base;
    }

    private static function localizedName(string $filename, string $locale): ?string
    {
        if (! preg_match('/^[a-z]{2}$/', $locale)) {
            return null;
        }

        $base = preg_replace('/\.(jpe?g|png|webp|svg)$/i', '', $filename);
        if (! is_string($base) || $base === '' || str_contains($base, '..')) {
            return null;
        }

        return $base.'-'.$locale.'.svg';
    }
}
