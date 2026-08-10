<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    protected $fillable = ['name', 'group'];

    /**
     * Niches whose canonical names contain commas — catalog must never treat
     * those commas as multi-select separators. Keep in sync with
     * CategoriesTableSeeder (DB is the catalog picker source of truth).
     *
     * @var list<string>
     */
    public const NICHES_CONTAINING_COMMA = [
        'Events, Conferences & Trade Fairs',
        'Marketing, PR & Advertising',
        'NGOs, Charity & Social Impact',
    ];

    public function sites()
    {
        return $this->hasMany(Site::class, 'category', 'name');
    }

    /**
     * Catalog filter options from the categories table (publisher/admin source of truth).
     *
     * @return list<array{name: string, group: string}>
     */
    public static function catalogPickerRows(): array
    {
        return Cache::remember('category_catalog_picker_rows', 300, function () {
            return static::query()
                ->orderBy('group')
                ->orderBy('name')
                ->get(['name', 'group'])
                ->map(static fn (self $row): array => [
                    'name' => (string) $row->name,
                    'group' => trim((string) ($row->group ?? '')),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * Flat A–Z niche names for the catalog multi-select + JS known-names list.
     *
     * @return list<string>
     */
    public static function catalogPickerNames(): array
    {
        return collect(self::catalogPickerRows())
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Catalog category= wire format: `|` between niches (publisher-aligned).
     * Country/language params stay comma-separated (codes never contain commas).
     *
     * @param  list<string>  $names
     */
    public static function encodeCatalogCategoryParam(array $names): string
    {
        return implode('|', array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $names
        ), static fn ($n) => $n !== '')));
    }

    /**
     * Parse + re-encode category= to the pipe wire format.
     *
     * Known niches become canonical Category::name values; unknown tokens are
     * kept (so odd deep-links are not silently dropped). Legacy comma URLs
     * that parse into multiple niches are rewritten with `|`.
     */
    public static function canonicalizeCatalogCategoryParam(?string $raw): string
    {
        $tokens = self::parseCatalogCategoryParam($raw);
        if ($tokens === []) {
            return '';
        }

        $out = [];
        foreach ($tokens as $token) {
            $one = self::resolveNicheNames([$token]);
            $out[] = $one['resolved'][0] ?? $one['unknown'][0] ?? $token;
        }

        return self::encodeCatalogCategoryParam($out);
    }

    /**
     * Parse catalog category= query/hidden-field value into niche tokens.
     *
     * Rules:
     * - If the value contains `|`, split on `|` only.
     * - Else never blindly explode on `,`: match known niche names longest-first
     *   so "Marketing, PR & Advertising" stays one token, while legacy
     *   "Health & Wellness,Technology & Gadgets" still yields two.
     *
     * @param  list<string>|null  $knownNames  Canonical names; defaults to DB map.
     * @return list<string>
     */
    public static function parseCatalogCategoryParam(?string $raw, ?array $knownNames = null): array
    {
        $normalize = static function (string $v): string {
            return trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        };

        $str = $normalize((string) $raw);
        if ($str === '') {
            return [];
        }

        if (str_contains($str, '|')) {
            return array_values(array_filter(array_map($normalize, explode('|', $str)), static fn ($v) => $v !== ''));
        }

        $known = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $knownNames ?? array_values(self::nicheLookupMaps()['by_name'])
        ), static fn ($n) => $n !== ''));

        // Whole-string exact match (case-insensitive) → single niche.
        foreach ($known as $name) {
            if (strcasecmp($name, $str) === 0) {
                return [$name];
            }
        }

        usort($known, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $remaining = $str;
        $out = [];

        while ($remaining !== '') {
            $remaining = ltrim($remaining);
            if ($remaining === '') {
                break;
            }

            if (str_starts_with($remaining, ',')) {
                $remaining = ltrim(substr($remaining, 1));

                continue;
            }

            $matched = null;
            foreach ($known as $name) {
                $len = mb_strlen($name);
                if ($len === 0 || mb_strlen($remaining) < $len) {
                    continue;
                }
                if (strcasecmp(mb_substr($remaining, 0, $len), $name) !== 0) {
                    continue;
                }

                $after = mb_substr($remaining, $len);
                $afterTrim = ltrim($after);
                // Boundary: end, or a list separator (comma / pipe).
                if ($after === '' || $afterTrim === '' || str_starts_with($afterTrim, ',') || str_starts_with($afterTrim, '|')) {
                    $matched = $name;
                    $remaining = $afterTrim;
                    if (str_starts_with($remaining, ',')) {
                        $remaining = ltrim(substr($remaining, 1));
                    } elseif (str_starts_with($remaining, '|')) {
                        $remaining = ltrim(substr($remaining, 1));
                    }
                    break;
                }
            }

            if ($matched === null) {
                // Unknown remainder: take until next comma (legacy garbage) or all.
                $pos = strpos($remaining, ',');
                if ($pos === false) {
                    $out[] = $normalize($remaining);
                    break;
                }
                $out[] = $normalize(substr($remaining, 0, $pos));
                $remaining = ltrim(substr($remaining, $pos + 1));

                continue;
            }

            $out[] = $matched;
        }

        return array_values(array_filter($out, static fn ($v) => $v !== ''));
    }

    /**
     * Labels for catalog row badges — never split a niche name on commas.
     *
     * @param  list<string>|null  $categories  JSON categories array when present.
     * @return list<string>
     */
    public static function displayNicheLabels(?array $categories, ?string $legacyCategory = null): array
    {
        $labels = [];

        if (is_array($categories) && $categories !== []) {
            foreach ($categories as $cat) {
                if (is_array($cat)) {
                    continue;
                }
                $name = trim(html_entity_decode((string) $cat, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($name !== '') {
                    $labels[] = $name;
                }
            }
        } elseif (is_string($legacyCategory) && trim($legacyCategory) !== '') {
            $labels = self::parseCatalogCategoryParam($legacyCategory);
        }

        return array_values(array_unique(array_filter($labels, static fn ($v) => $v !== '')));
    }

    /**
     * Resolve submitted niche labels to canonical Category::name values.
     *
     * Accepts exact niche names and group aliases (e.g. "Technology" →
     * "Technology & Gadgets"). HTML entities are decoded first so Blade-escaped
     * multi-select values still match.
     *
     * @param  iterable<int, mixed>|string|null  $raw
     * @return array{resolved: list<string>, unknown: list<string>}
     */
    public static function resolveNicheNames(iterable|string|null $raw): array
    {
        $inputs = self::normalizeNicheInputs($raw);
        if ($inputs === []) {
            return ['resolved' => [], 'unknown' => []];
        }

        $maps = self::nicheLookupMaps();
        $resolved = [];
        $unknown = [];

        foreach ($inputs as $input) {
            $key = strtolower($input);
            if (isset($maps['by_name'][$key])) {
                $resolved[] = $maps['by_name'][$key];

                continue;
            }
            if (isset($maps['by_group'][$key])) {
                $resolved[] = $maps['by_group'][$key];

                continue;
            }

            // Prefix fallback when group metadata is missing (e.g. Technology →
            // Technology & Gadgets) or urlencoded truncation left only the head.
            $prefixHit = null;
            foreach ($maps['by_name'] as $lower => $name) {
                if (str_starts_with($lower, $key.' &') || str_starts_with($lower, $key.'&') || str_starts_with($lower, $key.' ')) {
                    $prefixHit = $name;
                    break;
                }
            }
            if ($prefixHit !== null) {
                $resolved[] = $prefixHit;

                continue;
            }

            $unknown[] = $input;
        }

        return [
            'resolved' => array_values(array_unique($resolved)),
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * @param  iterable<int, mixed>|string|null  $raw
     * @return list<string>
     */
    public static function normalizeNicheInputs(iterable|string|null $raw): array
    {
        $normalize = static function ($v): string {
            $decoded = html_entity_decode(trim((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return trim($decoded);
        };

        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $str = $normalize($raw);
            if ($str === '') {
                return [];
            }

            return array_values(array_filter(array_map($normalize, preg_split('/\|/', $str) ?: [])));
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                foreach (self::normalizeNicheInputs($item) as $nested) {
                    $out[] = $nested;
                }

                continue;
            }
            $name = $normalize($item);
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return array_values($out);
    }

    /**
     * @return array{by_name: array<string, string>, by_group: array<string, string>}
     */
    public static function nicheLookupMaps(): array
    {
        return Cache::remember('category_niche_lookup_maps', 300, function () {
            $categories = static::query()
                ->orderBy('id')
                ->get(['id', 'name', 'group']);

            $byName = [];
            $byGroup = [];
            $grouped = [];

            foreach ($categories as $category) {
                $name = (string) $category->name;
                $group = trim((string) ($category->group ?? ''));
                $byName[strtolower($name)] = $name;
                if ($group !== '') {
                    $grouped[$group][] = $category;
                }
            }

            foreach ($grouped as $group => $rows) {
                $exact = null;
                $prefix = null;
                foreach ($rows as $row) {
                    $name = (string) $row->name;
                    if (strcasecmp($name, $group) === 0) {
                        $exact = $name;
                        break;
                    }
                    if ($prefix === null && str_starts_with(strtolower($name), strtolower($group))) {
                        $prefix = $name;
                    }
                }
                $byGroup[strtolower($group)] = $exact
                    ?? $prefix
                    ?? (string) $rows[0]->name;
            }

            return [
                'by_name' => $byName,
                'by_group' => $byGroup,
            ];
        });
    }

    /**
     * Forget cached niche lookup maps (tests / after category changes).
     */
    public static function flushNicheLookupCache(): void
    {
        Cache::forget('category_niche_lookup_maps');
        Cache::forget('category_catalog_picker_rows');
    }
}
