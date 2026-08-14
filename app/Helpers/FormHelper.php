<?php

if (! function_exists('display_text')) {
    /**
     * Flatten a session/old-input value so Blade `{{ }}` cannot TypeError.
     */
    function display_text(mixed $value): string
    {
        if (is_array($value)) {
            // Keep the first usable scalar rather than discarding what the person
            // typed: they still have to see and correct it.
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
        return display_text(old($key, $default));
    }
}

if (! function_exists('session_text')) {
    /**
     * Flash/session value for Blade `{{ }}`. Same TypeError as old() when the
     * bag holds an array — and that bag is also session-wide.
     */
    function session_text(string $key, mixed $default = null): string
    {
        return display_text(session($key, $default));
    }
}
