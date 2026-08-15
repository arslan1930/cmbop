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
                if (is_string($withdrawal->payment_details)) {
                    $withdrawal->payment_details = json_decode($withdrawal->payment_details, true);
                }

                $invoice = $invoiceLinks->get((int) $withdrawal->id);
                $withdrawal->setAttribute('invoice', $invoice);
                $withdrawal->setAttribute('invoice_url', data_get($invoice, 'url'));

                return $withdrawal;
            });

            return response()->json([
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

            return response()->json([
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
                    return response()->json([
                        'success' => false,
                        'message' => 'Withdrawal not found',
                    ], 404);
                }

                abort(404);
            }

            if (is_string($withdrawal->payment_details)) {
                $withdrawal->payment_details = json_decode($withdrawal->payment_details, true);
            }

            $invoice = app(AdminInvoiceLinks::class)->forWithdrawals(collect([$withdrawal]))->get((int) $withdrawal->id);
            $withdrawal->setAttribute('invoice', $invoice);
            $withdrawal->setAttribute('invoice_url', data_get($invoice, 'url'));
            $this->attachDuplicateWarnings(collect([$withdrawal]));

            if ($wantsJson) {
                return response()->json([
                    'success' => true,
                    'data' => $withdrawal,
                ]);
            }

            return view('admin.withdrawals.show', [
                'withdrawal' => $withdrawal,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching withdrawal: '.$e->getMessage());

            if ($wantsJson) {
                return response()->json([
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
            $rows = $query->limit($limit)->get(['id', 'status']);
            $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $pendingIds = $rows->where('status', 'pending')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            return response()->json([
                'success' => true,
                'ids' => $ids,
                'pending_ids' => $pendingIds,
                'total' => $total,
                'capped' => $total > $limit,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching matching withdrawal ids: '.$e->getMessage());

            return response()->json([
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
            'ids.*' => 'integer|distinct',
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

        foreach ($ids as $id) {
            $response = $this->transitionWithdrawal((int) $id, $action, $notes, quiet: true);
            $payload = $response->getData(true);
            if (! empty($payload['success'])) {
                $ok++;
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
                        'ids' => $ids,
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

        return response()->json([
            'success' => $ok > 0,
            'message' => $ok.' updated'.(count($failed) ? ', '.count($failed).' failed' : ''),
            'succeeded' => $ok,
            'failed' => $failed,
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
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                        'total' => $total,
                        'limit' => $maxRows,
                    ], 422);
                }

                return redirect()
                    ->route('admin.withdrawals', $this->withdrawalIndexQuery($request))
                    ->with('error', $message);
            }

            $rows = $query->orderBy('payment_method')->orderBy('created_at')->orderBy('id')->get();
        } catch (\Throwable $e) {
            Log::error('Error exporting withdrawals: '.$e->getMessage());
            $message = UserFacingError::message($e, 'Failed to export withdrawals. Please try again.');
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 500);
            }

            return redirect()
                ->route('admin.withdrawals', $this->withdrawalIndexQuery($request))
                ->with('error', $message);
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
                $details = is_array($w->payment_details)
                    ? $w->payment_details
                    : (json_decode((string) $w->payment_details, true) ?: []);

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
                    $this->csvCell($details['bank_name'] ?? ''),
                    $this->csvCell($details['account_holder'] ?? ''),
                    $this->csvCell($details['account_number'] ?? ''),
                    $this->csvCell($details['swift_code'] ?? ''),
                    $this->csvCell($details['email'] ?? ''),
                    $this->csvCell($details['crypto_type'] ?? ''),
                    $this->csvCell($details['wallet_address'] ?? ''),
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
                    $row->payment_method => [
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

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching withdrawal statistics: '.$e->getMessage());

            return response()->json([
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

        $refs = array_map(fn (int $id) => 'WD-'.$id, $duplicateIds);
        $matchIds = [];
        foreach ($duplicateIds as $withdrawalId) {
            $matchIds[$withdrawalId] = $map[$withdrawalId] ?? [];
        }

        return response()->json([
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

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['withdrawal'],
            ]);
        } catch (ManualWithdrawalInvalidTransitionException $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'This withdrawal cannot be updated from its current status.'),
            ], 400);
        } catch (ManualWithdrawalUnknownWalletException $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Cannot return these funds: the source wallet is unknown.'),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error updating withdrawal status: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to update status. Please try again.'),
            ], 500);
        }
    }
}
