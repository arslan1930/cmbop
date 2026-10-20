<?php

namespace App\Services\Partners;

use Exception;
use Illuminate\Http\JsonResponse;

class PartnerSandboxUnavailableException extends Exception
{
    public function __construct(string $message = 'Sandbox is unavailable until the is_sandbox columns exist on orders and order_items.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return self::jsonResponse();
    }

    public static function jsonResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'sandbox_unavailable',
            'message' => 'Sandbox is unavailable until the is_sandbox columns exist on orders and order_items.',
        ], 503);
    }
}
