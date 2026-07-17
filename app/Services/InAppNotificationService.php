<?php

namespace App\Services;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
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

        $this->recordOrderActivity(
            $order,
            'order.created',
            'Order created',
            "Order #{$order->order_number} placed for {$siteName}.",
            ['icon' => 'package', 'badge_color' => 'primary']
        );

        if ($site?->publisher_id) {
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
            if (!$site?->publisher_id) {
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

    public function notifyOrderCompleted(Order $order, ?User $publisher = null, ?float $amount = null): void
    {
        $alreadyLogged = OrderActivity::where('order_id', $order->id)
            ->where('event', 'order.completed')
            ->exists();

        if (!$alreadyLogged) {
            $this->recordOrderActivity(
                $order,
                'order.completed',
                'Order completed',
                'Advertiser approved the published content.',
                ['icon' => 'badge-check', 'badge_color' => 'success']
            );
        }

        if ($publisher) {
            $msg = $amount !== null
                ? 'Payment of €'.number_format($amount, 2).' was credited to your wallet.'
                : 'The advertiser approved the order.';

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

    public function unreadCount(int $userId): int
    {
        return InAppNotification::forUser($userId)->unread()->whereNull('archived_at')->count();
    }

    public function listForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = InAppNotification::forUser($userId)->latest();

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

        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%")
                    ->orWhere('message', 'like', "%{$q}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function markAllRead(int $userId): int
    {
        return InAppNotification::forUser($userId)
            ->unread()
            ->whereNull('archived_at')
            ->update([
                'status' => InAppNotification::STATUS_READ,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
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
