<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\StaffCapability;
use App\Models\User;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\Orders\AdminOrderStatusOverride;
use App\Services\Orders\AdminPaymentStatusPolicy;
use App\Services\Orders\OrderClawbackService;
use App\Support\ArticleDownload;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        try {
            return $this->ordersData($request);
        } catch (\Throwable $e) {
            Log::error('Error fetching admin orders data: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load orders. Please try again.'),
            ], 500);
        }
    }

    private function ordersData(Request $request)
    {
        $query = Order::with(['user', 'items.site.publisher'])
            ->orderByDesc('created_at');

        if (OrderItemDispute::tableAvailable()) {
            $query->withCount([
                'disputes as open_disputes_count' => fn ($q) => $q->where('status', OrderItemDispute::STATUS_OPEN),
            ]);
        }

        $search = search_text($request->input('search'));
        if ($search !== '') {
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
        $status = search_text($request->input('status'));
        if ($status === 'scheduled') {
            $query->awaitingScheduledRelease();
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        // "unpaid" is the ops queue (not paid/refunded + still open), not an enum value.
        $paymentStatus = search_text($request->input('payment_status'));
        if ($paymentStatus === 'unpaid') {
            $query->unpaidOps();
        } elseif ($paymentStatus !== '') {
            $query->where('payment_status', $paymentStatus);
        }

        if ($request->input('dispute') === 'open' && OrderItemDispute::tableAvailable()) {
            $query->whereHas('disputes', fn ($q) => $q->where('status', OrderItemDispute::STATUS_OPEN));
        }

        $dateFrom = search_text($request->input('date_from'));
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        $dateTo = search_text($request->input('date_to'));
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));
        $orders = $query->paginate($perPage);
        $invoiceLinks = app(AdminInvoiceLinks::class);
        $invoicesByOrder = $invoiceLinks->forOrders($orders->getCollection());

        $data = $orders->getCollection()->map(function (Order $order) use ($invoiceLinks, $invoicesByOrder) {
            $item = $order->items->first();
            $site = $item?->site;
            $publisher = $site?->publisher;
            $liveUrl = $order->items->first(fn (OrderItem $line) => filled($line->live_url))?->live_url;
            $documents = $invoicesByOrder->get((int) $order->id, []);
            $primary = $invoiceLinks->primary($documents);

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
                'invoices' => $documents,
                'invoice_url' => data_get($primary, 'url'),
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
        try {
            return $this->renderShow($id);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.orders.index')
                ->with('error', UserFacingError::message($e, 'We could not load that order. Please try again.'));
        }
    }

    private function renderShow($id)
    {
        // Heal a skipped migration before reading, so disputes come back rather
        // than staying invisible on the screen built to manage them.
        OrderItemDispute::ensureTable();

        $order = Order::with($this->showRelations())->findOrFail($id);
        $this->hydrateMissingShowRelations($order);

        $activities = collect();
        if ($this->tableReady('order_activities')) {
            $activities = OrderActivity::where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (OrderActivity $a) => $a->toApiArray())
                ->values();
        }

        $clawbacks = app(OrderClawbackService::class);
        $disputes = OrderItemDispute::tableAvailable()
            ? $order->items
                ->flatMap(fn (OrderItem $line) => $line->disputes ?? collect())
                ->sortByDesc('id')
                ->values()
            : collect();
        $openDispute = $disputes->first(fn (OrderItemDispute $d) => $d->isOpen());
        $disputableItems = $order->items
            ->filter(fn (OrderItem $line) => $clawbacks->canOpenDispute($order, $line, asAdmin: true))
            ->values();

        $override = app(AdminOrderStatusOverride::class);
        $paymentPolicy = app(AdminPaymentStatusPolicy::class);
        $paymentMethod = (string) ($order->payment_method ?? '');
        $actor = auth()->user();
        $canRefundInFlight = $paymentPolicy->canRefundInFlight($order)
            && $actor instanceof User
            && $actor->staffCan(StaffCapability::FINANCE);
        $canFailInFlight = $paymentPolicy->canFailInFlight($order)
            && $actor instanceof User
            && $actor->staffCan(StaffCapability::FINANCE);
        $canOpenDispute = $disputableItems->isNotEmpty()
            && $actor instanceof User
            && $actor->staffCan(StaffCapability::SUPPORT);
        $canUpholdDispute = $actor instanceof User && $actor->staffCan(StaffCapability::FINANCE);
        $canDismissDispute = $actor instanceof User && $actor->staffCan(StaffCapability::SUPPORT);

        return view('admin.orders.show', [
            'order' => $order,
            'activities' => $activities,
            'messages' => $order->chatMessages,
            'disputes' => $disputes,
            'openDispute' => $openDispute,
            'disputableItems' => $disputableItems,
            'canOpenDispute' => $canOpenDispute,
            'canUpholdDispute' => $canUpholdDispute,
            'canDismissDispute' => $canDismissDispute,
            'statusTargets' => $override->availableFor($order),
            'canOverrideStatus' => $override->isOverridable($order),
            'canRefundInFlight' => $canRefundInFlight,
            'canFailInFlight' => $canFailInFlight,
            'needsDisputeClawback' => $paymentPolicy->needsDisputeClawback($order),
            'refundHint' => $canRefundInFlight
                ? $paymentPolicy->moneyHint('refunded', $paymentMethod, (string) $order->payment_status)
                : '',
            'failHint' => $canFailInFlight
                ? $paymentPolicy->moneyHint('failed', $paymentMethod, (string) $order->payment_status)
                : '',
            'paymentUpdateUrl' => route('admin.payments.updateStatus', $order->id),
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
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', UserFacingError::message($e, 'We could not update that order status. Please try again.'));
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

            return $disk->download($path, $filename, ArticleDownload::headers($filename, $mime));
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

        return $user->adminShowUrl();
    }

    /**
     * @return list<string|\Closure>
     */
    private function showRelations(): array
    {
        $relations = [
            'user',
            'items.site.publisher',
        ];

        if ($this->tableReady('content_submissions')) {
            $relations[] = 'items.contentSubmission';
        }
        if ($this->tableReady('order_chat_messages')) {
            $relations[] = 'chatMessages.user';
        }
        if ($this->tableReady('invoices')) {
            $relations['invoices'] = fn ($q) => $q->latest('id');
        }

        return array_merge($relations, OrderItemDispute::eagerPaths([
            'items.disputes.opener',
            'items.disputes.resolver',
        ]));
    }

    private function hydrateMissingShowRelations(Order $order): void
    {
        if (! $this->tableReady('invoices')) {
            $order->setRelation('invoices', collect());
        }
        if (! $this->tableReady('order_chat_messages')) {
            $order->setRelation('chatMessages', collect());
        }
        if (! $this->tableReady('content_submissions')) {
            foreach ($order->items as $item) {
                $item->setRelation('contentSubmission', null);
            }
        }
    }

    private function tableReady(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
