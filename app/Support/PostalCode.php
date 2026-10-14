<?php

namespace App\Support;

/**
 * Billing postal codes: letters, digits, spaces and hyphens (UK-friendly).
 */
class PostalCode
{
    public const PATTERN = '/^(?=.*[A-Za-z0-9])[A-Za-z0-9 \-]{2,10}$/';

    public const MESSAGE = 'Postal code may contain letters, numbers, spaces and hyphens (2–10 characters).';

    public static function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        return strtoupper($trimmed);
    }

    public static function isValid(?string $value): bool
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return false;
        }

        return (bool) preg_match(self::PATTERN, $normalized);
    }

    /**
     * @return list<string>
     */
    public static function rules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'regex:'.self::PATTERN,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'postal_code.regex' => self::MESSAGE,
            'postal_code.required' => self::MESSAGE,
        ];
    }
}
