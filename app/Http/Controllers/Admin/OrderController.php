<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\User;
use App\Services\Orders\AdminOrderStatusOverride;
use App\Services\Orders\OrderClawbackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderController extends Controller
{
    public function index()
    {
        return view('admin.orders.index');
    }

    public function data(Request $request)
    {
        $query = Order::with(['user', 'items.site.publisher'])
            ->orderByDesc('created_at');

        if (OrderItemDispute::tableAvailable()) {
            $query->withCount([
                'disputes as open_disputes_count' => fn ($q) => $q->where('status', OrderItemDispute::STATUS_OPEN),
            ]);
        }

        if ($request->filled('search')) {
            $search = trim(scalar_text($request->input('search')));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items', function ($sub) use ($search) {
                        $sub->where('site_name', 'like', "%{$search}%")
                            ->orWhere('site_url', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items.site.publisher', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // "scheduled" is publication_mode (status stays pending). The status
        // column value is leftover and would miss live scheduled rows.
        if ($request->input('status') === 'scheduled') {
            $query->awaitingScheduledRelease();
        } elseif ($request->filled('status')) {
            $query->where('status', scalar_text($request->input('status')));
        }

        // "unpaid" is the ops queue (not paid/refunded + still open), not an enum value.
        $paymentStatus = scalar_text($request->input('payment_status'));
        if ($paymentStatus === 'unpaid') {
            $query->unpaidOps();
        } elseif ($paymentStatus !== '') {
            $query->where('payment_status', $paymentStatus);
        }

        if ($request->input('dispute') === 'open' && OrderItemDispute::tableAvailable()) {
            $query->whereHas('disputes', fn ($q) => $q->where('status', OrderItemDispute::STATUS_OPEN));
        }

        $dateFrom = scalar_text($request->input('date_from'));
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        $dateTo = scalar_text($request->input('date_to'));
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));
        $orders = $query->paginate($perPage);

        $data = $orders->getCollection()->map(function (Order $order) {
            $item = $order->items->first();
            $site = $item?->site;
            $publisher = $site?->publisher;
            $liveUrl = $order->items->first(fn (OrderItem $line) => filled($line->live_url))?->live_url;

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'reference_code' => $order->reference_code,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'total_amount' => (float) $order->total_amount,
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'created_at_human' => optional($order->created_at)?->format('M j, Y g:i A'),
                'advertiser' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                    'url' => $this->adminUserUrl($order->user),
                ] : null,
                'site_name' => $item?->site_name ?: ($site?->site_name),
                'site_admin_url' => $site ? route('admin.sites.edit', $site->id) : null,
                'publisher_name' => $publisher?->name,
                'publisher' => $publisher ? [
                    'id' => $publisher->id,
                    'name' => $publisher->name,
                    'url' => $this->adminUserUrl($publisher),
                ] : null,
                'live_url' => $liveUrl,
                'has_open_dispute' => OrderItemDispute::tableAvailable()
                    && (int) ($order->open_disputes_count ?? 0) > 0,
                'has_live_url' => filled($liveUrl),
                'is_scheduled' => $order->isAwaitingScheduledRelease(),
                'scheduled_publish_at' => optional($order->scheduled_publish_at)?->toIso8601String(),
                'scheduled_publish_at_human' => $this->scheduledPublishAtHuman($order),
                'modification_requested' => $item?->modification_requested,
                'url' => route('admin.orders.show', $order->id),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show($id)
    {
        // Heal a skipped migration before reading, so disputes come back rather
        // than staying invisible on the screen built to manage them.
        OrderItemDispute::ensureTable();

        $order = Order::with(array_merge([
            'user',
            'items.site.publisher',
            'items.contentSubmission',
            'chatMessages.user',
        ], OrderItemDispute::eagerPaths([
            'items.disputes.opener',
            'items.disputes.resolver',
        ])))->findOrFail($id);

        $activities = OrderActivity::where('order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (OrderActivity $a) => $a->toApiArray())
            ->values();

        $item = $order->items->first();
        $disputes = $item && OrderItemDispute::tableAvailable()
            ? $item->disputes->sortByDesc('id')->values()
            : collect();
        $openDispute = $disputes->first(fn (OrderItemDispute $d) => $d->isOpen());
        $canOpenDispute = app(OrderClawbackService::class)->canOpenDispute($order, $item, asAdmin: true);

        $override = app(AdminOrderStatusOverride::class);

        return view('admin.orders.show', [
            'order' => $order,
            'activities' => $activities,
            'messages' => $order->chatMessages,
            'disputes' => $disputes,
            'openDispute' => $openDispute,
            'canOpenDispute' => $canOpenDispute,
            'statusTargets' => $override->availableFor($order),
            'canOverrideStatus' => $override->isOverridable($order),
        ]);
    }

    /**
     * Move a running order between stages when it is stuck on the wrong one.
     * Settling an order stays with approval and refunds — see the service.
     */
    public function updateStatus(Request $request, $id, AdminOrderStatusOverride $override)
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $order = Order::with('items')->findOrFail($id);

        try {
            $override->apply($order, $data['status'], $request->user(), $data['reason']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Order '.$order->order_number.' moved to '.$data['status'].'.');
    }

    /**
     * Download the article file for this placement (submission, else item snapshot).
     */
    public function downloadContent(OrderItem $orderItem): StreamedResponse
    {
        $orderItem->loadMissing('contentSubmission');
        $submission = $orderItem->contentSubmission;

        if ($submission && $submission->hasStoredFile()) {
            $download = $this->downloadFromDisk(
                $submission->disk ?: 'local',
                $submission->path,
                $submission->original_filename ?: 'article',
                $submission->mime ?: 'application/octet-stream',
                $orderItem,
            );
            if ($download) {
                return $download;
            }
        }

        if (filled($orderItem->content_path)) {
            $download = $this->downloadFromDisk(
                $orderItem->content_disk ?: 'local',
                $orderItem->content_path,
                $orderItem->content_original_name ?: 'article',
                $orderItem->content_mime ?: 'application/octet-stream',
                $orderItem,
            );
            if ($download) {
                return $download;
            }
        }

        abort(404, 'Content file not found.');
    }

    private function downloadFromDisk(
        string $diskName,
        string $path,
        string $filename,
        string $mime,
        OrderItem $orderItem,
    ): ?StreamedResponse {
        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                return null;
            }

            return $disk->download($path, $filename, ['Content-Type' => $mime]);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Admin content download failed', [
                'order_item_id' => $orderItem->id,
                'disk' => $diskName,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            abort(404, 'Content file not found.');
        }
    }

    private function scheduledPublishAtHuman(Order $order): ?string
    {
        $local = $order->scheduledPublishAtInScheduleTimezone();
        if (! $local) {
            return null;
        }

        return $local->format('M j, Y g:i A').' '.$order->scheduleTimezoneOrUtc();
    }

    private function adminUserUrl(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return route('admin.users.index', ['user' => $user->id]).'#user-'.$user->id;
    }
}
