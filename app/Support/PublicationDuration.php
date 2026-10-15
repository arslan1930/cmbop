<?php

namespace App\Support;

/**
 * How long a guest post stays live on a listing.
 * Stored values stay short slugs so sites.publication_time (string/20 or enum) fits.
 */
class PublicationDuration
{
    public const SIX_MONTHS = '6months';

    public const ONE_YEAR = '1year';

    public const TWO_YEARS = '2years';

    public const THREE_YEARS = '3years';

    public const PERMANENT = 'permanent';

    public const DEFAULT = self::PERMANENT;

    /** @var list<string> */
    public const VALUES = [
        self::SIX_MONTHS,
        self::ONE_YEAR,
        self::TWO_YEARS,
        self::THREE_YEARS,
        self::PERMANENT,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::SIX_MONTHS => '6 Months',
        self::ONE_YEAR => '1 Year',
        self::TWO_YEARS => '2 Years',
        self::THREE_YEARS => '3 Years',
        self::PERMANENT => 'Permanent',
    ];

    /** @var array<string, string> Catalog / sentence-case labels. */
    public const CATALOG_LABELS = [
        self::SIX_MONTHS => '6 months',
        self::ONE_YEAR => '1 year',
        self::TWO_YEARS => '2 years',
        self::THREE_YEARS => '3 years',
        self::PERMANENT => 'Permanent',
    ];

    /**
     * @return array<string, string> value => form label
     */
    public static function options(): array
    {
        return self::LABELS;
    }

    /**
     * Catalog More-filters select: Any plus every stored slug.
     *
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        return ['' => 'Any'] + self::LABELS;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return self::VALUES;
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::VALUES);
    }

    public static function csvHint(): string
    {
        return implode(', ', array_map(static fn (string $value) => '`'.$value.'`', self::VALUES));
    }

    public static function helpText(): string
    {
        return 'How long the published article and link stay live. Permanent means you do not take the post down after a set term. 2 Years and 3 Years sit between 1 Year and Permanent.';
    }

    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $raw = strtolower(trim((string) $value));
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;

        return match ($raw) {
            '6months', '6month', '6mo' => self::SIX_MONTHS,
            '1year', '1yr', '12months' => self::ONE_YEAR,
            '2years', '2year', '2yrs', '24months' => self::TWO_YEARS,
            '3years', '3year', '3yrs', '36months' => self::THREE_YEARS,
            'permanent', 'forever' => self::PERMANENT,
            default => in_array($raw, self::VALUES, true) ? $raw : null,
        };
    }

    public static function label(?string $value, ?string $fallback = null): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return $fallback;
        }

        return self::LABELS[$normalized];
    }

    public static function catalogLabel(?string $value, ?string $fallback = null): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return $fallback;
        }

        return self::CATALOG_LABELS[$normalized];
    }
}
