<?php

namespace App\Services\VerifiedTraffic;

use RuntimeException;

class GoogleTrafficException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function isAuthFailure(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
