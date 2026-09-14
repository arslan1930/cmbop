<?php

namespace App\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Decides what an end user is allowed to read from a caught exception.
 *
 * Domain code deliberately throws readable messages ("Insufficient balance",
 * "This promotional bonus cannot be withdrawn") and those should survive.
 * Infrastructure failures must not: a publisher should never see
 * "SQLSTATE[42S22]: Unknown column ..." in a popup.
 */
class UserFacingError
{
    /**
     * Exception types that are never safe to show — they carry SQL, file paths
     * or engine internals.
     *
     * @var array<int, class-string>
     */
    private const INTERNAL_TYPES = [
        QueryException::class,
        ModelNotFoundException::class,
        \PDOException::class,
        \ErrorException::class,
        \Error::class, // TypeError, ValueError, ArgumentCountError, ...
        \JsonException::class,
    ];

    /**
     * Fragments that mark a message as internal even on a generic Exception.
     *
     * @var array<int, string>
     */
    private const INTERNAL_FRAGMENTS = [
        'SQLSTATE',
        'SQL:',
        'Call to ',
        'Undefined ',
        'Class "',
        'syntax error',
        'Connection refused',
        'cURL',
        'No such file',
        'Trying to access',
        'must be of type',
        'Too few arguments',
        'vendor/',
        '.php',
        '::__',
        'stack trace',
        'No query results',
        'App\\Models',
    ];

    /**
     * Message a user may safely read, falling back to $fallback for internals.
     * Always logs the underlying exception with context.
     *
     * @param  array<string, mixed>  $context
     */
    public static function message(\Throwable $e, string $fallback, array $context = []): string
    {
        Log::error($fallback, array_merge($context, [
            'exception' => $e::class,
            'error' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]));

        return self::isSafe($e) ? trim($e->getMessage()) : $fallback;
    }

    /**
     * Extra exception detail for JSON 500s when APP_DEBUG is on.
     * Production stays message-only so internals never leak in the payload.
     */
    public static function debugDetail(\Throwable $e): ?string
    {
        if (! config('app.debug')) {
            return null;
        }

        return $e::class.': '.$e->getMessage();
    }

    /**
     * Same fragment/length rules as {@see isSafe()}, for stored provider text
     * that is not wrapped in a Throwable.
     */
    public static function safeText(?string $message, string $fallback): string
    {
        $message = trim((string) $message);

        if ($message === '') {
            return $fallback;
        }

        return self::isSafe(new \RuntimeException($message)) ? $message : $fallback;
    }

    /**
     * Whether the exception message can be shown verbatim to an end user.
     */
    public static function isSafe(\Throwable $e): bool
    {
        foreach (self::INTERNAL_TYPES as $type) {
            if ($e instanceof $type) {
                return false;
            }
        }

        $message = trim($e->getMessage());

        if ($message === '') {
            return false;
        }

        // Long messages are almost always dumps rather than a sentence for a user.
        if (Str::length($message) > 200) {
            return false;
        }

        foreach (self::INTERNAL_FRAGMENTS as $fragment) {
            if (Str::contains($message, $fragment, ignoreCase: true)) {
                return false;
            }
        }

        return true;
    }
}
