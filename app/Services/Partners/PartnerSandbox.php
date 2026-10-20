<?php

namespace App\Services\Partners;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PartnerApiKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PartnerSandbox
{
    protected static ?bool $columnsReady = null;

    public static function currentIsTest(?Request $request = null): bool
    {
        $request ??= request();
        $key = $request->attributes->get('partner_api_key');

        return $key instanceof PartnerApiKey && $key->mode === 'test';
    }

    /**
     * Sandbox isolation needs is_sandbox on both orders and order_items.
     * True is cached for the process; false is re-checked so applying the
     * SQL without a restart can recover. Tests may force either value.
     */
    public static function columnsReady(): bool
    {
        if (self::$columnsReady !== null) {
            return self::$columnsReady;
        }
        $ready = Order::hasSandboxColumn() && OrderItem::hasSandboxColumn();
        if ($ready) {
            self::$columnsReady = true;
        }

        return $ready;
    }

    public static function assertAvailable(): void
    {
        if (! self::columnsReady()) {
            throw new PartnerSandboxUnavailableException;
        }
    }

    public static function forceColumnsReady(?bool $ready): void
    {
        self::$columnsReady = $ready;
        Order::forceSandboxColumnCache($ready);
        OrderItem::forceSandboxColumnCache($ready);
    }

    public static function forgetColumnCache(): void
    {
        self::$columnsReady = null;
        Order::forgetSandboxColumnCache();
        OrderItem::forgetSandboxColumnCache();
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
