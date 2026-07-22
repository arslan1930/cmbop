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
use App\Models\Wallet;
use App\Services\ContentUpload\ArticlePreviewHtml;
use App\Services\InAppNotificationService;
use App\Services\LiveUrlHealthChecker;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

            // Status filter - using orders.status (the order status)
            if ($request->filled('status')) {
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
                ->selectRaw('order_id, COUNT(*) as unread_count')
                ->groupBy('order_id')
                ->pluck('unread_count', 'order_id');

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
                    'completion_notes' => $item->completion_notes ?? null,
                    'unread_chat' => (int) ($unreadByOrder[$item->order_id] ?? 0),
                    'created_at' => $item->created_at,
                    'order' => [
                        'id' => $item->order->id,
                        'order_number' => $item->order->order_number,
                        'status' => $item->order->status,
                        'payment_method' => $item->order->payment_method,
                        'payment_status' => $item->order->payment_status,
                        'reference_code' => $item->order->reference_code,
                        'total_amount' => (float) $item->order->total_amount,
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
                'message' => 'Failed to fetch orders: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single order item details (AJAX)
     */
    public function getOrderDetails($id)
    {
        try {
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
                'message' => 'Failed to fetch order details: '.$e->getMessage(),
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

            // Verify this order belongs to a site owned by the publisher
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site',
                ], 403);
            }

            $order = Order::find($orderItem->order_id);

            if ($order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order payment is not confirmed yet',
                ], 400);
            }

            DB::beginTransaction();

            // Update the order status to 'processing' (accepted)
            $order->update([
                'status' => 'processing',
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
                'message' => 'Failed to accept order: '.$e->getMessage(),
            ], 500);
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
            $advertiserRoleId = Wallet::advertiserRoleId();
            if (! $advertiserRoleId) {
                throw new \RuntimeException('Advertiser role not configured');
            }

            // Caller must already be inside a DB transaction
            $advertiserWallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);

            // For wallet payments: Move from reserved_balance to balance (restore spend-only bonus if used)
            if ($order->payment_method === 'wallet') {
                $bonusReservedBefore = (float) $advertiserWallet->bonus_reserved;
                $advertiserWallet->refundReserved($orderAmount);
                $bonusRestored = max(0, round($bonusReservedBefore - (float) $advertiserWallet->bonus_reserved, 2));

                app(WalletLedgerService::class)->recordRefund(
                    $advertiserWallet,
                    (float) $orderAmount,
                    $bonusRestored,
                    $order,
                    $order->reference_code ?? $order->order_number
                );

                Log::info('Wallet refund: funds moved from reserved to balance', [
                    'order_id' => $order->id,
                    'amount' => $orderAmount,
                    'new_balance' => $advertiserWallet->balance,
                    'new_reserved_balance' => $advertiserWallet->reserved_balance,
                    'bonus_balance' => $advertiserWallet->bonus_balance,
                    'bonus_reserved' => $advertiserWallet->bonus_reserved,
                ]);
            }
            // For all other payment methods (card, wise, crypto, bank): Direct refund to balance
            else {
                $advertiserWallet->credit((float) $orderAmount);
                app(WalletLedgerService::class)->recordRefund(
                    $advertiserWallet,
                    (float) $orderAmount,
                    0,
                    $order,
                    $order->reference_code ?? $order->order_number
                );

                Log::info('Direct refund to advertiser balance', [
                    'order_id' => $order->id,
                    'payment_method' => $order->payment_method,
                    'amount' => $orderAmount,
                    'new_balance' => $advertiserWallet->balance,
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Refund failed for advertiser', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reject an order with reason - Update order status to 'cancelled' and refund advertiser
     */
    public function rejectOrder(Request $request, $id)
    {
        try {
            $request->validate([
                'reason' => 'required|string|min:10',
            ]);

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

            $order->update([
                'status' => 'cancelled',
                'payment_status' => 'refunded',
            ]);

            $orderAmount = (float) $orderItem->price;
            $reason = $request->reason;

            // Process refund for ALL payment types
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
                'message' => 'Failed to reject order: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit live URL - Update order status to 'review' for advertiser approval
     */
    public function submitLiveUrl(Request $request, $id)
    {
        try {
            $request->validate([
                'live_url' => 'required|url',
            ]);

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

            $health = app(LiveUrlHealthChecker::class)->check((string) $request->live_url);

            DB::beginTransaction();

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

            // Update order status to 'review' (ready for advertiser review/approval)
            $order = Order::find($orderItem->order_id);
            $order->update([
                'status' => 'review',
            ]);

            DB::commit();

            // Get the advertiser (user who placed the order)
            $advertiser = User::find($order->user_id);

            // Send email notification to advertiser that live URL is submitted and ready for review
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

            Log::info('Live URL submitted by publisher, order status changed to review', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
                'live_url' => $request->live_url,
            ]);

            $windowHours = OrderItem::autoApproveHours();
            $windowDays = max(1, (int) ceil($windowHours / 24));
            $message = "Live URL submitted successfully! The advertiser will now review your submission. The order will be auto-approved in about {$windowDays} day(s) ({$windowHours} hours) if not reviewed.";
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
                'message' => 'Failed to submit live URL: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resubmit live URL after modification request - Reset timer and update status to review
     */
    public function resubmitLiveUrl(Request $request, $id)
    {
        try {
            $request->validate([
                'live_url' => 'required|url',
            ]);

            $orderItem = OrderItem::with('order')->findOrFail($id);

            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $health = app(LiveUrlHealthChecker::class)->check((string) $request->live_url);

            DB::beginTransaction();

            // Update live_url and RESET timer, CLEAR modification flag
            $payload = [
                'live_url' => $request->live_url,
                'live_url_submitted_at' => now(),  // RESET timer
                'modification_requested' => 'no',  // CLEAR modification flag
                'modification_requested_at' => null,
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

            // Update order status back to 'review'
            $order = Order::find($orderItem->order_id);
            $order->update([
                'status' => 'review',
            ]);

            DB::commit();

            $order = $order->fresh(['user', 'items']);
            $orderItem = $orderItem->fresh();

            try {
                OrderChatMessage::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'sender_type' => 'publisher',
                    'message' => 'Live URL resubmitted: '.$request->live_url,
                    'is_read' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to create live URL resubmit chat message', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $advertiser = User::find($order->user_id);
                if ($advertiser?->email) {
                    Mail::to($advertiser->email)->send(
                        new LiveUrlSubmitted($order, $orderItem, $site, $request->live_url)
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send live URL resubmit email', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                app(InAppNotificationService::class)->notifyLiveUrlSubmitted(
                    $order,
                    $orderItem,
                    $site,
                    $request->live_url
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to create live URL resubmit in-app notification', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

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
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error resubmitting: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to resubmit: '.$e->getMessage(),
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
                'message' => 'Failed to fetch statistics: '.$e->getMessage(),
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
