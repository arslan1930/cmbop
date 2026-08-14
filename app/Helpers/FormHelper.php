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

if (! function_exists('csv_text')) {
    /**
     * Comma-separated filter value. Arrays become "de,fr" instead of 500ing
     * explode() / htmlspecialchars() on catalog country and language.
     */
    function csv_text(mixed $value): string
    {
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($item) use (&$flat) {
                if (is_scalar($item) && ! is_bool($item)) {
                    $part = trim((string) $item);
                    if ($part !== '') {
                        $flat[] = $part;
                    }
                }
            });

            return implode(',', $flat);
        }

        return scalar_text($value);
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
