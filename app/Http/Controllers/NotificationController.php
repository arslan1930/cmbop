<?php

namespace App\Http\Controllers;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private InAppNotificationService $notifications)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $paginator = $this->notifications->listForUser($user->id, [
            'status' => $request->get('status', 'active'),
            'category' => $request->get('category', 'all'),
            'q' => $request->get('q'),
        ], (int) $request->get('per_page', 20));

        $items = collect($paginator->items())->map(fn (InAppNotification $n) => $n->toApiArray())->values();

        return response()->json([
            'success' => true,
            'notifications' => $items,
            'unread_count' => $this->notifications->unreadCount($user->id),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        $count = $this->notifications->unreadCount($request->user()->id);

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    public function markRead(Request $request, int $id)
    {
        $notification = InAppNotification::forUser($request->user()->id)->findOrFail($id);
        $notification->markRead();

        return response()->json([
            'success' => true,
            'notification' => $notification->fresh()->toApiArray(),
            'unread_count' => $this->notifications->unreadCount($request->user()->id),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $updated = $this->notifications->markAllRead($request->user()->id);

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'unread_count' => 0,
        ]);
    }

    public function archive(Request $request, int $id)
    {
        $notification = InAppNotification::forUser($request->user()->id)->findOrFail($id);
        $notification->archive();

        return response()->json([
            'success' => true,
            'unread_count' => $this->notifications->unreadCount($request->user()->id),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $notification = InAppNotification::forUser($request->user()->id)->findOrFail($id);
        $notification->delete();

        return response()->json([
            'success' => true,
            'unread_count' => $this->notifications->unreadCount($request->user()->id),
        ]);
    }

    public function orderTimeline(Request $request, int $orderId)
    {
        $user = $request->user();
        $order = Order::with('items.site')->findOrFail($orderId);

        $isAdvertiser = (int) $order->user_id === (int) $user->id;
        $isPublisher = $order->items->contains(function ($item) use ($user) {
            return $item->site && (int) $item->site->publisher_id === (int) $user->id;
        });
        $isStaff = method_exists($user, 'isAdmin') && ($user->isAdmin() || $user->isMarketing());

        if (!$isAdvertiser && !$isPublisher && !$isStaff) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $activities = OrderActivity::where('order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (OrderActivity $a) => $a->toApiArray())
            ->values();

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'activities' => $activities,
        ]);
    }
}
