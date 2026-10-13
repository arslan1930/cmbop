<?php

namespace App\Support;

/**
 * Staff-only archive category. Never emailed to publishers.
 */
class StaffArchiveReason
{
    public const BELOW_QUALITY = 'below_quality';

    public const DUPLICATE = 'duplicate';

    public const SPAM = 'spam';

    public const PUBLISHER_REQUEST = 'publisher_request';

    public const OTHER = 'other';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::BELOW_QUALITY => 'Below quality bar',
            self::DUPLICATE => 'Duplicate',
            self::SPAM => 'Spam',
            self::PUBLISHER_REQUEST => 'Publisher request',
            self::OTHER => 'Other',
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::options());
    }

    public static function normalize(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return in_array($text, self::codes(), true) ? $text : null;
    }

    public static function label(?string $code): ?string
    {
        $code = self::normalize($code);

        return $code !== null ? self::options()[$code] : null;
    }
}
