<?php

namespace App\Services\Orders;

use App\Mail\PackProgressMail;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPack;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\AdvertiserApiSerializer;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoiceNumberGenerator;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Support\BulkDiscount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Article packs: N placements on one site from one checkout, linked by order_packs.
 */
class OrderPackService
{
    public function __construct(
        private readonly OrderRefundService $refunds,
        private readonly AdvertiserApiSerializer $serializer,
        private readonly InAppNotificationService $notifications,
        private readonly InvoiceNumberGenerator $invoiceNumbers,
        private readonly InvoicePdfGenerator $pdfs,
    ) {}

    public function available(): bool
    {
        return OrderPack::tableAvailable() && OrderPack::ordersColumnAvailable();
    }

    /**
     * After paid orders are created for a checkout, group bulk-pack lines into OrderPack rows.
     * Idempotent: re-running for the same reference + site returns the existing pack.
     *
     * @param  Collection<int, Order>|iterable<Order>  $orders
     * @return Collection<int, OrderPack>
     */
    public function linkCreatedOrders(iterable $orders, ?array $packageMeta = null): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        $orderModels = collect($orders)->filter(fn ($o) => $o instanceof Order)->values();
        if ($orderModels->isEmpty()) {
            return collect();
        }

        $orderModels->each(fn (Order $o) => $o->loadMissing('items'));

        $bySite = [];
        foreach ($orderModels as $order) {
            $item = $order->items->first();
            $siteId = $item ? (int) $item->site_id : 0;
            if ($siteId <= 0) {
                continue;
            }
            $bySite[$siteId][] = $order;
        }

        $packs = collect();
        foreach ($bySite as $siteId => $siteOrders) {
            $siteOrders = collect($siteOrders)->unique('id')->values();
            if ($siteOrders->count() < BulkDiscount::minQty()) {
                continue;
            }

            // Prefer explicit pack flag from pending package lines when present.
            $explicitPack = $this->packageMarksSiteAsPack($packageMeta, (int) $siteId);
            if (! $explicitPack && $siteOrders->count() < BulkDiscount::minQty()) {
                continue;
            }

            $pack = $this->findOrCreatePackForSiteOrders($siteOrders, (int) $siteId, $packageMeta);
            if ($pack) {
                $packs->push($pack);
            }
        }

        return $packs->unique('id')->values();
    }

    /**
     * @param  Collection<int, Order>  $siteOrders
     */
    private function findOrCreatePackForSiteOrders(Collection $siteOrders, int $siteId, ?array $packageMeta): ?OrderPack
    {
        $first = $siteOrders->first();
        if (! $first instanceof Order) {
            return null;
        }

        $reference = (string) ($first->reference_code ?? '');
        $userId = (int) $first->user_id;

        return DB::transaction(function () use ($siteOrders, $siteId, $packageMeta, $reference, $userId, $first) {
            $existing = null;
            if ($reference !== '') {
                $existing = OrderPack::query()
                    ->where('user_id', $userId)
                    ->where('site_id', $siteId)
                    ->where('reference_code', $reference)
                    ->lockForUpdate()
                    ->first();
            }

            $linkedIds = $siteOrders->pluck('id')->filter()->all();
            if ($existing) {
                Order::query()
                    ->whereIn('id', $linkedIds)
                    ->whereNull('order_pack_id')
                    ->update(['order_pack_id' => $existing->id]);
                $this->refreshStatus($existing);

                return $existing->fresh();
            }

            $size = $siteOrders->count();
            $discountPercent = $this->discountPercentFromOrdersOrMeta($siteOrders, $packageMeta, $siteId);
            $tier = $this->tierForSize($size);

            $pack = OrderPack::create([
                'user_id' => $userId,
                'site_id' => $siteId,
                'reference_code' => $reference !== '' ? $reference : null,
                'pack_size' => $size,
                'discount_tier' => $tier,
                'discount_percent' => $discountPercent,
                'status' => OrderPack::STATUS_IN_PROGRESS,
                'payment_method' => $first->payment_method,
                'total_amount' => round((float) $siteOrders->sum(fn (Order $o) => (float) $o->total_amount), 2),
                'published_count' => 0,
                'refunded_count' => 0,
                'completed_count' => 0,
            ]);

            Order::query()
                ->whereIn('id', $linkedIds)
                ->update(['order_pack_id' => $pack->id]);

            $this->refreshStatus($pack);

            return $pack->fresh();
        });
    }

    private function packageMarksSiteAsPack(?array $packageMeta, int $siteId): bool
    {
        if (! is_array($packageMeta)) {
            return false;
        }
        $lines = is_array($packageMeta['lines'] ?? null) ? $packageMeta['lines'] : [];
        $siteLines = collect($lines)->filter(
            fn ($line) => is_array($line) && (int) ($line['site_id'] ?? 0) === $siteId
        );
        if ($siteLines->isEmpty()) {
            return false;
        }
        if ($siteLines->contains(fn ($line) => ! empty($line['bulk_pack']))) {
            return true;
        }

        return $siteLines->count() >= BulkDiscount::minQty();
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function discountPercentFromOrdersOrMeta(Collection $orders, ?array $packageMeta, int $siteId): ?float
    {
        $lines = is_array($packageMeta['lines'] ?? null) ? $packageMeta['lines'] : [];
        foreach ($lines as $line) {
            if (! is_array($line) || (int) ($line['site_id'] ?? 0) !== $siteId) {
                continue;
            }
            if (isset($line['discount_percent']) && is_numeric($line['discount_percent'])) {
                return round((float) $line['discount_percent'], 2);
            }
        }

        $first = $orders->first();
        $item = $first?->items?->first();
        if ($item && isset($item->discount_percent) && is_numeric($item->discount_percent)) {
            return round((float) $item->discount_percent, 2);
        }

        return null;
    }

    public function tierForSize(int $size): int
    {
        if ($size >= BulkDiscount::TIER_10) {
            return BulkDiscount::TIER_10;
        }
        if ($size >= BulkDiscount::TIER_5) {
            return BulkDiscount::TIER_5;
        }

        return BulkDiscount::TIER_3;
    }

    public function refreshStatus(OrderPack $pack): OrderPack
    {
        if (! $this->available()) {
            return $pack;
        }

        $pack->loadMissing(['orders.items']);
        $orders = $pack->orders;
        $size = max((int) $pack->pack_size, $orders->count(), 1);

        $refunded = 0;
        $completed = 0;
        $published = 0;
        $active = 0;

        foreach ($orders as $order) {
            $payment = (string) $order->payment_status;
            $status = (string) $order->status;
            $item = $order->items->first();

            if ($status === 'cancelled' || $payment === 'refunded') {
                $refunded++;

                continue;
            }

            if ($status === 'completed') {
                $completed++;
                $published++;

                continue;
            }

            $active++;
            if ($item && filled($item->live_url)) {
                $published++;
            }
        }

        if ($orders->isEmpty()) {
            $derived = OrderPack::STATUS_CANCELLED;
        } elseif ($active === 0 && $completed === 0 && $refunded > 0) {
            $derived = OrderPack::STATUS_REFUNDED;
        } elseif ($active === 0 && $completed > 0 && $refunded === 0) {
            $derived = OrderPack::STATUS_COMPLETED;
        } elseif ($active === 0 && $completed > 0 && $refunded > 0) {
            $derived = OrderPack::STATUS_PARTIALLY_REFUNDED;
        } elseif ($refunded > 0 && ($active > 0 || $completed > 0)) {
            $derived = OrderPack::STATUS_PARTIALLY_REFUNDED;
        } elseif ($completed > 0 && $active > 0) {
            $derived = OrderPack::STATUS_PARTIALLY_COMPLETED;
        } else {
            $derived = OrderPack::STATUS_IN_PROGRESS;
        }

        $pack->fill([
            'pack_size' => $size,
            'published_count' => $published,
            'refunded_count' => $refunded,
            'completed_count' => $completed,
            'status' => $derived,
            'completed_at' => $derived === OrderPack::STATUS_COMPLETED
                ? ($pack->completed_at ?: now())
                : null,
            'total_amount' => round((float) $orders->sum(fn (Order $o) => (float) $o->total_amount), 2),
        ]);
        $pack->save();

        return $pack->fresh();
    }

    public function findForOrder(Order $order): ?OrderPack
    {
        if (! $this->available()) {
            return null;
        }
        $packId = (int) ($order->order_pack_id ?? 0);
        if ($packId <= 0) {
            return null;
        }

        return OrderPack::query()->find($packId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summaryForOrder(Order $order): ?array
    {
        $pack = $this->findForOrder($order);
        if (! $pack) {
            return null;
        }
        $this->refreshStatus($pack);
        $pack->loadMissing('site');

        $siblings = $pack->orders()->orderBy('id')->pluck('id')->all();
        $index = array_search((int) $order->id, array_map('intval', $siblings), true);
        $position = $index === false ? null : ($index + 1);

        return [
            'id' => $pack->id,
            'label' => $pack->label(),
            'pack_size' => (int) $pack->pack_size,
            'position' => $position,
            'position_label' => $position ? ('Pack '.$position.' of '.(int) $pack->pack_size) : $pack->label(),
            'status' => $pack->status,
            'status_label' => $pack->statusLabel(),
            'progress_label' => $pack->progressLabel(),
            'published_count' => (int) $pack->published_count,
            'completed_count' => (int) $pack->completed_count,
            'refunded_count' => (int) $pack->refunded_count,
            'site_name' => $pack->site?->site_name,
            'site_url' => $pack->site?->site_url,
            'reference_code' => $pack->reference_code,
            'total_amount' => (float) $pack->total_amount,
            'discount_percent' => $pack->discount_percent !== null ? (float) $pack->discount_percent : null,
            'discount_tier' => $pack->discount_tier !== null ? (int) $pack->discount_tier : null,
            'receipt_url' => route('advertiser.packs.receipt', $pack),
            'orders_url' => route('advertiser.orders', ['pack' => $pack->id]),
            'can_cancel_rest' => $this->cancellableRest($pack)->isNotEmpty(),
            'order_ids' => $siblings,
        ];
    }

    /**
     * Group list payload: one card per pack + ungrouped singles.
     *
     * @param  Collection<int, array<string, mixed>>  $orderPayloads
     * @return list<array<string, mixed>>
     */
    public function groupOrderPayloads(Collection $orderPayloads): array
    {
        if (! $this->available()) {
            return $orderPayloads->values()->all();
        }

        $grouped = [];
        $packBuckets = [];

        foreach ($orderPayloads as $payload) {
            $packId = (int) ($payload['order_pack_id'] ?? data_get($payload, 'pack.id') ?? 0);
            if ($packId <= 0) {
                $grouped[] = [
                    'type' => 'order',
                    'order' => $payload,
                ];

                continue;
            }
            $packBuckets[$packId][] = $payload;
        }

        foreach ($packBuckets as $packId => $children) {
            $pack = OrderPack::query()->with('site')->find($packId);
            if (! $pack) {
                foreach ($children as $child) {
                    $grouped[] = ['type' => 'order', 'order' => $child];
                }

                continue;
            }
            $this->refreshStatus($pack);
            $grouped[] = [
                'type' => 'pack',
                'pack' => [
                    'id' => $pack->id,
                    'label' => $pack->label(),
                    'pack_size' => (int) $pack->pack_size,
                    'status' => $pack->status,
                    'status_label' => $pack->statusLabel(),
                    'progress_label' => $pack->progressLabel(),
                    'published_count' => (int) $pack->published_count,
                    'completed_count' => (int) $pack->completed_count,
                    'refunded_count' => (int) $pack->refunded_count,
                    'site_name' => $pack->site?->site_name
                        ?? data_get($children[0], 'items.0.site_name'),
                    'site_url' => $pack->site?->site_url
                        ?? data_get($children[0], 'items.0.site_url'),
                    'reference_code' => $pack->reference_code,
                    'total_amount' => (float) $pack->total_amount,
                    'created_at' => optional($pack->created_at)?->toIso8601String(),
                    'payment_method' => $pack->payment_method,
                    'receipt_url' => route('advertiser.packs.receipt', $pack),
                    'can_cancel_rest' => $this->cancellableRest($pack)->isNotEmpty(),
                    'orders' => $children,
                ],
            ];
        }

        return $grouped;
    }

    /**
     * @return Collection<int, Order>
     */
    public function cancellableRest(OrderPack $pack): Collection
    {
        $pack->loadMissing(['orders.items']);

        return $pack->orders
            ->filter(fn (Order $order) => $this->serializer->cancellable($order))
            ->values();
    }

    /**
     * Cancel remaining not-yet-accepted articles; refund each at the discounted price paid.
     *
     * @return array{cancelled: int, refunded_total: float, orders: list<int>}
     */
    public function cancelRest(OrderPack $pack, ?User $actor = null, string $reason = 'Advertiser cancelled the rest of this pack.'): array
    {
        $cancelled = [];
        $refundedTotal = 0.0;

        DB::transaction(function () use ($pack, $reason, &$cancelled, &$refundedTotal) {
            $pack = OrderPack::query()->whereKey($pack->id)->lockForUpdate()->firstOrFail();
            $rest = $this->cancellableRest($pack);
            foreach ($rest as $order) {
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                if (! $order || ! $this->serializer->cancellable($order)) {
                    continue;
                }
                $amount = (float) $order->total_amount;
                $this->refunds->cancelAndRefund($order, $reason);
                $cancelled[] = (int) $order->id;
                $refundedTotal = round($refundedTotal + $amount, 2);
            }
            $this->refreshStatus($pack->fresh());
        });

        Log::info('Pack cancel-rest completed', [
            'order_pack_id' => $pack->id,
            'actor_id' => $actor?->id,
            'cancelled' => $cancelled,
            'refunded_total' => $refundedTotal,
        ]);

        return [
            'cancelled' => count($cancelled),
            'refunded_total' => $refundedTotal,
            'orders' => $cancelled,
        ];
    }

    /**
     * Accept every remaining pending article in the pack for this publisher.
     *
     * @return array{accepted: int, order_item_ids: list<int>}
     */
    public function acceptAllPending(OrderPack $pack, User $publisher, callable $acceptOne): array
    {
        $pack->loadMissing(['orders.items']);
        $accepted = [];

        foreach ($pack->orders as $order) {
            $item = $order->items->first();
            if (! $item instanceof OrderItem) {
                continue;
            }
            $site = Site::query()->whereKey($item->site_id)->where('publisher_id', $publisher->id)->first();
            if (! $site) {
                continue;
            }
            if ((string) $order->payment_status !== 'paid') {
                continue;
            }
            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                continue;
            }
            if ($order->isAwaitingScheduledRelease()) {
                continue;
            }
            if (! in_array((string) $order->status, ['pending', 'processing', 'review'], true)) {
                continue;
            }
            if (in_array((string) ($item->publisher_status ?? ''), ['accepted', 'completed', 'rejected'], true)) {
                continue;
            }
            if ($item->accepted_at) {
                continue;
            }

            $result = $acceptOne($item);
            if ($result === true || (is_array($result) && ($result['success'] ?? false))) {
                $accepted[] = (int) $item->id;
            }
        }

        $this->refreshStatus($pack->fresh());

        return [
            'accepted' => count($accepted),
            'order_item_ids' => $accepted,
        ];
    }

    /**
     * Pending acceptables for publisher badge / Accept all.
     *
     * @return Collection<int, OrderItem>
     */
    public function pendingAcceptItemsForPublisher(OrderPack $pack, int $publisherId): Collection
    {
        $pack->loadMissing(['orders.items']);

        return $pack->orders
            ->map(fn (Order $o) => $o->items->first())
            ->filter(function ($item) use ($publisherId) {
                if (! $item instanceof OrderItem) {
                    return false;
                }
                $order = $item->order ?? Order::query()->find($item->order_id);
                if (! $order) {
                    return false;
                }
                if ((int) $item->site?->publisher_id !== $publisherId
                    && (int) Site::query()->whereKey($item->site_id)->value('publisher_id') !== $publisherId) {
                    return false;
                }
                if ((string) $order->payment_status !== 'paid') {
                    return false;
                }
                if (in_array((string) $order->status, ['cancelled', 'completed'], true)) {
                    return false;
                }
                if ($order->isAwaitingScheduledRelease()) {
                    return false;
                }
                if ($item->accepted_at || in_array((string) ($item->publisher_status ?? ''), ['accepted', 'completed', 'rejected'], true)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    public function notifyProgress(OrderPack $pack, Order $triggerOrder, string $event = 'published'): void
    {
        if (! $this->available()) {
            return;
        }

        $pack = $this->refreshStatus($pack->fresh() ?? $pack);
        $pack->loadMissing('site');
        $advertiser = User::query()->find($pack->user_id);
        if (! $advertiser) {
            return;
        }

        $published = (int) $pack->published_count;
        $size = max(1, (int) $pack->pack_size);
        $complete = $pack->status === OrderPack::STATUS_COMPLETED
            || ($published >= $size && (int) $pack->completed_count + (int) $pack->refunded_count >= $size);

        $title = $complete
            ? $pack->label().' complete'
            : $pack->label().' — '.$published.' of '.$size.' published';
        $body = $complete
            ? 'All articles in your pack on '.($pack->site?->site_name ?? 'the site').' are done.'
            : 'An article in your pack on '.($pack->site?->site_name ?? 'the site').' was published ('.$published.' of '.$size.').';

        $this->notifications->notify(
            (int) $advertiser->id,
            $complete ? InAppNotificationService::TYPE_ORDER_COMPLETED : InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            $title,
            $body,
            [
                'category' => InAppNotificationService::CATEGORY_ORDERS,
                'icon' => $complete ? 'check-circle' : 'rocket',
                'priority' => InAppNotification::PRIORITY_NORMAL,
                'related' => $triggerOrder,
                'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                'action_label' => 'View pack',
                'action_url' => route('advertiser.orders', ['pack' => $pack->id], false),
                'meta' => [
                    'order_pack_id' => $pack->id,
                    'published_count' => $published,
                    'pack_size' => $size,
                    'event' => $event,
                    'complete' => $complete,
                ],
            ]
        );

        if ($advertiser->email) {
            try {
                Mail::to($advertiser->email)->send(new PackProgressMail($pack, $triggerOrder, $complete));
            } catch (\Throwable $e) {
                Log::warning('Pack progress mail failed: '.$e->getMessage(), [
                    'order_pack_id' => $pack->id,
                ]);
            }
        }
    }

    public function ensurePackReceipt(OrderPack $pack): ?Invoice
    {
        if (! $this->available()) {
            return null;
        }

        $pack->loadMissing(['orders.items', 'user', 'site']);
        $user = $pack->user;
        if (! $user) {
            return null;
        }

        $existingQuery = Invoice::query()
            ->where('type', Invoice::TYPE_PACK_RECEIPT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED);
        if (Schema::hasColumn('invoices', 'order_pack_id')) {
            $existingQuery->where('order_pack_id', $pack->id);
        } else {
            $existingQuery->where('reference_code', $pack->reference_code)
                ->where('meta->order_pack_id', $pack->id);
        }
        $existing = $existingQuery->first();
        if ($existing) {
            return $existing;
        }

        $orders = $pack->orders->sortBy('id')->values();
        $first = $orders->first();
        $lineItems = [];
        $refundLines = [];
        $subtotal = 0.0;

        foreach ($orders as $order) {
            $item = $order->items->first();
            $amount = round((float) $order->total_amount, 2);
            $subtotal = round($subtotal + $amount, 2);
            $desc = 'Guest post / sponsored placement';
            if ($item && filled($item->anchor_text)) {
                $desc .= ' — '.$item->anchor_text;
            }
            $line = [
                'description' => $desc,
                'publisher_website' => $item?->site_name ?: ($pack->site?->site_name),
                'site_url' => $item?->site_url ?: ($pack->site?->site_url),
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
            ];
            $lineItems[] = $line;
            if ((string) $order->payment_status === 'refunded' || (string) $order->status === 'cancelled') {
                $refundLines[] = array_merge($line, [
                    'description' => 'Refund — '.$desc,
                    'unit_price' => -1 * $amount,
                    'line_total' => -1 * $amount,
                ]);
            }
        }

        $allLines = array_merge($lineItems, $refundLines);
        $refundedSum = round((float) collect($refundLines)->sum('line_total'), 2);
        $total = round($subtotal + $refundedSum, 2);

        $payload = [
            'invoice_number' => $this->invoiceNumbers->nextForType(
                Invoice::TYPE_PACK_RECEIPT,
                $this->invoiceNumbers->yearFromDate($pack->created_at)
            ),
            'type' => Invoice::TYPE_PACK_RECEIPT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $user->id,
            'order_id' => $first?->id,
            'reference_code' => $pack->reference_code,
            'order_number' => $pack->label().' · '.$pack->reference_code,
            'currency' => config('billing.currency', 'EUR'),
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'payment_method' => $pack->payment_method,
            'payment_status' => 'paid',
            'transaction_id' => $pack->reference_code,
            'invoice_date' => $pack->created_at ?: now(),
            'paid_at' => $first?->paid_at ?: $pack->created_at ?: now(),
            'customer_name' => $user->billing_name ?? $user->name,
            'customer_email' => $user->email,
            'billing_snapshot' => [
                'name' => $user->billing_name ?? $user->name,
                'email' => $user->email,
                'company' => $user->company_name ?? null,
            ],
            'line_items' => $allLines,
            'pdf_disk' => config('billing.storage.disk', 'local'),
            'notes' => $pack->label().' receipt ('.$pack->progressLabel().')',
            'meta' => [
                'order_pack_id' => $pack->id,
                'pack_size' => (int) $pack->pack_size,
                'site_id' => $pack->site_id,
            ],
        ];
        if (Schema::hasColumn('invoices', 'order_pack_id')) {
            $payload['order_pack_id'] = $pack->id;
        }

        $schema = app(CheckoutSchemaService::class);
        $invoice = Invoice::create($schema->filterExistingColumns('invoices', $payload));
        try {
            $this->pdfs->generateAndStore($invoice);
        } catch (\Throwable $e) {
            Log::warning('Pack receipt PDF failed: '.$e->getMessage(), ['order_pack_id' => $pack->id]);
        }

        return $invoice->fresh();
    }

    /**
     * Snapshot cart unit prices so checkout can flag tier/price changes.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function stampQuotedPrice(array $line): array
    {
        if (empty($line['bulk_pack'])) {
            return $line;
        }

        $unit = round((float) ($line['price'] ?? 0), 2);
        $list = round((float) ($line['list_total'] ?? $unit), 2);
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $discount = isset($line['discount_percent']) ? round((float) $line['discount_percent'], 2) : null;

        if (! isset($line['quoted_unit_price'])) {
            $line['quoted_unit_price'] = $unit;
            $line['quoted_list_total'] = $list;
            $line['quoted_discount_percent'] = $discount;
            $line['quoted_pack_total'] = round($unit * $qty, 2);
            $line['quoted_at'] = now()->toIso8601String();
        }

        $line['price_changed'] = abs($unit - (float) $line['quoted_unit_price']) > 0.009
            || ($discount !== null && $line['quoted_discount_percent'] !== null
                && abs($discount - (float) $line['quoted_discount_percent']) > 0.009);
        $line['current_pack_total'] = round($unit * $qty, 2);
        $line['quoted_pack_total'] = round((float) $line['quoted_unit_price'] * $qty, 2);

        return $line;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cart
     * @return list<array<string, mixed>>
     */
    public function priceChangeNotices(array $cart): array
    {
        $notices = [];
        foreach ($cart as $line) {
            if (! is_array($line) || empty($line['bulk_pack']) || empty($line['price_changed'])) {
                continue;
            }
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $notices[] = [
                'site_id' => (int) ($line['id'] ?? 0),
                'site_name' => (string) ($line['name'] ?? 'Site'),
                'quantity' => $qty,
                'old_unit_price' => round((float) ($line['quoted_unit_price'] ?? 0), 2),
                'new_unit_price' => round((float) ($line['price'] ?? 0), 2),
                'old_pack_total' => round((float) ($line['quoted_pack_total'] ?? 0), 2),
                'new_pack_total' => round((float) ($line['current_pack_total'] ?? 0), 2),
                'old_discount_percent' => isset($line['quoted_discount_percent'])
                    ? (float) $line['quoted_discount_percent'] : null,
                'new_discount_percent' => isset($line['discount_percent'])
                    ? (float) $line['discount_percent'] : null,
            ];
        }

        return $notices;
    }
}
