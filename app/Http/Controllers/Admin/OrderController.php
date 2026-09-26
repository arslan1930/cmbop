<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\Billing\InvoiceRepairQueue;
use App\Services\ContentUpload\ContentUploadService;
use App\Services\Orders\AdminOrderStatusOverride;
use App\Services\Orders\OrderClawbackService;
use App\Services\Reminders\StalledOrderQueue;
use App\Support\ArticleDownload;
use App\Support\UserFacingError;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderController extends Controller
{
    private const EXPORT_LIMIT = 5000;

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

    public function export(Request $request): StreamedResponse
    {
        $dateError = null;
        $matchCount = 0;
        try {
            $query = $this->applyOrderSort($this->ordersFilterQuery($request, $dateError), $request);
            $matchCount = (clone $query)->count();
            $rows = $query
                ->with(['user:id,name,email', 'items.site'])
                ->limit(self::EXPORT_LIMIT)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Admin orders export query failed', ['error' => $e->getMessage()]);
            $rows = collect();
            $matchCount = 0;
        }

        ActivityLogger::tryLog(
            'order.exported',
            ($request->user()?->name ?? 'Admin').' exported marketplace orders ('.$rows->count().' row(s)).',
            null,
            [
                'rows_exported' => $rows->count(),
                'truncated' => $matchCount > self::EXPORT_LIMIT,
            ]
        );

        $filename = 'marketplace-orders-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'order_number',
                'reference_code',
                'advertiser',
                'advertiser_email',
                'placements',
                'status',
                'payment_status',
                'payment_method',
                'total_eur',
                'charge_currency',
                'charge_amount',
                'created_at',
                'paid_at',
                'completed_at',
                'stripe_session_id',
                'stripe_payment_intent_id',
                'paypal_order_id',
                'paypal_capture_id',
            ]);
            foreach ($rows as $order) {
                $sites = $order->items
                    ->sortBy('id')
                    ->map(fn (OrderItem $line) => $line->site_name ?: $line->site?->site_name)
                    ->filter()
                    ->implode('; ');
                fputcsv($out, [
                    $this->csvCell($order->order_number),
                    $this->csvCell($order->reference_code),
                    $this->csvCell($order->user?->name),
                    $this->csvCell($order->user?->email),
                    $this->csvCell($sites),
                    $this->csvCell($order->status),
                    $this->csvCell($order->payment_status),
                    $this->csvCell($this->paymentMethodLabel((string) $order->payment_method)),
                    number_format((float) $order->total_amount, 2, '.', ''),
                    $this->csvCell($order->charge_currency),
                    $order->charge_amount !== null ? number_format((float) $order->charge_amount, 2, '.', '') : '',
                    optional($order->created_at)->toDateTimeString(),
                    optional($order->paid_at)->toDateTimeString(),
                    optional($order->completed_at)->toDateTimeString(),
                    $this->csvCell($order->stripe_session_id),
                    $this->csvCell($order->stripe_payment_intent_id),
                    $this->csvCell($order->paypal_order_id),
                    $this->csvCell($order->paypal_capture_id),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function ordersData(Request $request)
    {
        OrderItemDispute::ensureTable();
        Invoice::ensureOrderLinkColumn();

        $dateError = null;
        $filtered = $this->ordersFilterQuery($request, $dateError);
        $totals = $this->orderFilterTotals(clone $filtered);

        $query = $this->applyOrderSort(clone $filtered, $request)
            ->with(['user', 'items.site.publisher']);

        if (OrderItemDispute::tableAvailable()
            && Schema::hasColumn('order_item_disputes', 'order_id')
            && Schema::hasColumn('order_item_disputes', 'status')) {
            $query->withCount([
                'disputes as open_disputes_count' => fn ($q) => $q->where('status', OrderItemDispute::STATUS_OPEN),
            ]);
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));
        $orders = $query->paginate($perPage);
        $invoiceLinks = app(AdminInvoiceLinks::class);
        $invoicesByOrder = $invoiceLinks->forOrders($orders->getCollection());
        $missingTaxIds = $this->missingTaxOrderIds($orders->getCollection()->pluck('id')->all());

        $data = $orders->getCollection()->map(function (Order $order) use ($invoiceLinks, $invoicesByOrder, $missingTaxIds) {
            $lines = $order->items->sortBy('id')->values();
            $item = $lines->first();
            $site = $item?->site;
            $publisher = $site?->publisher;
            $liveUrl = $lines->first(fn (OrderItem $line) => filled($line->live_url))?->live_url;
            $documents = $invoicesByOrder->get((int) $order->id, []);
            $primary = $invoiceLinks->primary($documents);
            $placementCount = $lines->count();
            $stages = $lines
                ->map(fn (OrderItem $line) => strtolower(trim((string) ($line->publisher_status ?? ''))))
                ->unique()
                ->values();
            $missingTax = in_array((int) $order->id, $missingTaxIds, true);
            [$chargeCurrency, $chargeAmount] = $this->chargeBesideEuro($order);

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'reference_code' => $order->reference_code,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'method_label' => $this->paymentMethodLabel((string) $order->payment_method),
                'total_amount' => (float) $order->total_amount,
                'charge_currency' => $chargeCurrency,
                'charge_amount' => $chargeAmount,
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'created_at_human' => optional($order->created_at)?->format('M j, Y g:i A'),
                'advertiser' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                    'url' => $this->adminUserUrl($order->user),
                    'dossier_url' => $this->financeDossierUrl($order->user),
                ] : null,
                'site_name' => $item?->site_name ?: ($site?->site_name),
                'site_admin_url' => $site ? route('admin.sites.edit', $site->id) : null,
                'placement_count' => $placementCount,
                'more_placements' => max(0, $placementCount - 1),
                'stages_differ' => $stages->count() > 1,
                'publisher_name' => $publisher?->name,
                'publisher' => $publisher ? [
                    'id' => $publisher->id,
                    'name' => $publisher->name,
                    'url' => $this->adminUserUrl($publisher),
                    'dossier_url' => $this->financeDossierUrl($publisher),
                ] : null,
                'live_url' => $liveUrl,
                'has_open_dispute' => OrderItemDispute::tableAvailable()
                    && (int) ($order->open_disputes_count ?? 0) > 0,
                'has_live_url' => filled($liveUrl),
                'is_scheduled' => $order->isAwaitingScheduledRelease(),
                'scheduled_publish_at' => optional($order->scheduled_publish_at)?->toIso8601String(),
                'scheduled_publish_at_human' => $this->scheduledPublishAtHuman($order),
                'modification_requested' => $lines->contains(fn (OrderItem $line) => $line->modification_requested === 'yes'),
                'missing_tax' => $missingTax,
                'missing_tax_url' => $missingTax ? route('admin.invoices.index', [
                    'queue' => 'missing',
                    'search' => (string) ($order->order_number ?: $order->id),
                ]) : null,
                'url' => route('admin.orders.show', $order->id),
                'invoices' => $documents,
                'invoice_url' => data_get($primary, 'url'),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'date_error' => $dateError,
            'totals' => $totals,
            'export_limited' => ($totals['count'] ?? 0) > self::EXPORT_LIMIT,
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * @return Builder<Order>
     */
    private function ordersFilterQuery(Request $request, ?string &$dateError = null): Builder
    {
        $query = Order::query();
        $this->constrainOrderSearch($query, search_text($request->input('search')));

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

        $paymentMethod = search_text($request->input('payment_method'));
        if ($paymentMethod === 'card') {
            $query->whereIn('payment_method', ['card', 'stripe']);
        } elseif ($paymentMethod === 'bank') {
            $query->whereIn('payment_method', ['bank', 'bank_transfer']);
        } elseif ($paymentMethod !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        if ($request->input('dispute') === 'open' && OrderItemDispute::tableAvailable()) {
            $query->whereHas('disputes', fn ($q) => $q->where('status', OrderItemDispute::STATUS_OPEN));
        }

        if ($request->input('stalled') === '1') {
            app(StalledOrderQueue::class)->constrainStalledOrders($query);
        }

        if ($request->input('modification') === 'yes') {
            if (Schema::hasColumn('order_items', 'modification_requested')) {
                $query->whereHas('items', fn ($item) => $item->where('modification_requested', 'yes'));
            } else {
                $query->whereRaw('0 = 1');
            }
        }

        $fromRaw = search_text($request->input('date_from'));
        $toRaw = search_text($request->input('date_to'));
        $fromOk = $fromRaw === '' || $this->isOrderDay($fromRaw);
        $toOk = $toRaw === '' || $this->isOrderDay($toRaw);
        if (! $fromOk || ! $toOk) {
            $dateError = 'Enter real dates.';
        } elseif ($fromRaw !== '' && $toRaw !== '' && $toRaw < $fromRaw) {
            $dateError = 'The to date must be on or after the from date.';
        } else {
            $dateField = search_text($request->input('date_field'));
            if (! in_array($dateField, ['paid_at', 'completed_at'], true) || ! $this->ordersHaveColumn($dateField)) {
                $dateField = 'created_at';
            }
            if ($fromRaw !== '') {
                $query->whereDate($dateField, '>=', $fromRaw);
            }
            if ($toRaw !== '') {
                $query->whereDate($dateField, '<=', $toRaw);
            }
        }

        return $query;
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
        $canOpenDispute = $disputableItems->isNotEmpty();

        $override = app(AdminOrderStatusOverride::class);

        return view('admin.orders.show', [
            'order' => $order,
            'activities' => $activities,
            'messages' => $order->chatMessages,
            'disputes' => $disputes,
            'openDispute' => $openDispute,
            'disputableItems' => $disputableItems,
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
        $path = $this->safeContentDownloadPath($diskName, $path);
        if ($path === null) {
            return null;
        }

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

    /**
     * Stored article paths may only be the content-upload disk and directory.
     * Reject traversal, encoded dots, and NUL before the disk is touched.
     */
    private function safeContentDownloadPath(string $diskName, string $path): ?string
    {
        if ($diskName === '' || ! in_array($diskName, $this->allowedContentDownloadDisks(), true)) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, '%')
            || str_contains($path, "\0")
        ) {
            return null;
        }

        foreach ($this->allowedContentDownloadDirectories() as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function allowedContentDownloadDisks(): array
    {
        $disks = [(string) (config('content_upload.disk') ?: 'local')];
        $configured = $this->effectiveContentUploadConfig()['disk'] ?? null;
        if (is_string($configured) && $configured !== '') {
            $disks[] = $configured;
        }

        return array_values(array_unique($disks));
    }

    /**
     * @return list<string>
     */
    private function allowedContentDownloadDirectories(): array
    {
        $directories = [trim((string) (config('content_upload.directory') ?: 'content-uploads'), '/')];
        $configured = $this->effectiveContentUploadConfig()['directory'] ?? null;
        if (is_string($configured)) {
            $directories[] = trim($configured, '/');
        }

        return array_values(array_filter(
            array_unique($directories),
            fn (string $directory) => $directory !== ''
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveContentUploadConfig(): array
    {
        try {
            $config = app(ContentUploadService::class)->effectiveConfig();

            return is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function constrainOrderSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $needle = str_replace(['\\', '%', '_'], '', $search);
        if ($needle === '') {
            $query->whereRaw('0 = 1');

            return;
        }

        $like = like_contains($search);
        $query->where(function ($q) use ($like, $search) {
            $q->whereRaw('order_number LIKE ? ESCAPE ?', [$like, '\\'])
                ->orWhereRaw('reference_code LIKE ? ESCAPE ?', [$like, '\\']);
            if (ctype_digit($search) && (string) (int) $search === $search) {
                $q->orWhere($q->getModel()->getQualifiedKeyName(), (int) $search);
            }
            $q->orWhereHas('user', function ($user) use ($like) {
                $user->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                    ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                if (Schema::hasColumn('users', 'company_name')) {
                    $user->orWhereRaw('company_name LIKE ? ESCAPE ?', [$like, '\\']);
                }
            });
            $itemColumns = array_values(array_filter(
                ['site_name', 'site_url', 'anchor_text', 'target_url'],
                fn (string $column) => Schema::hasColumn('order_items', $column)
            ));
            $siteColumns = Schema::hasTable('sites')
                ? array_values(array_filter(
                    ['site_name', 'site_url', 'domain'],
                    fn (string $column) => Schema::hasColumn('sites', $column)
                ))
                : [];
            $canSearchSite = $siteColumns !== [] && Schema::hasColumn('order_items', 'site_id');
            if ($itemColumns !== [] || $canSearchSite) {
                $q->orWhereHas('items', function ($item) use ($like, $itemColumns, $siteColumns, $canSearchSite) {
                    $item->where(function ($inner) use ($like, $itemColumns, $siteColumns, $canSearchSite) {
                        $added = false;
                        foreach ($itemColumns as $column) {
                            $method = $added ? 'orWhereRaw' : 'whereRaw';
                            $inner->{$method}($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                            $added = true;
                        }
                        if ($canSearchSite) {
                            $method = $added ? 'orWhereHas' : 'whereHas';
                            $inner->{$method}('site', function ($site) use ($like, $siteColumns) {
                                foreach ($siteColumns as $index => $column) {
                                    $siteMethod = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                                    $site->{$siteMethod}($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                                }
                            });
                        }
                    });
                });
            }
            if (Schema::hasTable('content_submissions') && Schema::hasColumn('order_items', 'content_submission_id')) {
                foreach (['anchor_text', 'target_url'] as $column) {
                    if (! Schema::hasColumn('content_submissions', $column)) {
                        continue;
                    }
                    $q->orWhereHas('items', function ($item) use ($like, $column) {
                        if (Schema::hasColumn('order_items', $column)) {
                            $item->where(function ($blank) use ($column) {
                                $blank->whereNull($column)->orWhere($column, '');
                            });
                        }
                        $item->whereHas('contentSubmission', function ($submission) use ($like, $column) {
                            $submission->whereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                        });
                    });
                }
            }
            if (Schema::hasTable('sites') && Schema::hasColumn('sites', 'publisher_id')) {
                $q->orWhereHas('items.site.publisher', function ($publisher) use ($like) {
                    $publisher->whereRaw('name LIKE ? ESCAPE ?', [$like, '\\'])
                        ->orWhereRaw('email LIKE ? ESCAPE ?', [$like, '\\']);
                });
            }
            foreach (['stripe_session_id', 'stripe_payment_intent_id', 'paypal_order_id', 'paypal_capture_id'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $q->orWhereRaw($column.' LIKE ? ESCAPE ?', [$like, '\\']);
                }
            }
        });
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function applyOrderSort(Builder $query, Request $request): Builder
    {
        $sort = search_text($request->input('sort'));
        if ($sort === 'paid' && ! $this->ordersHaveColumn('paid_at')) {
            $sort = '';
        }
        if ($sort === 'completed' && ! $this->ordersHaveColumn('completed_at')) {
            $sort = '';
        }

        return match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'amount' => $query->orderByDesc('total_amount')->orderByDesc('id'),
            'paid' => $query
                ->orderByRaw('CASE WHEN paid_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('paid_at')
                ->orderByDesc('id'),
            'completed' => $query
                ->orderByRaw('CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('completed_at')
                ->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * @param  Builder<Order>  $query
     * @return array{count: int, euros: float, charges: array<string, float>, not_recorded: int}
     */
    private function orderFilterTotals(Builder $query): array
    {
        $base = (clone $query)->setEagerLoads([])->reorder();
        $base->getQuery()->columns = null;
        $count = (clone $base)->count();
        $euros = round((float) (clone $base)->sum('total_amount'), 2);
        $charges = [];
        $notRecorded = 0;

        if ($this->ordersHaveColumn('charge_currency') && $this->ordersHaveColumn('charge_amount')) {
            $external = (clone $base)->whereIn('payment_method', ['card', 'stripe', 'paypal']);
            $notRecorded = (clone $external)->where(function ($q) {
                $q->whereNull('charge_currency')
                    ->orWhereRaw("TRIM(charge_currency) = ''")
                    ->orWhereNull('charge_amount');
            })->count();
            $inner = (clone $base)
                ->reorder()
                ->whereNotNull('charge_currency')
                ->whereRaw("TRIM(charge_currency) <> ''")
                ->whereNotNull('charge_amount');
            $inner->getQuery()->columns = null;
            $inner->selectRaw('UPPER(TRIM(charge_currency)) as code, charge_amount');
            $rows = DB::query()
                ->fromSub($inner, 'order_charge_rows')
                ->selectRaw('code, SUM(charge_amount) as total')
                ->groupBy('code')
                ->get();
            foreach ($rows as $row) {
                $code = strtoupper(trim((string) $row->code));
                if ($code !== '') {
                    $charges[$code] = round((float) $row->total, 2);
                }
            }
            ksort($charges);
        }

        return [
            'count' => $count,
            'euros' => $euros,
            'charges' => $charges,
            'not_recorded' => $notRecorded,
        ];
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function missingTaxOrderIds(array $orderIds): array
    {
        if ($orderIds === [] || ! Invoice::tableAvailable() || ! Schema::hasTable('orders')) {
            return [];
        }

        try {
            return app(InvoiceRepairQueue::class)
                ->missingTaxInvoiceOrders()
                ->whereIn('id', $orderIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{0: ?string, 1: ?float}
     */
    private function chargeBesideEuro(Order $order): array
    {
        if (! $this->ordersHaveColumn('charge_currency') || ! $this->ordersHaveColumn('charge_amount')) {
            return [null, null];
        }

        $code = strtoupper(trim((string) ($order->charge_currency ?? '')));
        if ($code === '' || $code === 'EUR' || $order->charge_amount === null) {
            return [null, null];
        }

        return [$code, (float) $order->charge_amount];
    }

    private function paymentMethodLabel(string $method): string
    {
        $method = strtolower(trim($method));

        return match ($method) {
            'stripe' => 'card',
            'bank_transfer' => 'bank',
            '' => '',
            default => $method,
        };
    }

    private function financeDossierUrl(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        try {
            return route('admin.finance.user', $user);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isOrderDay(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            return Carbon::parse($value)->toDateString() === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ordersHaveColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('orders', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text)) {
            return "'".$text;
        }

        return $text;
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
