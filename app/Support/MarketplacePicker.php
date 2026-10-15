<?php

namespace App\Support;

use App\Models\Country;
use App\Models\Language;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Country / language rows for marketplace selects.
 *
 * Views historically assumed Eloquent models (`$country->code`). PR #1288
 * cached those collections under `admin.marketplace.countries` /
 * `admin.marketplace.languages`. Database/file cache PHP-serializes the
 * payload; a hit can come back as models, attribute arrays, a code=>name
 * map, or a list of codes/names. Always normalize to `{code, name}` objects
 * and cache that array shape — never Eloquent models.
 */
class MarketplacePicker
{
    public const COUNTRIES_CACHE_KEY = 'admin.marketplace.countries.v2';

    public const LANGUAGES_CACHE_KEY = 'admin.marketplace.languages.v2';

    public const LEGACY_COUNTRIES_CACHE_KEY = 'admin.marketplace.countries';

    public const LEGACY_LANGUAGES_CACHE_KEY = 'admin.marketplace.languages';

    public const CACHE_TTL_SECONDS = 300;

    /**
     * @return Collection<int, object{code: string, name: string}>
     */
    public static function countries(mixed $raw = null): Collection
    {
        if ($raw === null) {
            $raw = Country::marketplace()->orderBy('name')->get(['code', 'name']);
        }

        return self::normalize($raw, 'country');
    }

    /**
     * @return Collection<int, object{code: string, name: string}>
     */
    public static function languages(mixed $raw = null): Collection
    {
        if ($raw === null) {
            $raw = Language::marketplace()->orderBy('name')->get(['code', 'name']);
        }

        return self::normalize($raw, 'language');
    }

    /**
     * @return Collection<int, object{code: string, name: string}>
     */
    public static function cachedCountries(): Collection
    {
        return self::remember(
            self::COUNTRIES_CACHE_KEY,
            [self::LEGACY_COUNTRIES_CACHE_KEY],
            'country',
            static fn () => Country::marketplace()->orderBy('name')->get(['code', 'name'])
        );
    }

    /**
     * @return Collection<int, object{code: string, name: string}>
     */
    public static function cachedLanguages(): Collection
    {
        return self::remember(
            self::LANGUAGES_CACHE_KEY,
            [self::LEGACY_LANGUAGES_CACHE_KEY],
            'language',
            static fn () => Language::marketplace()->orderBy('name')->get(['code', 'name'])
        );
    }

    /**
     * @return Collection<int, object{code: string, name: string}>
     */
    public static function normalize(mixed $raw, string $kind = 'country'): Collection
    {
        $kind = $kind === 'language' ? 'language' : 'country';
        $items = self::unwrap($raw);

        if ($items === []) {
            return collect();
        }

        if (self::looksLikeRow($items)) {
            $items = [$items];
        }

        if (self::isCodeNameMap($items)) {
            return collect($items)
                ->map(function ($value, $key) use ($kind) {
                    if (is_array($value) || is_object($value)) {
                        return self::rowFrom($value, $kind, is_string($key) ? $key : null);
                    }

                    $name = is_scalar($value) ? trim((string) $value) : null;

                    return self::rowFromString(is_string($key) ? $key : (string) $value, $kind, $name !== '' ? $name : null);
                })
                ->filter()
                ->values();
        }

        return collect($items)
            ->map(fn ($item) => self::rowFrom($item, $kind))
            ->filter()
            ->values();
    }

    public static function code(mixed $item): string
    {
        $row = self::rowFrom($item, self::guessKind($item));

        return $row?->code ?? '';
    }

    public static function name(mixed $item, string $kind = 'country'): string
    {
        $row = self::rowFrom($item, $kind === 'language' ? 'language' : 'country');

        return $row?->name ?? '';
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function toCachePayload(Collection $rows): array
    {
        return $rows
            ->map(fn ($row) => [
                'code' => strtolower(trim((string) ($row->code ?? ''))),
                'name' => trim((string) ($row->name ?? '')),
            ])
            ->filter(fn (array $row) => $row['code'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $legacyKeys
     * @return Collection<int, object{code: string, name: string}>
     */
    private static function remember(string $key, array $legacyKeys, string $kind, callable $fresh): Collection
    {
        $cached = Cache::get($key);
        if ($cached !== null) {
            $rows = self::normalize($cached, $kind);
            if ($rows->isNotEmpty()) {
                return $rows;
            }
        }

        foreach ($legacyKeys as $legacyKey) {
            $legacy = Cache::get($legacyKey);
            if ($legacy === null) {
                continue;
            }
            $rows = self::normalize($legacy, $kind);
            if ($rows->isNotEmpty()) {
                Cache::put($key, self::toCachePayload($rows), self::CACHE_TTL_SECONDS);

                return $rows;
            }
        }

        $rows = self::normalize($fresh(), $kind);
        Cache::put($key, self::toCachePayload($rows), self::CACHE_TTL_SECONDS);

        return $rows;
    }

    /**
     * @return list<mixed>|array<string, mixed>
     */
    private static function unwrap(mixed $raw): array
    {
        if ($raw instanceof Collection) {
            return $raw->all();
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return array_values(array_filter(array_map('trim', preg_split('/[|,]+/', $trimmed) ?: [])));
        }

        if (is_object($raw)) {
            return [$raw];
        }

        return [];
    }

    /**
     * @param  array<mixed>  $items
     */
    private static function isCodeNameMap(array $items): bool
    {
        if ($items === [] || array_is_list($items) || self::looksLikeRow($items)) {
            return false;
        }

        foreach ($items as $key => $value) {
            if (! is_string($key) || $key === '') {
                return false;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if (! is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>|object  $item
     */
    private static function looksLikeRow(array|object $item): bool
    {
        if (is_object($item)) {
            return isset($item->code) || isset($item->name);
        }

        return array_key_exists('code', $item) || array_key_exists('name', $item);
    }

    private static function rowFrom(mixed $item, string $kind, ?string $fallbackCode = null): ?object
    {
        if ($item === null || $item === '') {
            return null;
        }

        if (is_string($item) || is_int($item) || is_float($item)) {
            return self::rowFromString((string) $item, $kind);
        }

        if (! is_array($item) && ! is_object($item)) {
            return null;
        }

        $code = strtolower(trim(scalar_text(data_get($item, 'code'))));
        $name = trim(scalar_text(data_get($item, 'name')));

        if ($code === '' && is_string($fallbackCode)) {
            $code = strtolower(trim($fallbackCode));
        }

        if ($code === '' && $name !== '') {
            return self::rowFromString($name, $kind);
        }

        if ($code === '') {
            return is_string($fallbackCode) ? self::rowFromString($fallbackCode, $kind) : null;
        }

        if ($kind === 'country') {
            $code = CountryCode::normalize($code) ?? $code;
            if ($name === '') {
                $name = self::countryName($code);
            }
        } elseif ($name === '') {
            $name = self::languageName($code);
        }

        return (object) [
            'code' => $code,
            'name' => $name !== '' ? $name : strtoupper($code),
        ];
    }

    private static function rowFromString(string $value, string $kind, ?string $explicitName = null): ?object
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($kind === 'country') {
            $code = CountryCode::normalize($value) ?? (strlen($value) === 2 && ctype_alpha($value) ? strtolower($value) : null);
            if ($code === null) {
                $code = strtolower($value);
            }
            $name = trim((string) $explicitName);
            if ($name === '' || $name === $value) {
                $lookedUp = self::countryName($code);
                if ($lookedUp !== '' && strtoupper($lookedUp) !== strtoupper($code)) {
                    $name = $lookedUp;
                } elseif ($name === '') {
                    $name = CountryCode::displayName($value);
                }
            }

            return (object) [
                'code' => $code,
                'name' => $name !== '' ? $name : strtoupper($code),
            ];
        }

        $code = strtolower($value);
        $name = trim((string) $explicitName);
        if ($name === '') {
            $name = self::languageName($code);
        }

        return (object) [
            'code' => $code,
            'name' => $name !== '' ? $name : strtoupper($code),
        ];
    }

    private static function countryName(string $code): string
    {
        if (function_exists('fullCountry')) {
            $name = trim((string) fullCountry($code));
            if ($name !== '' && strtoupper($name) !== strtoupper($code)) {
                return $name;
            }
        }

        return CountryCode::displayName($code);
    }

    private static function languageName(string $code): string
    {
        if (function_exists('fullLanguage')) {
            $name = trim((string) fullLanguage($code));
            if ($name !== '') {
                return $name;
            }
        }

        return strtoupper($code);
    }

    private static function guessKind(mixed $item): string
    {
        $code = is_string($item)
            ? strtolower(trim($item))
            : strtolower(trim(scalar_text(data_get($item, 'code'))));

        if ($code !== '' && function_exists('marketplace_languages') && isset(marketplace_languages()[$code])
            && (! function_exists('marketplace_countries') || ! isset(marketplace_countries()[$code]))) {
            return 'language';
        }

        return 'country';
    }
}
