<?php

namespace App\Services\Advertiser;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Services\Marketplace\ArticleWritingService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Cart / order placement state for Content Library badges, the one-site lock,
 * and catalog/cart “already have an article” signs.
 *
 * Never includes partner rates or markup.
 */
class LibraryArticlePlacement
{
    public const STATE_IN_CART = 'in_cart';

    public const STATE_ORDERED = 'ordered';

    public const STATE_PUBLISHED = 'published';

    public const LOCK_MESSAGE = 'This article is already reserved for one site. Remove it from the cart, or wait until that order is cancelled, refunded, or rejected, before using it on another site.';

    /**
     * @param  array<int, mixed>  $cart
     * @return array<int, array<string, mixed>>
     */
    public function cartArticleMap(array $cart): array
    {
        $map = [];
        $siteIds = [];
        foreach ($this->cartLines($cart) as $line) {
            $siteId = (int) ($line['id'] ?? 0);
            if ($siteId > 0) {
                $siteIds[$siteId] = $siteId;
            }
        }
        $sites = $this->sitesById($siteIds);

        foreach ($this->cartLines($cart) as $line) {
            $siteId = (int) ($line['id'] ?? 0);
            $siteName = $this->lineSiteName($line, $sites->get($siteId));
            foreach ($this->lineContentIds($line) as $copyIndex => $submissionId) {
                if ($submissionId <= 0) {
                    continue;
                }
                $assignedAt = $this->lineAssignedAt($line, $copyIndex);
                $map[$submissionId] = [
                    'state' => self::STATE_IN_CART,
                    'submission_id' => $submissionId,
                    'site_id' => $siteId,
                    'site_name' => $siteName,
                    'copy_index' => $copyIndex,
                    'assigned_at' => $assignedAt,
                    'stale' => $this->isStale($assignedAt),
                ];
            }
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    public function cartArticleIds(array $cart): array
    {
        return array_values(array_unique(array_map('intval', array_keys($this->cartArticleMap($cart)))));
    }

    /**
     * Sites the advertiser already has an article in-cart, ordered, or live for.
     *
     * @param  array<int, mixed>  $cart
     * @return array<int, array<string, mixed>>
     */
    public function occupiedSites(int $userId, array $cart = []): array
    {
        if ($userId <= 0) {
            return [];
        }

        $occupied = [];
        foreach ($this->cartArticleMap($cart) as $placement) {
            $siteId = (int) ($placement['site_id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }
            $occupied[$siteId] = $this->decorateOccupancy($placement, $userId);
        }

        foreach ($this->orderPlacementsForUser($userId) as $placement) {
            $siteId = (int) ($placement['site_id'] ?? 0);
            if ($siteId <= 0) {
                continue;
            }
            $existing = $occupied[$siteId] ?? null;
            if ($existing && $this->stateRank((string) ($existing['state'] ?? '')) >= $this->stateRank((string) $placement['state'])) {
                continue;
            }
            $occupied[$siteId] = $this->decorateOccupancy($placement, $userId);
        }

        return $occupied;
    }

    /**
     * Badge / lock payload for one library row. Published and paid orders
     * win over an unpaid cart line.
     *
     * @param  array<int, mixed>  $cart
     * @return array<string, mixed>|null
     */
    public function placementFor(ContentSubmission $submission, array $cart = []): ?array
    {
        $fromOrder = $this->orderPlacementForSubmission($submission);
        if ($fromOrder) {
            return $this->decoratePlacement($fromOrder, (int) $submission->user_id);
        }

        $fromCart = $this->cartArticleMap($cart)[(int) $submission->id] ?? null;
        if (is_array($fromCart)) {
            return $this->decoratePlacement($fromCart, (int) $submission->user_id);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $cart
     */
    public function isLockedToAnotherSite(ContentSubmission $submission, array $cart, int $targetSiteId, ?int $exceptCopyIndex = null): bool
    {
        $lockedSiteId = $this->lockedSiteId($submission, $cart, $exceptCopyIndex);

        return $lockedSiteId !== null && $lockedSiteId !== $targetSiteId;
    }

    /**
     * Site this article is reserved for, or null when free.
     *
     * @param  array<int, mixed>  $cart
     */
    public function lockedSiteId(ContentSubmission $submission, array $cart, ?int $exceptCopyIndex = null): ?int
    {
        $fromOrder = $this->orderPlacementForSubmission($submission);
        if ($fromOrder
            && in_array($fromOrder['state'], [self::STATE_ORDERED, self::STATE_PUBLISHED], true)
            && empty($fromOrder['replaceable'])) {
            $siteId = (int) ($fromOrder['site_id'] ?? 0);

            return $siteId > 0 ? $siteId : null;
        }

        foreach ($this->cartArticleMap($cart) as $submissionId => $placement) {
            if ((int) $submissionId !== (int) $submission->id) {
                continue;
            }
            if ($exceptCopyIndex !== null && (int) ($placement['copy_index'] ?? -1) === $exceptCopyIndex) {
                continue;
            }
            $siteId = (int) ($placement['site_id'] ?? 0);

            return $siteId > 0 ? $siteId : null;
        }

        return null;
    }

    public function lockMessage(?string $siteName = null): string
    {
        $siteName = trim((string) $siteName);
        if ($siteName === '') {
            return self::LOCK_MESSAGE;
        }

        return 'This article is already reserved for '.$siteName.'. Remove it from the cart, or wait until that order is cancelled, refunded, or rejected, before using it on another site.';
    }

    public function staleCartDays(): int
    {
        return max(1, (int) config('content_library.stale_cart_days', 3));
    }

    public function staleHint(): string
    {
        return 'Still in your cart — finish payment?';
    }

    /**
     * Advertiser-facing stage for an ordered (paid / processing) placement.
     */
    public function advertiserStage(?Order $order, ?OrderItem $item = null): string
    {
        if (! $order instanceof Order) {
            return 'Processing';
        }

        if ($item instanceof OrderItem) {
            $writing = $this->writingStage($item);
            if ($writing !== null) {
                return $writing;
            }
        }

        $meta = AdvertiserOrderStatus::meta($order, $item, true);

        return match ((string) ($meta['stage'] ?? '')) {
            'paid' => 'Waiting for publisher',
            'review' => 'In review',
            'url_delivered' => 'Awaiting your approval',
            'processing' => 'In review',
            'revision', 'content_revision' => 'In review',
            'scheduled' => 'Scheduled',
            'awaiting_payment' => 'Not paid',
            'payment_failed' => 'Payment failed',
            default => $this->shortStageLabel((string) ($meta['label'] ?? 'Processing')),
        };
    }

    /**
     * @return list<int>
     */
    public function lineContentIds(array $line): array
    {
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $raw = [];
        if (! empty($line['content_submission_ids']) && is_array($line['content_submission_ids'])) {
            foreach ($line['content_submission_ids'] as $i => $id) {
                $raw[(int) $i] = (int) $id;
            }
        }
        if ((! isset($raw[0]) || $raw[0] <= 0) && ! empty($line['content_submission_id'])) {
            $raw[0] = (int) $line['content_submission_id'];
        }

        $ids = [];
        for ($i = 0; $i < $qty; $i++) {
            $ids[$i] = max(0, (int) ($raw[$i] ?? 0));
        }

        return $ids;
    }

    /**
     * @param  array<int, mixed>  $cart
     * @return list<array<string, mixed>>
     */
    public function cartLines(array $cart): array
    {
        $lines = [];
        foreach ($cart as $line) {
            if (is_array($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return array<string, mixed>
     */
    protected function decoratePlacement(array $placement, int $userId): array
    {
        $state = (string) ($placement['state'] ?? self::STATE_IN_CART);
        $siteName = trim((string) ($placement['site_name'] ?? '')) ?: 'this site';
        $submissionId = (int) ($placement['submission_id'] ?? 0);
        $orderId = (int) ($placement['order_id'] ?? 0);
        $stale = (bool) ($placement['stale'] ?? false);
        $liveUrl = trim((string) ($placement['live_url'] ?? ''));
        $safeLive = $liveUrl !== '' ? safe_external_url($liveUrl, '') : '';
        $stage = (string) ($placement['stage'] ?? '');

        if ($state === self::STATE_PUBLISHED) {
            $label = 'Live on '.$siteName;
        } elseif ($state === self::STATE_ORDERED) {
            $stage = $stage !== '' ? $stage : 'Processing';
            $label = 'Ordered · '.$siteName.' · '.$stage;
        } else {
            $stage = $stage !== '' ? $stage : 'Not paid';
            $label = 'In cart · '.$siteName.' · '.$stage;
            $stale = $stale || $this->isStale($placement['assigned_at'] ?? null);
        }

        $libraryAvailability = match ($state) {
            self::STATE_PUBLISHED => 'completed',
            self::STATE_ORDERED => 'ordered',
            default => 'in_cart',
        };

        return array_merge($placement, [
            'state' => $state,
            'label' => $label,
            'site_name' => $siteName,
            'stage' => $stage,
            'stale' => $stale,
            'stale_hint' => $stale ? $this->staleHint() : null,
            'pay_url' => $state === self::STATE_IN_CART ? route('advertiser.checkout', absolute: false) : null,
            'checkout_url' => $state === self::STATE_IN_CART ? route('advertiser.checkout', absolute: false) : null,
            'order_url' => $orderId > 0 ? route('advertiser.orders', absolute: false).'#order-'.$orderId : null,
            'live_url' => $safeLive !== '' ? $safeLive : null,
            'library_url' => $submissionId > 0
                ? route('advertiser.content-library', [
                    'availability' => $libraryAvailability,
                    'status' => 'all',
                ], false).'#library-row-'.$submissionId
                : route('advertiser.content-library', absolute: false),
            'lock_message' => $this->lockMessage($siteName),
        ]);
    }

    /**
     * @param  array<string, mixed>  $placement
     * @return array<string, mixed>
     */
    protected function decorateOccupancy(array $placement, int $userId): array
    {
        $decorated = $this->decoratePlacement($placement, $userId);

        return [
            'state' => $decorated['state'],
            'submission_id' => (int) ($decorated['submission_id'] ?? 0),
            'site_id' => (int) ($decorated['site_id'] ?? 0),
            'site_name' => $decorated['site_name'],
            'label' => 'You already have an article for this site',
            'url' => $decorated['library_url'],
            'order_url' => $decorated['order_url'],
            'live_url' => $decorated['live_url'],
            'lock_message' => $decorated['lock_message'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function orderPlacementForSubmission(ContentSubmission $submission): ?array
    {
        if ($submission->isPublished()) {
            $item = $submission->libraryPlacementItem();
            $order = $submission->libraryOrder();
            if ($item instanceof OrderItem && $this->itemIsReleased($item, $order)) {
                return null;
            }

            return $this->placementFromOrderItem($submission, $item, $order, self::STATE_PUBLISHED);
        }

        $order = $submission->libraryOrder();
        $item = $submission->libraryPlacementItem();
        if (! $order instanceof Order || $this->orderIsReleased($order)) {
            return null;
        }
        if ($item instanceof OrderItem && $this->itemIsReleased($item, $order)) {
            return null;
        }

        $placement = $this->placementFromOrderItem($submission, $item, $order, self::STATE_ORDERED);
        if ($placement && $this->isReplaceableLeftover($submission)) {
            $placement['replaceable'] = true;
        }

        return $placement;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function orderPlacementsForUser(int $userId): array
    {
        if ($userId <= 0 || ! Schema::hasTable('order_items') || ! Schema::hasColumn('order_items', 'content_submission_id')) {
            return [];
        }

        try {
            $items = OrderItem::query()
                ->whereNotNull('content_submission_id')
                ->where('content_submission_id', '>', 0)
                ->whereHas('order', function ($order) use ($userId) {
                    $order->where('user_id', $userId)
                        ->where('status', '!=', 'cancelled')
                        ->where(function ($payment) {
                            $payment->whereNull('payment_status')
                                ->orWhere('payment_status', '!=', 'refunded');
                        });
                })
                ->with(['order', 'site'])
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $placements = [];
        foreach ($items as $item) {
            if (! $item instanceof OrderItem || $this->itemIsReleased($item, $item->order)) {
                continue;
            }
            $order = $item->order;
            if (! $order instanceof Order || $this->orderIsReleased($order)) {
                continue;
            }
            $state = ($item->hasLiveUrl() || (string) $order->status === 'completed')
                ? self::STATE_PUBLISHED
                : self::STATE_ORDERED;
            $placement = $this->placementFromOrderItem(null, $item, $order, $state);
            if ($placement) {
                if ($state === self::STATE_ORDERED
                    && $order->payment_status !== 'paid'
                    && (string) $order->status === 'pending') {
                    $placement['replaceable'] = true;
                }
                $placements[] = $placement;
            }
        }

        return $placements;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function placementFromOrderItem(?ContentSubmission $submission, ?OrderItem $item, ?Order $order, string $state): ?array
    {
        $siteId = (int) ($item?->site_id ?? 0);
        $siteName = trim((string) ($item?->site_name ?: $item?->site?->site_name ?: ''));
        if ($siteName === '') {
            $siteName = 'this site';
        }
        $liveUrl = $item?->hasLiveUrl() ? trim((string) $item->live_url) : '';

        return [
            'state' => $state,
            'submission_id' => (int) ($submission?->id ?? $item?->content_submission_id ?? 0),
            'site_id' => $siteId,
            'site_name' => $siteName,
            'order_id' => (int) ($order?->id ?? $item?->order_id ?? 0),
            'stage' => $state === self::STATE_PUBLISHED ? 'Live' : $this->advertiserStage($order, $item),
            'live_url' => $liveUrl,
            'stale' => false,
        ];
    }

    protected function orderIsReleased(?Order $order): bool
    {
        if (! $order instanceof Order) {
            return true;
        }

        if ((string) $order->status === 'cancelled') {
            return true;
        }

        return in_array((string) $order->payment_status, ['refunded'], true)
            && (string) $order->status !== 'completed';
    }

    protected function isReplaceableLeftover(ContentSubmission $submission): bool
    {
        try {
            return $submission->canReplaceUnpaidLeftover() && ! $submission->isLockedByPaidOrder();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function itemIsReleased(OrderItem $item, ?Order $order): bool
    {
        if (method_exists($item, 'isClawedBack') && $item->isClawedBack()) {
            return true;
        }

        if (! empty($item->rejected_at)) {
            return true;
        }

        return $this->orderIsReleased($order);
    }

    protected function writingStage(OrderItem $item): ?string
    {
        try {
            $writing = app(ArticleWritingService::class);
            if (! $writing->isWritingLine($item)) {
                return null;
            }
            if ($writing->awaitingAdvertiserArticleApproval($item)) {
                return 'Awaiting your approval';
            }
            $status = (string) ($item->writing_status ?? '');
            if (in_array($status, [
                ArticleWritingService::STATUS_AWAITING_WRITER,
                ArticleWritingService::STATUS_ASSIGNED,
                ArticleWritingService::STATUS_ARTICLE_REVISION,
                ArticleWritingService::STATUS_ARTICLE_SUBMITTED,
            ], true)) {
                return 'Writing';
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    protected function shortStageLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return 'Processing';
        }
        $label = preg_replace('/^Paid\s*·\s*/u', '', $label) ?: $label;

        return $label;
    }

    protected function isStale(mixed $assignedAt): bool
    {
        if ($assignedAt === null || $assignedAt === '') {
            return false;
        }

        try {
            $at = $assignedAt instanceof Carbon ? $assignedAt : Carbon::parse((string) $assignedAt);
        } catch (\Throwable) {
            return false;
        }

        return $at->lte(now()->subDays($this->staleCartDays()));
    }

    protected function lineAssignedAt(array $line, int $copyIndex): ?string
    {
        $raw = $line['content_assigned_at'] ?? null;
        if (is_array($raw)) {
            $value = $raw[$copyIndex] ?? $raw[(string) $copyIndex] ?? null;
        } else {
            $value = $raw;
        }
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return Collection<int, Site>
     */
    protected function sitesById(array $siteIds): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $siteIds)));
        if ($ids === []) {
            return collect();
        }

        try {
            return Site::query()->whereIn('id', $ids)->get()->keyBy('id');
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    protected function lineSiteName(array $line, ?Site $site): string
    {
        $name = trim((string) ($site?->site_name ?? $line['name'] ?? ''));

        return $name !== '' ? $name : 'this site';
    }

    protected function stateRank(string $state): int
    {
        return match ($state) {
            self::STATE_PUBLISHED => 3,
            self::STATE_ORDERED => 2,
            self::STATE_IN_CART => 1,
            default => 0,
        };
    }
}
