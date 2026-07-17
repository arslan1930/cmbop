<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Order;

class BillingEventLogger
{
    public function log(
        string $eventType,
        ?Invoice $invoice = null,
        ?Order $order = null,
        ?int $userId = null,
        array $meta = []
    ): BillingEvent {
        return BillingEvent::create([
            'event_type' => $eventType,
            'invoice_id' => $invoice?->id,
            'order_id' => $order?->id ?? $invoice?->order_id,
            'user_id' => $userId ?? $invoice?->user_id ?? $order?->user_id,
            'meta' => $meta ?: null,
        ]);
    }
}
