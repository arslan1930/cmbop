<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class InAppNotificationService
{
    public const TYPE_MESSAGE = 'message';

    public const TYPE_CHAT_REPLY = 'chat_reply';

    public const TYPE_ORDER_CREATED = 'order_created';

    public const TYPE_ORDER_ACCEPTED = 'order_accepted';

    public const TYPE_ORDER_REJECTED = 'order_rejected';

    public const TYPE_GUEST_POST_PUBLISHED = 'guest_post_published';

    public const TYPE_ORDER_COMPLETED = 'order_completed';

    public const TYPE_ORDER_UPDATED = 'order_updated';

    public const TYPE_MODIFICATION_REQUESTED = 'modification_requested';

    public const TYPE_PAYMENT_RECEIVED = 'payment_received';

    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_ACCOUNT = 'account';

    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_MESSAGES = 'messages';

    public const CATEGORY_PAYMENTS = 'payments';

    public const CATEGORY_SYSTEM = 'system';

    public const CATEGORY_SUPPORT = 'support';

    public const CATEGORY_ACCOUNT = 'account';

    /**
     * Create a persistent in-app notification for a user.
     */
    public function notify(
        User|int $user,
        string $type,
        string $title,
        ?string $message = null,
        array $options = []
    ): ?InAppNotification {
        try {
            $userId = $user instanceof User ? $user->id : (int) $user;

            $related = $options['related'] ?? null;
            $relatedType = $options['related_type'] ?? ($related instanceof Model ? get_class($related) : null);
            $relatedId = $options['related_id'] ?? ($related instanceof Model ? $related->getKey() : null);

            return InAppNotification::create([
                'user_id' => $userId,
                'audience' => $options['audience'] ?? $this->inferAudienceFromUrl($options['action_url'] ?? null),
                'type' => $type,
                'category' => $options['category'] ?? $this->categoryForType($type),
                'title' => $title,
                'message' => $message,
                'icon' => $options['icon'] ?? $this->iconForType($type),
                'priority' => $options['priority'] ?? InAppNotification::PRIORITY_NORMAL,
                'status' => InAppNotification::STATUS_UNREAD,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'action_label' => $options['action_label'] ?? 'View details',
                'action_url' => $options['action_url'] ?? null,
                'meta' => $options['meta'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create in-app notification: '.$e->getMessage(), [
                'type' => $type,
                'title' => $title,
            ]);

            return null;
        }
    }

    /**
     * Record an order timeline activity (does not notify by itself).
     */
    public function recordOrderActivity(
        Order $order,
        string $event,
        string $title,
        ?string $description = null,
        array $options = []
    ): ?OrderActivity {
        try {
            $actor = $options['actor'] ?? Auth::user();

            return OrderActivity::create([
                'order_id' => $order->id,
                'actor_id' => $actor?->id,
                'actor_name' => $options['actor_name'] ?? $actor?->name,
                'actor_role' => $options['actor_role'] ?? ($actor?->activeRole()),
                'event' => $event,
                'title' => $title,
                'description' => $description,
                'icon' => $options['icon'] ?? 'package',
                'badge_color' => $options['badge_color'] ?? 'secondary',
                'meta' => $options['meta'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record order activity: '.$e->getMessage(), [
                'order_id' => $order->id,
                'event' => $event,
            ]);

            return null;
        }
    }

    public function notifyOrderCreated(Order $order, ?Site $site = null): void
    {
        $item = $order->items()->first();
        $site = $site ?: ($item?->site_id ? Site::find($item->site_id) : null);
        $siteName = $item?->site_name ?: ($site?->site_name ?: 'a website');

        $alreadyLogged = OrderActivity::where('order_id', $order->id)
            ->where('event', 'order.created')
            ->exists();

        if (! $alreadyLogged) {
            $this->recordOrderActivity(
                $order,
                'order.created',
                'Order created',
                "Order #{$order->order_number} placed for {$siteName}.",
                ['icon' => 'package', 'badge_color' => 'primary']
            );
        }

        // Publishers only get a bell ping after payment is confirmed (wallet/card/manual paid).
        if ($site?->publisher_id && $order->payment_status === 'paid') {
            $this->notify(
                $site->publisher_id,
                self::TYPE_ORDER_CREATED,
                "New order #{$order->order_number}",
                "You received a new order for {$siteName}.",
                [
                    'category' => self::CATEGORY_ORDERS,
                    'icon' => 'package',
                    'priority' => InAppNotification::PRIORITY_HIGH,
                    'related' => $order,
                    'action_label' => 'View task',
                    'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                    'meta' => ['order_number' => $order->order_number, 'site_name' => $siteName],
                ]
            );
        }
    }

    /**
     * One advertiser confirmation when checkout payment succeeds (wallet / card / manual paid).
     *
     * @param  iterable<Order>  $orders
     */
    public function notifyAdvertiserOrdersPaid(iterable $orders): void
    {
        $orders = Collection::make($orders)->filter();
        if ($orders->isEmpty()) {
            return;
        }

        /** @var Order $first */
        $first = $orders->first();
        if (! $first->user_id) {
            return;
        }

        $count = $orders->count();
        $total = round((float) $orders->sum(fn (Order $o) => (float) $o->total_amount), 2);
        $method = (string) ($first->payment_method ?? '');
        $numbers = $orders->pluck('order_number')->filter()->values();
        $orderLabel = $count === 1
            ? "Order #{$first->order_number}"
            : $count.' orders';

        $message = match ($method) {
            'wallet' => $count === 1
                ? '€'.number_format($total, 2).' was reserved from your wallet. The publisher has been notified.'
                : '€'.number_format($total, 2).' was reserved from your wallet for '.$count.' orders. Publishers have been notified.',
            default => $count === 1
                ? 'Payment of €'.number_format($total, 2).' succeeded. The publisher has been notified.'
                : 'Payment of €'.number_format($total, 2).' succeeded for '.$count.' orders. Publishers have been notified.',
        };

        $this->notify(
            (int) $first->user_id,
            self::TYPE_PAYMENT_RECEIVED,
            $orderLabel.' placed',
            $message,
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $first,
                'action_label' => 'View orders',
                'action_url' => route('advertiser.orders', [
                    'focus' => 'order',
                    'order' => $first->id,
                ], false),
                'meta' => [
                    'reference_code' => $first->reference_code,
                    'order_numbers' => $numbers->all(),
                    'amount' => $total,
                    'payment_method' => $method,
                ],
            ]
        );
    }

    /**
     * Clear wallet credit notice after reject / admin refund (money moved).
     */
    public function notifyRefundCredited(Order $order, float $amount, ?string $reason = null): void
    {
        if (! $order->user_id || $amount <= 0) {
            return;
        }

        $amountLabel = '€'.number_format($amount, 2);
        $message = "{$amountLabel} was credited back to your wallet for order #{$order->order_number}.";
        if ($reason) {
            $message .= ' Reason: '.$reason;
        }

        $this->notify(
            (int) $order->user_id,
            self::TYPE_PAYMENT_RECEIVED,
            "{$amountLabel} back to your wallet",
            $message,
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'action_label' => 'View balance',
                'action_url' => route('advertiser.balance', [], false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'amount' => $amount,
                    'reason' => $reason,
                ],
            ]
        );
    }

    /**
     * Stripe / card payment failed — one in-app notice with Pay again.
     *
     * @param  iterable<Order>  $orders
     */
    public function notifyPaymentFailed(iterable $orders, ?string $reason = null): void
    {
        $orders = Collection::make($orders)->filter();
        if ($orders->isEmpty()) {
            return;
        }

        /** @var Order $first */
        $first = $orders->first();
        if (! $first->user_id) {
            return;
        }

        // One bell item per checkout reference (avoid N notices for multi-line carts).
        $recentDuplicate = InAppNotification::query()
            ->where('user_id', $first->user_id)
            ->where('type', self::TYPE_PAYMENT_FAILED)
            ->where('related_type', Order::class)
            ->whereIn('related_id', $orders->pluck('id')->all())
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recentDuplicate) {
            return;
        }

        $count = $orders->count();
        $title = $count === 1
            ? "Payment failed for order #{$first->order_number}"
            : "Payment failed for {$count} orders";
        $message = $reason
            ?: 'Your card payment did not go through. You can pay again from your orders.';

        $this->notify(
            (int) $first->user_id,
            self::TYPE_PAYMENT_FAILED,
            $title,
            $message,
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $first,
                'action_label' => 'Pay again',
                'action_url' => route('advertiser.orders', [
                    'payment_status' => 'failed',
                    'focus' => 'order',
                    'order' => $first->id,
                ], false),
                'meta' => [
                    'reference_code' => $first->reference_code,
                    'reason' => $reason,
                ],
            ]
        );
    }

    public function notifyDepositApproved(DepositRequest $deposit): void
    {
        if (! $deposit->user_id) {
            return;
        }

        $amount = '€'.number_format((float) $deposit->amount, 2);

        $this->notify(
            (int) $deposit->user_id,
            self::TYPE_PAYMENT_RECEIVED,
            "Deposit approved — {$amount}",
            "{$amount} has been added to your wallet.",
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $deposit,
                'action_label' => 'View balance',
                'action_url' => route('advertiser.balance', [], false),
                'meta' => [
                    'amount' => (float) $deposit->amount,
                    'reference_code' => $deposit->reference_code,
                ],
            ]
        );
    }

    public function notifyDepositRejected(DepositRequest $deposit): void
    {
        if (! $deposit->user_id) {
            return;
        }

        $amount = '€'.number_format((float) $deposit->amount, 2);
        $notes = trim((string) ($deposit->admin_notes ?? ''));
        $message = "Your {$amount} deposit request was rejected.";
        if ($notes !== '') {
            $message .= ' '.$notes;
        }

        $this->notify(
            (int) $deposit->user_id,
            self::TYPE_PAYMENT_FAILED,
            "Deposit rejected — {$amount}",
            $message,
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $deposit,
                'action_label' => 'Add funds',
                'action_url' => route('advertiser.add-funds', [], false),
                'meta' => [
                    'amount' => (float) $deposit->amount,
                    'reference_code' => $deposit->reference_code,
                ],
            ]
        );
    }

    public function notifyWithdrawalPaid(Withdrawal $withdrawal): void
    {
        if (! $withdrawal->user_id) {
            return;
        }

        $amount = '€'.number_format((float) $withdrawal->amount, 2);
        $net = isset($withdrawal->net_amount)
            ? '€'.number_format((float) $withdrawal->net_amount, 2)
            : $amount;

        $this->notify(
            (int) $withdrawal->user_id,
            self::TYPE_PAYMENT_RECEIVED,
            "Withdrawal paid — {$net}",
            "Your withdrawal of {$amount} has been marked as paid.",
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $withdrawal,
                'action_label' => 'View withdrawals',
                'action_url' => route('publisher.withdraw', [], false),
                'meta' => [
                    'amount' => (float) $withdrawal->amount,
                    'net_amount' => (float) ($withdrawal->net_amount ?? $withdrawal->amount),
                    'status' => $withdrawal->status,
                ],
            ]
        );
    }

    public function notifyWithdrawalRejected(Withdrawal $withdrawal): void
    {
        if (! $withdrawal->user_id) {
            return;
        }

        $amount = '€'.number_format((float) $withdrawal->amount, 2);

        $this->notify(
            (int) $withdrawal->user_id,
            self::TYPE_PAYMENT_FAILED,
            "Withdrawal cancelled — {$amount}",
            "{$amount} was returned to your publisher wallet.",
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $withdrawal,
                'action_label' => 'View balance',
                'action_url' => route('publisher.balance', [], false),
                'meta' => [
                    'amount' => (float) $withdrawal->amount,
                    'status' => $withdrawal->status,
                ],
            ]
        );
    }

    public function notifyOrderAccepted(Order $order, OrderItem $item, Site $site): void
    {
        $this->recordOrderActivity(
            $order,
            'order.accepted',
            'Publisher accepted',
            "Publisher accepted order #{$order->order_number}.",
            ['icon' => 'check-circle', 'badge_color' => 'success']
        );

        $this->notify(
            $order->user_id,
            self::TYPE_ORDER_ACCEPTED,
            "Order #{$order->order_number} accepted",
            "The publisher accepted your order for {$item->site_name}.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'check-circle',
                'related' => $order,
                'action_label' => 'View order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => ['order_number' => $order->order_number],
            ]
        );
    }

    public function notifyOrderRejected(Order $order, OrderItem $item, Site $site, ?string $reason = null): void
    {
        $this->recordOrderActivity(
            $order,
            'order.rejected',
            'Order rejected',
            $reason ?: "Publisher rejected order #{$order->order_number}.",
            ['icon' => 'x-circle', 'badge_color' => 'danger', 'meta' => ['reason' => $reason]]
        );

        $this->notify(
            $order->user_id,
            self::TYPE_ORDER_REJECTED,
            "Order #{$order->order_number} rejected",
            $reason
                ? "Your order for {$item->site_name} was rejected: {$reason}"
                : "Your order for {$item->site_name} was rejected and refunded.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'x-circle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'action_label' => 'View order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
            ]
        );
    }

    public function notifyLiveUrlSubmitted(Order $order, OrderItem $item, Site $site, string $liveUrl): void
    {
        $this->recordOrderActivity(
            $order,
            'order.published',
            'Guest post published',
            "Live URL submitted for review: {$liveUrl}",
            ['icon' => 'rocket', 'badge_color' => 'info', 'meta' => ['live_url' => $liveUrl]]
        );

        $this->notify(
            $order->user_id,
            self::TYPE_GUEST_POST_PUBLISHED,
            "Order #{$order->order_number} published",
            "Your backlink on {$item->site_name} has been published and is ready for review.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'rocket',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'action_label' => 'Review order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => ['live_url' => $liveUrl],
            ]
        );
    }

    public function notifyModificationRequested(Order $order, string $reason): void
    {
        $this->recordOrderActivity(
            $order,
            'order.modification_requested',
            'Revision requested',
            $reason,
            ['icon' => 'pencil', 'badge_color' => 'warning']
        );

        foreach ($order->items as $item) {
            $site = Site::find($item->site_id);
            if (! $site?->publisher_id) {
                continue;
            }

            $this->notify(
                $site->publisher_id,
                self::TYPE_MODIFICATION_REQUESTED,
                "Revision requested on #{$order->order_number}",
                $reason,
                [
                    'category' => self::CATEGORY_ORDERS,
                    'icon' => 'pencil',
                    'priority' => InAppNotification::PRIORITY_HIGH,
                    'related' => $order,
                    'action_label' => 'Open task',
                    'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        }
    }

    public function notifyOrderCompleted(Order $order, ?User $publisher = null, ?float $amount = null, bool $autoApproved = false): void
    {
        $alreadyLogged = OrderActivity::where('order_id', $order->id)
            ->where('event', 'order.completed')
            ->exists();

        if (! $alreadyLogged) {
            $this->recordOrderActivity(
                $order,
                'order.completed',
                'Order completed',
                $autoApproved
                    ? 'Order was auto-approved after the review window.'
                    : 'Advertiser approved the published content.',
                ['icon' => 'badge-check', 'badge_color' => 'success']
            );
        }

        if ($publisher) {
            $msg = $amount !== null
                ? 'Payment of €'.number_format($amount, 2).' was credited to your wallet.'
                : ($autoApproved
                    ? 'The order was auto-approved after the review window.'
                    : 'The advertiser approved the order.');

            $this->notify(
                $publisher->id,
                self::TYPE_ORDER_COMPLETED,
                "Order #{$order->order_number} completed",
                $msg,
                [
                    'category' => self::CATEGORY_ORDERS,
                    'icon' => 'badge-check',
                    'related' => $order,
                    'action_label' => 'View tasks',
                    'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );

            if ($amount !== null) {
                $this->notify(
                    $publisher->id,
                    self::TYPE_PAYMENT_RECEIVED,
                    'Payment received',
                    '€'.number_format($amount, 2)." credited for order #{$order->order_number}.",
                    [
                        'category' => self::CATEGORY_PAYMENTS,
                        'icon' => 'wallet',
                        'related' => $order,
                        'action_label' => 'View balance',
                        'action_url' => route('publisher.balance', [], false),
                    ]
                );
            }
        }

        // Advertiser bell only for auto-approve (they already know when they click Approve).
        if ($autoApproved && $order->user_id) {
            $this->notify(
                (int) $order->user_id,
                self::TYPE_ORDER_COMPLETED,
                "Order #{$order->order_number} completed",
                'Your guest post was auto-approved after the review window. The live URL stays on your order.',
                [
                    'category' => self::CATEGORY_ORDERS,
                    'icon' => 'badge-check',
                    'related' => $order,
                    'action_label' => 'View order',
                    'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        }
    }

    public function notifyNewChatMessage(Order $order, User $sender, User $receiver, string $body): void
    {
        $preview = mb_strlen($body) > 120 ? mb_substr($body, 0, 117).'…' : $body;
        $isAdvertiserReceiver = (int) $receiver->id === (int) $order->user_id;
        $url = $isAdvertiserReceiver
            ? route('advertiser.orders', ['focus' => 'messages', 'order' => $order->id], false)
            : route('publisher.tasks', ['focus' => 'messages', 'order' => $order->id], false);

        $this->recordOrderActivity(
            $order,
            'chat.message',
            'Message sent',
            "{$sender->name}: {$preview}",
            [
                'actor' => $sender,
                'icon' => 'message-circle',
                'badge_color' => 'secondary',
            ]
        );

        $this->notify(
            $receiver->id,
            self::TYPE_MESSAGE,
            "New message on #{$order->order_number}",
            $preview,
            [
                'category' => self::CATEGORY_MESSAGES,
                'icon' => 'message-circle',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $order,
                'action_label' => 'Open chat',
                'action_url' => $url,
                'meta' => [
                    'order_number' => $order->order_number,
                    'sender_id' => $sender->id,
                    'sender_name' => $sender->name,
                ],
            ]
        );
    }

    public function unreadCount(int $userId, ?string $audience = null): int
    {
        return InAppNotification::forUser($userId)
            ->forAudience($audience)
            ->unread()
            ->whereNull('archived_at')
            ->count();
    }

    public function listForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = InAppNotification::forUser($userId)
            ->forAudience($filters['audience'] ?? null)
            ->latest();

        $status = $filters['status'] ?? 'active';
        if ($status === 'unread') {
            $query->unread()->whereNull('archived_at');
        } elseif ($status === 'archived') {
            $query->where(function ($q) {
                $q->where('status', InAppNotification::STATUS_ARCHIVED)
                    ->orWhereNotNull('archived_at');
            });
        } else {
            // active = not archived / not soft-deleted (soft deletes automatic)
            $query->where(function ($q) {
                $q->whereNull('archived_at')
                    ->where(function ($inner) {
                        $inner->whereNull('status')
                            ->orWhere('status', '!=', InAppNotification::STATUS_ARCHIVED);
                    });
            });
        }

        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%")
                    ->orWhere('message', 'like', "%{$q}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function markAllRead(int $userId, ?string $audience = null): int
    {
        return InAppNotification::forUser($userId)
            ->forAudience($audience)
            ->unread()
            ->whereNull('archived_at')
            ->update([
                'status' => InAppNotification::STATUS_READ,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    protected function inferAudienceFromUrl(?string $url): string
    {
        $url = (string) $url;
        if ($url === '') {
            return InAppNotification::AUDIENCE_ALL;
        }

        if (str_contains($url, '/publisher/')) {
            return InAppNotification::AUDIENCE_PUBLISHER;
        }
        if (str_contains($url, '/advertiser/')) {
            return InAppNotification::AUDIENCE_ADVERTISER;
        }
        if (str_contains($url, '/admin/')) {
            return InAppNotification::AUDIENCE_ADMIN;
        }

        return InAppNotification::AUDIENCE_ALL;
    }

    protected function categoryForType(string $type): string
    {
        return match ($type) {
            self::TYPE_MESSAGE, self::TYPE_CHAT_REPLY => self::CATEGORY_MESSAGES,
            self::TYPE_PAYMENT_RECEIVED, self::TYPE_PAYMENT_FAILED => self::CATEGORY_PAYMENTS,
            self::TYPE_ACCOUNT => self::CATEGORY_ACCOUNT,
            self::TYPE_SYSTEM => self::CATEGORY_SYSTEM,
            default => self::CATEGORY_ORDERS,
        };
    }

    protected function iconForType(string $type): string
    {
        return match ($type) {
            self::TYPE_MESSAGE, self::TYPE_CHAT_REPLY => 'message-circle',
            self::TYPE_ORDER_CREATED => 'package',
            self::TYPE_ORDER_ACCEPTED => 'check-circle',
            self::TYPE_ORDER_REJECTED => 'x-circle',
            self::TYPE_GUEST_POST_PUBLISHED => 'rocket',
            self::TYPE_ORDER_COMPLETED => 'badge-check',
            self::TYPE_MODIFICATION_REQUESTED => 'pencil',
            self::TYPE_ORDER_UPDATED => 'refresh-cw',
            self::TYPE_PAYMENT_RECEIVED => 'wallet',
            self::TYPE_PAYMENT_FAILED => 'alert-triangle',
            self::TYPE_ACCOUNT => 'user',
            default => 'bell',
        };
    }
}
