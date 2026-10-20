<?php

namespace App\Services\Advertiser;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Fan advertiser-facing order events from the website publisher/admin path.
 */
class AdvertiserOrderWebhookBridge
{
    public function __construct(
        private AdvertiserWebhookService $webhooks,
        private AdvertiserApiSerializer $serializer,
    ) {}

    public function emitForItem(OrderItem $item, string $type): void
    {
        $item->loadMissing('order');
        $order = $item->order;
        if (! $order instanceof Order) {
            return;
        }
        $this->emitForOrder($order, $type);
    }

    public function emitForOrder(Order $order, string $type): void
    {
        $user = User::query()->find($order->user_id);
        if (! $user || ! $user->hasRole('advertiser')) {
            return;
        }
        $livemode = ! AdvertiserSandbox::isSandboxOrder($order);
        try {
            $this->webhooks->emit($user, $type, [
                'object' => $this->serializer->order($user, $order->fresh(['items']) ?: $order),
            ], $livemode);
        } catch (\Throwable $e) {
            Log::warning('Advertiser order webhook bridge failed', [
                'type' => $type,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
