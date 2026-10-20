<?php

namespace App\Services\Advertiser;

use App\Models\AdvertiserApiToken;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class AdvertiserSandbox
{
    public static function currentIsTest(?Request $request = null): bool
    {
        $request ??= request();
        $token = $request->attributes->get('advertiser_api_token');

        return $token instanceof AdvertiserApiToken && $token->isTest();
    }

    public static function schemaReady(): bool
    {
        return Schema::hasColumn('orders', 'is_sandbox')
            && Schema::hasColumn('order_items', 'is_sandbox');
    }

    public static function failClosedResponse(): Response
    {
        return response()->json([
            'status' => 'error',
            'error' => 'sandbox_unavailable',
            'message' => 'Test-mode keys need the sandbox columns. Apply database/sql/add_advertiser_ordering_api.sql and retry.',
        ], 503);
    }

    public static function isSandboxOrder(?Order $order): bool
    {
        if (! $order) {
            return false;
        }
        if ($order->isSandbox()) {
            return true;
        }

        return $order->payment_method === 'sandbox';
    }

    public static function isSandboxItem(?OrderItem $item): bool
    {
        if (! $item) {
            return false;
        }
        if ($item->isSandbox()) {
            return true;
        }
        $item->loadMissing('order');

        return $item->order instanceof Order && self::isSandboxOrder($item->order);
    }

    /**
     * @template T of Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function constrain($query, bool $test)
    {
        if ($test) {
            return $query->sandboxOnly();
        }

        return $query->notSandbox();
    }
}
