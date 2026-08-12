<?php

namespace App\Services;

use App\Models\AgencySiteImport;
use App\Models\BulkSiteRequest;
use App\Models\DepositRequest;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Orders\AdminOrderStatusOverride;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    public const TYPE_CONTENT_REVISION_REQUESTED = 'content_revision_requested';

    public const TYPE_CONTENT_REVISION_FULFILLED = 'content_revision_fulfilled';

    public const TYPE_PAYMENT_RECEIVED = 'payment_received';

    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public const TYPE_PAYMENT_PENDING = 'payment_pending';

    public const TYPE_SITE_STATUS = 'site_status';

    public const TYPE_CONTENT_APPROVED = 'content_approved';

    public const TYPE_CONTENT_NEEDS_CHANGES = 'content_needs_changes';

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
            InAppNotification::ensureTable();
            if (! InAppNotification::tableAvailable()) {
                return null;
            }

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
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
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
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
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
        $isCard = strtolower((string) ($deposit->payment_method ?? '')) === 'card';
        $title = $isCard
            ? "Wallet topped up — {$amount}"
            : "Deposit approved — {$amount}";
        $message = $isCard
            ? "{$amount} from your card has been added to your wallet."
            : "{$amount} has been added to your wallet.";

        $this->notify(
            (int) $deposit->user_id,
            self::TYPE_PAYMENT_RECEIVED,
            $title,
            $message,
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $deposit,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View balance',
                'action_url' => route('advertiser.balance', [], false),
                'meta' => [
                    'amount' => (float) $deposit->amount,
                    'reference_code' => $deposit->reference_code,
                    'payment_method' => $deposit->payment_method,
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

    /**
     * Advertiser: manual deposit invoice was submitted and awaits admin review.
     */
    public function notifyDepositSubmitted(DepositRequest $deposit): void
    {
        if (! $deposit->user_id) {
            return;
        }

        $amount = '€'.number_format((float) $deposit->amount, 2);
        $method = strtoupper((string) ($deposit->payment_method ?? 'manual'));

        $this->notify(
            (int) $deposit->user_id,
            self::TYPE_PAYMENT_PENDING,
            "Deposit submitted — {$amount}",
            "We received your {$method} deposit request (ref {$deposit->reference_code}). We'll review and credit your wallet soon.",
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $deposit,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View Add Funds',
                'action_url' => route('advertiser.add-funds', [], false),
                'meta' => [
                    'amount' => (float) $deposit->amount,
                    'reference_code' => $deposit->reference_code,
                    'payment_method' => $deposit->payment_method,
                    'status' => $deposit->status,
                ],
            ]
        );
    }

    /**
     * Advertiser: confirmation that their "I paid" report was logged.
     *
     * The success dialog disappears with the page reload it triggers, so without
     * this the click leaves no trace the advertiser can go back and check.
     */
    public function notifyDepositMarkedPaid(DepositRequest $deposit): void
    {
        if (! $deposit->user_id) {
            return;
        }

        $amount = '€'.number_format((float) $deposit->amount, 2);

        $this->notify(
            (int) $deposit->user_id,
            self::TYPE_PAYMENT_PENDING,
            "Payment reported — {$amount}",
            "Thanks — we're looking out for your transfer (ref {$deposit->reference_code}). Your wallet is credited once the funds arrive.",
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $deposit,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View Add Funds',
                'action_url' => route('advertiser.add-funds', [], false),
                'meta' => [
                    'amount' => (float) $deposit->amount,
                    'reference_code' => $deposit->reference_code,
                    'payment_method' => $deposit->payment_method,
                    'status' => $deposit->status,
                ],
            ]
        );
    }

    /**
     * Advertiser: order payment is pending (manual invoice / awaiting confirmation).
     * Deduped so the same order does not spam the bell.
     */
    public function notifyPaymentPending(Order $order, ?string $reason = null): void
    {
        if (! $order->user_id) {
            return;
        }

        $recentDuplicate = InAppNotification::query()
            ->where('user_id', $order->user_id)
            ->where('type', self::TYPE_PAYMENT_PENDING)
            ->where('related_type', Order::class)
            ->where('related_id', $order->id)
            ->where('created_at', '>=', now()->subHours(12))
            ->exists();

        if ($recentDuplicate) {
            return;
        }

        $amount = '€'.number_format((float) $order->total_amount, 2);
        $message = $reason
            ? $reason
            : "Payment for order #{$order->order_number} ({$amount}) is still pending. Complete payment to notify the publisher.";

        $this->notify(
            (int) $order->user_id,
            self::TYPE_PAYMENT_PENDING,
            "Payment pending — order #{$order->order_number}",
            $message,
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View orders',
                'action_url' => route('advertiser.orders', [
                    'payment_status' => 'pending',
                    'focus' => 'order',
                    'order' => $order->id,
                ], false),
                'meta' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'amount' => (float) $order->total_amount,
                    'payment_method' => $order->payment_method,
                ],
            ]
        );
    }

    /**
     * Publisher: withdrawal moved to processing.
     */
    public function notifyWithdrawalProcessing(Withdrawal $withdrawal): void
    {
        if (! $withdrawal->user_id) {
            return;
        }

        $amount = '€'.number_format((float) $withdrawal->amount, 2);

        $this->notify(
            (int) $withdrawal->user_id,
            self::TYPE_PAYMENT_PENDING,
            "Withdrawal processing — {$amount}",
            "Your withdrawal of {$amount} is being processed. We'll notify you when it's paid.",
            [
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $withdrawal,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'View withdrawals',
                'action_url' => route('publisher.withdraw', [], false),
                'meta' => [
                    'amount' => (float) $withdrawal->amount,
                    'status' => $withdrawal->status,
                ],
            ]
        );
    }

    /**
     * Publisher: site verified / unverified / activated / deactivated.
     */
    public function notifySiteStatusChanged(Site $site, string $status, ?string $reason = null): void
    {
        $publisherId = (int) ($site->publisher_id ?? 0);
        if ($publisherId <= 0) {
            return;
        }

        $labels = [
            'verified' => ['Site verified', 'Your site is verified and ready for marketplace listings.'],
            'unverified' => ['Site verification removed', 'Your site is no longer verified. Contact support if this looks wrong.'],
            'activated' => ['Site activated', 'Your site is active and visible to advertisers.'],
            'deactivated' => ['Site deactivated', 'Your site was deactivated and is hidden from the catalog.'],
            'removed' => ['Site submission removed', 'Your site submission was removed and will not be listed.'],
        ];

        [$title, $defaultMessage] = $labels[$status] ?? ['Site status updated', 'Your site status was updated.'];
        $name = $site->site_name ?: ($site->site_url ?: 'Your site');
        $reason = $reason !== null ? trim($reason) : '';
        if ($reason === '' && filled($site->status_reason) && in_array($status, ['unverified', 'deactivated', 'removed'], true)) {
            $reason = trim((string) $site->status_reason);
        }
        if ($reason !== '' && in_array($status, ['unverified', 'deactivated', 'removed'], true)) {
            $defaultMessage .= ' Reason: '.$reason;
        }

        $this->notify(
            $publisherId,
            self::TYPE_SITE_STATUS,
            "{$title} — {$name}",
            $defaultMessage,
            [
                'category' => self::CATEGORY_ACCOUNT,
                'icon' => in_array($status, ['verified', 'activated'], true) ? 'check-circle' : 'alert-triangle',
                'priority' => in_array($status, ['unverified', 'deactivated', 'removed'], true)
                    ? InAppNotification::PRIORITY_HIGH
                    : InAppNotification::PRIORITY_NORMAL,
                'related' => $site,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'View sites',
                'action_url' => route('publisher.websites', [], false),
                'meta' => [
                    'site_id' => $site->id,
                    'status' => $status,
                    'verified' => (bool) $site->verified,
                    'active' => (bool) $site->active,
                    'reason' => $reason !== '' ? $reason : null,
                ],
            ]
        );
    }

    /**
     * Publisher: scheduled publication window is due (publish today).
     */
    public function notifyScheduledPublishDue(Order $order, bool $isReminder = false): void
    {
        $order->loadMissing(['items.site']);
        $siteName = $order->items->first()?->site_name
            ?: $order->items->first()?->site?->site_name
            ?: 'your site';

        $publisherIds = $order->items
            ->map(fn (OrderItem $item) => (int) ($item->site?->publisher_id ?? 0))
            ->filter()
            ->unique()
            ->values();

        $title = $isReminder
            ? "Publish soon — order #{$order->order_number}"
            : "Publish today — order #{$order->order_number}";
        $message = $isReminder
            ? "Scheduled publication for {$siteName} begins within 24 hours."
            : "The scheduled date for {$siteName} has arrived. Please publish the article today.";

        foreach ($publisherIds as $publisherId) {
            $this->notify(
                (int) $publisherId,
                self::TYPE_ORDER_UPDATED,
                $title,
                $message,
                [
                    'category' => self::CATEGORY_ORDERS,
                    'icon' => 'rocket',
                    'priority' => InAppNotification::PRIORITY_HIGH,
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                    'action_label' => 'Open tasks',
                    'action_url' => route('publisher.tasks', [
                        'focus' => 'order',
                        'order' => $order->id,
                    ], false),
                    'meta' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reminder' => $isReminder,
                        'scheduled_publish_at' => optional($order->scheduled_publish_at)?->toIso8601String(),
                    ],
                ]
            );
        }
    }

    /**
     * Advertiser: Content Library evaluation result (approved / needs changes).
     *
     * @param  array<string, mixed>  $result
     */
    public function notifyContentEvaluation(User $user, $submission, array $result): void
    {
        $approved = (bool) ($result['approved'] ?? false);
        $title = $approved
            ? 'Article approved'
            : ((string) ($result['title'] ?? 'Article needs changes'));
        $message = (string) ($result['message'] ?? '');
        $terms = $result['matched_terms'] ?? ($result['report']['matched_terms'] ?? []);
        $blockedUrls = $result['blocked_urls'] ?? ($result['report']['blocked_urls'] ?? []);
        if (! $approved && is_array($terms) && $terms !== []) {
            $message .= ' Terms to fix: '.implode(', ', array_slice($terms, 0, 8)).'.';
        }
        if (! $approved && is_array($blockedUrls) && $blockedUrls !== []) {
            $message .= ' Blocked links: '.implode(', ', array_slice($blockedUrls, 0, 3)).'.';
        }

        $this->notify(
            $user,
            $approved ? self::TYPE_CONTENT_APPROVED : self::TYPE_CONTENT_NEEDS_CHANGES,
            $title,
            $message,
            [
                'category' => self::CATEGORY_SYSTEM,
                'icon' => $approved ? 'check-circle' : 'alert-triangle',
                'priority' => $approved ? InAppNotification::PRIORITY_NORMAL : InAppNotification::PRIORITY_HIGH,
                'related' => $submission,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Open Content Library',
                'action_url' => route('advertiser.content-library', [], false),
                'meta' => [
                    'submission_id' => $submission->id ?? null,
                    'moderation_status' => $result['moderation_status'] ?? null,
                    'matched_terms' => $terms,
                    'blocked_urls' => $blockedUrls,
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
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
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
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
            ]
        );
    }

    public function notifyLiveUrlSubmitted(Order $order, OrderItem $item, Site $site, string $liveUrl): void
    {
        $windowDays = max(1, (int) ceil(OrderItem::autoApproveHours() / 24));

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
            "Your backlink on {$item->site_name} has been published and is ready for review. Auto-completes in about {$windowDays} day(s) if you take no action.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'rocket',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Review order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => [
                    'live_url' => $liveUrl,
                    'auto_approve_hours' => OrderItem::autoApproveHours(),
                ],
            ]
        );
    }

    /**
     * Advertiser: ~24h left before auto-complete.
     */
    public function notifyAutoApproveReminder(Order $order, OrderItem $item, int $hoursRemaining): void
    {
        if (! $order->user_id) {
            return;
        }

        $this->notify(
            (int) $order->user_id,
            self::TYPE_ORDER_UPDATED,
            "1 day left — order #{$order->order_number}",
            "About {$hoursRemaining} hour(s) remain before this order auto-completes. Approve the live URL or request changes.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Review order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'order_item_id' => $item->id,
                    'hours_remaining' => $hoursRemaining,
                    'live_url' => $item->live_url,
                ],
            ]
        );
    }

    /**
     * Advertiser: mid-window nudge while a live URL waits for review.
     */
    public function notifyAdvertiserReviewNudge(Order $order, OrderItem $item, ?\DateTimeInterface $autoCompletesAt = null): void
    {
        if (! $order->user_id) {
            return;
        }

        $siteName = $item->site?->site_name ?: ($item->site_name ?: 'your placement');
        $when = $autoCompletesAt
            ? ' It auto-completes on '.$autoCompletesAt->format('j M \a\t H:i').' if you take no action.'
            : '';

        $this->notify(
            (int) $order->user_id,
            self::TYPE_ORDER_UPDATED,
            "Your link is live — order #{$order->order_number}",
            "Take a quick look at the live URL for {$siteName}.{$when}",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'eye',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Review order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'order_item_id' => $item->id,
                    'live_url' => $item->live_url,
                    'track' => 'review_nudge',
                ],
            ]
        );
    }

    /**
     * Publisher bell: a paid order they have not accepted.
     */
    public function notifyPublisherAcceptNudge(Order $order, OrderItem $item, User $publisher, int $stage): void
    {
        $siteName = $item->site?->site_name ?: ($item->site_name ?: 'your site');

        $this->notify(
            (int) $publisher->id,
            self::TYPE_ORDER_UPDATED,
            $stage >= 3 ? "Still unaccepted — order #{$order->order_number}" : "Accept order #{$order->order_number}",
            "A paid order for {$siteName} is waiting for you to accept it. The advertiser cannot move until you do.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Open tasks',
                'action_url' => route('publisher.tasks', [
                    'focus' => 'order',
                    'order' => $order->id,
                ], false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'order_item_id' => $item->id,
                    'stage' => $stage,
                    'track' => 'accept',
                ],
            ]
        );
    }

    /**
     * Publisher bell: an accepted order that is due soon or overdue.
     */
    public function notifyPublisherPublishNudge(
        Order $order,
        OrderItem $item,
        User $publisher,
        int $stage,
        int $hoursOverdue,
    ): void {
        $siteName = $item->site?->site_name ?: ($item->site_name ?: 'your site');
        $late = $hoursOverdue >= 24 ? ((int) round($hoursOverdue / 24)).' day(s) late' : $hoursOverdue.'h late';

        $this->notify(
            (int) $publisher->id,
            self::TYPE_ORDER_UPDATED,
            $stage <= 1
                ? "Due soon — order #{$order->order_number}"
                : "Overdue — order #{$order->order_number}",
            $stage <= 1
                ? "Your guest post for {$siteName} is due shortly. Submit the live URL to release your payout."
                : "Your guest post for {$siteName} is {$late}. Submit the live URL or update the advertiser in chat.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => $stage <= 1 ? 'clock' : 'alert-triangle',
                'priority' => $stage >= 2 ? InAppNotification::PRIORITY_HIGH : InAppNotification::PRIORITY_NORMAL,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Submit live URL',
                'action_url' => route('publisher.tasks', [
                    'focus' => 'order',
                    'order' => $order->id,
                ], false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'order_item_id' => $item->id,
                    'stage' => $stage,
                    'hours_overdue' => $hoursOverdue,
                    'track' => 'publish',
                ],
            ]
        );
    }

    /**
     * Advertiser bell: their publisher is late and we are chasing.
     */
    public function notifyAdvertiserOrderStalled(Order $order, OrderItem $item, int $hoursOverdue): void
    {
        if (! $order->user_id) {
            return;
        }

        $days = max(1, (int) round($hoursOverdue / 24));
        $siteName = $item->site?->site_name ?: ($item->site_name ?: 'your placement');

        $this->notify(
            (int) $order->user_id,
            self::TYPE_ORDER_UPDATED,
            "We are chasing order #{$order->order_number}",
            "Your placement on {$siteName} is {$days} day(s) late. Your payment is still held and our team is following up with the publisher.",
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'order_item_id' => $item->id,
                    'hours_overdue' => $hoursOverdue,
                ],
            ]
        );
    }

    /**
     * Admin bell: the reminder cadence ran out and a person needs to decide.
     */
    public function notifyAdminsStalledOrder(
        Order $order,
        OrderItem $item,
        ?User $publisher,
        string $track,
        int $hoursOverdue,
    ): void {
        $days = max(1, (int) round($hoursOverdue / 24));
        $who = $publisher?->name ?: ($publisher?->email ?: 'The publisher');
        $what = $track === 'accept' ? 'has not accepted' : 'has not published';

        $this->notifyAdmins(
            self::TYPE_ORDER_UPDATED,
            "Order #{$order->order_number} needs attention",
            "{$who} {$what} after {$days} day(s) and every reminder. Chase them or refund the advertiser.",
            [
                // Admin-only: marketing cannot open /admin/orders.
                'roles' => ['admin'],
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'action_label' => 'Open order',
                'action_url' => route('admin.orders.show', $order->id, false),
                'meta' => [
                    'order_number' => $order->order_number,
                    'order_item_id' => $item->id,
                    'publisher_id' => $publisher?->id,
                    'track' => $track,
                    'hours_overdue' => $hoursOverdue,
                ],
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
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                    'action_label' => 'Open task',
                    'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        }
    }

    /**
     * Publisher asked the advertiser to revise / resend the article.
     */
    public function notifyContentRevisionRequested(
        Order $order,
        OrderItem $item,
        Site $site,
        string $reason,
        bool $updated = false,
    ): void {
        $this->recordOrderActivity(
            $order,
            $updated ? 'order.content_revision_reason_updated' : 'order.content_revision_requested',
            $updated ? 'Publisher updated revision notes' : 'Publisher requested revised article',
            $reason,
            ['icon' => 'file-text', 'badge_color' => 'warning', 'site_id' => $site->id, 'item_id' => $item->id]
        );

        $this->notify(
            (int) $order->user_id,
            self::TYPE_CONTENT_REVISION_REQUESTED,
            $updated
                ? "Publisher updated revision notes for #{$order->order_number}"
                : "Publisher needs a revised article for #{$order->order_number}",
            $reason,
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'file-text',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'Send revised article',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
            ]
        );
    }

    /**
     * Advertiser fulfilled a publisher content revision request.
     */
    public function notifyContentRevisionFulfilled(Order $order, OrderItem $item, Site $site): void
    {
        $this->recordOrderActivity(
            $order,
            'order.content_revision_fulfilled',
            'Revised article received',
            'The advertiser sent an updated article for '.$site->site_name.'.',
            ['icon' => 'check-circle', 'badge_color' => 'success', 'site_id' => $site->id, 'item_id' => $item->id]
        );

        if (! $site->publisher_id) {
            return;
        }

        $this->notify(
            (int) $site->publisher_id,
            self::TYPE_CONTENT_REVISION_FULFILLED,
            "Revised article ready for #{$order->order_number}",
            'The advertiser updated the article for '.$site->site_name.'. You can continue publishing.',
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'check-circle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Open task',
                'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
            ]
        );
    }

    /**
     * Support moved an order between stages by hand.
     *
     * Both sides see the stage change in their dashboards, so tell them why it
     * moved rather than leaving it looking like the order jumped on its own.
     */
    public function notifyOrderStatusOverridden(Order $order, string $summary): void
    {
        $this->recordOrderActivity(
            $order,
            'order.status_overridden',
            'Status changed by support',
            $summary,
            ['icon' => 'shield', 'badge_color' => 'warning']
        );

        $this->notify(
            $order->user_id,
            self::TYPE_ORDER_UPDATED,
            "Order #{$order->order_number} updated by support",
            $summary,
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'shield',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View order',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
            ]
        );

        foreach (AdminOrderStatusOverride::publisherIdsFor($order) as $publisherId) {
            $this->notify(
                $publisherId,
                self::TYPE_ORDER_UPDATED,
                "Order #{$order->order_number} updated by support",
                $summary,
                [
                    'category' => self::CATEGORY_ORDERS,
                    'icon' => 'shield',
                    'priority' => InAppNotification::PRIORITY_HIGH,
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                    'action_label' => 'Open task',
                    'action_url' => route('publisher.tasks', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        }
    }

    public function notifyOrderCompleted(Order $order, ?User $publisher = null, ?float $amount = null, bool $autoApproved = false): void
    {
        $alreadyLogged = false;
        try {
            if (Schema::hasTable((new OrderActivity)->getTable())) {
                $alreadyLogged = OrderActivity::where('order_id', $order->id)
                    ->where('event', 'order.completed')
                    ->exists();
            }
        } catch (\Throwable $e) {
            Log::warning('Could not check order.completed activity', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

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
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
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
                        'audience' => InAppNotification::AUDIENCE_PUBLISHER,
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
                    'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                    'action_label' => 'View order',
                    'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        }
    }

    public function notifyDisputeOpened(OrderItemDispute $dispute): void
    {
        $order = $dispute->order ?? Order::find($dispute->order_id);
        if (! $order) {
            return;
        }

        $this->notifyAdmins(
            self::TYPE_ORDER_UPDATED,
            "Dispute opened on order #{$order->order_number}",
            'Advertiser reported a removed live link. Review and uphold or dismiss.',
            [
                'roles' => ['admin'],
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'flag',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $order,
                'action_label' => 'Review order',
                'action_url' => route('admin.orders.show', $order->id, false),
                'meta' => [
                    'dispute_id' => $dispute->id,
                    'reason' => $dispute->reason,
                ],
            ]
        );

        if ($order->user_id) {
            $this->notify(
                (int) $order->user_id,
                self::TYPE_ORDER_UPDATED,
                "Dispute submitted for order #{$order->order_number}",
                'We received your report that the live link was removed. Our team will review it.',
                [
                    'category' => self::CATEGORY_ORDERS,
                    'icon' => 'flag',
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                    'action_label' => 'View orders',
                    'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                    'meta' => ['dispute_id' => $dispute->id],
                ]
            );
        }
    }

    public function notifyDisputeDismissed(OrderItemDispute $dispute): void
    {
        $order = $dispute->order ?? Order::find($dispute->order_id);
        if (! $order || ! $order->user_id) {
            return;
        }

        $this->notify(
            (int) $order->user_id,
            self::TYPE_ORDER_UPDATED,
            "Dispute dismissed for order #{$order->order_number}",
            'Your link-removed report was reviewed and dismissed.'.($dispute->admin_notes ? ' Notes: '.$dispute->admin_notes : ''),
            [
                'category' => self::CATEGORY_ORDERS,
                'icon' => 'circle-x',
                'related' => $order,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View orders',
                'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                'meta' => [
                    'dispute_id' => $dispute->id,
                    'admin_notes' => $dispute->admin_notes,
                ],
            ]
        );
    }

    public function notifyDisputeUpheld(OrderItemDispute $dispute): void
    {
        $order = $dispute->order ?? Order::find($dispute->order_id);
        if (! $order) {
            return;
        }

        $credited = (float) ($dispute->advertiser_credited ?? 0);
        $debited = (float) ($dispute->publisher_debited ?? 0);
        $debt = (float) ($dispute->debt_created ?? 0);

        if ($order->user_id && $credited > 0) {
            $this->notifyRefundCredited(
                $order,
                $credited,
                'Link-removed dispute upheld'.($dispute->admin_notes ? ': '.$dispute->admin_notes : '')
            );
        }

        $item = $dispute->orderItem ?? OrderItem::find($dispute->order_item_id);
        $site = $item?->site;
        $publisherId = $site?->publisher_id;
        if ($publisherId) {
            $msg = 'A link-removed dispute was upheld for order #'.$order->order_number.'.';
            if ($debited > 0) {
                $msg .= ' €'.number_format($debited, 2).' was deducted from your wallet.';
            }
            if ($debt > 0) {
                $msg .= ' Outstanding debt: €'.number_format($debt, 2).' (withdrawals blocked until cleared).';
            }

            $this->notify(
                (int) $publisherId,
                self::TYPE_ORDER_UPDATED,
                "Clawback on order #{$order->order_number}",
                $msg,
                [
                    'category' => self::CATEGORY_PAYMENTS,
                    'icon' => 'wallet',
                    'priority' => InAppNotification::PRIORITY_HIGH,
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                    'action_label' => 'View balance',
                    'action_url' => route('publisher.balance', [], false),
                    'meta' => [
                        'dispute_id' => $dispute->id,
                        'publisher_debited' => $debited,
                        'debt_created' => $debt,
                    ],
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
                'audience' => $isAdvertiserReceiver
                    ? InAppNotification::AUDIENCE_ADVERTISER
                    : InAppNotification::AUDIENCE_PUBLISHER,
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

    /**
     * Fan out an in-app notification to every user with the admin role.
     *
     * @return Collection<int, InAppNotification>
     */
    /**
     * An account is taking publisher domains at a pace worth a human glance.
     *
     * Masking is metered by pace rather than quota, so the meter has to be
     * watched by someone — a competitor working through the catalog otherwise
     * looks exactly like a thorough buyer until the inventory is gone.
     */
    public function notifyAdminsCatalogPace(
        User $user,
        int $count,
        int $windowMinutes,
        string $state = 'review',
        string $because = 'rate',
    ): void {
        $who = $user->name ?: ($user->email ?: 'An advertiser');
        $window = $windowMinutes >= 120
            ? round($windowMinutes / 60).' hours'
            : $windowMinutes.' minutes';

        [$title, $lead] = match ($state) {
            'frozen' => [
                'Catalog access paused',
                "{$who} was opening publisher addresses fast enough that new ones are paused for now.",
            ],
            'slow' => [
                'Catalog activity looks automated',
                "{$who} is opening publisher addresses at a pace with {$because} — the shape of a script rather than a person.",
            ],
            default => [
                'Heavy catalog activity',
                "{$who} has opened {$count} publisher addresses in the last {$window}.",
            ],
        };

        $tail = $state === 'review'
            ? ' Nothing has been restricted. Most accounts here are genuine buyers working a shortlist — check the ratio of addresses opened to orders placed before doing anything.'
            : " That is {$count} in {$window}. Browsing and existing orders are unaffected.";

        $this->notifyAdmins(
            self::TYPE_SYSTEM,
            $title,
            $lead.$tail,
            [
                'roles' => ['admin'],
                'category' => self::CATEGORY_SYSTEM,
                'icon' => $state === 'review' ? 'eye' : 'alert-triangle',
                'priority' => $state === 'review'
                    ? InAppNotification::PRIORITY_NORMAL
                    : InAppNotification::PRIORITY_HIGH,
                'related' => $user,
                'action_label' => 'Review activity',
                'action_url' => route('admin.catalog-activity', [], false),
                'meta' => [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'reveals' => $count,
                    'window_minutes' => $windowMinutes,
                    'state' => $state,
                    'reason' => $because,
                ],
            ]
        );
    }

    public function notifyAdmins(
        string $type,
        string $title,
        ?string $message = null,
        array $options = []
    ): Collection {
        $roles = $options['roles'] ?? ['admin', 'marketing'];
        unset($options['roles']);

        $options['audience'] = InAppNotification::AUDIENCE_ADMIN;
        $created = collect();

        foreach ($this->usersWithRoles($roles) as $admin) {
            $note = $this->notify($admin, $type, $title, $message, $options);
            if ($note) {
                $created->push($note);
            }
        }

        return $created;
    }

    public function notifyAdminsDepositSubmitted(DepositRequest $deposit): void
    {
        $user = $deposit->user;
        $amount = number_format((float) $deposit->amount, 2);
        $ref = $deposit->reference_code ?: ('#'.$deposit->id);
        $who = $user?->name ?: ($user?->email ?: 'An advertiser');

        $this->notifyAdmins(
            self::TYPE_PAYMENT_RECEIVED,
            'New deposit to review',
            "{$who} submitted a €{$amount} deposit (REF {$ref}). Confirm and credit their wallet.",
            [
                // Money queues are admin-only — marketing is redirected away from /admin/deposits.
                'roles' => ['admin'],
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $deposit,
                'action_label' => 'Review deposit',
                'action_url' => route('admin.deposits', [], false),
                'meta' => [
                    'deposit_id' => $deposit->id,
                    'reference_code' => $deposit->reference_code,
                    'amount' => (float) $deposit->amount,
                    'payment_method' => $deposit->payment_method,
                ],
            ]
        );
    }

    /**
     * Admin: the advertiser says the money is on its way. Nothing is credited
     * until someone matches it against the account, so this needs chasing.
     */
    public function notifyAdminsDepositMarkedPaid(DepositRequest $deposit): void
    {
        $user = $deposit->user;
        $amount = number_format((float) $deposit->amount, 2);
        $ref = $deposit->reference_code ?: ('#'.$deposit->id);
        $who = $user?->name ?: ($user?->email ?: 'An advertiser');
        $note = $deposit->user_payment_note
            ? " Their reference: {$deposit->user_payment_note}."
            : '';

        $this->notifyAdmins(
            self::TYPE_PAYMENT_RECEIVED,
            'Advertiser reported a payment',
            "{$who} says they sent €{$amount} for REF {$ref}.{$note} Check the account and credit the wallet.",
            [
                'roles' => ['admin'],
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $deposit,
                'action_label' => 'Review deposit',
                'action_url' => route('admin.deposits', [], false),
                'meta' => [
                    'deposit_id' => $deposit->id,
                    'reference_code' => $deposit->reference_code,
                    'amount' => (float) $deposit->amount,
                    'payment_method' => $deposit->payment_method,
                    'user_payment_note' => $deposit->user_payment_note,
                    'user_marked_paid_at' => optional($deposit->user_marked_paid_at)?->toIso8601String(),
                ],
            ]
        );
    }

    public function notifyAdminsWithdrawalRequested(Withdrawal $withdrawal, ?User $requester = null): void
    {
        $requester = $requester ?: User::find($withdrawal->user_id);
        $amount = number_format((float) $withdrawal->amount, 2);
        $who = $requester?->name ?: ($requester?->email ?: 'A user');

        $this->notifyAdmins(
            self::TYPE_PAYMENT_RECEIVED,
            'New withdrawal to process',
            "{$who} requested a €{$amount} withdrawal.",
            [
                'roles' => ['admin'],
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $withdrawal,
                'action_label' => 'Review withdrawal',
                'action_url' => route('admin.withdrawals', [], false),
                'meta' => [
                    'withdrawal_id' => $withdrawal->id,
                    'amount' => (float) $withdrawal->amount,
                    'payment_method' => $withdrawal->payment_method ?? null,
                ],
            ]
        );
    }

    public function notifyAdminsNewSite(Site $site, string $action = 'create'): void
    {
        // Incomplete / pre-submit bulk drafts are not ready for admin decision yet.
        if ($site->awaitsPublisherDetails() || $site->hasDetailsComplete()) {
            return;
        }

        $isUpdate = $action === 'update';
        $name = $site->site_name ?: ($site->site_url ?: 'A website');
        $publisherId = (int) ($site->publisher_id ?? 0);

        $actionUrl = route('admin.sites.index', array_filter([
            'needs_review' => 1,
            'publisher' => $publisherId > 0 ? $publisherId : null,
            'site' => $site->id,
        ]), false);

        $this->notifyAdmins(
            self::TYPE_SYSTEM,
            $isUpdate ? 'Site updated — needs review' : 'New site to verify',
            $isUpdate
                ? "{$name} was updated and needs review again."
                : "{$name} was submitted and needs verification.",
            [
                'category' => self::CATEGORY_SYSTEM,
                'icon' => 'bell',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $site,
                'action_label' => 'Review site',
                'action_url' => $actionUrl,
                'meta' => [
                    'site_id' => $site->id,
                    'publisher_id' => $publisherId > 0 ? $publisherId : null,
                    'action' => $isUpdate ? 'update' : 'create',
                    'bulk_site_request_id' => $site->bulk_site_request_id,
                    'open_task' => true,
                ],
            ]
        );
    }

    /**
     * Archive open admin "new site / needs review" bell items for this site.
     * Safe on production: uses existing related_type/related_id + archive columns only.
     */
    public function completeAdminSiteReviewNotifications(Site $site): int
    {
        $siteId = (int) $site->id;
        if ($siteId < 1) {
            return 0;
        }

        $completed = 0;

        try {
            $notes = InAppNotification::query()
                ->where('audience', InAppNotification::AUDIENCE_ADMIN)
                ->whereNull('archived_at')
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhere('status', '!=', InAppNotification::STATUS_ARCHIVED);
                })
                ->where(function ($q) use ($siteId) {
                    $q->where(function ($inner) use ($siteId) {
                        $inner->where('related_type', Site::class)
                            ->where('related_id', $siteId);
                    })->orWhere(function ($inner) use ($siteId) {
                        // Legacy rows that only stored site_id in meta (title-scoped for safety)
                        $inner->whereIn('title', [
                            'New site to verify',
                            'Site updated — needs review',
                        ])->where(function ($meta) use ($siteId) {
                            $meta->where('meta', 'like', '%"site_id":'.$siteId.'%')
                                ->orWhere('meta', 'like', '%"site_id": '.$siteId.'%');
                        });
                    });
                })
                ->get();

            foreach ($notes as $note) {
                $note->archive();
                $completed++;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to complete admin site review notifications: '.$e->getMessage(), [
                'site_id' => $siteId,
            ]);
        }

        return $completed;
    }

    /**
     * Archive open admin "agency CSV import ready for review" bell items.
     */
    public function completeAgencySiteImportNotifications(AgencySiteImport $import): int
    {
        $importId = (int) $import->id;
        if ($importId < 1) {
            return 0;
        }

        $completed = 0;

        try {
            $notes = InAppNotification::query()
                ->where('audience', InAppNotification::AUDIENCE_ADMIN)
                ->whereNull('archived_at')
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhere('status', '!=', InAppNotification::STATUS_ARCHIVED);
                })
                ->where(function ($q) use ($importId) {
                    $q->where(function ($inner) use ($importId) {
                        $inner->where('related_type', AgencySiteImport::class)
                            ->where('related_id', $importId);
                    })->orWhere(function ($inner) use ($importId) {
                        $inner->where('title', 'Agency CSV import ready for review')
                            ->where(function ($meta) use ($importId) {
                                $meta->where('meta', 'like', '%"import_id":'.$importId.'%')
                                    ->orWhere('meta', 'like', '%"import_id": '.$importId.'%');
                            });
                    });
                })
                ->get();

            foreach ($notes as $note) {
                $note->archive();
                $completed++;
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to complete agency CSV import notifications: '.$e->getMessage(), [
                'import_id' => $importId,
            ]);
        }

        return $completed;
    }

    public function notifyAdminsNewUser(User $user): void
    {
        $who = $user->name ?: $user->email;
        $role = $user->activeRole();
        $title = match ($role) {
            'advertiser' => 'New advertiser registered',
            'publisher' => 'New publisher registered',
            default => 'New user registered',
        };
        $roleLabel = $role ?: 'user';
        $article = preg_match('/^[aeiou]/i', $roleLabel) ? 'an' : 'a';
        $actionUrl = match ($role) {
            'advertiser' => route('admin.audiences.index', ['tab' => 'no_orders'], false),
            'publisher' => route('admin.audiences.index', ['tab' => 'no_sites'], false),
            default => route('admin.users.index', [], false),
        };
        $actionLabel = match ($role) {
            'advertiser' => 'View advertisers (no orders)',
            'publisher' => 'View publishers (no sites)',
            default => 'View users',
        };

        $this->notifyAdmins(
            self::TYPE_ACCOUNT,
            $title,
            "{$who} just created {$article} {$roleLabel} account.",
            [
                'roles' => ['admin'],
                'category' => self::CATEGORY_ACCOUNT,
                'icon' => 'user',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $user,
                'action_label' => $actionLabel,
                'action_url' => $actionUrl,
                'meta' => [
                    'registered_user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $role,
                ],
            ]
        );
    }

    /**
     * @param  iterable<int, Order>  $orders
     */
    public function notifyAdminsManualPayment(User $customer, iterable $orders, string $paymentMethod): void
    {
        $orders = collect($orders);
        $total = (float) $orders->sum(fn (Order $o) => (float) $o->total_amount);
        $count = $orders->count();
        $who = $customer->name ?: $customer->email;
        $method = strtoupper($paymentMethod);
        $amount = number_format($total, 2);

        $this->notifyAdmins(
            self::TYPE_PAYMENT_RECEIVED,
            'Manual payment to confirm',
            "{$who} marked {$count} order(s) paid via {$method} (€{$amount}). Confirm when funds arrive.",
            [
                'roles' => ['admin'],
                'category' => self::CATEGORY_PAYMENTS,
                'icon' => 'wallet',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $orders->first(),
                'action_label' => 'Review payments',
                'action_url' => route('admin.payments', [], false),
                'meta' => [
                    'customer_id' => $customer->id,
                    'payment_method' => $paymentMethod,
                    'order_ids' => $orders->pluck('id')->values()->all(),
                    'total_amount' => $total,
                ],
            ]
        );
    }

    /**
     * Staff (admin + marketing): publisher submitted a bulk URL+price request.
     */
    public function notifyStaffBulkSiteRequestSubmitted(BulkSiteRequest $bulk): void
    {
        $bulk->loadMissing('publisher', 'items');
        $who = $bulk->publisher?->name ?: ($bulk->publisher?->email ?: 'A publisher');
        $count = $bulk->items->count() ?: (int) ($bulk->estimated_count ?? 0);

        $this->notifyAdmins(
            self::TYPE_SYSTEM,
            'New bulk sites request',
            "{$who} submitted {$count} site URL(s) + price(s). Add them to Pending sites when ready.",
            [
                'category' => self::CATEGORY_SYSTEM,
                'icon' => 'bell',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $bulk,
                'action_label' => 'Open bulk request',
                // Admin route works for admins; RedirectMarketingFromAdmin remaps it for marketers.
                'action_url' => route('admin.bulk-site-requests.show', $bulk->id, false),
                'meta' => [
                    'bulk_site_request_id' => $bulk->id,
                    'publisher_id' => $bulk->publisher_id,
                    'estimated_count' => $count,
                ],
            ]
        );
    }

    /**
     * Publisher: marketer finished their part — drafts are on Pending sites.
     */
    /**
     * The publisher submitted a batch of sites and staff cancelled it.
     *
     * Without this the request simply disappears from their queue with no
     * explanation, which reads as the platform losing their work.
     */
    public function notifyPublisherBulkRequestCancelled(BulkSiteRequest $bulk, ?string $reason = null): void
    {
        $publisherId = (int) ($bulk->publisher_id ?? 0);
        if ($publisherId <= 0) {
            return;
        }

        $message = 'Your bulk website submission was cancelled, so those sites will not be prepared.';
        if (filled($reason)) {
            $message .= ' Reason: '.trim($reason);
        }
        $message .= ' You can submit them again at any time.';

        $this->notify(
            $publisherId,
            self::TYPE_SITE_STATUS,
            'Bulk website request cancelled',
            $message,
            [
                'category' => self::CATEGORY_ACCOUNT,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $bulk,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Add websites',
                'action_url' => route('publisher.websites', [], false),
                'meta' => [
                    'bulk_site_request_id' => $bulk->id,
                    'reason' => $reason,
                ],
            ]
        );
    }

    public function notifyPublisherBulkSitesAdded(BulkSiteRequest $bulk, int $createdCount): void
    {
        $publisherId = (int) ($bulk->publisher_id ?? 0);
        if ($publisherId <= 0 || $createdCount <= 0) {
            return;
        }

        $this->notify(
            $publisherId,
            self::TYPE_SITE_STATUS,
            $createdCount === 1
                ? 'Your site was added to Pending sites'
                : "{$createdCount} sites were added to Pending sites",
            'We’ve added your sites to Pending sites — open them and finish any remaining details. They stay hidden from advertisers until we approve.',
            [
                'category' => self::CATEGORY_ACCOUNT,
                'icon' => 'check-circle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $bulk,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Open Pending sites',
                'action_url' => route('publisher.websites', ['status' => 'pending'], false),
                'meta' => [
                    'bulk_site_request_id' => $bulk->id,
                    'created_count' => $createdCount,
                ],
            ]
        );
    }

    public function notifyPublisherSiteAssignedForAcceptance(Site $site): void
    {
        $publisherId = (int) ($site->publisher_id ?? 0);
        if ($publisherId <= 0) {
            return;
        }

        $domain = $site->domain ?: $site->site_name ?: 'a website';

        $this->notify(
            $publisherId,
            self::TYPE_SITE_STATUS,
            'Please accept a website we added for you',
            "Our team added {$domain}. Accept it to show the listing in My Sites. You can still verify ownership with the TXT file for the Verified badge.",
            [
                'category' => self::CATEGORY_ACCOUNT,
                'icon' => 'check-circle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $site,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'Review & accept',
                'action_url' => route('publisher.websites', ['status' => 'invites'], false),
                'meta' => [
                    'site_id' => $site->id,
                    'domain' => $domain,
                ],
            ]
        );
    }

    /**
     * Admin bell: publisher submitted an agency CSV import batch.
     */
    public function notifyAdminsAgencySiteImportSubmitted(AgencySiteImport $import): void
    {
        $import->loadMissing(['publisher']);
        $who = $import->publisher?->name ?: ($import->publisher?->email ?: 'A publisher');
        $created = (int) $import->created_count;
        $failed = (int) $import->failed_count;

        $this->notifyAdmins(
            self::TYPE_SYSTEM,
            'Agency CSV import ready for review',
            "{$who} submitted {$created} site(s) via CSV".($failed > 0 ? " ({$failed} row(s) failed)" : '').'.',
            [
                'roles' => ['admin'],
                'category' => self::CATEGORY_SYSTEM,
                'icon' => 'file-csv',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $import,
                'action_label' => 'Review import',
                'action_url' => route('admin.agency-imports.show', $import, false),
                'meta' => [
                    'import_id' => $import->id,
                    'publisher_id' => $import->publisher_id,
                    'created_count' => $created,
                    'failed_count' => $failed,
                ],
            ]
        );
    }

    /**
     * Admin bell: a user submitted an ownership claim awaiting review.
     */
    public function notifyAdminsSiteClaimSubmitted(SiteClaim $claim): void
    {
        $claim->loadMissing(['site', 'claimer']);
        $siteName = $claim->site?->site_name ?: ($claim->website_name ?: 'a website');
        $who = $claim->claimer?->name ?: ($claim->contact_email ?: ($claim->claimer?->email ?: 'A user'));
        $matchLabel = $claim->name_matches ? 'Name matches the listing.' : 'Name does not match — verify carefully.';

        $this->notifyAdmins(
            self::TYPE_SYSTEM,
            'New site ownership claim',
            "{$who} claimed ownership of {$siteName}. {$matchLabel}",
            [
                // Community review is admin-only (marketing is redirected away).
                'roles' => ['admin'],
                'category' => self::CATEGORY_SYSTEM,
                'icon' => 'user-check',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $claim->site ?: $claim,
                'action_label' => 'Review claims',
                'action_url' => route('admin.community.index', ['tab' => 'claims', 'status' => 'pending'], false),
                'meta' => [
                    'claim_id' => $claim->id,
                    'site_id' => $claim->site_id,
                    'claimer_id' => $claim->claimer_id,
                    'name_matches' => (bool) $claim->name_matches,
                ],
            ]
        );
    }

    /**
     * Claimer bell: their ownership claim was approved or rejected.
     */
    public function notifyClaimerSiteClaimReviewed(SiteClaim $claim): void
    {
        $claimerId = (int) ($claim->claimer_id ?? 0);
        if ($claimerId <= 0) {
            return;
        }

        $claim->loadMissing(['site']);
        $approved = $claim->status === 'approved';
        $siteName = $claim->site?->site_name ?: ($claim->website_name ?: 'the website');
        $notes = trim((string) ($claim->admin_notes ?? ''));

        if ($approved) {
            $title = "Claim approved — {$siteName}";
            $message = "Ownership of {$siteName} was transferred to your account. Manage it from My Sites.";
            $actionLabel = 'Open My Sites';
            $actionUrl = route('publisher.websites', [], false);
        } else {
            $title = "Claim not approved — {$siteName}";
            $message = "We reviewed your ownership claim for {$siteName} and could not transfer it at this time.";
            if ($notes !== '') {
                $message .= ' Note: '.$notes;
            }
            $actionLabel = 'View my claims';
            $actionUrl = route('site-claims.index', [], false);
        }

        $this->notify(
            $claimerId,
            self::TYPE_SITE_STATUS,
            $title,
            $message,
            [
                'category' => self::CATEGORY_ACCOUNT,
                'icon' => $approved ? 'check-circle' : 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $claim->site ?: $claim,
                // Advertisers can claim before they ever switch to publisher; keep
                // the approve bell visible on their current active role.
                'audience' => InAppNotification::AUDIENCE_ALL,
                'action_label' => $actionLabel,
                'action_url' => $actionUrl,
                'meta' => [
                    'claim_id' => $claim->id,
                    'site_id' => $claim->site_id,
                    'status' => $claim->status,
                ],
            ]
        );
    }

    /**
     * Previous publisher bell: an approved claim moved their listing away.
     */
    public function notifyPreviousPublisherOwnershipTransferred(SiteClaim $claim, User $previous): void
    {
        $previousId = (int) ($previous->id ?? 0);
        if ($previousId <= 0) {
            return;
        }

        $claim->loadMissing(['site']);
        $siteName = $claim->site?->site_name ?: ($claim->website_name ?: 'a listing');

        $this->notify(
            $previousId,
            self::TYPE_SITE_STATUS,
            "Listing ownership transferred — {$siteName}",
            "An approved ownership claim moved {$siteName} off your publisher account. Contact support if this looks wrong.",
            [
                'category' => self::CATEGORY_ACCOUNT,
                'icon' => 'alert-triangle',
                'priority' => InAppNotification::PRIORITY_HIGH,
                'related' => $claim->site ?: $claim,
                'audience' => InAppNotification::AUDIENCE_PUBLISHER,
                'action_label' => 'View sites',
                'action_url' => route('publisher.websites', [], false),
                'meta' => [
                    'claim_id' => $claim->id,
                    'site_id' => $claim->site_id,
                ],
            ]
        );
    }

    /**
     * Staff who share Sites/bulk ops (admin + marketing).
     *
     * @return Collection<int, User>
     */
    protected function adminUsers(): Collection
    {
        return $this->usersWithRoles(['admin', 'marketing']);
    }

    /**
     * @param  list<string>  $roleNames
     * @return Collection<int, User>
     */
    protected function usersWithRoles(array $roleNames): Collection
    {
        $roleNames = array_values(array_unique(array_filter($roleNames)));
        if ($roleNames === []) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roleNames))
            ->get();
    }

    public function unreadCount(int $userId, ?string $audience = null): int
    {
        InAppNotification::ensureTable();
        if (! InAppNotification::tableAvailable()) {
            return 0;
        }

        return InAppNotification::forUser($userId)
            ->forAudience($audience)
            ->unread()
            ->whereNull('archived_at')
            ->count();
    }

    public function listForUser(int $userId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        InAppNotification::ensureTable();
        if (! InAppNotification::tableAvailable()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

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
        InAppNotification::ensureTable();
        if (! InAppNotification::tableAvailable()) {
            return 0;
        }

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
        if (str_contains($url, '/admin/') || str_contains($url, '/marketing/')) {
            return InAppNotification::AUDIENCE_ADMIN;
        }

        return InAppNotification::AUDIENCE_ALL;
    }

    protected function categoryForType(string $type): string
    {
        return match ($type) {
            self::TYPE_MESSAGE, self::TYPE_CHAT_REPLY => self::CATEGORY_MESSAGES,
            self::TYPE_PAYMENT_RECEIVED, self::TYPE_PAYMENT_FAILED, self::TYPE_PAYMENT_PENDING => self::CATEGORY_PAYMENTS,
            self::TYPE_ACCOUNT, self::TYPE_SITE_STATUS => self::CATEGORY_ACCOUNT,
            self::TYPE_SYSTEM, self::TYPE_CONTENT_APPROVED, self::TYPE_CONTENT_NEEDS_CHANGES => self::CATEGORY_SYSTEM,
            default => self::CATEGORY_ORDERS,
        };
    }

    protected function iconForType(string $type): string
    {
        return match ($type) {
            self::TYPE_MESSAGE, self::TYPE_CHAT_REPLY => 'message-circle',
            self::TYPE_ORDER_CREATED => 'package',
            self::TYPE_ORDER_ACCEPTED, self::TYPE_CONTENT_APPROVED => 'check-circle',
            self::TYPE_ORDER_REJECTED => 'x-circle',
            self::TYPE_GUEST_POST_PUBLISHED => 'rocket',
            self::TYPE_ORDER_COMPLETED => 'badge-check',
            self::TYPE_MODIFICATION_REQUESTED => 'pencil',
            self::TYPE_CONTENT_REVISION_REQUESTED => 'file-text',
            self::TYPE_CONTENT_REVISION_FULFILLED => 'check-circle',
            self::TYPE_ORDER_UPDATED => 'refresh-cw',
            self::TYPE_PAYMENT_RECEIVED => 'wallet',
            self::TYPE_PAYMENT_FAILED, self::TYPE_CONTENT_NEEDS_CHANGES => 'alert-triangle',
            self::TYPE_PAYMENT_PENDING => 'wallet',
            self::TYPE_SITE_STATUS => 'check-circle',
            self::TYPE_ACCOUNT => 'user',
            default => 'bell',
        };
    }
}
