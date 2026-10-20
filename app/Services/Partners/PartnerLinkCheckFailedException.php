<?php

namespace App\Services\Partners;

use RuntimeException;

class PartnerLinkCheckFailedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $check
     */
    public function __construct(string $message, public array $check = [])
    {
        parent::__construct($message);
    }
}
