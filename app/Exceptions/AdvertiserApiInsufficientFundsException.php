<?php

namespace App\Exceptions;

use RuntimeException;

class AdvertiserApiInsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly float $required,
        public readonly float $available,
        string $message = 'Insufficient wallet balance.',
    ) {
        parent::__construct($message);
    }
}
