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
use App\Support\PublisherNeedsAction;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            app(CheckoutSchemaService::class)->ensureCheckoutTables();

            $user = auth()->user();
            try {
                $activeRole = $user->activeRole()
                    ?? optional($user->roles()->first())->name;
            } catch (\Throwable $e) {
                $activeRole = null;
            }

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
                try {
                    $orderIds = AdvertiserOrderStatus::itemsTableAvailable()
                        ? Order::where('payment_status', 'paid')
                            ->where('status', '!=', 'cancelled')
                            ->whereHas('items.site', function ($q) use ($user) {
                                $q->where('publisher_id', $user->id);
                            })->pluck('id')
                        : collect();
                    $needsAction = AdvertiserOrderStatus::itemsTableAvailable()
                        ? PublisherNeedsAction::needsYouCount((int) $user->id)
                        : 0;
                } catch (\Throwable $e) {
                    $orderIds = collect();
                    $needsAction = 0;
                }
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
            }

            return response()->json([
                'success' => true,
                'unread_chat' => $unreadChat,
                'needs_action' => $needsAction,
                'latest_unread_order' => $latestUnreadOrder,
                'role' => $activeRole,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'unread_chat' => 0,
                'needs_action' => 0,
                'message' => UserFacingError::message($e, 'Failed to load chat summary.'),
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
                $messages = $this->loadChatMessages(
                    (clone $baseQuery)->where('id', '>', $sinceId)->orderBy('id', 'asc')
                );
                $hasMoreOlder = false;
            } else {
                $base = clone $baseQuery;
                if ($beforeId) {
                    $base->where('id', '<', $beforeId);
                }
                $totalMatching = (clone $base)->count();
                $messages = $this->loadChatMessages(
                    (clone $base)->orderByDesc('id')->limit($limit)
                )->sortBy('id')->values();
                $hasMoreOlder = $totalMatching > $messages->count();
            }

            // Mark delivered counterpart messages as read when loading (including poll refreshes).
            try {
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
            } catch (\Throwable $e) {
                // Leftover is_read / read_at must not hide the thread.
            }

            try {
                $order->loadMissing(['user']);
            } catch (\Throwable $e) {
                // Leftover users table must not hide the thread.
            }
            $this->loadOrderItemsForChat($order);
            $details = $this->buildOrderChatDetails($order, $user);

            return response()->json([
                'success' => true,
                'messages' => $this->serializeMessages($messages),
                'has_more_older' => $hasMoreOlder,
                'own_read_ids' => $this->ownReadMessageIds((int) $orderId, (int) $user->id),
                'current_user_id' => $user->id,
                'order_details' => $details,
                'can_send' => $details['can_send'],
                'composer_note' => $details['composer_note'],
            ]);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch messages.'),
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

            if (! $this->userCanAccessOrder($order, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $isAdvertiser = (int) $order->user_id === (int) $user->id;

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
            try {
                $message->load('user');
            } catch (\Throwable $e) {
                // Serialize still has user_id; leftover users must not fail the send.
            }

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

                    try {
                        app(InAppNotificationService::class)->notifyNewChatMessage(
                            $order,
                            $user,
                            $receiver,
                            $body
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Chat notification failed: '.$e->getMessage(), [
                            'order_id' => $order->id,
                            'message_id' => $message->id,
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $this->serializeMessage($message),
                'delivery' => $isBlocked ? 'blocked' : 'delivered',
                'current_user_id' => $user->id,
                'can_send' => true,
            ]);
        } catch (ValidationException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

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

        try {
            $isPublisher = $order->items()->whereHas('site', function ($q) use ($user) {
                $q->where('publisher_id', $user->id);
            })->exists();
        } catch (\Throwable $e) {
            return false;
        }

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
            if (! AdvertiserOrderStatus::itemsTableAvailable()) {
                return [];
            }

            try {
                $order->loadMissing('items.site.publisher');
            } catch (\Throwable $e) {
                return [];
            }

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
     * @return list<int>
     */
    private function ownReadMessageIds(int $orderId, int $userId): array
    {
        try {
            return OrderChatMessage::query()
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->where('is_read', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return Collection<int, OrderChatMessage>
     */
    private function loadChatMessages(Builder $query): Collection
    {
        try {
            return (clone $query)->with('user')->get();
        } catch (\Throwable $e) {
            return (clone $query)->get();
        }
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
        $userId = $message->user_id;
        $userName = 'User';
        try {
            $userId = $message->user?->id ?? $message->user_id;
            $userName = $message->user?->name ?? 'User';
        } catch (\Throwable $e) {
            // Leftover users must not hide a delivered message.
        }

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
                'id' => $userId,
                'name' => $userName,
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

        $item = $this->resolveChatPlacement($order, $viewer, $isAdvertiser);
        $site = null;
        if ($item) {
            try {
                $site = $item->site;
            } catch (\Throwable $e) {
                $item->setRelation('site', null);
            }
        }

        $linkType = $site?->link_type
            ?? ($item ? 'dofollow' : null);
        $dfLinks = $linkType === 'dofollow' ? 1 : ($linkType === 'nofollow' ? 0 : null);

        $startedAt = $order->paid_at ?? $order->created_at;

        $meta = AdvertiserOrderStatus::meta($order, $item);
        try {
            $openContentRevision = OrderItem::orderHasOpenContentRevision((int) $order->id);
        } catch (\Throwable $e) {
            $openContentRevision = false;
        }
        $liveUrl = safe_href_url($item?->live_url);
        $contentLink = safe_href_url($item?->publisherContentLink());
        $canReview = $isAdvertiser
            && $order->status === 'review'
            && filled($liveUrl)
            && ! $openContentRevision;
        $canSend = $order->status !== 'cancelled' && $order->payment_status === 'paid';
        $composerNote = $this->chatComposerNote($order, $item, $isAdvertiser, $liveUrl);

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
            'can_view_order' => (bool) $isAdvertiser,
            'has_placement' => $item instanceof OrderItem,
            'details_missing' => ! $item,
            'composer_note' => $composerNote,
            'website_name' => $item
                ? ($item->site_name ?: ($site?->site_name ?: 'Placement details'))
                : 'Placement details are missing for this order.',
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
            'social_post_urls' => collect($item ? $item->socialPostUrls() : [])
                ->map(fn ($url) => safe_href_url($url))
                ->filter()
                ->all(),
            'content_link' => $contentLink,
            'live_url' => $liveUrl,
            'live_url_check_ok' => $item?->live_url_check_ok,
            'live_url_http_status' => $item?->live_url_http_status,
            'completion_notes' => $item?->completion_notes,
            'modification_requested' => $item?->modification_requested,
            'content_revision_requested' => $item?->content_revision_requested,
            'has_open_content_revision' => $openContentRevision,
            'counterpart' => $this->chatCounterpart($order, $isAdvertiser, $site, $viewer),
        ];
    }

    /**
     * Presence for the other party on this order chat. Never the viewer.
     *
     * @return array{name: string, role: string, online: bool, last_seen_at: ?string, label: ?string}|null
     */
    private function chatCounterpart(Order $order, bool $isAdvertiser, $site, ?User $viewer): ?array
    {
        if ($isAdvertiser) {
            $other = $site?->publisher;
            if (! $other) {
                try {
                    foreach ($order->items as $item) {
                        try {
                            $candidate = $item->site?->publisher;
                        } catch (\Throwable $e) {
                            $candidate = null;
                        }
                        if ($candidate) {
                            $other = $candidate;
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    $other = null;
                }
            }
            $role = 'publisher';
        } else {
            $other = $order->user ?? User::query()->find($order->user_id);
            $role = 'advertiser';
        }

        if (! $other || ($viewer && (int) $other->id === (int) $viewer->id)) {
            return null;
        }

        try {
            $presence = $other->presencePayload();
        } catch (\Throwable $e) {
            $presence = [
                'online' => false,
                'last_seen_at' => null,
                'label' => null,
            ];
        }

        return [
            'name' => (string) $other->name,
            'role' => $role,
            'online' => $presence['online'],
            'last_seen_at' => $presence['last_seen_at'],
            'label' => $presence['label'],
        ];
    }

    private function loadOrderItemsForChat(Order $order): void
    {
        if (! AdvertiserOrderStatus::itemsTableAvailable()) {
            $order->setRelation('items', collect());

            return;
        }

        try {
            $order->loadMissing(['items.site.publisher']);
        } catch (\Throwable $e) {
            try {
                $order->unsetRelation('items');
                $order->loadMissing(['items.site']);
            } catch (\Throwable $siteGone) {
                try {
                    $order->unsetRelation('items');
                    $order->loadMissing(['items']);
                    foreach ($order->items as $loaded) {
                        if ($loaded instanceof OrderItem) {
                            $loaded->setRelation('site', null);
                        }
                    }
                } catch (\Throwable $inner) {
                    $order->setRelation('items', collect());
                }
            }
        }
    }

    private function resolveChatPlacement(Order $order, ?User $viewer, bool $isAdvertiser): ?OrderItem
    {
        try {
            $items = $order->items;
        } catch (\Throwable $e) {
            $order->setRelation('items', collect());

            return null;
        }

        $item = null;
        if ($viewer && ! $isAdvertiser) {
            $item = $items->first(function ($candidate) use ($viewer) {
                return $candidate instanceof OrderItem
                    && (int) ($candidate->site?->publisher_id) === (int) $viewer->id;
            });
        }
        $item = $item ?: $items->first();

        return $item instanceof OrderItem ? $item : null;
    }

    private function chatComposerNote(Order $order, ?OrderItem $item, bool $isAdvertiser, ?string $liveUrl): ?string
    {
        if ($order->status === 'cancelled') {
            return 'This order is cancelled. Chat is read-only.';
        }

        if ($order->payment_status !== 'paid') {
            return 'Chat is available after the order is paid.';
        }

        if ($order->status !== 'completed') {
            return null;
        }

        if (! $item) {
            return 'Placement details are missing for this order. You can still send a message.';
        }

        if (filled($liveUrl)) {
            return 'This order is completed. You can still message about the live post.';
        }

        return $isAdvertiser
            ? 'This order is completed. You can still message the publisher.'
            : 'This order is completed. You can still message the advertiser.';
    }
}
