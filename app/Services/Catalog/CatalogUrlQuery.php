<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Http\Request;

/**
 * Catalog listing query string — the allowlisted source of truth for
 * refresh, share links, filter chips, and (later) live results fetches.
 */
class CatalogUrlQuery
{
    /**
     * Keys that define catalog listing state.
     *
     * @var list<string>
     */
    public const KEYS = [
        'search',
        'category',
        'country',
        'language',
        'price_min',
        'price_max',
        'da_min',
        'da_max',
        'dr_min',
        'dr_max',
        'traffic_min',
        'traffic_max',
        'sponsored',
        'favorites_filter',
        'blacklist_filter',
        'bulk_deals',
        'new_badge',
        'on_sale',
        'verified',
        'quality',
        'rating_min',
        'has_completions',
        'site',
        'sort',
        'per_page',
        'page',
        // Contextual chrome — keep across filter navigation when present.
        'wizard',
    ];

    /** Server / form default sort — omit from the URL when unchanged. */
    public const DEFAULT_SORT = 'dr_desc';

    /** Allowed catalog page sizes; default omitted from share URLs. */
    public const DEFAULT_PER_PAGE = 20;

    /** @var list<int> */
    public const ALLOWED_PER_PAGE = [10, 20, 25, 50];

    /**
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        return self::canonicalize($request->query());
    }

    /**
     * Clamp listing page size to the allowlist (invalid → default 20).
     */
    public static function perPage(Request|array $input): int
    {
        $raw = $input instanceof Request
            ? $input->input('per_page')
            : ($input['per_page'] ?? null);

        $value = (int) scalar_text($raw);
        if (in_array($value, self::ALLOWED_PER_PAGE, true)) {
            return $value;
        }

        return self::DEFAULT_PER_PAGE;
    }

    /**
     * Keep allowlisted keys with non-empty scalar values.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function fromArray(array $input): array
    {
        $out = [];

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if ($value === null || is_array($value)) {
                continue;
            }

            $string = trim((string) $value);
            if ($string === '') {
                continue;
            }

            $out[$key] = $string;
        }

        return $out;
    }

    /**
     * Drop empty defaults so refresh/share URLs stay stable and short.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function canonicalize(array $input): array
    {
        $params = self::fromArray($input);

        if (($params['sort'] ?? null) === self::DEFAULT_SORT) {
            unset($params['sort']);
        }

        if (array_key_exists('per_page', $params)) {
            $perPage = (int) $params['per_page'];
            if (! in_array($perPage, self::ALLOWED_PER_PAGE, true)
                || $perPage === self::DEFAULT_PER_PAGE) {
                unset($params['per_page']);
            } else {
                $params['per_page'] = (string) $perPage;
            }
        }

        if (($params['page'] ?? null) === '1') {
            unset($params['page']);
        }

        return $params;
    }

    /**
     * Build query for chip-remove / clear-filter links.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $drop
     * @return array<string, string>
     */
    public static function except(array $query, array $drop, bool $dropPage = true): array
    {
        $clean = self::fromArray($query);

        foreach ($drop as $key) {
            unset($clean[$key]);
        }

        if ($dropPage) {
            unset($clean['page']);
        }

        return self::canonicalize($clean);
    }

    /**
     * Remove one niche from category= (pipe wire format), keep the rest.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function withoutCategoryNiche(array $query, string $niche): array
    {
        $clean = self::fromArray($query);
        $raw = (string) ($clean['category'] ?? '');
        $needle = trim(html_entity_decode($niche, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($raw === '' || $needle === '') {
            return self::except($query, ['category']);
        }

        $remaining = [];
        foreach (Category::parseCatalogCategoryParam($raw) as $token) {
            $resolved = Category::resolveNicheNames([$token]);
            $name = $resolved['resolved'][0] ?? $resolved['unknown'][0] ?? $token;
            if (strcasecmp($name, $needle) === 0 || strcasecmp($token, $needle) === 0) {
                continue;
            }
            $remaining[] = $name;
        }

        $encoded = Category::encodeCatalogCategoryParam($remaining);
        if ($encoded === '') {
            unset($clean['category']);
        } else {
            $clean['category'] = $encoded;
        }

        unset($clean['page']);

        return self::canonicalize($clean);
    }
}
