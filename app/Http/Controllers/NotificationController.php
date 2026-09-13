<?php

namespace App\Http\Controllers;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Services\InAppNotificationService;
use App\Support\AdvertiserOrderDetails;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    public function __construct(private InAppNotificationService $notifications) {}

    /**
     * Full notification inbox page ("Show all").
     */
    public function all(Request $request)
    {
        $user = $request->user();
        $role = $user->activeRole();

        $layout = match ($role) {
            'publisher' => 'publisher.layouts.app',
            'marketing' => 'marketing.layouts.app',
            'admin' => 'admin.layouts.app',
            default => 'advertiser.layouts.app',
        };

        $category = $request->get('category', 'all');
        $status = match ($category) {
            'unread' => 'unread',
            'archived' => 'archived',
            default => $request->get('status', 'inbox'),
        };
        $filterCategory = in_array($category, ['unread', 'archived'], true) ? 'all' : $category;

        $q = search_text($request->get('q'));
        try {
            $paginator = $this->notifications->listForUser($user->id, [
                'status' => $status,
                'category' => $filterCategory,
                'q' => $q,
                'audience' => $role,
            ], 30);
            $unreadCount = $this->notifications->unreadCount($user->id, $role);
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load notifications. Please refresh and try again.')
            );
            $paginator = new LengthAwarePaginator([], 0, 30, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
            $unreadCount = 0;
        }

        return view('notifications.all', [
            'layout' => $layout,
            'notifications' => $paginator,
            'unreadCount' => $unreadCount,
            'filters' => [
                'status' => $status,
                'category' => $category,
                'q' => $q,
            ],
        ]);
    }

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $role = $user->activeRole();
            $paginator = $this->notifications->listForUser($user->id, [
                'status' => $request->get('status', 'active'),
                'category' => $request->get('category', 'all'),
                'q' => search_text($request->get('q')),
                'audience' => $role,
            ], (int) $request->get('per_page', 20));

            $items = collect($paginator->items())->map(fn (InAppNotification $n) => $n->toApiArray())->values();

            return response()->json([
                'success' => true,
                'notifications' => $items,
                'unread_count' => $this->notifications->unreadCount($user->id, $role),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Could not load notifications.',
                'notifications' => [],
                'unread_count' => 0,
            ], 500);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $user = $request->user();
            $count = $this->notifications->unreadCount($user->id, $user->activeRole());

            return response()->json([
                'success' => true,
                'unread_count' => $count,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => true,
                'unread_count' => 0,
            ]);
        }
    }

    public function markRead(Request $request, int $id)
    {
        try {
            InAppNotification::ensureTable();
            $user = $request->user();
            $role = $user->activeRole();
            $notification = InAppNotification::forUser($user->id)->forAudience($role)->findOrFail($id);
            $notification->markRead();

            return response()->json([
                'success' => true,
                'notification' => $notification->fresh()->toApiArray(),
                'unread_count' => $this->notifications->unreadCount($user->id, $role),
            ]);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not update that notification. Please try again.'),
            ], 500);
        }
    }

    public function markUnread(Request $request, int $id)
    {
        try {
            InAppNotification::ensureTable();
            $user = $request->user();
            $role = $user->activeRole();
            $notification = InAppNotification::forUser($user->id)->forAudience($role)->findOrFail($id);
            $notification->markUnread();

            return response()->json([
                'success' => true,
                'notification' => $notification->fresh()->toApiArray(),
                'unread_count' => $this->notifications->unreadCount($user->id, $role),
            ]);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not update that notification. Please try again.'),
            ], 500);
        }
    }

    public function markAllRead(Request $request)
    {
        try {
            InAppNotification::ensureTable();
            $user = $request->user();
            $role = $user->activeRole();
            $updated = $this->notifications->markAllRead($user->id, $role);

            return response()->json([
                'success' => true,
                'updated' => $updated,
                'unread_count' => 0,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not update your notifications. Please try again.'),
            ], 500);
        }
    }

    public function archive(Request $request, int $id)
    {
        try {
            InAppNotification::ensureTable();
            $user = $request->user();
            $role = $user->activeRole();
            $notification = InAppNotification::forUser($user->id)->forAudience($role)->findOrFail($id);
            $notification->archive();

            return response()->json([
                'success' => true,
                'unread_count' => $this->notifications->unreadCount($user->id, $role),
            ]);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not archive that notification. Please try again.'),
            ], 500);
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            InAppNotification::ensureTable();
            $user = $request->user();
            $role = $user->activeRole();
            $notification = InAppNotification::forUser($user->id)->forAudience($role)->findOrFail($id);
            $notification->delete();

            return response()->json([
                'success' => true,
                'unread_count' => $this->notifications->unreadCount($user->id, $role),
            ]);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not delete that notification. Please try again.'),
            ], 500);
        }
    }

    public function orderTimeline(Request $request, int $orderId)
    {
        $user = $request->user();
        $order = Order::with('items')->findOrFail($orderId);
        try {
            $order->loadMissing('items.site');
        } catch (\Throwable) {
            // Leftover Hostinger: sites table missing — advertiser timeline still works.
        }

        $isAdvertiser = (int) $order->user_id === (int) $user->id;
        $isPublisher = $order->items->contains(function ($item) use ($user) {
            $site = $item->relationLoaded('site') ? $item->site : null;

            return $site && (int) $site->publisher_id === (int) $user->id;
        });
        $isStaff = method_exists($user, 'isAdmin') && ($user->isAdmin() || $user->isMarketing());

        if (! $isAdvertiser && ! $isPublisher && ! $isStaff) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($isPublisher && ! $isAdvertiser && ! $isStaff
            && ! AdvertiserOrderDetails::canSendOrderChat($order)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $activities = OrderActivity::where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (OrderActivity $a) => $a->toApiArray())
                ->values();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not load this order timeline. Please try again.'),
            ], 500);
        }

        $reconstructed = $activities->isEmpty();
        if ($reconstructed) {
            $activities = collect(AdvertiserOrderDetails::reconstructedActivities($order))->values();
        }

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'reconstructed' => $reconstructed,
            'activities' => $activities,
        ]);
    }
}
