<?php

namespace App\Support;

/**
 * Classify SMTP exceptions so 451 rate-limits retry and 554/5xx do not.
 */
class SmtpFailure
{
    public static function isPermanent(\Throwable $exception): bool
    {
        $haystack = self::haystack($exception);

        foreach (['554', '550', '553', '501', '503', '504'] as $code) {
            if (self::hasSmtpCode($haystack, $code)) {
                return true;
            }
        }

        return str_contains($haystack, 'user unknown')
            || str_contains($haystack, 'mailbox unavailable')
            || str_contains($haystack, 'spam')
            || str_contains($haystack, 'rejected') && str_contains($haystack, 'policy');
    }

    public static function isTransient(\Throwable $exception): bool
    {
        $haystack = self::haystack($exception);

        return self::hasSmtpCode($haystack, '451')
            || self::hasSmtpCode($haystack, '421')
            || self::hasSmtpCode($haystack, '450')
            || str_contains($haystack, 'try again later')
            || str_contains($haystack, 'rate limit')
            || str_contains($haystack, 'throttl');
    }

    private static function hasSmtpCode(string $haystack, string $code): bool
    {
        return (bool) preg_match('/\b'.$code.'\b/', $haystack);
    }

    private static function haystack(\Throwable $exception): string
    {
        $parts = [$exception->getMessage()];
        $previous = $exception->getPrevious();
        while ($previous) {
            $parts[] = $previous->getMessage();
            $previous = $previous->getPrevious();
        }

        return strtolower(implode(' ', $parts));
    }
}
