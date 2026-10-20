<?php

namespace App\Services\Partners;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PartnerApiKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PartnerSandbox
{
    public static function currentIsTest(?Request $request = null): bool
    {
        $request ??= request();
        $key = $request->attributes->get('partner_api_key');

        return $key instanceof PartnerApiKey && $key->mode === 'test';
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

    /**
     * @template T of Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function exclude($query)
    {
        $model = method_exists($query, 'getModel') ? $query->getModel() : null;
        $table = $model?->getTable();
        if ($table === 'orders') {
            return $query->notSandbox();
        }
        if ($table === 'order_items') {
            return $query->notSandbox();
        }

        return $query;
    }
}
