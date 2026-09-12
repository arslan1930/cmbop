<?php

namespace App\Support;

class CountryLander
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        /** @var array<string, array<string, mixed>> $landers */
        $landers = config('country_landers', []);

        return $landers;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $lander = self::all()[$key] ?? null;

        return is_array($lander) ? $lander : null;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        $slugs = [];
        foreach (self::all() as $lander) {
            $slug = trim((string) ($lander['slug'] ?? ''));
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return list<array{key: string, slug: string, market: string, kicker: string, url: string}>
     */
    public static function siblings(?string $exceptKey = null): array
    {
        $out = [];
        foreach (self::all() as $key => $lander) {
            if ($exceptKey !== null && $key === $exceptKey) {
                continue;
            }
            $slug = trim((string) ($lander['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'key' => $key,
                'slug' => $slug,
                'market' => (string) ($lander['market'] ?? $key),
                'kicker' => (string) ($lander['kicker'] ?? ''),
                'url' => url('/'.$slug),
            ];
        }

        return $out;
    }
}
