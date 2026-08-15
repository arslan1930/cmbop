<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\ActivityLogger;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\Wallet\ManualWithdrawalInvalidTransitionException;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use App\Services\Wallet\ManualWithdrawalUnknownWalletException;
use App\Services\Wallet\WithdrawalDuplicatePayoutWarning;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdminWithdrawalController extends Controller
{
    /**
     * Display withdrawals payout queue.
     */
    public function index()
    {
        return view('admin.withdrawals');
    }

    /**
     * Get withdrawals data for the payout queue table (AJAX).
     */
    public function getWithdrawalsData(Request $request)
    {
        try {
            $query = Withdrawal::with('user:id,name,email');
            $filters = $this->applyWithdrawalFilters($query, $request);
            $this->applyWithdrawalOrder($query, $filters['queue'], $filters['status']);

            $perPage = max(1, min((int) (filter_number($request->input('per_page')) ?? 20), 100));
            $page = max(1, (int) (filter_number($request->input('page')) ?? 1));
            $withdrawals = (clone $query)->paginate($perPage, ['*'], 'page', $page);
            if ($withdrawals->lastPage() >= 1 && $page > $withdrawals->lastPage()) {
                $withdrawals = (clone $query)->paginate($perPage, ['*'], 'page', $withdrawals->lastPage());
            }

            $invoiceLinks = app(AdminInvoiceLinks::class)->forWithdrawals($withdrawals->getCollection());
            $this->attachDuplicateWarnings($withdrawals->getCollection());

            $withdrawals->getCollection()->transform(function ($withdrawal) use ($invoiceLinks) {
                $withdrawal->payment_details = Withdrawal::detailsArray($withdrawal->payment_details);

                $invoice = $invoiceLinks->get((int) $withdrawal->id);
                $withdrawal->setAttribute('invoice', $invoice);
                $withdrawal->setAttribute('invoice_url', data_get($invoice, 'url'));

                return $withdrawal;
            });

            return $this->noStoreJson([
                'success' => true,
                'data' => $withdrawals->items(),
                'pagination' => [
                    'current_page' => $withdrawals->currentPage(),
                    'last_page' => $withdrawals->lastPage(),
                    'per_page' => $withdrawals->perPage(),
                    'total' => $withdrawals->total(),
                    'from' => $withdrawals->firstItem(),
                    'to' => $withdrawals->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching withdrawals: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch withdrawals. Please try again.'),
            ], 500);
        }
    }

    /**
     * Get single withdrawal details.
     * Browser GET renders a shareable HTML page; XHR / JSON Accept stays JSON for the modal.
     */
    public function show(Request $request, $id)
    {
        $wantsJson = $request->wantsJson();

        try {
            $withdrawal = Withdrawal::with('user:id,name,email')->find($id);

            if (! $withdrawal) {
                if ($wantsJson) {
                    return $this->noStoreJson([
                        'success' => false,
                        'message' => 'Withdrawal not found',
                    ], 404);
                }

                abort(404);
            }

            $withdrawal->payment_details = Withdrawal::detailsArray($withdrawal->payment_details);

            $invoice = app(AdminInvoiceLinks::class)->forWithdrawals(collect([$withdrawal]))->get((int) $withdrawal->id);
            $withdrawal->setAttribute('invoice', $invoice);
            $withdrawal->setAttribute('invoice_url', data_get($invoice, 'url'));
            $this->attachDuplicateWarnings(collect([$withdrawal]));

            if ($wantsJson) {
                return $this->noStoreJson([
                    'success' => true,
                    'data' => $withdrawal,
                ]);
            }

            return response()
                ->view('admin.withdrawals.show', [
                    'withdrawal' => $withdrawal,
                    'invoiceUrl' => $this->safeAdminUrl(data_get($invoice, 'url')),
                    'hasPayoutStatement' => is_array($invoice),
                ])
                ->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Error fetching withdrawal: '.$e->getMessage());

            if ($wantsJson) {
                return $this->noStoreJson([
                    'success' => false,
                    'message' => UserFacingError::message($e, 'Failed to load withdrawal.'),
                ], 500);
            }

            throw $e;
        }
    }

    /**
     * Actionable (pending + processing) ids for the current filters. Cap 100.
     * Ignores ids[] so a selection cannot shrink the matching set.
     */
    public function matchingIds(Request $request): JsonResponse
    {
        try {
            $limit = max(1, (int) config('billing.withdrawal_select_matching_limit', 100));

            $query = Withdrawal::query();
            $filters = $this->applyWithdrawalFilters($query, $request, applyIds: false);
            $query->whereIn('status', ['pending', 'processing']);
            $this->applyWithdrawalOrder($query, $filters['queue'], $filters['status']);

            $total = (clone $query)->count();
            $rows = $query->limit($limit)->get(['id', 'status', 'user_id', 'net_amount', 'created_at']);
            $this->attachDuplicateWarnings($rows);
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $pendingIds = $rows->where('status', 'pending')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $duplicateIds = $rows->filter(fn ($row) => $row->possible_duplicate)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $duplicateMatchIds = [];
            foreach ($rows as $row) {
                $matchIds = $row->duplicate_match_ids ?? [];
                if (is_array($matchIds) && $matchIds !== []) {
                    $duplicateMatchIds[(int) $row->id] = array_values(array_map('intval', $matchIds));
                }
            }

            return $this->noStoreJson([
                'success' => true,
                'ids' => $ids,
                'pending_ids' => $pendingIds,
                'duplicate_ids' => $duplicateIds,
                'duplicate_match_ids' => (object) $duplicateMatchIds,
                'total' => $total,
                'capped' => $total > $limit,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching matching withdrawal ids: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => 'Failed to load matching withdrawals.',
            ], 500);
        }
    }

    /**
     * Generic status update (kept for existing tests / API clients).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,cancelled',
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->transitionWithdrawal(
            (int) $id,
            $request->status,
            $request->input('notes')
        );
    }

    /**
     * Start processing a pending withdrawal.
     */
    public function markProcessing(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->transitionWithdrawal((int) $id, 'processing', $request->input('notes'));
    }

    /**
     * Mark a withdrawal as paid (funds already sent outside the app).
     */
    public function markPaid(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->transitionWithdrawal((int) $id, 'completed', $request->input('notes'));
    }

    /**
     * Reject & refund a pending/processing withdrawal.
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->transitionWithdrawal((int) $id, 'cancelled', $request->input('notes'));
    }

    /**
     * Batch update selected withdrawals.
     */
    public function batchUpdate(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|distinct|min:1',
            'action' => 'required|in:processing,completed,cancelled',
            'notes' => 'nullable|string|max:2000',
            'confirm_duplicates' => 'sometimes|boolean',
        ]);

        $ids = $request->input('ids');
        $action = $request->input('action');
        $notes = $request->input('notes');

        if ($action === 'completed' && ! $request->boolean('confirm_duplicates')) {
            $blocked = $this->batchDuplicateBlock($ids);
            if ($blocked !== null) {
                return $blocked;
            }
        }

        $ok = 0;
        $failed = [];
        $succeededIds = [];
        $unchangedIds = [];
        $missingStatementIds = [];

        foreach ($ids as $id) {
            $response = $this->transitionWithdrawal((int) $id, $action, $notes, quiet: true);
            $payload = $response->getData(true);
            if (! empty($payload['success'])) {
                if (! empty($payload['unchanged'])) {
                    $unchangedIds[] = (int) $id;
                } else {
                    $ok++;
                    $succeededIds[] = (int) $id;
                }
                if ($action === 'completed' && array_key_exists('has_statement', $payload) && $payload['has_statement'] === false) {
                    $missingStatementIds[] = (int) $id;
                }
            } else {
                $failed[] = [
                    'id' => (int) $id,
                    'message' => $payload['message'] ?? 'Failed',
                ];
            }
        }

        $runId = 'PAYOUT-'.now()->format('Ymd-His').'-'.$ok;

        if ($ok > 0) {
            try {
                ActivityLogger::log(
                    'withdrawal.batch_'.$action,
                    (auth()->user()->name ?? 'Admin').' batch '.$action.' on '.$ok.' withdrawal(s) ['.$runId.']',
                    null,
                    [
                        'action' => $action,
                        'succeeded' => $ok,
                        'failed' => count($failed),
                        'unchanged' => count($unchangedIds),
                        'ids' => $succeededIds,
                        'payout_run_id' => $runId,
                    ],
                    $runId
                );
            } catch (\Throwable $e) {
                Log::error('Failed to log payout batch: '.$e->getMessage(), [
                    'payout_run_id' => $runId,
                ]);
            }
        }

        $parts = [$ok.' updated'];
        if ($unchangedIds !== []) {
            $parts[] = count($unchangedIds).' already in that status';
        }
        if ($failed !== []) {
            $parts[] = count($failed).' failed';
        }
        if ($missingStatementIds !== []) {
            $refs = array_map(fn (int $id) => 'WD-'.$id, $missingStatementIds);
            $shown = array_slice($refs, 0, 5);
            $label = implode(', ', $shown);
            if (count($refs) > 5) {
                $label .= '…';
            }
            $parts[] = count($missingStatementIds).' missing payout statement'
                .(count($missingStatementIds) === 1 ? '' : 's')
                .' ('.$label.')';
        }

        return $this->noStoreJson([
            'success' => $ok > 0,
            'message' => implode(', ', $parts),
            'succeeded' => $ok,
            'unchanged' => $unchangedIds,
            'failed' => $failed,
            'missing_statement_ids' => $missingStatementIds,
            'payout_run_id' => $runId,
        ], $ok > 0 ? 200 : 422);
    }

    /**
     * CSV export of open (or filtered) withdrawals for bank / Wise upload.
     */
    public function exportCsv(Request $request): StreamedResponse|RedirectResponse|JsonResponse
    {
        try {
            $query = Withdrawal::with('user:id,name,email');
            $this->applyWithdrawalFilters($query, $request);

            $maxRows = max(1, (int) config('billing.withdrawal_export_max_rows', 2000));
            $total = (clone $query)->count();
            if ($total > $maxRows) {
                $message = 'Export is limited to '.$maxRows.' rows. This view has '.$total.'. Narrow the filters and try again.';
                if ($request->wantsJson()) {
                    return $this->noStoreJson([
                        'success' => false,
                        'message' => $message,
                        'total' => $total,
                        'limit' => $maxRows,
                    ], 422);
                }

                return $this->redirectToWithdrawalIndex($request, $message);
            }

            $rows = $query->orderBy('payment_method')->orderBy('created_at')->orderBy('id')->get();
        } catch (\Throwable $e) {
            Log::error('Error exporting withdrawals: '.$e->getMessage());
            $message = UserFacingError::message($e, 'Failed to export withdrawals. Please try again.');
            if ($request->wantsJson()) {
                return $this->noStoreJson([
                    'success' => false,
                    'message' => $message,
                ], 500);
            }

            return $this->redirectToWithdrawalIndex($request, $message);
        }

        $filename = 'withdrawals-export-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'reference',
                'withdrawal_id',
                'publisher_name',
                'publisher_email',
                'amount',
                'fee',
                'net_amount',
                'currency',
                'payment_method',
                'status',
                'waiting_days',
                'bank_name',
                'account_holder',
                'iban_account',
                'swift',
                'paypal_or_wise_email',
                'crypto_type',
                'wallet_address',
                'requested_at',
            ]);

            foreach ($rows as $w) {
                $details = Withdrawal::detailsArray($w->payment_details);

                fputcsv($out, [
                    'WD-'.$w->id,
                    $w->id,
                    $this->csvCell($w->user?->name),
                    $this->csvCell($w->user?->email),
                    number_format((float) $w->amount, 2, '.', ''),
                    number_format((float) $w->fee, 2, '.', ''),
                    number_format((float) $w->net_amount, 2, '.', ''),
                    'EUR',
                    $this->csvCell($w->payment_method),
                    $this->csvCell($w->status),
                    $this->csvCell($w->waiting_days),
                    $this->csvCell(Withdrawal::destinationText($details, 'bank_name')),
                    $this->csvCell(Withdrawal::destinationText($details, 'account_holder')),
                    $this->csvCell(Withdrawal::destinationText($details, 'account_number')),
                    $this->csvCell(Withdrawal::destinationText($details, 'swift_code')),
                    $this->csvCell(Withdrawal::destinationText($details, 'email')),
                    $this->csvCell(Withdrawal::destinationText($details, 'crypto_type')),
                    $this->csvCell(Withdrawal::destinationText($details, 'wallet_address')),
                    optional($w->created_at)->toDateTimeString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Withdrawal statistics for the payout queue strip.
     * Default scope is all open. scope=view applies the current list filters
     * to pending / processing / to-pay / by-method. Paid-this-week stays global.
     */
    public function getStatistics(Request $request)
    {
        try {
            $scope = search_text($request->input('scope')) === 'view' ? 'view' : 'all';

            $pendingQuery = Withdrawal::query()->where('status', 'pending');
            $processingQuery = Withdrawal::query()->where('status', 'processing');
            $openQuery = Withdrawal::query()->whereIn('status', ['pending', 'processing']);
            $byMethodQuery = Withdrawal::query()->whereIn('status', ['pending', 'processing']);

            if ($scope === 'view') {
                $this->applyWithdrawalFilters($pendingQuery, $request, applyIds: false);
                $this->applyWithdrawalFilters($processingQuery, $request, applyIds: false);
                $this->applyWithdrawalFilters($openQuery, $request, applyIds: false);
                $this->applyWithdrawalFilters($byMethodQuery, $request, applyIds: false);
            }

            $byMethod = $byMethodQuery
                ->selectRaw('payment_method, COUNT(*) as count, SUM(net_amount) as net_total')
                ->groupBy('payment_method')
                ->get()
                ->mapWithKeys(fn ($row) => [
                    (string) ($row->payment_method ?: 'unknown') => [
                        'count' => (int) $row->count,
                        'net_total' => (float) $row->net_total,
                    ],
                ]);

            $stats = [
                'scope' => $scope,
                'total_withdrawals' => Withdrawal::count(),
                'pending' => (clone $pendingQuery)->count(),
                'processing' => (clone $processingQuery)->count(),
                'completed' => Withdrawal::where('status', 'completed')->count(),
                'cancelled' => Withdrawal::where('status', 'cancelled')->count(),
                'pending_amount' => (float) (clone $pendingQuery)->sum('net_amount'),
                'processing_amount' => (float) (clone $processingQuery)->sum('net_amount'),
                'total_to_pay' => (float) (clone $openQuery)->sum('net_amount'),
                'completed_this_week' => Withdrawal::where('status', 'completed')
                    ->where('processed_at', '>=', now()->startOfWeek())
                    ->count(),
                'completed_this_week_amount' => (float) Withdrawal::where('status', 'completed')
                    ->where('processed_at', '>=', now()->startOfWeek())
                    ->sum('net_amount'),
                'total_amount_requested' => (float) Withdrawal::sum('amount'),
                'total_fees_collected' => (float) Withdrawal::where('status', 'completed')->sum('fee'),
                'total_amount_paid' => (float) Withdrawal::where('status', 'completed')->sum('net_amount'),
                'by_method' => $byMethod,
            ];

            return $this->noStoreJson([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching withdrawal statistics: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => 'Failed to fetch statistics',
            ], 500);
        }
    }

    /**
     * @param  Collection<int, Withdrawal>  $withdrawals
     */
    private function attachDuplicateWarnings($withdrawals): void
    {
        $map = app(WithdrawalDuplicatePayoutWarning::class)->matchIdsByWithdrawalId($withdrawals);

        foreach ($withdrawals as $withdrawal) {
            $ids = $map[(int) $withdrawal->id] ?? [];
            $withdrawal->setAttribute('possible_duplicate', $ids !== []);
            $withdrawal->setAttribute('duplicate_match_ids', $ids);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function batchDuplicateBlock(array $ids): ?JsonResponse
    {
        $rows = Withdrawal::query()->whereIn('id', $ids)->get();
        $map = app(WithdrawalDuplicatePayoutWarning::class)->matchIdsByWithdrawalId($rows);
        $duplicateIds = [];
        foreach ($map as $withdrawalId => $matchIds) {
            if ($matchIds !== []) {
                $duplicateIds[] = (int) $withdrawalId;
            }
        }

        if ($duplicateIds === []) {
            return null;
        }

        $matchIds = [];
        foreach ($duplicateIds as $withdrawalId) {
            $matchIds[$withdrawalId] = $map[$withdrawalId] ?? [];
        }
        $refs = $this->paidDuplicateRefs($matchIds);
        if ($refs === []) {
            $refs = array_map(fn (int $id) => 'WD-'.$id, $duplicateIds);
        }

        return $this->noStoreJson([
            'success' => false,
            'needs_duplicate_confirm' => true,
            'message' => 'Possible duplicate payout: same publisher was paid this net amount recently ('.implode(', ', $refs).'). Confirm you are not paying twice.',
            'duplicate_ids' => $duplicateIds,
            'duplicate_match_ids' => $matchIds,
        ], 422);
    }

    /**
     * Shared list/export filters. Arrays and junk dates are ignored (same as Payments).
     *
     * @param  Builder<Withdrawal>  $query
     * @return array{queue: string, status: string}
     */
    private function applyWithdrawalFilters(Builder $query, Request $request, bool $applyIds = true): array
    {
        $status = search_text($request->input('status'));
        $allowedStatuses = ['pending', 'processing', 'completed', 'cancelled'];
        if (! in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        $queue = search_text($request->input('queue'));
        if (! in_array($queue, ['open', 'history', 'all'], true)) {
            $queue = 'open';
        }

        if ($status !== '') {
            $query->where('status', $status);
        } elseif ($queue === 'open') {
            $query->whereIn('status', ['pending', 'processing']);
        } elseif ($queue === 'history') {
            $query->whereIn('status', ['completed', 'cancelled']);
        }

        $this->applyWithdrawalSearch($query, search_text($request->input('search')));

        $paymentMethod = search_text($request->input('payment_method'));
        $allowedMethods = ['bank', 'paypal', 'wise', 'crypto'];
        if (in_array($paymentMethod, $allowedMethods, true)) {
            $query->where('payment_method', $paymentMethod);
        }

        $dates = validator(
            [
                'date_from' => search_text($request->input('date_from')) ?: null,
                'date_to' => search_text($request->input('date_to')) ?: null,
            ],
            [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]
        )->valid();
        if (! empty($dates['date_from'])) {
            $query->whereDate('created_at', '>=', $dates['date_from']);
        }
        if (! empty($dates['date_to'])) {
            $query->whereDate('created_at', '<=', $dates['date_to']);
        }

        if ($applyIds) {
            $ids = $this->withdrawalExportIds($request->input('ids'));
            if ($ids !== []) {
                $query->whereIn('id', $ids);
            }
        }

        return [
            'queue' => $queue,
            'status' => $status,
        ];
    }

    /**
     * @param  Builder<Withdrawal>  $query
     */
    private function applyWithdrawalSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        if (preg_match('/^#?WD-?(\d+)$/i', $search, $matches) === 1) {
            $query->whereKey((int) $matches[1]);

            return;
        }

        $query->where(function (Builder $inner) use ($search) {
            if (ctype_digit($search)) {
                $inner->whereKey((int) $search);
            }

            $inner->orWhereHas('user', function ($sub) use ($search) {
                $sub->where(function ($user) use ($search) {
                    // INSTR treats %/_ as literals (LIKE would not, and ESCAPE
                    // differs between MySQL and SQLite).
                    $user->whereRaw('INSTR(LOWER(name), LOWER(?)) > 0', [$search])
                        ->orWhereRaw('INSTR(LOWER(email), LOWER(?)) > 0', [$search]);
                });
            });
        });
    }

    /**
     * @param  Builder<Withdrawal>  $query
     */
    private function applyWithdrawalOrder(Builder $query, string $queue, string $status): void
    {
        if (in_array($status, ['completed', 'cancelled'], true) || $queue === 'history') {
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

            return;
        }

        $query->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * Paid WD- refs from open-id => paid-id map. The warning cites the
     * prior payout, not the open row being paid now.
     *
     * @param  array<int|string, list<int>>  $matchIds
     * @return list<string>
     */
    private function paidDuplicateRefs(array $matchIds): array
    {
        $refs = [];
        foreach ($matchIds as $paidIds) {
            if (! is_array($paidIds)) {
                continue;
            }
            foreach ($paidIds as $paidId) {
                $n = (int) $paidId;
                if ($n > 0) {
                    $refs[$n] = 'WD-'.$n;
                }
            }
        }

        return array_values($refs);
    }

    /**
     * Stay on the current host. Absolute route() would jump to APP_URL and
     * drop the session/flash when the tab origin differs (www vs bare host).
     */
    private function redirectToWithdrawalIndex(Request $request, string $message): RedirectResponse
    {
        $params = [];
        try {
            $params = $this->withdrawalIndexQuery($request);
        } catch (\Throwable $ignored) {
        }

        return redirect()
            ->to(route('admin.withdrawals', $params, false))
            ->with('error', $message);
    }

    /**
     * Safe query string for returning to the payout queue (export over-cap flash).
     *
     * @return array<string, string>
     */
    private function withdrawalIndexQuery(Request $request): array
    {
        $parsed = $this->applyWithdrawalFilters(Withdrawal::query(), $request, applyIds: false);
        $params = [];
        if ($parsed['status'] !== '') {
            $params['status'] = $parsed['status'];
        } elseif ($parsed['queue'] !== 'open') {
            $params['queue'] = $parsed['queue'];
        }

        $search = search_text($request->input('search'));
        if ($search !== '') {
            $params['search'] = $search;
        }

        $paymentMethod = search_text($request->input('payment_method'));
        if (in_array($paymentMethod, ['bank', 'paypal', 'wise', 'crypto'], true)) {
            $params['payment_method'] = $paymentMethod;
        }

        $dates = validator(
            [
                'date_from' => search_text($request->input('date_from')) ?: null,
                'date_to' => search_text($request->input('date_to')) ?: null,
            ],
            [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]
        )->valid();
        if (! empty($dates['date_from'])) {
            $params['date_from'] = $dates['date_from'];
        }
        if (! empty($dates['date_to'])) {
            $params['date_to'] = $dates['date_to'];
        }

        return $params;
    }

    /**
     * @return list<int>
     */
    private function withdrawalExportIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $n = (int) $id;
                if ($n > 0) {
                    $normalized[] = $n;
                }
            }
        }

        $normalized = array_values(array_unique($normalized));
        $max = max(1, (int) config('billing.withdrawal_export_max_rows', 2000));

        return array_slice($normalized, 0, $max);
    }

    private function csvCell(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Same-origin admin path only. APP_URL host/scheme may differ from the
     * browser; javascript: and off-site hrefs are dropped.
     */
    private function safeAdminUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || ($path !== '/admin' && ! str_starts_with($path, '/admin/'))) {
            return null;
        }

        $safe = $path;
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $safe .= '?'.$query;
        }
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        if (is_string($fragment) && $fragment !== '') {
            $safe .= '#'.$fragment;
        }

        return $safe;
    }

    private function noStoreJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Core status transition with wallet refund, notes, processed_at, notifications.
     */
    private function transitionWithdrawal(int $id, string $newStatus, ?string $notes = null, bool $quiet = false)
    {
        try {
            $result = app(ManualWithdrawalSettlementService::class)->transition(
                $id,
                $newStatus,
                auth()->user(),
                $notes,
                $quiet
            );

            $payload = [
                'success' => true,
                'unchanged' => ! empty($result['unchanged']),
                'message' => $result['message'],
                'data' => $result['withdrawal'],
            ];
            if (array_key_exists('has_statement', $result)) {
                $payload['has_statement'] = (bool) $result['has_statement'];
            }

            return $this->noStoreJson($payload);
        } catch (ManualWithdrawalInvalidTransitionException $e) {
            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'This withdrawal cannot be updated from its current status.'),
            ], 400);
        } catch (ManualWithdrawalUnknownWalletException $e) {
            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'Cannot return these funds: the source wallet is unknown.'),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error updating withdrawal status: '.$e->getMessage());

            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to update status. Please try again.'),
            ], 500);
        }
    }
}
