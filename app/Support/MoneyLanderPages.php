<?php

namespace App\Support;

/**
 * Shared helpers for locale money-lander classes (slugs, aliases, cluster nav).
 */
trait MoneyLanderPages
{
    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(static::pages());
    }

    public static function isSlug(string $segment): bool
    {
        $segment = trim($segment, '/');

        return $segment !== '' && in_array($segment, static::slugs(), true);
    }

    public static function isPublicSegment(string $segment): bool
    {
        $segment = trim($segment, '/');
        if ($segment === '') {
            return false;
        }

        return static::isSlug($segment) || array_key_exists($segment, static::aliases());
    }

    /**
     * @return list<string>
     */
    public static function copyRedirectLocales(): array
    {
        return [static::LOCALE];
    }

    public static function capturesLocaleCopy(string $locale, string $segment): bool
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, static::copyRedirectLocales(), true)
            && static::isPublicSegment($segment);
    }

    /**
     * @return list<string>
     */
    public static function publicSegments(): array
    {
        return array_values(array_unique(array_merge(
            static::slugs(),
            array_keys(static::aliases())
        )));
    }

    public static function url(string $slug): string
    {
        return url('/'.static::LOCALE.'/'.$slug);
    }

    public static function marketingUrl(string $englishKey): string
    {
        $maps = class_exists(LocalizedPublicPath::class) ? LocalizedPublicPath::map() : [];
        $segment = $maps[static::LOCALE][$englishKey] ?? $englishKey;

        return url('/'.static::LOCALE.'/'.$segment);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        $slug = trim($slug, '/');

        return static::pages()[$slug] ?? null;
    }

    /**
     * @param  list<array{slug: string, label: string, url: string}>  $items
     * @return list<array{slug: string, label: string, url: string}>
     */
    public static function filterCluster(array $items, ?string $current = null): array
    {
        if ($current === null || $current === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (array $item) => $item['slug'] !== $current
        ));
    }
}
