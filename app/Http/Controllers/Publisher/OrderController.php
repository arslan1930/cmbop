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
use App\Services\CheckoutSchemaService;
use App\Services\ContentUpload\ArticlePreviewHtml;
use App\Services\InAppNotificationService;
use App\Services\LiveUrlHealthChecker;
use App\Services\Orders\ContentRevisionService;
use App\Services\Orders\OrderRefundService;
use App\Services\Orders\ReviewHandoffService;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $allowed = OrderItem::query()
            ->where('content_submission_id', $submission->id)
            ->whereHas('site', fn ($q) => $q->where('publisher_id', auth()->id()))
            ->exists();

        abort_unless($allowed, 403);

        $disk = Storage::disk($submission->disk ?: 'local');
        if (! $disk->exists($submission->path)) {
            abort(404, 'File not found');
        }

        return $disk->download(
            $submission->path,
            $submission->original_filename,
            ['Content-Type' => $submission->mime ?: 'application/octet-stream']
        );
    }

    /**
     * Get orders list for publisher (AJAX)
     */
    public function getOrders(Request $request)
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();

            $userId = auth()->id();

            Log::info('Fetching orders for publisher', ['user_id' => $userId]);

            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();

            Log::info('Sites found for publisher', ['site_ids' => $siteIds]);

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
            $query = OrderItem::with(['order.user', 'site', 'contentSubmission'])
                ->whereIn('site_id', $siteIds)
                ->whereHas('order', function ($q) {
                    $q->where('payment_status', 'paid');
                })
                ->orderBy('created_at', 'desc');

            // Search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('order', function ($sub) use ($search) {
                        $sub->where('order_number', 'like', "%{$search}%")
                            ->orWhere('reference_code', 'like', "%{$search}%");
                    })->orWhere('site_name', 'like', "%{$search}%");
                });
            }

            // Needs-action filter (accept / publish / modification) — paid already applied above.
            if ($request->boolean('needs_action')) {
                $query->where(function ($q) {
                    $q->whereHas('order', function ($sub) {
                        $sub->where('status', 'pending');
                    })->orWhere(function ($sub) {
                        $sub->where('modification_requested', 'yes');
                    })->orWhere(function ($sub) {
                        $sub->whereHas('order', function ($o) {
                            $o->where('status', 'processing');
                        })->where(function ($u) {
                            $u->whereNull('live_url')->orWhere('live_url', '');
                        })->where(function ($m) {
                            $m->whereNull('modification_requested')
                                ->orWhere('modification_requested', '!=', 'yes');
                        });
                        if (Schema::hasColumn('order_items', 'content_revision_requested')) {
                            $sub->where(function ($c) {
                                $c->whereNull('content_revision_requested')
                                    ->orWhere('content_revision_requested', '!=', 'yes');
                            });
                        }
                    });
                });
            } elseif ($request->filled('status')) {
                // Status filter - using orders.status (the order status)
                $query->whereHas('order', function ($sub) use ($request) {
                    $sub->where('status', $request->status);
                });
            }

            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 20);
            $orderItems = $query->paginate($perPage);

            $orderIds = collect($orderItems->items())->pluck('order_id')->unique()->values();
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

            // Transform data to include sensitive price info and auto-approve fields
            $transformedItems = [];
            foreach ($orderItems->items() as $item) {
                $transformedItems[] = [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'site_id' => $item->site_id,
                    'site_name' => $item->site_name,
                    'site_url' => $item->site_url,
                    'price' => $item->publisherPayoutAmount(),
                    'additional_price' => (float) ($item->additional_price ?? 0),
                    'sensitive_type' => $item->sensitive_type ?? null,
                    'content_link' => $item->content_link,
                    'content_download_url' => $item->content_submission_id
                        ? route('publisher.content.download', $item->content_submission_id)
                        : $item->content_link,
                    'content_original_name' => $item->content_original_name,
                    'anchor_text' => $item->anchor_text,
                    'target_url' => $item->target_url,
                    'feature_image_url' => $item->feature_image_url,
                    'moderation_status' => $item->moderation_status,
                    ...$this->articlePreviewFields($item),
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
                    'order' => [
                        'id' => $item->order->id,
                        'order_number' => $item->order->order_number,
                        'status' => $item->order->status,
                        'payment_method' => $item->order->payment_method,
                        'payment_status' => $item->order->payment_status,
                        'reference_code' => $item->order->reference_code,
                        'total_amount' => (float) $item->order->total_amount,
                        'has_open_content_revision' => $ordersWithOpenContentRevision->has($item->order_id),
                        'publication_mode' => $item->order->publication_mode,
                        'scheduled_publish_at' => optional($item->order->scheduled_publish_at)?->toIso8601String(),
                        'schedule_timezone' => $item->order->schedule_timezone,
                        'scheduled_label' => $item->order->scheduled_publish_at
                            ? $item->order->scheduled_publish_at
                                ->timezone($item->order->schedule_timezone ?: 'UTC')
                                ->format('d F Y g:i A').' '.($item->order->schedule_timezone ?: 'UTC')
                            : null,
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $transformedItems,
                'pagination' => [
                    'current_page' => $orderItems->currentPage(),
                    'last_page' => $orderItems->lastPage(),
                    'per_page' => $orderItems->perPage(),
                    'total' => $orderItems->total(),
                    'from' => $orderItems->firstItem(),
                    'to' => $orderItems->lastItem(),
                ],
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
    public function getOrderDetails($id)
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();

            $userId = auth()->id();

            $orderItem = OrderItem::with(['order', 'contentSubmission'])->findOrFail($id);

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
                'additional_price' => (float) ($orderItem->additional_price ?? 0),
                'sensitive_type' => $orderItem->sensitive_type ?? null,
                'content_link' => $orderItem->content_link,
                'content_download_url' => $orderItem->content_submission_id
                    ? route('publisher.content.download', $orderItem->content_submission_id)
                    : $orderItem->content_link,
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

            DB::beginTransaction();

            $orderItem = OrderItem::query()->whereKey($orderItem->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($orderItem->order_id)->lockForUpdate()->first();

            if (! $order) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            if ($order->payment_status !== 'paid') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order payment is not confirmed yet',
                ], 400);
            }

            if ($order->status !== 'pending') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Only new (pending) orders can be accepted.',
                ], 422);
            }

            // Dedicated OrderAccepted mail covers the advertiser — skip generic
            // OrderStatusChanged for that audience on this transition.
            $suppressedOrderId = (int) $order->id;
            $suppressor->suppress($suppressedOrderId, ['advertiser']);

            // Update the order status to 'processing' (accepted)
            $order->update([
                'status' => 'processing',
            ]);

            // accepted_at drives advertiser "Accepted" UI and turnaround windows.
            $orderItem->update([
                'accepted_at' => now(),
                'publisher_status' => 'accepted',
            ]);

            DB::commit();

            // Get the advertiser (user who placed the order)
            $advertiser = User::find($order->user_id);

            // Send email notification to advertiser
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

            Log::info('Order accepted by publisher', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order accepted successfully. Please submit the live URL when your content is ready.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error accepting order: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to accept order. Please try again.'),
            ], 500);
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

            // Verify this order belongs to a site owned by the publisher
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site',
                ], 403);
            }

            DB::beginTransaction();

            // Lock order to prevent double-reject / double-refund races
            $order = Order::where('id', $orderItem->order_id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Order has already been cancelled or refunded',
                ], 400);
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

            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'refunded',
            ]);

            $reason = $request->reason;
            // rejectOrder cancels the whole order — always refund the full order total,
            // not just the clicked line (multi-item carts must not strand reserved funds).
            $orderAmount = app(OrderRefundService::class)
                ->resolveOrderCancelRefundAmount($order);

            // Process refund for ALL payment types (throws on failure so TX rolls back)
            $refundProcessed = $this->refundAdvertiser($order, $orderAmount, $reason);

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
                $refundMessage = ' The funds have been returned from reserved balance to your wallet balance.';
            } else {
                $refundMessage = ' The full amount has been credited back to your wallet balance.';
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

            $health = app(LiveUrlHealthChecker::class)->check((string) $request->live_url);

            DB::beginTransaction();

            // Dedicated LiveUrlSubmitted mail covers the advertiser.
            $suppressedOrderId = (int) $orderItem->order_id;
            $suppressor->suppress($suppressedOrderId, ['advertiser']);

            $orderItem = OrderItem::query()->whereKey($orderItem->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($orderItem->order_id)->lockForUpdate()->firstOrFail();

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

            if ($order->status !== 'processing') {
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

                app(InAppNotificationService::class)->notifyLiveUrlSubmitted($order, $orderItem, $site, $request->live_url);
            }

            Log::info('Live URL submitted by publisher', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
                'live_url' => $request->live_url,
                'held_in_processing_for_sibling_revision' => $heldForSiblingRevision,
            ]);

            $windowHours = OrderItem::autoApproveHours();
            $windowDays = max(1, (int) ceil($windowHours / 24));
            $message = $heldForSiblingRevision
                ? 'Live URL saved. This order stays in progress until the advertiser sends the revised article for the other placement.'
                : "Live URL submitted successfully! The advertiser will now review your submission. The order will be auto-approved in about {$windowDays} day(s) ({$windowHours} hours) if not reviewed.";
            if (! $health['ok']) {
                $message .= ' Note: '.$health['message'];
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'live_url_check' => [
                    'ok' => $health['ok'],
                    'status' => $health['status'],
                    'message' => $health['message'],
                ],
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

            $liveUrl = (string) $request->live_url;

            $health = app(ReviewHandoffService::class)->handBack(
                $orderItem,
                $site,
                $liveUrl,
                'Live URL resubmitted: '.$liveUrl
            );

            $message = 'Live URL resubmitted successfully!';
            if (! $health['ok']) {
                $message .= ' Note: '.$health['message'];
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'live_url_check' => [
                    'ok' => $health['ok'],
                    'status' => $health['status'],
                    'message' => $health['message'],
                ],
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

            Log::info('Fetching statistics for publisher', ['user_id' => $userId, 'site_ids' => $siteIds]);

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
                'pending_orders' => Order::whereIn('id', $orderIds)->where('status', 'pending')->count(),
                'accepted_orders' => Order::whereIn('id', $orderIds)->where('status', 'processing')->count(),
                'review_orders' => Order::whereIn('id', $orderIds)->where('status', 'review')->count(),
                'completed_orders' => Order::whereIn('id', $orderIds)->where('status', 'completed')->count(),
                'rejected_orders' => Order::whereIn('id', $orderIds)->where('status', 'cancelled')->count(),
                'total_earnings' => round((float) OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function ($q) {
                        $q->where('status', 'completed')
                            ->where('payment_status', 'paid');
                    })
                    ->sum(OrderItem::publisherPayoutSqlExpression()), 2),
            ];

            Log::info('Statistics calculated', $stats);

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

        } catch (\Exception $e) {
            Log::error('Error fetching recent orders: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent orders',
            ]);
        }
    }

    /**
     * Article HTML + multi-link metadata for publisher preview / copy tools.
     *
     * @return array{article_title:?string, preview_html:?string, detected_links:array<int, array{anchor:string, url:string}>}
     */
    protected function articlePreviewFields(OrderItem $item): array
    {
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
}
