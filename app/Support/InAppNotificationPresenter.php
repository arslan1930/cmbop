<?php

namespace App\Support;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Enriches in-app notification payloads for the bell dropdown + Show all page.
 * Pure presentation — never invents review deadlines when none exist.
 */
class InAppNotificationPresenter
{
    /** Types that typically need the recipient to do something. */
    public const ACTION_NEEDED_TYPES = [
        InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
        InAppNotificationService::TYPE_ORDER_CREATED,
        InAppNotificationService::TYPE_MODIFICATION_REQUESTED,
        InAppNotificationService::TYPE_CONTENT_REVISION_REQUESTED,
        InAppNotificationService::TYPE_CONTENT_REVISION_FULFILLED,
        InAppNotificationService::TYPE_CONTENT_NEEDS_CHANGES,
        InAppNotificationService::TYPE_PAYMENT_FAILED,
        InAppNotificationService::TYPE_ORDER_REJECTED,
    ];

    /** Compact lifecycle steps shown on grouped order cards. */
    public const TIMELINE_STEPS = [
        'placed' => 'Placed',
        'accepted' => 'Accepted',
        'published' => 'Published',
        'completed' => 'Approved',
    ];

    /**
     * @param  Collection<int, InAppNotification>|iterable<InAppNotification>  $notifications
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $notifications): array
    {
        $models = Collection::make($notifications)->values();
        if ($models->isEmpty()) {
            return [];
        }

        [$ordersById, $liveActionIds] = $this->loadOrders($models);

        return $models
            ->map(fn (InAppNotification $n) => $this->presentOne($n, $ordersById, $liveActionIds))
            ->all();
    }

    /**
     * @param  Collection<int, Order>|null  $ordersById
     * @param  array<int, true>|null  $liveActionIds
     * @return array<string, mixed>
     */
    public function presentOne(
        InAppNotification $notification,
        ?Collection $ordersById = null,
        ?array $liveActionIds = null
    ): array {
        $base = $notification->toApiArray();
        $meta = is_array($base['meta'] ?? null) ? $base['meta'] : [];
        $order = $this->resolveOrder($notification, $ordersById);
        if (! $order) {
            $order = $this->fetchRelatedOrder($notification);
        }
        $item = $order?->items?->first();
        $site = $item?->site;

        if ($liveActionIds === null && $order) {
            $liveActionIds = app(OrderNotificationReconciliation::class)->liveActionOrderIds(
                (int) $notification->user_id,
                (string) ($notification->audience ?: InAppNotification::AUDIENCE_ALL),
                [(int) $order->id],
                collect([(int) $order->id => $order])
            );
        }

        $liveUrl = $this->resolveLiveUrl($meta, $item);
        $domain = $this->resolveDomain($meta, $site, $item);
        $actionNeeded = app(OrderNotificationReconciliation::class)->notificationNeedsAction(
            $notification,
            $order,
            $liveActionIds ?? []
        );
        $autoApproveLabel = $this->autoApproveLabel($base['type'] ?? '', $meta, $item, $notification->created_at, $order);
        $timelineStep = $this->timelineStepForType($base['type'] ?? '');
        $siteName = $this->resolveSiteName($meta, $site, $item);
        $isMessage = $this->isMessageType((string) ($base['type'] ?? ''), (string) ($base['category'] ?? ''));
        $isLifecycle = InAppNotificationService::isLifecycleNotification(
            (string) ($base['type'] ?? ''),
            (string) ($base['category'] ?? '')
        );

        $base['action_needed'] = $actionNeeded;
        $base['auto_approve_label'] = $autoApproveLabel;
        $base['site_domain'] = $domain;
        $base['site_name'] = $siteName;
        $base['site_initial'] = $this->siteInitial($siteName, $domain);
        $faviconChain = $this->faviconChain($domain, $site);
        $thumb = $faviconChain[0] ?? null;
        $base['thumb_url'] = $thumb;
        $base['favicon_url'] = $thumb;
        $base['favicon_chain'] = $faviconChain;
        $base['order_id'] = $this->orderId($notification, $order, $meta);
        $base['order_number'] = $meta['order_number'] ?? $order?->order_number;
        $base['order_ref'] = $this->orderRef($base['order_number'] ?? null);
        $base['order_pack_id'] = $meta['order_pack_id'] ?? $order?->order_pack_id;
        $base['is_message'] = $isMessage;
        $base['is_lifecycle'] = $isLifecycle;
        $base['group_key'] = $this->groupKey($base);
        $base['timeline_step'] = $timelineStep;
        $base['display_time'] = $this->displayTime($notification->created_at);
        $base['clock_time'] = $this->clockTime($notification->created_at);
        $base['day_group'] = $this->dayGroup($notification->created_at);
        $base['lifecycle_stepper'] = null;
        if ($isLifecycle && $order && ! $this->orderIsRejected($order, (string) ($base['type'] ?? ''))) {
            try {
                $base['lifecycle_stepper'] = AdvertiserOrderStatus::lifecycleStepper($order, $item);
            } catch (\Throwable) {
                $base['lifecycle_stepper'] = null;
            }
        }

        $this->applyLiveCopy($base, $order, $item, $meta, $notification);
        $base['live_url'] = ($isMessage || ! $this->shouldShowLiveUrl($order, $item, $liveUrl))
            ? null
            : $liveUrl;

        return $base;
    }

    /**
     * Collapse order-related rows into cards; leave others as single rows.
     * Action-needed cards are pinned above info-only cards.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function groupItems(array $items): array
    {
        $groups = [];
        $order = [];

        foreach ($items as $item) {
            $key = $item['group_key'] ?? null;
            $isOrderGroup = is_string($key)
                && (str_starts_with($key, 'order:') || str_starts_with($key, 'pack:'));
            if (! $isOrderGroup) {
                $singletonKey = 'solo:'.($item['id'] ?? uniqid('n', true));
                $groups[$singletonKey] = $this->makeCard([$item], false);
                $order[] = $singletonKey;

                continue;
            }

            if (! isset($groups[$key])) {
                $groups[$key] = ['events' => [], 'is_group' => true];
                $order[] = $key;
            }
            $groups[$key]['events'][] = $item;
        }

        $cards = [];
        foreach ($order as $key) {
            $bucket = $groups[$key];
            if (isset($bucket['is_group']) && $bucket['is_group'] && ! isset($bucket['latest'])) {
                $cards[] = $this->makeCard($bucket['events'], true);
            } else {
                $cards[] = $bucket;
            }
        }

        usort($cards, function (array $a, array $b) {
            $aNeed = ! empty($a['action_needed']) ? 0 : 1;
            $bNeed = ! empty($b['action_needed']) ? 0 : 1;
            if ($aNeed !== $bNeed) {
                return $aNeed <=> $bNeed;
            }

            $aTs = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['created_at'] ?? '')) ?: 0;

            return $bTs <=> $aTs;
        });

        return $this->collapseLifecycleCards($cards);
    }

    /**
     * Last-pass collapse so two Approved cards for the same order never ship
     * even when related_id / audience / archive flags disagreed upstream.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    protected function collapseLifecycleCards(array $cards): array
    {
        $out = [];
        $index = [];

        foreach ($cards as $card) {
            if (empty($card['is_lifecycle'])) {
                $out[] = $card;

                continue;
            }

            $keys = $this->lifecycleDedupeKeys($card);
            $hit = null;
            foreach ($keys as $key) {
                if (isset($index[$key])) {
                    $hit = $index[$key];
                    break;
                }
            }

            if ($hit === null) {
                $slot = count($out);
                $out[] = $card;
                foreach ($keys as $key) {
                    $index[$key] = $slot;
                }

                continue;
            }

            if ($this->cardIsNewer($card, $out[$hit])) {
                $out[$hit] = $card;
            }
            foreach ($keys as $key) {
                $index[$key] = $hit;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    protected function lifecycleDedupeKeys(array $card): array
    {
        $keys = [];
        if (! empty($card['order_id'])) {
            $keys[] = 'id:'.(int) $card['order_id'];
        }
        $number = trim((string) ($card['order_number'] ?? ''));
        if ($number !== '') {
            $keys[] = 'num:'.strtolower(ltrim($number, '#'));
        }
        $ref = trim((string) ($card['order_ref'] ?? ''));
        if ($ref !== '') {
            $keys[] = 'ref:'.strtolower($ref);
        }

        return $keys !== [] ? $keys : ['solo:'.($card['id'] ?? uniqid('n', true))];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    protected function cardIsNewer(array $candidate, array $current): bool
    {
        $aId = (int) ($candidate['id'] ?? 0);
        $bId = (int) ($current['id'] ?? 0);
        if ($aId !== $bId) {
            return $aId > $bId;
        }
        $aTs = strtotime((string) ($candidate['created_at'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($current['created_at'] ?? '')) ?: 0;

        return $aTs >= $bTs;
    }

    public function isActionNeeded(string $type, ?string $actionLabel = null, ?string $priority = null): bool
    {
        return app(OrderNotificationReconciliation::class)->typeHeuristic($type, $actionLabel, $priority);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    protected function makeCard(array $events, bool $grouped): array
    {
        usort($events, function (array $a, array $b) {
            $aTs = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['created_at'] ?? '')) ?: 0;

            return $bTs <=> $aTs;
        });

        $latest = $events[0];
        $anyUnread = collect($events)->contains(fn ($e) => ! empty($e['is_unread']));
        $anyAction = collect($events)->contains(fn ($e) => ! empty($e['action_needed']));
        $ids = collect($events)->pluck('id')->filter()->values()->all();

        $timeline = null;
        $lifecycle = null;
        if (($latest['order_id'] ?? null) && ($latest['category'] ?? '') === InAppNotificationService::CATEGORY_ORDERS) {
            $timeline = $this->buildTimeline($events);
            $fromOrder = collect($events)->first(
                fn ($e) => ! empty($e['action_needed']) && ! empty($e['lifecycle_stepper']) && is_array($e['lifecycle_stepper'])
            )['lifecycle_stepper'] ?? null;
            if (! is_array($fromOrder) || $fromOrder === []) {
                $fromOrder = ! empty($latest['lifecycle_stepper']) && is_array($latest['lifecycle_stepper'])
                    ? $latest['lifecycle_stepper']
                    : null;
            }
            // Live order stepper wins. Event history is kept on `timeline` only.
            $lifecycle = $fromOrder;
            if (! $lifecycle && is_array($timeline) && $timeline !== []) {
                $current = 1;
                foreach ($timeline as $i => $step) {
                    if (($step['state'] ?? '') === 'current') {
                        $current = $i + 1;
                    }
                }
                $lifecycle = ['current' => $current, 'steps' => $timeline];
            }
        }

        return array_merge($latest, [
            'is_group' => $grouped && count($events) > 1,
            'event_count' => count($events),
            'events' => $events,
            'event_ids' => $ids,
            'is_unread' => $anyUnread,
            'action_needed' => $anyAction || ! empty($latest['action_needed']),
            'timeline' => $timeline,
            'lifecycle_stepper' => $lifecycle,
            'is_lifecycle' => ! empty($latest['is_lifecycle']),
            'is_message' => ! empty($latest['is_message']),
            'title_primary' => $latest['title_primary'] ?? ($latest['title'] ?? ''),
            'order_ref' => $latest['order_ref'] ?? null,
            'site_initial' => $latest['site_initial'] ?? null,
            'thumb_url' => collect($events)->first(fn ($e) => ! empty($e['thumb_url']))['thumb_url']
                ?? ($latest['thumb_url'] ?? null),
            // Prefer live URL / auto-approve from the most relevant event in the stack.
            'live_url' => (! empty($latest['is_message']))
                ? null
                : (collect($events)->first(fn ($e) => ! empty($e['live_url']))['live_url']
                    ?? ($latest['live_url'] ?? null)),
            'auto_approve_label' => collect($events)->first(fn ($e) => ! empty($e['auto_approve_label']))['auto_approve_label']
                ?? ($latest['auto_approve_label'] ?? null),
            'favicon_url' => collect($events)->first(fn ($e) => ! empty($e['favicon_url']))['favicon_url']
                ?? ($latest['favicon_url'] ?? null),
            'favicon_chain' => collect($events)->first(fn ($e) => ! empty($e['favicon_chain']))['favicon_chain']
                ?? ($latest['favicon_chain'] ?? []),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array{key: string, label: string, state: string}>
     */
    protected function buildTimeline(array $events): array
    {
        $reached = [];
        foreach ($events as $event) {
            $step = $event['timeline_step'] ?? $this->timelineStepForType((string) ($event['type'] ?? ''));
            if ($step) {
                $reached[$step] = true;
            }
        }

        // Rejected orders still show Placed as done; Accepted stays pending.
        $sequence = array_keys(self::TIMELINE_STEPS);
        $current = null;
        foreach (array_reverse($sequence) as $step) {
            if (! empty($reached[$step])) {
                $current = $step;
                break;
            }
        }

        $passedCurrent = false;
        $out = [];
        foreach (self::TIMELINE_STEPS as $key => $label) {
            if ($key === $current) {
                $state = 'current';
                $passedCurrent = true;
            } elseif (! $passedCurrent && ! empty($reached[$key])) {
                $state = 'done';
            } elseif (! $passedCurrent) {
                // Earlier steps implied by a later one
                $laterReached = false;
                $seen = false;
                foreach ($sequence as $s) {
                    if ($s === $key) {
                        $seen = true;

                        continue;
                    }
                    if ($seen && ! empty($reached[$s])) {
                        $laterReached = true;
                        break;
                    }
                }
                $state = $laterReached ? 'done' : 'todo';
            } else {
                $state = 'todo';
            }

            $out[] = [
                'key' => $key,
                'label' => $label,
                'state' => $state,
            ];
        }

        return $out;
    }

    protected function timelineStepForType(string $type): ?string
    {
        return match ($type) {
            InAppNotificationService::TYPE_ORDER_CREATED,
            InAppNotificationService::TYPE_PAYMENT_RECEIVED => 'placed',
            InAppNotificationService::TYPE_ORDER_ACCEPTED => 'accepted',
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED => 'published',
            InAppNotificationService::TYPE_ORDER_COMPLETED => 'completed',
            default => null,
        };
    }

    /**
     * @param  Collection<int, InAppNotification>  $models
     * @return array{0: Collection<int, Order>, 1: array<int, true>}
     */
    protected function loadOrders(Collection $models): array
    {
        $orderIds = $models
            ->map(fn (InAppNotification $n) => InAppNotificationService::canonicalOrderId($n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return [collect(), []];
        }

        try {
            $orders = Order::query()
                ->with(['items.site'])
                ->whereIn('id', $orderIds)
                ->get()
                ->keyBy('id');
        } catch (\Throwable) {
            return [collect(), []];
        }

        $liveActionIds = [];
        $byUserAudience = [];
        foreach ($models as $notification) {
            $oid = InAppNotificationService::canonicalOrderId($notification);
            if (! $oid || ! $orders->has($oid)) {
                continue;
            }
            $uid = (int) $notification->user_id;
            $audience = strtolower((string) ($notification->audience ?: InAppNotification::AUDIENCE_ALL));
            $byUserAudience[$uid][$audience][$oid] = true;
        }
        $reconciliation = app(OrderNotificationReconciliation::class);
        foreach ($byUserAudience as $userId => $audiences) {
            foreach ($audiences as $audience => $ids) {
                $liveActionIds += $reconciliation->liveActionOrderIds(
                    (int) $userId,
                    (string) $audience,
                    array_map('intval', array_keys($ids)),
                    $orders
                );
            }
        }

        return [$orders, $liveActionIds];
    }

    /**
     * @param  Collection<int, Order>|null  $ordersById
     */
    protected function resolveOrder(InAppNotification $notification, ?Collection $ordersById): ?Order
    {
        $id = InAppNotificationService::canonicalOrderId($notification);
        if (! $id) {
            return null;
        }

        return $ordersById?->get($id);
    }

    protected function fetchRelatedOrder(InAppNotification $notification): ?Order
    {
        $id = InAppNotificationService::canonicalOrderId($notification);
        if (! $id) {
            return null;
        }

        try {
            return Order::query()->with('items.site')->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveLiveUrl(array $meta, ?OrderItem $item): ?string
    {
        $url = $meta['live_url'] ?? null;
        if (is_string($url) && $url !== '' && Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }
        $fromItem = $item?->live_url;
        if (is_string($fromItem) && $fromItem !== '' && Str::startsWith($fromItem, ['http://', 'https://'])) {
            return $fromItem;
        }

        return null;
    }

    protected function shouldShowLiveUrl(?Order $order, ?OrderItem $item, ?string $url): bool
    {
        if (! is_string($url) || $url === '' || ! Str::startsWith($url, ['http://', 'https://'])) {
            return false;
        }
        $status = strtolower((string) ($order?->status ?? ''));
        if (in_array($status, ['review', 'completed'], true)) {
            return true;
        }
        if ($item && filled($item->live_url) && in_array($status, ['review', 'completed', 'in_progress'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $meta
     */
    protected function applyLiveCopy(array &$base, ?Order $order, ?OrderItem $item, array $meta, InAppNotification $notification): void
    {
        $siteName = (string) ($base['site_name'] ?? '');
        $orderRef = $base['order_ref'] ?? $this->orderRef($base['order_number'] ?? null);
        $isMessage = ! empty($base['is_message']);
        $isLifecycle = ! empty($base['is_lifecycle']);

        if ($isMessage) {
            $primary = $siteName !== '' ? $siteName : (string) ($base['title'] ?? '');
            $base['title_primary'] = $primary;
            $base['order_ref'] = $orderRef;
            $base['title'] = $primary;
            $base['message'] = $this->prefixedMessage(
                (string) ($base['message'] ?? ''),
                $order,
                $meta,
                (string) ($notification->audience ?? '')
            );

            return;
        }

        if (! $isLifecycle || ! $order) {
            $base['title_primary'] = (string) ($base['title'] ?? '');
            $base['order_ref'] = $orderRef;

            return;
        }

        $statusKey = $this->liveStatusKey($order, (string) ($base['type'] ?? ''));
        $statusLabel = $this->statusLabel($statusKey);
        $primary = $siteName !== ''
            ? __('messages.nc_title_with_status', ['site' => $siteName, 'status' => $statusLabel])
            : $statusLabel;
        $base['title_primary'] = $primary;
        $base['order_ref'] = $orderRef;
        $base['title'] = $primary;
        $base['status_key'] = $statusKey;
        $base['status_label'] = $statusLabel;

        $storedStep = $this->timelineStepForType((string) ($base['type'] ?? ''));
        if ($statusKey === 'rejected') {
            $storedStep = 'rejected';
        }
        if ($statusKey && $storedStep === $statusKey) {
            return;
        }

        $audience = strtolower((string) ($notification->audience ?? ''));
        $rewritten = $this->liveStatusBody($statusKey, $siteName, $audience, $item);
        if ($rewritten !== null) {
            $base['message'] = $rewritten;
        }
    }

    protected function liveStatusKey(?Order $order, string $type): string
    {
        if ($this->orderIsRejected($order, $type)) {
            return 'rejected';
        }
        if (! $order) {
            return match ($type) {
                InAppNotificationService::TYPE_ORDER_CREATED,
                InAppNotificationService::TYPE_PAYMENT_RECEIVED => 'placed',
                InAppNotificationService::TYPE_ORDER_ACCEPTED => 'accepted',
                InAppNotificationService::TYPE_GUEST_POST_PUBLISHED => 'published',
                InAppNotificationService::TYPE_ORDER_COMPLETED => 'approved',
                InAppNotificationService::TYPE_ORDER_REJECTED => 'rejected',
                default => 'placed',
            };
        }

        try {
            $stepper = AdvertiserOrderStatus::lifecycleStepper($order, $order->items->first());
            $current = (int) ($stepper['current'] ?? 1);
        } catch (\Throwable) {
            $current = 1;
        }

        return match ($current) {
            4 => 'approved',
            3 => 'published',
            2 => 'accepted',
            default => 'placed',
        };
    }

    protected function statusLabel(string $key): string
    {
        return match ($key) {
            'accepted' => __('messages.nc_site_accepted'),
            'published' => __('messages.nc_site_published'),
            'approved' => __('messages.nc_site_approved'),
            'rejected' => __('messages.nc_site_rejected'),
            default => __('messages.nc_site_placed'),
        };
    }

    protected function liveStatusBody(string $statusKey, string $site, string $audience, ?OrderItem $item): ?string
    {
        $site = $site !== '' ? $site : 'this site';
        $publisher = $audience === InAppNotification::AUDIENCE_PUBLISHER;

        $body = match ($statusKey) {
            'accepted' => $publisher
                ? __('messages.nc_publisher_accepted_body', ['site' => $site])
                : __('messages.nc_order_accepted_body', ['site' => $site]),
            'published' => $publisher
                ? __('messages.nc_publisher_published_body', ['site' => $site])
                : __('messages.nc_order_published_body', ['site' => $site]),
            'approved' => $publisher
                ? __('messages.nc_publisher_approved_body', ['site' => $site])
                : __('messages.nc_order_approved_body', ['site' => $site]),
            'rejected' => $publisher
                ? __('messages.nc_publisher_rejected_body', ['site' => $site])
                : __('messages.nc_order_rejected_body', ['site' => $site]),
            default => $publisher
                ? __('messages.nc_publisher_placed_body', ['site' => $site])
                : __('messages.nc_order_placed_body', ['site' => $site]),
        };

        if ($statusKey === 'accepted' && $item && method_exists($item, 'purchasedPlacementSentence')) {
            try {
                $extras = $item->purchasedPlacementSentence();
                if (is_string($extras) && $extras !== '') {
                    $body .= ' '.$extras;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function prefixedMessage(string $preview, ?Order $order, array $meta, string $audience): string
    {
        $preview = trim($preview);
        if ($preview === '') {
            return $preview;
        }
        if (preg_match('/^(Publisher|Advertiser|Support)\s*:/u', $preview)) {
            return $preview;
        }

        $label = $this->senderRoleLabel($order, $meta, $audience);
        if ($label === '') {
            return $preview;
        }

        return __('messages.nc_message_preview', ['sender' => $label, 'preview' => $preview]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function senderRoleLabel(?Order $order, array $meta, string $audience): string
    {
        $stored = strtolower(trim((string) ($meta['sender_role'] ?? '')));
        if (in_array($stored, ['publisher', 'advertiser', 'support'], true)) {
            return match ($stored) {
                'publisher' => __('messages.nc_sender_publisher'),
                'advertiser' => __('messages.nc_sender_advertiser'),
                default => __('messages.nc_sender_support'),
            };
        }

        $senderId = (int) ($meta['sender_id'] ?? 0);
        if ($order && $senderId > 0) {
            if ((int) $order->user_id === $senderId) {
                return __('messages.nc_sender_advertiser');
            }
            try {
                $publisherId = (int) ($order->items->first()?->site?->publisher_id ?? 0);
                if ($publisherId === $senderId) {
                    return __('messages.nc_sender_publisher');
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $name = $this->safePersonName($meta['sender_name'] ?? null);
        if ($name !== '') {
            return $name;
        }

        return $audience === InAppNotification::AUDIENCE_ADVERTISER
            ? __('messages.nc_sender_publisher')
            : ($audience === InAppNotification::AUDIENCE_PUBLISHER
                ? __('messages.nc_sender_advertiser')
                : __('messages.nc_sender_support'));
    }

    protected function safePersonName(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $name = trim($value);
        if ($name === '' || str_contains($name, '@')) {
            return '';
        }
        $first = preg_split('/\s+/', $name)[0] ?? '';

        return is_string($first) ? $first : '';
    }

    protected function isMessageType(string $type, string $category): bool
    {
        return $category === InAppNotificationService::CATEGORY_MESSAGES
            || in_array($type, [
                InAppNotificationService::TYPE_MESSAGE,
                InAppNotificationService::TYPE_CHAT_REPLY,
            ], true);
    }

    protected function orderIsRejected(?Order $order, string $type): bool
    {
        if ($type === InAppNotificationService::TYPE_ORDER_REJECTED) {
            return true;
        }
        if (! $order) {
            return false;
        }

        return in_array((string) $order->status, ['cancelled'], true);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveSiteName(array $meta, ?Site $site, ?OrderItem $item): string
    {
        foreach ([
            $meta['site_name'] ?? null,
            $item?->site_name,
            $site?->site_name,
            $meta['site_domain'] ?? null,
            $site?->domain,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    protected function orderRef(mixed $orderNumber): ?string
    {
        $number = trim((string) $orderNumber);
        if ($number === '') {
            return null;
        }
        $number = ltrim($number, '#');

        return '#'.$number;
    }

    protected function siteInitial(string $siteName, ?string $domain): string
    {
        $source = $siteName !== '' ? $siteName : (string) $domain;
        $source = preg_replace('#^https?://#', '', $source) ?? $source;
        $source = ltrim($source, '#@.');
        $char = mb_substr($source, 0, 1);
        if (! is_string($char) || $char === '') {
            return 'S';
        }

        return mb_strtoupper($char);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function resolveDomain(array $meta, ?Site $site, ?OrderItem $item): ?string
    {
        foreach ([$meta['site_domain'] ?? null, $meta['domain'] ?? null, $site?->domain] as $candidate) {
            $host = $this->normalizeHost($candidate);
            if ($host) {
                return $host;
            }
        }

        if ($site?->url) {
            $host = $this->normalizeHost(parse_url((string) $site->url, PHP_URL_HOST));
            if ($host) {
                return $host;
            }
        }

        $name = $meta['site_name'] ?? $item?->site_name ?? $site?->site_name;
        $host = $this->normalizeHost($name);
        if ($host && str_contains($host, '.')) {
            return $host;
        }

        return null;
    }

    protected function normalizeHost(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = strtolower(trim($value));
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = explode('/', $value)[0] ?? $value;
        $value = explode('?', $value)[0] ?? $value;
        $value = rtrim($value, '.');

        return $value !== '' ? $value : null;
    }

    /**
     * Notification tiles use the site favicon, never the homepage screenshot.
     *
     * @return list<string>
     */
    protected function faviconChain(?string $domain, ?Site $site): array
    {
        $urls = [];
        $stored = $this->storedFaviconUrl($site);
        if (is_string($stored) && $stored !== '') {
            $urls[] = $stored;
        }
        $google = $this->googleFaviconUrl($domain);
        if (is_string($google) && $google !== '') {
            $urls[] = $google;
        }

        return array_values(array_unique($urls));
    }

    protected function storedFaviconUrl(?Site $site): ?string
    {
        if (! $site) {
            return null;
        }
        $path = $site->getAttribute('favicon_path');
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        try {
            $url = Site::publicDiskUrl($path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    protected function googleFaviconUrl(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        return 'https://www.google.com/s2/favicons?sz=128&domain='.rawurlencode($domain);
    }

    protected function faviconUrl(?string $domain, ?Site $site): ?string
    {
        return $this->faviconChain($domain, $site)[0] ?? null;
    }

    protected function thumbUrl(?string $domain, ?Site $site): ?string
    {
        return $this->faviconUrl($domain, $site);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function orderId(InAppNotification $notification, ?Order $order, array $meta): ?int
    {
        if ($order) {
            return (int) $order->id;
        }

        return InAppNotificationService::canonicalOrderId($notification, $meta);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function groupKey(array $item): ?string
    {
        if (empty($item['is_lifecycle'])) {
            return null;
        }
        if (! empty($item['order_id'])) {
            return 'order:'.(int) $item['order_id'];
        }
        $number = trim((string) ($item['order_number'] ?? ''));
        if ($number !== '') {
            return 'ordernum:'.strtolower(ltrim($number, '#'));
        }
        if (! empty($item['related_id'])
            && is_string($item['related_type'] ?? null)
            && str_ends_with((string) $item['related_type'], 'Order')) {
            return 'order:'.(int) $item['related_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function autoApproveLabel(
        string $type,
        array $meta,
        ?OrderItem $item,
        mixed $createdAt,
        ?Order $order = null
    ): ?string {
        if ($order) {
            if ((string) $order->status !== 'review') {
                return null;
            }
            try {
                if (AdvertiserOrderStatus::isManagedBySlb($order, $item)) {
                    return null;
                }
            } catch (\Throwable) {
                // continue with the window label
            }
        }

        // Only surface when the platform has a real auto-approve window.
        $hoursConfigured = null;
        try {
            $hoursConfigured = OrderItem::autoApproveHours();
        } catch (\Throwable) {
            $hoursConfigured = (int) ($meta['auto_approve_hours'] ?? 0);
        }

        if (! $hoursConfigured || $hoursConfigured < 1) {
            return null;
        }

        $relevant = in_array($type, [
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            InAppNotificationService::TYPE_ORDER_UPDATED,
        ], true);
        if (! $relevant && empty($meta['auto_approve_hours']) && empty($meta['hours_remaining'])) {
            return null;
        }

        if ($item && method_exists($item, 'isAutoApproved') && $item->isAutoApproved()) {
            return null;
        }
        if ($item && method_exists($item, 'isPayoutComplete') && $item->isPayoutComplete()) {
            return null;
        }

        $remainingHours = null;
        if (isset($meta['hours_remaining']) && is_numeric($meta['hours_remaining'])) {
            $remainingHours = (int) ceil((float) $meta['hours_remaining']);
        } elseif ($item && method_exists($item, 'getAutoApproveHoursRemaining')) {
            try {
                $remainingHours = (int) $item->getAutoApproveHoursRemaining();
            } catch (\Throwable) {
                $remainingHours = null;
            }
        } elseif (! empty($meta['auto_approve_hours']) || $type === InAppNotificationService::TYPE_GUEST_POST_PUBLISHED) {
            $window = (int) ($meta['auto_approve_hours'] ?? $hoursConfigured);
            $start = null;
            try {
                if ($item?->live_url_submitted_at) {
                    $start = Carbon::parse($item->live_url_submitted_at);
                } elseif ($createdAt) {
                    $start = Carbon::parse($createdAt);
                }
            } catch (\Throwable) {
                $start = null;
            }
            if ($start) {
                $due = $start->copy()->addHours($window);
                if ($due->isPast()) {
                    $remainingHours = 0;
                } else {
                    $mins = max(0, (int) now()->diffInMinutes($due, false));
                    $remainingHours = (int) max(1, (int) ceil($mins / 60));
                }
            }
        }

        if ($remainingHours === null) {
            return null;
        }
        if ($remainingHours <= 0) {
            return 'Auto-approve due';
        }
        if ($remainingHours < 24) {
            return $remainingHours === 1
                ? 'Auto-approves in 1 hour'
                : "Auto-approves in {$remainingHours} hours";
        }
        $days = (int) max(1, (int) ceil($remainingHours / 24));

        return $days === 1
            ? 'Auto-approves in 1 day'
            : "Auto-approves in {$days} days";
    }

    protected function displayTime(mixed $createdAt): string
    {
        try {
            $dt = Carbon::parse($createdAt);
        } catch (\Throwable) {
            return '';
        }

        if ($dt->isToday()) {
            return $dt->diffForHumans(null, true).' ago';
        }

        return $dt->format('g:i A');
    }

    protected function clockTime(mixed $createdAt): string
    {
        try {
            return Carbon::parse($createdAt)->format('g:i A');
        } catch (\Throwable) {
            return '';
        }
    }

    protected function dayGroup(mixed $createdAt): string
    {
        try {
            $dt = Carbon::parse($createdAt);
        } catch (\Throwable) {
            return 'Earlier';
        }

        if ($dt->isToday()) {
            return 'Today';
        }
        if ($dt->isYesterday()) {
            return 'Yesterday';
        }

        return 'Earlier';
    }
}
