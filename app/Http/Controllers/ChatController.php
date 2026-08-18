<?php

namespace App\Http\Controllers;

use App\Mail\NewChatMessageNotification;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use App\Services\OrderChatContactGuard;
use App\Support\AdvertiserOrderStatus;
use App\Support\CatalogVisitUrl;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    /**
     * Unread chat count + "needs action" counts for the active role.
     */
    public function unreadSummary()
    {
        try {
            app(CheckoutSchemaService::class)->ensureCheckoutTables();

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
                    ->where('is_read', false)
                    ->notBlocked();
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
                $needsAction = AdvertiserOrderStatus::needsActionCountForUser((int) $user->id);
            } elseif ($activeRole === 'publisher') {
                $orderIds = Order::where('payment_status', 'paid')
                    ->where('status', '!=', 'cancelled')
                    ->whereHas('items.site', function ($q) use ($user) {
                        $q->where('publisher_id', $user->id);
                    })->pluck('id');
                $unreadQuery = OrderChatMessage::whereIn('order_id', $orderIds)
                    ->where('sender_type', 'advertiser')
                    ->where('is_read', false)
                    ->notBlocked();
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
                    // Match Tasks list: only paid orders appear in My Tasks.
                    $q->where('payment_status', 'paid')
                        ->whereIn('status', ['pending', 'processing', 'review']);
                });

                $needsActionQuery = (clone $publisherItems)->whereHas('order', function ($q) {
                    $q->where('status', 'processing');
                })->where(function ($q) {
                    $q->whereNull('live_url')->orWhere('live_url', '');
                })->where(function ($q) {
                    $q->whereNull('modification_requested')
                        ->orWhere('modification_requested', '!=', 'yes');
                });

                if (Schema::hasColumn('order_items', 'content_revision_requested')) {
                    $needsActionQuery->where(function ($q) {
                        $q->whereNull('content_revision_requested')
                            ->orWhere('content_revision_requested', '!=', 'yes');
                    });
                }

                $needsAction = (clone $publisherItems)->whereHas('order', function ($q) {
                    $q->where('status', 'pending')->notAwaitingScheduledRelease();
                })->count()
                + (clone $publisherItems)->where('modification_requested', 'yes')->count()
                + $needsActionQuery->count();
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

            $baseQuery = OrderChatMessage::where('order_id', $orderId);
            $this->applyVisibleToViewer($baseQuery, $user);

            if ($sinceId) {
                $messages = (clone $baseQuery)
                    ->with('user')
                    ->where('id', '>', $sinceId)
                    ->orderBy('id', 'asc')
                    ->get();
                $hasMoreOlder = false;
            } else {
                $base = clone $baseQuery;
                if ($beforeId) {
                    $base->where('id', '<', $beforeId);
                }
                $totalMatching = (clone $base)->count();
                $messages = (clone $base)->with('user')
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->sortBy('id')
                    ->values();
                $hasMoreOlder = $totalMatching > $messages->count();
            }

            // Mark delivered counterpart messages as read when loading (including poll refreshes).
            if ($isAdvertiser) {
                OrderChatMessage::where('order_id', $orderId)
                    ->where('sender_type', 'publisher')
                    ->notBlocked()
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);
            } else {
                OrderChatMessage::where('order_id', $orderId)
                    ->where('sender_type', 'advertiser')
                    ->notBlocked()
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);
            }

            $order->loadMissing(['items.site']);
            $details = $this->buildOrderChatDetails($order, $user);

            return response()->json([
                'success' => true,
                'messages' => $this->serializeMessages($messages),
                'has_more_older' => $hasMoreOlder,
                'current_user_id' => $user->id,
                'order_details' => $details,
                'can_send' => $details['can_send'],
                'composer_note' => $details['composer_note'],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching messages: '.$e->getMessage(), [
                'order_id' => $orderId,
                'user_id' => auth()->id(),
                'exception' => $e::class,
            ]);

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

            if ($order->status === 'cancelled' || $order->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => $order->status === 'cancelled'
                        ? 'This order is cancelled. Chat is closed.'
                        : 'Chat is available after the order is paid.',
                    'can_send' => false,
                ], 422);
            }

            $senderType = $isAdvertiser ? 'advertiser' : 'publisher';
            $body = (string) $request->message;
            $guard = app(OrderChatContactGuard::class)->inspect($body);
            $isBlocked = (bool) $guard['blocked'];

            $payload = [
                'order_id' => $orderId,
                'user_id' => $user->id,
                'sender_type' => $senderType,
                'message' => $body,
                'is_read' => false,
            ];
            // Contact-guard columns may lag deploy if migration is not applied yet.
            if (OrderChatMessage::hasBlockedColumn()) {
                $payload['is_blocked'] = $isBlocked;
                $payload['blocked_reason'] = $isBlocked ? $guard['reason'] : null;
            } elseif ($isBlocked) {
                // Without moderation columns, refuse contact-share instead of writing invalid SQL.
                return response()->json([
                    'success' => false,
                    'message' => 'This message was blocked because it appears to share contact details. Please keep communication on-platform.',
                    'delivery' => 'blocked',
                ], 422);
            }

            $message = OrderChatMessage::create($payload);
            $message->load('user');

            if (! $isBlocked) {
                foreach ($this->resolveChatReceivers($order, $isAdvertiser) as $receiver) {
                    if ($receiver->email) {
                        try {
                            Mail::to($receiver->email)->send(new NewChatMessageNotification(
                                $order,
                                $user,
                                $body,
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

                    app(InAppNotificationService::class)->notifyNewChatMessage(
                        $order,
                        $user,
                        $receiver,
                        $body
                    );
                }
            }

            return response()->json([
                'success' => true,
                'message' => $this->serializeMessage($message),
                'delivery' => $isBlocked ? 'blocked' : 'delivered',
                'current_user_id' => $user->id,
                'can_send' => true,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error sending message: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to send message. Please try again.'),
            ], 500);
        }
    }

    private function userCanAccessOrder(Order $order, User $user): bool
    {
        if ((int) $order->user_id === (int) $user->id) {
            return true;
        }

        $isPublisher = $order->items()->whereHas('site', function ($q) use ($user) {
            $q->where('publisher_id', $user->id);
        })->exists();

        if (! $isPublisher) {
            return false;
        }

        // Tasks already hide unpaid checkouts. Chat used to leak item ids
        // and content links to the publisher before payment landed.
        return $order->payment_status === 'paid' && $order->status !== 'cancelled';
    }

    /**
     * @return list<User>
     */
    private function resolveChatReceivers(Order $order, bool $senderIsAdvertiser): array
    {
        if ($senderIsAdvertiser) {
            $order->loadMissing('items.site.publisher');
            $publishers = [];
            foreach ($order->items as $item) {
                $publisher = $item->site?->publisher;
                if ($publisher instanceof User) {
                    $publishers[$publisher->id] = $publisher;
                }
            }

            return array_values($publishers);
        }

        $advertiser = User::find($order->user_id);

        return $advertiser instanceof User ? [$advertiser] : [];
    }

    /**
     * Blocked messages stay in history for the sender/admin, but are not shown to the counterpart.
     */
    private function applyVisibleToViewer(Builder $query, User $user): void
    {
        if (! OrderChatMessage::hasBlockedColumn()) {
            return;
        }

        $query->where(function (Builder $inner) use ($user) {
            $inner->where('is_blocked', false)
                ->orWhere('user_id', $user->id);
        });
    }

    /**
     * @param  Collection<int, OrderChatMessage>|iterable<OrderChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function serializeMessages(iterable $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $out[] = $this->serializeMessage($message);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(OrderChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'order_id' => $message->order_id,
            'user_id' => $message->user_id,
            'sender_type' => $message->sender_type,
            'message' => $message->message,
            'images' => $message->images,
            'is_read' => (bool) $message->is_read,
            'is_blocked' => (bool) $message->is_blocked,
            'blocked_reason' => $message->blocked_reason,
            'read_at' => optional($message->read_at)?->toIso8601String(),
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'updated_at' => optional($message->updated_at)?->toIso8601String(),
            'user' => [
                'id' => $message->user?->id ?? $message->user_id,
                'name' => $message->user?->name ?? 'User',
            ],
        ];
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
        $openContentRevision = OrderItem::orderHasOpenContentRevision((int) $order->id);
        $canReview = $isAdvertiser
            && $order->status === 'review'
            && filled($item?->live_url)
            && ! $openContentRevision;
        $canSend = $order->status !== 'cancelled' && $order->payment_status === 'paid';
        $composerNote = null;
        if ($order->status === 'cancelled') {
            $composerNote = 'This order is cancelled. Chat is read-only.';
        } elseif ($order->payment_status !== 'paid') {
            $composerNote = 'Chat is available after the order is paid.';
        } elseif ($order->status === 'completed') {
            $composerNote = 'This order is completed. You can still message about this placement.';
        }

        $modificationRequested = $item?->modification_requested === 'yes';
        $canResubmit = ! $isAdvertiser
            && $modificationRequested
            && in_array($order->status, ['processing', 'review'], true)
            && filled($item?->id)
            && ! ($item?->isContentRevisionRequested());

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
            'visit_url' => CatalogVisitUrl::forSiteId($item?->site_id ?: $site?->id),
            'order_date' => optional($order->created_at)?->toIso8601String(),
            'started_at' => optional($startedAt)?->toIso8601String(),
            'link_type' => $linkType,
            'df_links' => $dfLinks,
            'sensitive_type' => $item?->sensitive_type,
            'homepage_days' => $item?->homepage_days !== null ? (int) $item->homepage_days : null,
            'homepage_price' => (float) ($item?->homepage_price ?? 0),
            'social_channels' => $item ? $item->enabledSocialChannels() : [],
            'social_post_urls' => $item ? $item->socialPostUrls() : [],
            'content_link' => $item?->publisherContentLink(),
            'live_url' => (($safeLive = safe_external_url($item?->live_url, '')) !== '') ? $safeLive : null,
            'live_url_check_ok' => $item?->live_url_check_ok,
            'live_url_http_status' => $item?->live_url_http_status,
            'completion_notes' => $item?->completion_notes,
            'modification_requested' => $item?->modification_requested,
            'content_revision_requested' => $item?->content_revision_requested,
            'has_open_content_revision' => $openContentRevision,
        ];
    }
}
