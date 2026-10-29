<?php

// app/Http/Controllers/Publisher/OrderController.php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\LiveUrlSubmitted;
use App\Mail\OrderAccepted;
use App\Mail\OrderRejected;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\Advertiser\AdvertiserOrderWebhookBridge;
use App\Services\CheckoutSchemaService;
use App\Services\ContentUpload\ArticlePreviewHtml;
use App\Services\InAppNotificationService;
use App\Services\LiveUrlHealthChecker;
use App\Services\Marketplace\WritingFeeService;
use App\Models\OrderPack;
use App\Services\Orders\ContentRevisionService;
use App\Services\Orders\OrderPackService;
use App\Services\Orders\OrderRefundService;
use App\Services\Orders\ReviewHandoffService;
use App\Services\Partners\PartnerApiSerializer;
use App\Services\Partners\PartnerChannel;
use App\Services\Partners\PartnerSandbox;
use App\Services\Partners\PartnerWebhookService;
use App\Support\ArticleDownload;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\PublisherNeedsAction;
use App\Support\SocialPostUrlValidator;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OrderController extends Controller
{
    /**
     * Display tasks page for publisher
     */
    public function index()
    {
        return view('publisher.tasks');
    }

    /**
     * Download approved article file for an order assigned to this publisher.
     */
    public function downloadContent(ContentSubmission $submission): StreamedResponse
    {
        try {
            $allowed = OrderItem::query()
                ->where('content_submission_id', $submission->id)
                ->withoutClawback()
                ->whereHas('site', fn ($q) => $q->where('publisher_id', auth()->id()))
                ->whereHas('order', fn ($q) => $q->where('payment_status', 'paid'))
                ->exists();

            abort_unless($allowed, 403);

            $disk = Storage::disk($submission->disk ?: 'local');
            if (! $submission->hasStoredFile() || ! $disk->exists($submission->path)) {
                abort(404, 'File not found');
            }

            return $disk->download(
                $submission->path,
                $submission->original_filename,
                ArticleDownload::headers(
                    (string) $submission->original_filename,
                    (string) ($submission->mime ?: 'application/octet-stream')
                )
            );
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            abort(503, UserFacingError::message($e, 'Unable to download this file right now.'));
        }
    }

    /**
     * Get orders list for publisher (AJAX)
     */
    public function getOrders(Request $request)
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();

            $userId = auth()->id();

            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();

            // If no sites found, return empty data
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'total' => 0,
                        'from' => 0,
                        'to' => 0,
                    ],
                ]);
            }

            // Only paid orders — bank/Wise/crypto fund the wallet first; unpaid card checkouts stay hidden.
            $query = OrderItem::with(['order.user', 'site', 'disputes'])
                ->whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid');
                    if (Order::hasSandboxColumn()) {
                        $q->notSandbox();
                    }
                })
                ->where(function ($q) {
                    $q->where('content_source', '!=', WritingFeeService::SOURCE_SLB)
                        ->orWhereNull('content_source')
                        ->orWhereIn('writing_status', ['article_approved', 'released_to_publisher']);
                })
                ->orderBy('created_at', 'desc');
            if (OrderItem::hasSandboxColumn()) {
                $query->notSandbox();
            }

            // Search filter
            $search = search_text($request->input('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('order', function ($sub) use ($search) {
                        $sub->where('order_number', 'like', "%{$search}%")
                            ->orWhere('reference_code', 'like', "%{$search}%");
                    })->orWhere('site_name', 'like', "%{$search}%");
                });
            }

            // Needs-action filter (accept / publish / modification) — paid already applied above.
            if ($request->boolean('needs_action')) {
                PublisherNeedsAction::applyNeedsYouFilter($query);
            } elseif (($status = search_text($request->input('status'))) !== '') {
                $query->whereHas('order', function ($sub) use ($status) {
                    if ($status === 'scheduled') {
                        $sub->awaitingScheduledRelease();
                    } elseif ($status === 'pending') {
                        $sub->where('status', 'pending')->notAwaitingScheduledRelease();
                    } else {
                        $sub->where('status', $status);
                    }
                });
            }

            // Date range filter
            $dateFrom = search_text($request->input('date_from'));
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            $dateTo = search_text($request->input('date_to'));
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $perPage = max(1, (int) $request->get('per_page', 20));
            $page = max(1, (int) $request->get('page', 1));
            $packService = app(OrderPackService::class);
            $pageUnits = null;
            $pageItems = collect();
            $pagination = null;

            if ($packService->available()) {
                $liteRows = (clone $query)->toBase()
                    ->select('order_items.id', 'order_items.order_id')
                    ->get();
                $orderIdsForPack = $liteRows->pluck('order_id')->unique()->filter()->values()->all();
                $packByOrderId = $orderIdsForPack === []
                    ? collect()
                    : Order::query()->whereIn('id', $orderIdsForPack)->pluck('order_pack_id', 'id');
                $orderedRows = $liteRows->map(function ($row) use ($packByOrderId) {
                    return (object) [
                        'id' => (int) $row->id,
                        'order_pack_id' => (int) ($packByOrderId[$row->order_id] ?? 0),
                    ];
                });
                $units = $packService->collapseOrderedRowsToUnits($orderedRows);
                $pageSlice = $packService->paginateUnits($units, $page, $perPage);
                $pageUnits = $pageSlice['units'];
                $pageIds = $pageSlice['ids'];
                $pagination = $pageSlice['pagination'];

                if ($pageIds !== []) {
                    $loaded = OrderItem::with(['order.user', 'site', 'disputes'])
                        ->whereIn('id', $pageIds)
                        ->get()
                        ->keyBy('id');
                    $pageItems = collect($pageIds)
                        ->map(fn ($id) => $loaded->get((int) $id))
                        ->filter();
                }
            } else {
                $paginator = $query->paginate($perPage);
                $pageItems = collect($paginator->items());
                $pagination = [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ];
            }

            $orderIds = $pageItems->pluck('order_id')->unique()->values();
            $unreadByOrder = OrderChatMessage::whereIn('order_id', $orderIds)
                ->where('sender_type', 'advertiser')
                ->where('is_read', false)
                ->notBlocked()
                ->selectRaw('order_id, COUNT(*) as unread_count')
                ->groupBy('order_id')
                ->pluck('unread_count', 'order_id');

            $ordersWithOpenContentRevision = collect();
            if (
                $orderIds->isNotEmpty()
                && Schema::hasColumn('order_items', 'content_revision_requested')
            ) {
                $ordersWithOpenContentRevision = OrderItem::query()
                    ->whereIn('order_id', $orderIds)
                    ->where('content_revision_requested', 'yes')
                    ->distinct()
                    ->pluck('order_id')
                    ->flip();
            }

            $orderItemCountsByOrder = OrderItem::query()
                ->whereIn('order_id', $orderIds)
                ->selectRaw('order_id, COUNT(*) as items_count')
                ->groupBy('order_id')
                ->pluck('items_count', 'order_id');

            $packBadgeByPackId = [];
            if ($packService->available()) {
                $packIds = $pageItems
                    ->map(fn ($item) => (int) ($item->order?->order_pack_id ?? 0))
                    ->filter(fn ($id) => $id > 0)
                    ->unique()
                    ->values();
                if ($packIds->isNotEmpty()) {
                    $packs = OrderPack::query()->whereIn('id', $packIds)->get()->keyBy('id');
                    $siblingIdsByPack = Order::query()
                        ->whereIn('order_pack_id', $packIds)
                        ->orderBy('id')
                        ->get(['id', 'order_pack_id'])
                        ->groupBy('order_pack_id')
                        ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
                    foreach ($packs as $packId => $pack) {
                        $pendingCount = $packService->pendingAcceptItemsForPublisher($pack, (int) $userId)->count();
                        $packBadgeByPackId[(int) $packId] = [
                            'pack_id' => (int) $packId,
                            'pack_size' => (int) $pack->pack_size,
                            'pending_accept_count' => $pendingCount,
                            'order_ids' => $siblingIdsByPack->get($packId, []),
                        ];
                    }
                }
            }

            // Transform data to include sensitive price info and auto-approve fields
            $transformedItems = [];
            foreach ($pageItems as $item) {
                $packMeta = null;
                $packId = (int) ($item->order?->order_pack_id ?? 0);
                if ($packId > 0 && isset($packBadgeByPackId[$packId])) {
                    $badge = $packBadgeByPackId[$packId];
                    $siblings = $badge['order_ids'];
                    $index = array_search((int) $item->order_id, $siblings, true);
                    $position = $index === false ? null : ($index + 1);
                    $packMeta = [
                        'pack_id' => $packId,
                        'position' => $position,
                        'position_label' => $position
                            ? ('Pack '.$position.' of '.(int) $badge['pack_size'])
                            : ('Pack of '.(int) $badge['pack_size']),
                        'pack_size' => (int) $badge['pack_size'],
                        'pending_accept_count' => (int) $badge['pending_accept_count'],
                    ];
                }

                $transformedItems[] = [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'site_id' => $item->site_id,
                    'site_name' => $item->site_name,
                    'site_url' => $item->site_url,
                    'price' => $item->publisherPayoutAmount(),
                    'publisher_base' => $item->publisherBasePrice(),
                    'you_earn' => $item->publisherPayoutAmount(),
                    'you_earn_currency' => $item->publisherPayoutCurrency(),
                    'additional_price' => (float) ($item->additional_price ?? 0),
                    'sensitive_type' => $item->sensitive_type ?? null,
                    'homepage_days' => $item->homepage_days !== null ? (int) $item->homepage_days : null,
                    'homepage_price' => (float) ($item->homepage_price ?? 0),
                    'social_channels' => $item->enabledSocialChannels(),
                    'social_post_urls' => $item->socialPostUrls(),
                    'content_link' => $item->publisherContentLink(),
                    'content_download_url' => $item->publisherContentDownloadUrl(),
                    'content_original_name' => $item->content_original_name,
                    'anchor_text' => $item->anchor_text,
                    'target_url' => $item->target_url,
                    'feature_image_url' => $item->feature_image_url,
                    'moderation_status' => $item->moderation_status,
                    'article_title' => $item->content_original_name ?: null,
                    'preview_html' => null,
                    'detected_links' => [],
                    'live_url' => $item->live_url,
                    'live_url_submitted_at' => $item->live_url_submitted_at ?? null,
                    'auto_approve_triggered' => (bool) ($item->auto_approve_triggered ?? false),
                    'modification_requested' => $item->modification_requested ?? 'no',
                    'content_revision_requested' => $item->content_revision_requested ?? 'no',
                    'content_revision_reason' => $item->content_revision_reason ?? null,
                    'completion_notes' => $item->completion_notes ?? null,
                    'unread_chat' => (int) ($unreadByOrder[$item->order_id] ?? 0),
                    'order_items_count' => (int) ($orderItemCountsByOrder[$item->order_id] ?? 1),
                    'created_at' => $item->created_at,
                    'pack' => $packMeta,
                    'order' => [
                        'id' => $item->order->id,
                        'order_number' => $item->order->order_number,
                        'status' => $item->order->status,
                        'payment_method' => $item->order->payment_method,
                        'payment_status' => $item->order->payment_status,
                        'reference_code' => $item->order->reference_code,
                        'order_pack_id' => $packId > 0 ? $packId : null,
                        'has_open_content_revision' => $ordersWithOpenContentRevision->has($item->order_id),
                        'publication_mode' => $item->order->publication_mode,
                        'scheduled_publish_at' => optional($item->order->scheduled_publish_at)?->toIso8601String(),
                        'schedule_timezone' => $item->order->schedule_timezone,
                        'is_awaiting_scheduled_release' => $item->order->isAwaitingScheduledRelease(),
                        'scheduled_label' => $item->order->scheduled_publish_at
                            ? $item->order->scheduled_publish_at
                                ->timezone($item->order->schedule_timezone ?: 'UTC')
                                ->format('d F Y g:i A').' '.($item->order->schedule_timezone ?: 'UTC')
                            : null,
                    ],
                ];
            }

            $grouped = is_array($pageUnits)
                ? $packService->groupPublisherItemPayloads(collect($transformedItems), $pageUnits)
                : null;

            return response()->json([
                'success' => true,
                'data' => $transformedItems,
                'grouped' => $grouped,
                'pagination' => $pagination,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching publisher orders: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch orders. Please try again.'),
            ], 500);
        }
    }

    /**
     * Get single order item details (AJAX)
     */

    /**
     * Resolve a publisher-owned order item id for deep links (bell / email focus).
     */
    public function locateOrderItem(Request $request)
    {
        $orderId = (int) $request->query('order_id', 0);
        if ($orderId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'order_id is required',
            ], 422);
        }

        try {
            $userId = auth()->id();
            $siteIds = Site::where('publisher_id', $userId)->pluck('id');

            $item = OrderItem::query()
                ->with('order:id,order_number')
                ->where('order_id', $orderId)
                ->whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid');
                })
                ->orderBy('id')
                ->first();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not find that task. Please try again.'),
            ], 500);
        }

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found in your tasks',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'order_item_id' => $item->id,
            'order_id' => $item->order_id,
            'order_number' => optional($item->order)->order_number,
        ]);
    }

    public function getOrderDetails($id)
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();

            $userId = auth()->id();

            $orderItem = OrderItem::with(['order', 'contentSubmission', 'disputes'])->findOrFail($id);

            // Verify this order belongs to a site owned by the publisher
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site',
                ], 403);
            }

            $order = $orderItem->order;
            if (! $order || $order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is not available yet',
                ], 404);
            }

            $data = [
                'id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $orderItem->site_id,
                'site_name' => $orderItem->site_name,
                'site_url' => $orderItem->site_url,
                'price' => $orderItem->publisherPayoutAmount(),
                'publisher_base' => $orderItem->publisherBasePrice(),
                'you_earn' => $orderItem->publisherPayoutAmount(),
                'you_earn_currency' => $orderItem->publisherPayoutCurrency(),
                'additional_price' => (float) ($orderItem->additional_price ?? 0),
                'sensitive_type' => $orderItem->sensitive_type ?? null,
                'homepage_days' => $orderItem->homepage_days !== null ? (int) $orderItem->homepage_days : null,
                'homepage_price' => (float) ($orderItem->homepage_price ?? 0),
                'social_channels' => $orderItem->enabledSocialChannels(),
                'social_post_urls' => $orderItem->socialPostUrls(),
                'content_link' => $orderItem->publisherContentLink(),
                'content_download_url' => $orderItem->publisherContentDownloadUrl(),
                'content_original_name' => $orderItem->content_original_name,
                'anchor_text' => $orderItem->anchor_text,
                'target_url' => $orderItem->target_url,
                'feature_image_url' => $orderItem->feature_image_url,
                'moderation_status' => $orderItem->moderation_status,
                ...$this->articlePreviewFields($orderItem),
                'live_url' => $orderItem->live_url,
                'live_url_submitted_at' => $orderItem->live_url_submitted_at ?? null,
                'auto_approve_triggered' => (bool) ($orderItem->auto_approve_triggered ?? false),
                'modification_requested' => $orderItem->modification_requested ?? 'no',
                'content_revision_requested' => $orderItem->content_revision_requested ?? 'no',
                'content_revision_reason' => $orderItem->content_revision_reason ?? null,
                'completion_notes' => $orderItem->completion_notes ?? null,
                'created_at' => $orderItem->created_at,
                'order' => [
                    'id' => $orderItem->order->id,
                    'order_number' => $orderItem->order->order_number,
                    'status' => $orderItem->order->status,
                    'payment_method' => $orderItem->order->payment_method,
                    'payment_status' => $orderItem->order->payment_status,
                    'reference_code' => $orderItem->order->reference_code,
                    'total_amount' => (float) $orderItem->order->total_amount,
                    'created_at' => $orderItem->order->created_at,
                    'has_open_content_revision' => OrderItem::orderHasOpenContentRevision((int) $orderItem->order_id),
                    'publication_mode' => $orderItem->order->publication_mode,
                    'scheduled_publish_at' => optional($orderItem->order->scheduled_publish_at)?->toIso8601String(),
                    'schedule_timezone' => $orderItem->order->schedule_timezone,
                    'is_awaiting_scheduled_release' => $orderItem->order->isAwaitingScheduledRelease(),
                    'scheduled_label' => $orderItem->order->scheduled_publish_at
                        ? $orderItem->order->scheduled_publish_at
                            ->timezone($orderItem->order->schedule_timezone ?: 'UTC')
                            ->format('d F Y g:i A').' '.($orderItem->order->schedule_timezone ?: 'UTC')
                        : null,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching order details: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch order details. Please try again.'),
            ], 500);
        }
    }

    /**
     * Accept an order - Update order status to 'processing'
     */
    public function acceptOrder(Request $request, $id)
    {
        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);
            $result = $this->acceptOrderItemForPublisher($orderItem, (int) auth()->id());

            return response()->json([
                'success' => (bool) ($result['success'] ?? false),
                'message' => $result['message'] ?? 'Failed to accept order.',
            ], (int) ($result['status'] ?? (($result['success'] ?? false) ? 200 : 400)));
        } catch (\Exception $e) {
            Log::error('Error accepting order: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to accept order. Please try again.'),
            ], 500);
        }
    }

    /**
     * Accept every remaining pending article in a pack for this publisher.
     */
    public function acceptPack(Request $request, OrderPack $pack): \Illuminate\Http\JsonResponse
    {
        $userId = (int) auth()->id();
        $packService = app(OrderPackService::class);

        if (! $packService->available()) {
            return response()->json([
                'success' => false,
                'message' => 'Article packs are not available.',
            ], 404);
        }

        $ownsAny = $pack->orders()
            ->whereHas('items', function ($q) use ($userId) {
                $q->whereIn('site_id', Site::query()->where('publisher_id', $userId)->select('id'));
            })
            ->exists();

        if (! $ownsAny) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: This pack does not include your sites.',
            ], 403);
        }

        try {
            $result = $packService->acceptAllPending(
                $pack,
                $request->user(),
                function (OrderItem $item) use ($userId) {
                    return $this->acceptOrderItemForPublisher($item, $userId);
                }
            );

            $accepted = (int) ($result['accepted'] ?? 0);

            return response()->json([
                'success' => true,
                'message' => $accepted > 0
                    ? 'Accepted '.$accepted.' article'.($accepted === 1 ? '' : 's').' in this pack.'
                    : 'No remaining articles needed acceptance.',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error accepting pack: '.$e->getMessage(), [
                'order_pack_id' => $pack->id,
                'publisher_id' => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to accept pack articles. Please try again.'),
            ], 500);
        }
    }

    /**
     * Shared accept path for a single order item (used by acceptOrder and acceptPack).
     *
     * @return array{success: bool, message: string, status?: int}
     */
    private function acceptOrderItemForPublisher(OrderItem $orderItem, int $userId): array
    {
        $suppressor = app(OrderLifecycleMailSuppressor::class);
        $suppressedOrderId = null;

        try {
            if (PartnerSandbox::isSandboxItem($orderItem)) {
                return [
                    'success' => false,
                    'message' => 'Sandbox orders must be managed with a test API key.',
                    'status' => 403,
                ];
            }

            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return [
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site',
                    'status' => 403,
                ];
            }

            DB::beginTransaction();

            $orderItem = OrderItem::query()->whereKey($orderItem->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($orderItem->order_id)->lockForUpdate()->first();

            if (! $order) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Order not found',
                    'status' => 404,
                ];
            }

            if ($order->payment_status !== 'paid') {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Order payment is not confirmed yet',
                    'status' => 400,
                ];
            }

            if ($order->isAwaitingScheduledRelease()) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'This order is scheduled for later. Accept it after the publication date.',
                    'status' => 422,
                ];
            }

            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'This order is no longer open.',
                    'status' => 422,
                ];
            }

            if (! in_array($order->status, ['pending', 'processing', 'review'], true)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'Only open orders can be accepted.',
                    'status' => 422,
                ];
            }

            if ($this->placementAlreadyAccepted($orderItem)) {
                DB::rollBack();

                return [
                    'success' => false,
                    'message' => 'This placement was already accepted.',
                    'status' => 422,
                ];
            }

            // Dedicated OrderAccepted mail covers the advertiser — skip generic
            // OrderStatusChanged for that audience on this transition.
            $suppressedOrderId = (int) $order->id;
            $suppressor->suppress($suppressedOrderId, ['advertiser']);

            // First acceptance opens the parent order. A sibling placement on
            // another site can still be accepted after that.
            if ($order->status === 'pending') {
                $order->update([
                    'status' => 'processing',
                ]);
            }

            // accepted_at drives advertiser "Accepted" UI and turnaround windows.
            $orderItem->update([
                'accepted_at' => now(),
                'publisher_status' => 'accepted',
            ]);

            DB::commit();

            $advertiser = User::find($order->user_id);

            if ($advertiser && $advertiser->email) {
                try {
                    Mail::to($advertiser->email)->send(new OrderAccepted($order, $orderItem, $site));
                    Log::info('Order accepted email sent to advertiser', [
                        'order_id' => $order->id,
                        'advertiser_email' => $advertiser->email,
                        'order_number' => $order->order_number,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send order accepted email: '.$e->getMessage());
                }
            }

            app(InAppNotificationService::class)->notifyOrderAccepted($order, $orderItem, $site);
            $this->emitPartnerOrderWebhook($orderItem->fresh(), 'order.accepted');
            $this->emitAdvertiserOrderWebhook($orderItem->fresh(), 'order.accepted');

            Log::info('Order accepted by publisher', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
            ]);

            return [
                'success' => true,
                'message' => 'Order accepted successfully. Please submit the live URL when your content is ready.',
                'status' => 200,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error accepting order item: '.$e->getMessage());

            return [
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to accept order. Please try again.'),
                'status' => 500,
            ];
        } finally {
            if ($suppressedOrderId) {
                $suppressor->forget($suppressedOrderId);
            }
        }
    }

    /**
     * Refund advertiser for rejected order (Unified method for all payment types)
     * - Wallet: Move from reserved_balance to balance
     * - All other payments: Direct refund to advertiser's balance
     */
    private function placementAlreadyAccepted(OrderItem $orderItem): bool
    {
        if (in_array($orderItem->publisher_status, ['accepted', 'completed', 'rejected'], true)) {
            return true;
        }

        return $orderItem->accepted_at !== null;
    }

    private function refundAdvertiser($order, $orderAmount, $reason)
    {
        try {
            // Caller must already be inside a DB transaction with the order locked.
            return app(OrderRefundService::class)
                ->refundToAdvertiser($order, (float) $orderAmount, $reason);
        } catch (\Exception $e) {
            Log::error('Refund failed for advertiser', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            // Rethrow so the surrounding transaction rolls back cancelled/refunded status.
            throw $e;
        }
    }

    /**
     * Reject an order with reason - Update order status to 'cancelled' and refund advertiser
     */
    public function rejectOrder(Request $request, $id)
    {
        // Outside the try: the catch-all below would turn a ValidationException
        // into a 500 and hide the field errors from the UI.
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        $suppressor = app(OrderLifecycleMailSuppressor::class);
        $suppressedOrderId = null;

        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);
            if (PartnerSandbox::isSandboxItem($orderItem)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sandbox orders must be managed with a test API key.',
                ], 403);
            }

            // Verify this order belongs to a site owned by the publisher
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site',
                ], 403);
            }

            $previewOrder = $orderItem->order;
            if ($previewOrder instanceof Order
                && $previewOrder->payment_status === 'paid'
                && $previewOrder->payment_method === 'paypal'
            ) {
                try {
                    $paypalAmount = app(OrderRefundService::class)
                        ->resolveOrderCancelRefundAmount($previewOrder);
                    app(OrderRefundService::class)
                        ->refundPaypalCaptureIfPossible($previewOrder, $paypalAmount);
                } catch (\Throwable $e) {
                    Log::error('Publisher PayPal refund API failed', [
                        'order_id' => $previewOrder->id,
                        'error' => $e->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => UserFacingError::message(
                            $e,
                            'PayPal refund failed. The wallet was not credited.'
                        ),
                    ], 422);
                }
            }

            DB::beginTransaction();

            // Lock order to prevent double-reject / double-refund races
            $order = Order::where('id', $orderItem->order_id)->lockForUpdate()->firstOrFail();

            if ($order->payment_status !== 'paid') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order payment is not confirmed yet',
                ], 400);
            }

            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order has already been cancelled or refunded',
                ], 400);
            }

            if ($order->isAwaitingScheduledRelease()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'This order is scheduled for later. You can reject it after the publication date.',
                ], 422);
            }

            // Reject only before publisher payout (completed already credited publisher wallet).
            if (! in_array($order->status, ['pending', 'processing'], true)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'This order can no longer be rejected. Contact support if a refund is needed.',
                ], 400);
            }

            // Dedicated OrderRejected (+ refund bell) covers the advertiser.
            $suppressedOrderId = (int) $order->id;
            $suppressor->suppress($suppressedOrderId, ['advertiser']);

            $wasPaid = $order->payment_status === 'paid';
            $order->update([
                'status' => 'cancelled',
                'payment_status' => $wasPaid ? 'refunded' : $order->payment_status,
            ]);

            $reason = $request->reason;
            $orderAmount = 0.0;
            $refundProcessed = false;
            // Only refund money that was actually collected. Unpaid pending/failed
            // rows used to mint withdrawable cash via refundToAdvertiser().
            if ($wasPaid) {
                // rejectOrder cancels the whole order — refund the full order total,
                // not just the clicked line (multi-item carts must not strand reserved funds).
                $orderAmount = app(OrderRefundService::class)
                    ->resolveOrderCancelRefundAmount($order);
                $refundProcessed = $this->refundAdvertiser($order, $orderAmount, $reason);
            }

            ContentSubmission::releaseAllForOrder((int) $order->id);

            DB::commit();

            // Get the advertiser (user who placed the order)
            $advertiser = User::find($order->user_id);

            // Send email notification to advertiser with rejection reason
            if ($advertiser && $advertiser->email) {
                try {
                    Mail::to($advertiser->email)->send(new OrderRejected($order, $orderItem, $site, $request->reason));
                    Log::info('Order rejected email sent to advertiser', [
                        'order_id' => $order->id,
                        'advertiser_email' => $advertiser->email,
                        'order_number' => $order->order_number,
                        'reason' => $request->reason,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send order rejected email: '.$e->getMessage());
                }
            }

            $notifications = app(InAppNotificationService::class);
            $notifications->notifyOrderRejected($order, $orderItem, $site, $request->reason);
            if ($refundProcessed) {
                $notifications->notifyRefundCredited($order, $orderAmount, $request->reason);
            }

            $refundMessage = '';
            if ($order->payment_method === 'wallet') {
                $refundMessage = ' The funds have been returned from reserved balance to the advertiser wallet.';
            } elseif ($order->payment_method === 'paypal') {
                $refundMessage = ' The advertiser was refunded on PayPal.';
            } else {
                $refundMessage = ' The full amount has been credited back to the advertiser wallet.';
            }

            Log::info('Order rejected by publisher and refund processed', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
                'reason' => $request->reason,
                'refund_amount' => $orderAmount,
                'payment_method' => $order->payment_method,
                'refund_processed' => $refundProcessed,
            ]);

            $this->emitPartnerOrderWebhook($orderItem->fresh(), 'order.rejected');
            $this->emitAdvertiserOrderWebhook($orderItem->fresh(), 'order.rejected');

            return response()->json([
                'success' => true,
                'message' => 'Order rejected successfully.'.$refundMessage,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error rejecting order: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to reject order. Please try again.'),
            ], 500);
        } finally {
            if ($suppressedOrderId) {
                $suppressor->forget($suppressedOrderId);
            }
        }
    }

    /**
     * Ask the advertiser to revise / resend the article for this placement.
     */
    public function requestContentRevision(Request $request, $id)
    {
        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $request->validate([
            'reason' => 'required|string|min:10|max:2000',
        ]);

        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);
            $result = app(ContentRevisionService::class)->requestFromPublisher(
                $orderItem,
                $request->user(),
                (string) $request->input('reason')
            );

            app(ContentRevisionService::class)->notifyAdvertiserRequested(
                $result['order'],
                $result['item'],
                $result['site'],
                (string) $request->input('reason'),
                (bool) ($result['updated'] ?? false)
            );

            $updated = (bool) ($result['updated'] ?? false);

            return response()->json([
                'success' => true,
                'updated' => $updated,
                'message' => $updated
                    ? 'Revision notes updated. The advertiser will see the new reason.'
                    : 'Revision request sent. The advertiser will upload or link an updated article.',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error requesting content revision: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to request a revised article. Please try again.'),
            ], 500);
        }
    }

    /**
     * Submit live URL - Update order status to 'review' for advertiser approval
     */
    public function submitLiveUrl(Request $request, $id)
    {
        // Outside the try: the catch-all below would turn a ValidationException
        // into a 500 and hide the field errors from the UI.
        $request->validate([
            'live_url' => 'required|url',
            'social_post_urls' => 'nullable|array',
            'social_post_urls.facebook' => 'nullable|url',
            'social_post_urls.instagram' => 'nullable|url',
            'social_post_urls.x' => 'nullable|url',
        ]);

        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $suppressor = app(OrderLifecycleMailSuppressor::class);
        $suppressedOrderId = null;

        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);

            // Verify this order belongs to a site owned by the publisher
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site',
                ], 403);
            }

            if ($orderItem->isContentRevisionRequested()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wait for the advertiser to send the revised article before submitting a live URL.',
                ], 422);
            }

            $social = app(SocialPostUrlValidator::class)->normalize(
                $orderItem->enabledSocialChannels(),
                $request->input('social_post_urls')
            );

            $health = app(LiveUrlHealthChecker::class)->check((string) $request->live_url);

            DB::beginTransaction();

            // Dedicated LiveUrlSubmitted mail covers the advertiser.
            $suppressedOrderId = (int) $orderItem->order_id;
            $suppressor->suppress($suppressedOrderId, ['advertiser']);

            $orderItem = OrderItem::query()->whereKey($orderItem->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($orderItem->order_id)->lockForUpdate()->firstOrFail();

            if ($order->payment_status !== 'paid') {
                DB::rollBack();
                if ($suppressedOrderId) {
                    $suppressor->forget($suppressedOrderId);
                    $suppressedOrderId = null;
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Order payment is not confirmed yet',
                ], 400);
            }

            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                DB::rollBack();
                if ($suppressedOrderId) {
                    $suppressor->forget($suppressedOrderId);
                    $suppressedOrderId = null;
                }

                return response()->json([
                    'success' => false,
                    'message' => 'This order is no longer open for a live URL update.',
                ], 422);
            }

            if ($orderItem->isPayoutComplete()) {
                DB::rollBack();
                if ($suppressedOrderId) {
                    $suppressor->forget($suppressedOrderId);
                    $suppressedOrderId = null;
                }

                return response()->json([
                    'success' => false,
                    'message' => 'This placement has already been paid. The live URL cannot be replaced from here.',
                ], 422);
            }

            // Re-check after the slow health probe — a revision may have opened mid-flight.
            if ($orderItem->isContentRevisionRequested()) {
                DB::rollBack();
                if ($suppressedOrderId) {
                    $suppressor->forget($suppressedOrderId);
                    $suppressedOrderId = null;
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Wait for the advertiser to send the revised article before submitting a live URL.',
                ], 422);
            }

            // processing = first submit; review = sibling line still publishing
            // while the order is already waiting on the advertiser.
            if (! in_array($order->status, ['processing', 'review'], true)) {
                DB::rollBack();
                if ($suppressedOrderId) {
                    $suppressor->forget($suppressedOrderId);
                    $suppressedOrderId = null;
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Accept the order before submitting a live URL.',
                ], 422);
            }

            $heldForSiblingRevision = OrderItem::orderHasOpenContentRevision(
                (int) $order->id,
                (int) $orderItem->id
            );

            // Update live_url and live_url_submitted_at
            if (Schema::hasColumn('order_items', 'live_url')) {
                $payload = [
                    'live_url' => $request->live_url,
                    'live_url_submitted_at' => now(),
                    'modification_requested' => 'no',
                    'auto_approve_triggered' => false,
                ];
                if (Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
                    $payload['auto_approve_reminder_sent_at'] = null;
                }
                if (Schema::hasColumn('order_items', 'live_url_check_ok')) {
                    $payload['live_url_check_ok'] = $health['ok'];
                    $payload['live_url_http_status'] = $health['status'];
                    $payload['live_url_checked_at'] = $health['checked_at'];
                }
                if (Schema::hasColumn('order_items', 'social_post_urls')) {
                    $payload['social_post_urls'] = $social['urls'] === [] ? null : $social['urls'];
                }
                $orderItem->update($payload);
            } else {
                Log::warning('live_url column does not exist in order_items table');
            }

            // Promote to review unless another line still needs a revised article.
            if (! $heldForSiblingRevision && $order->status === 'processing') {
                $order->update(['status' => 'review']);
                // If siblings already had live URLs (e.g. saved during a content-revision
                // hold), restart their review clocks now that review actually starts.
                $siblingHadLiveUrl = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->where('id', '!=', $orderItem->id)
                    ->whereNotNull('live_url')
                    ->where('live_url', '!=', '')
                    ->exists();
                if ($siblingHadLiveUrl) {
                    OrderItem::restartAutoApproveClocksForOrder((int) $order->id);
                }
            }

            DB::commit();

            // Get the advertiser (user who placed the order)
            $advertiser = User::find($order->user_id);

            // Only notify "ready for review" when the order actually entered review.
            if (! $heldForSiblingRevision) {
                if ($advertiser && $advertiser->email) {
                    try {
                        Mail::to($advertiser->email)->send(new LiveUrlSubmitted($order, $orderItem, $site, $request->live_url));
                        Log::info('Live URL submitted email sent to advertiser', [
                            'order_id' => $order->id,
                            'advertiser_email' => $advertiser->email,
                            'order_number' => $order->order_number,
                            'live_url' => $request->live_url,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send live URL submitted email: '.$e->getMessage());
                    }
                }

                try {
                    app(InAppNotificationService::class)->notifyLiveUrlSubmitted($order, $orderItem, $site, $request->live_url);
                } catch (\Throwable $e) {
                    Log::error('Live URL saved but advertiser notification failed: '.$e->getMessage(), [
                        'order_id' => $order->id,
                        'order_item_id' => $orderItem->id,
                    ]);
                }
            }

            try {
                $packService = app(OrderPackService::class);
                if ($packService->available() && $order->order_pack_id) {
                    $pack = $packService->findForOrder($order);
                    if ($pack) {
                        $packService->notifyProgress($pack, $order, 'published');
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Pack progress notification failed: '.$e->getMessage(), [
                    'order_id' => $order->id,
                    'order_pack_id' => $order->order_pack_id,
                ]);
            }

            $this->emitAdvertiserOrderWebhook($orderItem->fresh(), 'order.published');

            Log::info('Live URL submitted by publisher', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
                'live_url' => $request->live_url,
                'social_post_urls' => $social['urls'],
                'held_in_processing_for_sibling_revision' => $heldForSiblingRevision,
            ]);

            // Message copy must never turn a successful save into a 500.
            try {
                $windowHours = OrderItem::autoApproveHours();
            } catch (\Throwable) {
                $windowHours = 72;
            }
            $windowDays = max(1, (int) ceil($windowHours / 24));
            $message = $heldForSiblingRevision
                ? 'Live URL saved. This order stays in progress until the advertiser sends the revised article for the other placement.'
                : "Live URL submitted successfully! The advertiser will now review your submission. The order will be auto-approved in about {$windowDays} day(s) ({$windowHours} hours) if not reviewed.";
            if (! $health['ok']) {
                $message .= ' Note: '.$health['message'];
            }
            foreach ($social['warnings'] as $warning) {
                $message .= ' Note: '.$warning;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'live_url_check' => [
                    'ok' => $health['ok'],
                    'status' => $health['status'],
                    'message' => $health['message'],
                ],
                'social_post_urls' => $social['urls'],
                'social_warnings' => $social['warnings'],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error submitting live URL: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to submit live URL. Please try again.'),
            ], 500);
        } finally {
            if ($suppressedOrderId) {
                $suppressor->forget($suppressedOrderId);
            }
        }
    }

    /**
     * Resubmit live URL after modification request - Reset timer and update status to review
     */
    public function resubmitLiveUrl(Request $request, $id)
    {
        // Outside the try: the catch-all below would turn a ValidationException
        // into a 500 and hide the field errors from the UI.
        $request->validate([
            'live_url' => 'required|url',
            'social_post_urls' => 'nullable|array',
            'social_post_urls.facebook' => 'nullable|url',
            'social_post_urls.instagram' => 'nullable|url',
            'social_post_urls.x' => 'nullable|url',
        ]);

        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);

            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($orderItem->isContentRevisionRequested()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wait for the advertiser to send the revised article before resubmitting a live URL.',
                ], 422);
            }

            $order = $orderItem->order;
            if (! $order || $order->payment_status !== 'paid'
                || $order->status === 'cancelled'
                || $order->payment_status === 'refunded') {
                return response()->json([
                    'success' => false,
                    'message' => $order && $order->payment_status !== 'paid'
                        ? 'Order payment is not confirmed yet'
                        : 'This order is no longer open for a live URL update.',
                ], 422);
            }

            $liveUrl = (string) $request->live_url;
            $social = app(SocialPostUrlValidator::class)->normalize(
                $orderItem->enabledSocialChannels(),
                $request->input('social_post_urls')
            );

            $health = app(ReviewHandoffService::class)->handBack(
                $orderItem,
                $site,
                $liveUrl,
                'Live URL resubmitted: '.$liveUrl,
                // Always pass the map (even empty) so resubmit can clear stale URLs.
                $social['urls']
            );

            $message = 'Live URL resubmitted successfully!';
            if (! $health['ok']) {
                $message .= ' Note: '.$health['message'];
            }
            foreach ($social['warnings'] as $warning) {
                $message .= ' Note: '.$warning;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'live_url_check' => [
                    'ok' => $health['ok'],
                    'status' => $health['status'],
                    'message' => $health['message'],
                ],
                'social_post_urls' => $social['urls'],
                'social_warnings' => $social['warnings'],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error resubmitting: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to resubmit. Please try again.'),
            ], 500);
        }
    }

    /**
     * Add or update optional social post URLs after the live article URL exists.
     *
     * Publishers often share on social after the article is live — this path
     * does not require resubmitting the live URL or an advertiser change request.
     */
    public function updateSocialPostUrls(Request $request, $id)
    {
        $request->validate([
            'social_post_urls' => 'nullable|array',
            'social_post_urls.facebook' => 'nullable|url',
            'social_post_urls.instagram' => 'nullable|url',
            'social_post_urls.x' => 'nullable|url',
        ]);

        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $channels = $orderItem->enabledSocialChannels();
            if ($channels === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not include a social promotion.',
                ], 422);
            }

            $liveUrl = trim((string) ($orderItem->live_url ?? ''));
            if ($liveUrl === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Submit the live article URL first, then add social post links.',
                ], 422);
            }

            $order = $orderItem->order;
            if (! $order || $order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order payment is not confirmed yet',
                ], 400);
            }

            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order is no longer open for social post updates.',
                ], 422);
            }

            if (! in_array($order->status, ['processing', 'review'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Social post links can only be updated while the order is in progress or under review.',
                ], 422);
            }

            if ($orderItem->isContentRevisionRequested()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wait for the advertiser to send the revised article before updating social links.',
                ], 422);
            }

            $social = app(SocialPostUrlValidator::class)->normalize(
                $channels,
                $request->input('social_post_urls')
            );

            if (! Schema::hasColumn('order_items', 'social_post_urls')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Social post links are not available yet. Please contact support.',
                ], 422);
            }

            $orderItem->update([
                'social_post_urls' => $social['urls'] === [] ? null : $social['urls'],
            ]);

            $message = $social['urls'] === []
                ? 'Social post links cleared.'
                : 'Social post links saved.';
            foreach ($social['warnings'] as $warning) {
                $message .= ' Note: '.$warning;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'social_post_urls' => $social['urls'],
                'social_warnings' => $social['warnings'],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error updating social post URLs: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to save social post links. Please try again.'),
            ], 500);
        }
    }

    /**
     * Report a revision as done when the article was corrected in place.
     *
     * The URL almost never changes during a revision, so making the publisher
     * re-paste it just to clear the request is pointless friction.
     */
    public function markRevisionFixed(Request $request, $id)
    {
        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);

            $site = Site::where('id', $orderItem->site_id)
                ->where('publisher_id', auth()->id())
                ->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($orderItem->isContentRevisionRequested()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wait for the advertiser to send the revised article before handing this back for review.',
                ], 422);
            }

            if (! $orderItem->isModificationRequested()) {
                return response()->json([
                    'success' => false,
                    'message' => 'There is no open change request on this order.',
                ], 422);
            }

            if (blank($orderItem->live_url)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Submit the live URL first so the advertiser has something to review.',
                ], 422);
            }

            if (! in_array($orderItem->order?->status, ['processing', 'review'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order is no longer open for changes.',
                ], 422);
            }

            $liveUrl = (string) $orderItem->live_url;

            $health = app(ReviewHandoffService::class)->handBack(
                $orderItem,
                $site,
                $liveUrl,
                'Marked the requested changes as fixed. The article is updated at '.$liveUrl
            );

            $message = 'Thanks — the advertiser has been asked to review your changes.';
            if (! $health['ok']) {
                $message .= ' Note: '.$health['message'];
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'live_url' => $liveUrl,
                'live_url_check' => [
                    'ok' => $health['ok'],
                    'status' => $health['status'],
                    'message' => $health['message'],
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error marking revision fixed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not report the fix. Please try again.'),
            ], 500);
        }
    }

    /**
     * Get order statistics
     */
    public function getStatistics()
    {
        try {
            $userId = auth()->id();
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();

            // If no sites found, return zero stats
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_orders' => 0,
                        'pending_orders' => 0,
                        'accepted_orders' => 0,
                        'completed_orders' => 0,
                        'rejected_orders' => 0,
                        'review_orders' => 0,
                        'total_earnings' => 0,
                    ],
                ]);
            }

            $orderIds = OrderItem::whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid');
                })
                ->pluck('order_id')
                ->unique()
                ->toArray();

            $stats = [
                'total_orders' => count($orderIds),
                'pending_orders' => Order::whereIn('id', $orderIds)
                    ->where('status', 'pending')
                    ->notAwaitingScheduledRelease()
                    ->count(),
                'accepted_orders' => Order::whereIn('id', $orderIds)->where('status', 'processing')->count(),
                'review_orders' => Order::whereIn('id', $orderIds)->where('status', 'review')->count(),
                'completed_orders' => Order::whereIn('id', $orderIds)->where('status', 'completed')->count(),
                'rejected_orders' => Order::whereIn('id', $orderIds)->where('status', 'cancelled')->count(),
                'total_earnings' => round((float) OrderItem::whereIn('site_id', $siteIds)
                    ->recognizedForFinance()
                    ->whereHas('order', function ($q) {
                        $q->where('status', 'completed')
                            ->where('payment_status', 'paid');
                    })
                    ->sum(OrderItem::publisherPayoutSqlExpression()), 2),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching order statistics: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch statistics. Please try again.'),
            ], 500);
        }
    }

    /**
     * Get recent orders for dashboard (AJAX)
     */
    public function getRecentOrders(Request $request)
    {
        try {
            $userId = auth()->id();

            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();

            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'orders' => [],
                ]);
            }

            // Get recent orders (last 5)
            $orderIds = OrderItem::whereIn('site_id', $siteIds)
                ->orderBy('created_at', 'desc')
                ->pluck('order_id')
                ->unique()
                ->take(5);

            $orders = Order::whereIn('id', $orderIds)
                ->with('items')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'orders' => $orders,
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch recent orders.'),
            ], 500);
        }
    }

    /**
     * Article HTML + multi-link metadata for publisher preview / copy tools.
     *
     * @return array{article_title:?string, preview_html:?string, detected_links:array<int, array{anchor:string, url:string}>}
     */
    protected function articlePreviewFields(OrderItem $item): array
    {
        if ($item->isClawedBack()) {
            return [
                'article_title' => $item->content_original_name ?: null,
                'preview_html' => null,
                'detected_links' => [],
            ];
        }

        $submission = $item->relationLoaded('contentSubmission')
            ? $item->contentSubmission
            : ($item->content_submission_id ? $item->contentSubmission()->first() : null);

        if ($submission) {
            $links = $submission->detectedLinks();
            $title = trim((string) ($submission->title ?: $submission->original_filename ?: ''));

            return [
                'article_title' => $title !== '' ? $title : null,
                'preview_html' => ArticlePreviewHtml::normalize((string) ($submission->preview_html ?? '')),
                'detected_links' => $links,
            ];
        }

        $fallback = [];
        if (filled($item->anchor_text) && filled($item->target_url)) {
            $fallback[] = [
                'anchor' => trim((string) $item->anchor_text),
                'url' => trim((string) $item->target_url),
            ];
        }

        return [
            'article_title' => $item->content_original_name ?: null,
            'preview_html' => null,
            'detected_links' => $fallback,
        ];
    }

    private function emitPartnerOrderWebhook(OrderItem $item, string $type): void
    {
        try {
            if (! PartnerChannel::itemIsPartner($item)) {
                return;
            }
            $partner = PartnerChannel::partnerForItem($item);
            if (! $partner || ! $partner->user) {
                return;
            }
            app(PartnerWebhookService::class)->emit($partner, $type, [
                'object' => app(PartnerApiSerializer::class)->order($partner->user, $item),
            ], ! PartnerSandbox::isSandboxItem($item));
        } catch (\Throwable) {
            // Partner webhook outbox is best-effort next to the publisher path.
        }
    }

    private function emitAdvertiserOrderWebhook(OrderItem $item, string $type): void
    {
        try {
            app(AdvertiserOrderWebhookBridge::class)->emitForItem($item, $type);
        } catch (\Throwable) {
            // Advertiser webhook outbox is best-effort next to the publisher path.
        }
    }
}
