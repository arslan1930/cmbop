<?php

if (! function_exists('scalar_text')) {
    /**
     * First usable scalar as a string.
     *
     * PHP casts a non-empty array to "Array" (warning → Laravel 500) or 1
     * when forced through (int)/(string). Query params like ?q[]= and nested
     * form fields hit this on Sites list, records, and My Sites ajax.
     */
    function scalar_text(mixed $value): string
    {
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($item) use (&$flat) {
                if (is_scalar($item)) {
                    $flat[] = $item;
                }
            });

            $value = $flat[0] ?? '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_object($value) && ! method_exists($value, '__toString')) {
            return '';
        }

        return (string) ($value ?? '');
    }
}

if (! function_exists('search_text')) {
    /**
     * Trimmed search/filter string, or empty when the value is not a string.
     *
     * Query params like ?search[]=x used to 500 via (string) cast or LIKE
     * interpolation ("Array to string conversion"). Ignore non-strings — same
     * as admin payments / finance ledger — instead of flattening the first item.
     */
    function search_text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}

if (! function_exists('like_contains')) {
    /**
     * LIKE pattern that matches a literal needle (%, _, \ are escaped).
     *
     * Pair with `ESCAPE '\\'` so SQLite and MySQL treat the backslashes
     * as escapes instead of leftover wildcards.
     */
    function like_contains(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value).'%';
    }
}

if (! function_exists('filter_number')) {
    /**
     * Numeric query/body value, or null when missing / non-scalar / non-numeric.
     *
     * Arrays like ?price_min[]=10 used to 500 when bound into whereRaw().
     */
    function filter_number(mixed $value): ?float
    {
        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return $value;
        }

        $text = search_text($value);
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }
}

if (! function_exists('scalar_list')) {
    /**
     * Flatten nested arrays into unique non-empty strings.
     *
     * Blade implode() and (string) casts 500 when a stored JSON list holds
     * objects or nested arrays (evaluation_report.matched_terms, etc.).
     *
     * @return list<string>
     */
    function scalar_list(mixed $value): array
    {
        if (! is_array($value)) {
            $text = trim(scalar_text($value));

            return $text !== '' ? [$text] : [];
        }

        $flat = [];
        array_walk_recursive($value, function ($item) use (&$flat) {
            if (is_bool($item)) {
                return;
            }
            if (is_scalar($item) || $item instanceof Stringable) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $flat[] = $text;
                }
            }
        });

        return array_values(array_unique($flat));
    }
}

if (! function_exists('old_text')) {
    /**
     * Old input for a field that must render as a single value.
     *
     * `{{ old('title') }}` compiles to htmlspecialchars(), which throws a
     * TypeError on an array and takes the whole page down with it. The old-input
     * bag is shared across the session and keyed only by field name, so one
     * request posting `title[]=` — a fuzzer, a scanner, a malformed AJAX submit —
     * poisons every later GET of any form with a `title` field. The page in
     * question need never have been posted to at all.
     *
     * Use this wherever a text, url, number or date input is redisplayed. Fields
     * that genuinely hold a list, like `categories[]`, should keep using old()
     * and iterate it.
     */
    function old_text(string $key, mixed $default = null): string
    {
        return scalar_text(old($key, $default));
    }
}

if (! function_exists('old_checked')) {
    /**
     * Checkbox state that stays unchecked after a validation redirect.
     *
     * Unchecked boxes are omitted from the POST, so they never land in the
     * old-input bag. Falling back to the model default would re-check them.
     */
    function old_checked(string $key, bool $default = false): bool
    {
        $old = session()->get('_old_input');
        if (is_array($old)) {
            if (! array_key_exists($key, $old)) {
                return false;
            }

            return filter_var($old[$key], FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }
}

if (! function_exists('blade_e')) {
    /**
     * Blade {{ }} echo that never hands an array to htmlspecialchars().
     *
     * Laravel compiles `{{ $value }}` to e(), and e() TypeErrors on arrays.
     * Promotions (and any other view) still 500s when a leftover `title[]`,
     * flashed list, or validation line is an array — even after individual
     * fields were switched to old_text()/scalar_text(). Flatten first, then
     * escape exactly as e() would.
     */
    function blade_e(mixed $value, bool $doubleEncode = true): string
    {
        if (is_array($value)) {
            $value = scalar_text($value);
        }

        return e($value, $doubleEncode);
    }
}
