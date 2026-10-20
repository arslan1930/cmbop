<?php

namespace App\Services\Partners;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Maps existing marketplace order statuses onto partner dashboard tiles.
 */
class PartnerOrderStatus
{
    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const SUBMITTED = 'submitted';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const AWAITING_CORRECTION = 'awaiting_correction';

    /** @deprecated Use AWAITING_CORRECTION */
    public const NEEDS_CORRECTION = self::AWAITING_CORRECTION;

    public const DISPUTED = 'disputed';

    /** @var list<string> */
    public const TILES = [
        self::PENDING,
        self::IN_PROGRESS,
        self::SUBMITTED,
        self::AWAITING_CORRECTION,
        self::DISPUTED,
        self::COMPLETED,
        self::REJECTED,
    ];

    public static function labels(): array
    {
        return [
            self::PENDING => 'New / pending',
            self::IN_PROGRESS => 'In progress',
            self::SUBMITTED => 'Submitted',
            self::AWAITING_CORRECTION => 'Awaiting correction',
            self::DISPUTED => 'Disputed',
            self::COMPLETED => 'Completed',
            self::REJECTED => 'Rejected',
        ];
    }

    public static function fromItem(OrderItem $item): string
    {
        $order = $item->order;
        $orderStatus = $order instanceof Order ? (string) $order->status : '';

        if ($orderStatus === 'cancelled') {
            return self::REJECTED;
        }
        if ((string) ($item->partner_dispute_status ?? '') === 'open') {
            return self::DISPUTED;
        }
        if ($orderStatus === 'completed') {
            return self::COMPLETED;
        }
        if ($item->isModificationRequested()) {
            return self::AWAITING_CORRECTION;
        }
        if ($orderStatus === 'review') {
            return self::SUBMITTED;
        }
        if ($orderStatus === 'processing') {
            return self::IN_PROGRESS;
        }

        return self::PENDING;
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}
