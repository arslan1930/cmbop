<?php

namespace App\Http\Controllers;

use App\Mail\NewChatMessageNotification;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Support\AdvertiserOrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    /**
     * Unread chat count + "needs action" counts for the active role.
     */
    public function unreadSummary()
    {
        try {
            $user = auth()->user();
            $activeRole = $user->activeRole()
                ?? optional($user->roles()->first())->name;

            $unreadChat = 0;
            $needsAction = 0;
            $latestUnreadOrder = null;

            if ($activeRole === 'advertiser') {
                $orderIds = Order::where('user_id', $user->id)->pluck('id');
                $unreadQuery = OrderChatMessage::whereIn('order_id', $orderIds)
                    ->where('sender_type', 'publisher')
                    ->where('is_read', false);
                $unreadChat = (clone $unreadQuery)->count();
                $latestUnread = (clone $unreadQuery)->orderByDesc('created_at')->first();
                if ($latestUnread) {
                    $order = Order::find($latestUnread->order_id);
                    if ($order) {
                        $latestUnreadOrder = [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                        ];
                    }
                }
                $needsAction = Order::where('user_id', $user->id)
                    ->where('status', 'review')
                    ->whereHas('items', function ($q) {
                        $q->whereNotNull('live_url')->where('live_url', '!=', '');
                    })
                    ->count();
            } elseif ($activeRole === 'publisher') {
                $orderIds = Order::whereHas('items.site', function ($q) use ($user) {
                    $q->where('publisher_id', $user->id);
                })->pluck('id');
                $unreadQuery = OrderChatMessage::whereIn('order_id', $orderIds)
                    ->where('sender_type', 'advertiser')
                    ->where('is_read', false);
                $unreadChat = (clone $unreadQuery)->count();
                $latestUnread = (clone $unreadQuery)->orderByDesc('created_at')->first();
                if ($latestUnread) {
                    $order = Order::find($latestUnread->order_id);
                    if ($order) {
                        $latestUnreadOrder = [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                        ];
                    }
                }

                $publisherItems = OrderItem::whereHas('site', function ($q) use ($user) {
                    $q->where('publisher_id', $user->id);
                })->whereHas('order', function ($q) {
                    $q->whereIn('status', ['pending', 'processing', 'review']);
                });

                $needsAction = (clone $publisherItems)->whereHas('order', function ($q) {
                    $q->where('status', 'pending');
                })->count()
                + (clone $publisherItems)->where('modification_requested', 'yes')->count()
                + (clone $publisherItems)->whereHas('order', function ($q) {
                    $q->where('status', 'processing');
                })->where(function ($q) {
                    $q->whereNull('live_url')->orWhere('live_url', '');
                })->where(function ($q) {
                    $q->whereNull('modification_requested')
                        ->orWhere('modification_requested', '!=', 'yes');
                })->count();
            }

            return response()->json([
                'success' => true,
                'unread_chat' => $unreadChat,
                'needs_action' => $needsAction,
                'latest_unread_order' => $latestUnreadOrder,
                'role' => $activeRole,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching chat unread summary: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'unread_chat' => 0,
                'needs_action' => 0,
            ], 500);
        }
    }

    public function getMessages(Request $request, $orderId)
    {
        try {
            $order = Order::findOrFail($orderId);
            $user = auth()->user();

            if (! $this->userCanAccessOrder($order, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $isAdvertiser = (int) $order->user_id === (int) $user->id;
            $sinceId = $request->integer('since_id') ?: null;
            $beforeId = $request->integer('before_id') ?: null;
            $limit = max(1, min(200, $request->integer('limit', 100) ?: 100));

            $query = OrderChatMessage::where('order_id', $orderId)->with('user');

            if ($sinceId) {
                $messages = $query->where('id', '>', $sinceId)
                    ->orderBy('id', 'asc')
                    ->get();
                $hasMoreOlder = false;
            } else {
                $base = OrderChatMessage::where('order_id', $orderId);
                if ($beforeId) {
                    $base->where('id', '<', $beforeId);
                }
                $totalMatching = (clone $base)->count();
                $messages = $base->with('user')
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->sortBy('id')
                    ->values();
                $hasMoreOlder = $totalMatching > $messages->count();
            }

            // Mark counterpart messages as read when loading (including poll refreshes).
            if ($isAdvertiser) {
                OrderChatMessage::where('order_id', $orderId)
                    ->where('sender_type', 'publisher')
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);
            } else {
                OrderChatMessage::where('order_id', $orderId)
                    ->where('sender_type', 'advertiser')
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);
            }

            $order->loadMissing(['items.site']);
            $details = $this->buildOrderChatDetails($order, $user);

            return response()->json([
                'success' => true,
                'messages' => $messages,
                'has_more_older' => $hasMoreOlder,
                'current_user_id' => $user->id,
                'order_details' => $details,
                'can_send' => $details['can_send'],
                'composer_note' => $details['composer_note'],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching messages: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages',
            ], 500);
        }
    }

    public function sendMessage(Request $request, $orderId)
    {
        try {
            $request->validate([
                'message' => 'required|string|max:5000',
            ]);

            $order = Order::findOrFail($orderId);
            $user = auth()->user();

            $isAdvertiser = (int) $order->user_id === (int) $user->id;
            $isPublisher = $order->items()->whereHas('site', function ($q) use ($user) {
                $q->where('publisher_id', $user->id);
            })->exists();

            if (! $isAdvertiser && ! $isPublisher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            if ($order->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order is cancelled. Chat is closed.',
                    'can_send' => false,
                ], 422);
            }

            $senderType = $isAdvertiser ? 'advertiser' : 'publisher';

            $message = OrderChatMessage::create([
                'order_id' => $orderId,
                'user_id' => $user->id,
                'sender_type' => $senderType,
                'message' => $request->message,
                'is_read' => false,
            ]);
            $message->load('user');

            $receiver = $this->resolveChatReceiver($order, $isAdvertiser);

            if ($receiver?->email) {
                try {
                    Mail::to($receiver->email)->send(new NewChatMessageNotification(
                        $order,
                        $user,
                        (string) $request->message,
                        (string) $receiver->name,
                        (int) $message->id
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Chat email failed: '.$e->getMessage(), [
                        'order_id' => $order->id,
                        'message_id' => $message->id,
                    ]);
                }
            }

            if ($receiver) {
                app(InAppNotificationService::class)->notifyNewChatMessage(
                    $order,
                    $user,
                    $receiver,
                    (string) $request->message
                );
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'current_user_id' => $user->id,
                'can_send' => true,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error sending message: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: '.$e->getMessage(),
            ], 500);
        }
    }

    private function userCanAccessOrder(Order $order, User $user): bool
    {
        if ((int) $order->user_id === (int) $user->id) {
            return true;
        }

        return $order->items()->whereHas('site', function ($q) use ($user) {
            $q->where('publisher_id', $user->id);
        })->exists();
    }

    private function resolveChatReceiver(Order $order, bool $senderIsAdvertiser): ?User
    {
        if ($senderIsAdvertiser) {
            $item = $order->items()->with('site')->first();
            $publisherId = $item?->site?->publisher_id;

            return $publisherId ? User::find($publisherId) : null;
        }

        return User::find($order->user_id);
    }

    /**
     * Compact order/site context shown above the chat thread.
     *
     * @return array<string, mixed>
     */
    private function buildOrderChatDetails(Order $order, ?User $viewer = null): array
    {
        $viewer = $viewer ?: auth()->user();
        $isAdvertiser = $viewer && (int) $order->user_id === (int) $viewer->id;

        $item = null;
        if ($viewer && ! $isAdvertiser) {
            $item = $order->items->first(function ($candidate) use ($viewer) {
                return (int) ($candidate->site?->publisher_id) === (int) $viewer->id;
            });
        }
        $item = $item ?: $order->items->first();
        $site = $item?->site;

        $linkType = $site?->link_type
            ?? ($item ? 'dofollow' : null);
        $dfLinks = $linkType === 'dofollow' ? 1 : ($linkType === 'nofollow' ? 0 : null);

        $startedAt = $order->paid_at ?? $order->created_at;

        $meta = AdvertiserOrderStatus::meta($order, $item);
        $canReview = $isAdvertiser && $order->status === 'review' && filled($item?->live_url);
        $canSend = $order->status !== 'cancelled';
        $composerNote = null;
        if ($order->status === 'cancelled') {
            $composerNote = 'This order is cancelled. Chat is read-only.';
        } elseif ($order->status === 'completed') {
            $composerNote = 'This order is completed. You can still message about this placement.';
        }

        $modificationRequested = $item?->modification_requested === 'yes';
        $canResubmit = ! $isAdvertiser
            && $modificationRequested
            && in_array($order->status, ['processing', 'review'], true)
            && filled($item?->id);

        return [
            'order_id' => $order->id,
            'order_item_id' => $item?->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => $meta['label'],
            'next_action' => $meta['next'],
            'auto_approve_hint' => $meta['auto_approve_hint'],
            'can_approve' => $canReview,
            'can_request_changes' => $canReview,
            'can_resubmit' => $canResubmit,
            'can_send' => $canSend,
            'composer_note' => $composerNote,
            'website_name' => $item?->site_name ?: ($site?->site_name ?: '—'),
            'website_url' => $item?->site_url ?: ($site?->site_url ?: null),
            'order_date' => optional($order->created_at)?->toIso8601String(),
            'started_at' => optional($startedAt)?->toIso8601String(),
            'link_type' => $linkType,
            'df_links' => $dfLinks,
            'sensitive_type' => $item?->sensitive_type,
            'content_link' => $item?->content_link,
            'live_url' => $item?->live_url,
            'live_url_check_ok' => $item?->live_url_check_ok,
            'live_url_http_status' => $item?->live_url_http_status,
            'completion_notes' => $item?->completion_notes,
            'modification_requested' => $item?->modification_requested,
        ];
    }
}
