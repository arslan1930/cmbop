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

        $ordersById = $this->loadOrders($models);

        return $models
            ->map(fn (InAppNotification $n) => $this->presentOne($n, $ordersById))
            ->all();
    }

    /**
     * @param  Collection<int, Order>  $ordersById
     * @return array<string, mixed>
     */
    public function presentOne(InAppNotification $notification, ?Collection $ordersById = null): array
    {
        $base = $notification->toApiArray();
        $meta = is_array($base['meta'] ?? null) ? $base['meta'] : [];
        $order = $this->resolveOrder($notification, $ordersById);
        $item = $order?->items?->first();
        $site = $item?->site;

        $liveUrl = $this->resolveLiveUrl($meta, $item);
        $domain = $this->resolveDomain($meta, $site, $item);
        $actionNeeded = $this->isActionNeeded($base['type'] ?? '', $base['action_label'] ?? null, $base['priority'] ?? null);
        $autoApproveLabel = $this->autoApproveLabel($base['type'] ?? '', $meta, $item, $notification->created_at);
        $timelineStep = $this->timelineStepForType($base['type'] ?? '');

        $base['action_needed'] = $actionNeeded;
        $base['auto_approve_label'] = $autoApproveLabel;
        $base['live_url'] = $liveUrl;
        $base['site_domain'] = $domain;
        $base['favicon_url'] = $this->faviconUrl($domain, $site);
        $base['order_id'] = $this->orderId($notification, $order, $meta);
        $base['order_number'] = $meta['order_number'] ?? $order?->order_number;
        $base['order_pack_id'] = $meta['order_pack_id'] ?? $order?->order_pack_id;
        $base['group_key'] = $this->groupKey($base);
        $base['timeline_step'] = $timelineStep;
        $base['display_time'] = $this->displayTime($notification->created_at);
        $base['clock_time'] = $this->clockTime($notification->created_at);
        $base['day_group'] = $this->dayGroup($notification->created_at);

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

        return $cards;
    }

    public function isActionNeeded(string $type, ?string $actionLabel = null, ?string $priority = null): bool
    {
        if (in_array($type, self::ACTION_NEEDED_TYPES, true)) {
            return true;
        }

        if ($type === InAppNotificationService::TYPE_ORDER_UPDATED) {
            $label = strtolower((string) $actionLabel);
            foreach (['review', 'accept', 'submit', 'open task', 'open tasks', 'send revised'] as $needle) {
                if ($needle !== '' && str_contains($label, $needle)) {
                    return true;
                }
            }
            if (in_array($priority, [InAppNotification::PRIORITY_HIGH, InAppNotification::PRIORITY_URGENT], true)) {
                return true;
            }
        }

        return false;
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
        if ($grouped && count($events) >= 1 && ($latest['order_id'] ?? null)) {
            $timeline = $this->buildTimeline($events);
        }

        return array_merge($latest, [
            'is_group' => $grouped && count($events) > 1,
            'event_count' => count($events),
            'events' => $events,
            'event_ids' => $ids,
            'is_unread' => $anyUnread,
            'action_needed' => $anyAction || ! empty($latest['action_needed']),
            'timeline' => $timeline,
            // Prefer live URL / auto-approve from the most relevant event in the stack.
            'live_url' => collect($events)->first(fn ($e) => ! empty($e['live_url']))['live_url']
                ?? ($latest['live_url'] ?? null),
            'auto_approve_label' => collect($events)->first(fn ($e) => ! empty($e['auto_approve_label']))['auto_approve_label']
                ?? ($latest['auto_approve_label'] ?? null),
            'favicon_url' => collect($events)->first(fn ($e) => ! empty($e['favicon_url']))['favicon_url']
                ?? ($latest['favicon_url'] ?? null),
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
     * @return Collection<int, Order>
     */
    protected function loadOrders(Collection $models): Collection
    {
        $orderIds = $models
            ->filter(function (InAppNotification $n) {
                $type = (string) $n->related_type;
                if ($type === Order::class || $type === 'App\\Models\\Order' || str_ends_with($type, '\\Order')) {
                    return (int) $n->related_id > 0;
                }

                return false;
            })
            ->map(fn (InAppNotification $n) => (int) $n->related_id)
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return collect();
        }

        try {
            return Order::query()
                ->with([
                    'items' => fn ($q) => $q->select([
                        'id', 'order_id', 'site_id', 'site_name', 'live_url',
                        'live_url_submitted_at', 'auto_approve_triggered',
                    ]),
                    'items.site' => fn ($q) => $q->select(['id', 'domain', 'site_name', 'favicon_path', 'url']),
                ])
                ->whereIn('id', $orderIds)
                ->get(['id', 'order_number', 'order_pack_id', 'user_id'])
                ->keyBy('id');
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * @param  Collection<int, Order>|null  $ordersById
     */
    protected function resolveOrder(InAppNotification $notification, ?Collection $ordersById): ?Order
    {
        $type = (string) $notification->related_type;
        $id = (int) $notification->related_id;
        if ($id <= 0) {
            return null;
        }
        if (! ($type === Order::class || $type === 'App\\Models\\Order' || str_ends_with($type, '\\Order'))) {
            return null;
        }

        return $ordersById?->get($id);
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

    protected function faviconUrl(?string $domain, ?Site $site): ?string
    {
        try {
            if ($site && method_exists($site, 'getLogoUrlAttribute')) {
                $logo = $site->logo_url;
                if (is_string($logo) && $logo !== '') {
                    return $logo;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        if (! $domain) {
            return null;
        }

        // Public favicon proxy — no auth, fine for third-party domains.
        return 'https://www.google.com/s2/favicons?domain='.rawurlencode($domain).'&sz=32';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function orderId(InAppNotification $notification, ?Order $order, array $meta): ?int
    {
        if ($order) {
            return (int) $order->id;
        }
        if (! empty($meta['order_id'])) {
            return (int) $meta['order_id'];
        }
        $type = (string) $notification->related_type;
        if ((int) $notification->related_id > 0
            && ($type === Order::class || $type === 'App\\Models\\Order' || str_ends_with($type, '\\Order'))) {
            return (int) $notification->related_id;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function groupKey(array $item): ?string
    {
        if (! empty($item['order_pack_id']) && ($item['category'] ?? '') === InAppNotificationService::CATEGORY_ORDERS) {
            return 'pack:'.(int) $item['order_pack_id'];
        }
        if (! empty($item['order_id']) && ($item['category'] ?? '') === InAppNotificationService::CATEGORY_ORDERS) {
            return 'order:'.(int) $item['order_id'];
        }
        // Also group order-category rows that only have related_id
        if (($item['category'] ?? '') === InAppNotificationService::CATEGORY_ORDERS
            && ! empty($item['related_id'])
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
        mixed $createdAt
    ): ?string {
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
