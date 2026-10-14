<?php

namespace App\Support;

/**
 * Shared mailbox validation before queueing SMTP work.
 */
class MailAddress
{
    public static function isValid(mixed $email): bool
    {
        $value = strtolower(trim((string) $email));
        if ($value === '' || strlen($value) > 190) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $host = substr(strrchr($value, '@') ?: '', 1);
        if ($host === '' || ! str_contains($host, '.')) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host);
    }

    public static function normalize(mixed $email): ?string
    {
        $value = strtolower(trim((string) $email));

        return self::isValid($value) ? $value : null;
    }
}
