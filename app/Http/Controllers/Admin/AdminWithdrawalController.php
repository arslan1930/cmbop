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
use App\Services\Wallet\WithdrawalPayoutContext;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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
        if (! Withdrawal::tableAvailable()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => max(1, min((int) $request->get('per_page', 20), 100)),
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
            ]);
        }

        try {
            $query = Withdrawal::with('user:id,name,email');
            $filters = $this->applyWithdrawalFilters($query, $request);
            $this->applyWithdrawalOrder($query, $filters['queue'], $filters['status'], $filters['sort']);

            $perPage = (int) $request->get('per_page', 20);
            $withdrawals = $query->paginate(max(1, min($perPage, 100)));

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
     */
    public function show($id)
    {
        if (! Withdrawal::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found',
            ], 404);
        }

        try {
            $withdrawal = Withdrawal::with('user:id,name,email')->findOrFail($id);

            if (is_string($withdrawal->payment_details)) {
                $withdrawal->payment_details = json_decode($withdrawal->payment_details, true);
            }

            $invoice = app(AdminInvoiceLinks::class)->forWithdrawals(collect([$withdrawal]))->get((int) $withdrawal->id);
            $withdrawal->setAttribute('invoice', $invoice);
            $withdrawal->setAttribute('invoice_url', data_get($invoice, 'url'));
            $this->attachDuplicateWarnings(collect([$withdrawal]));

            $payload = $withdrawal->toArray();
            try {
                $payload['payout_context'] = app(WithdrawalPayoutContext::class)->modalPayload(
                    $withdrawal,
                    $withdrawal->isActionable()
                );
            } catch (\Throwable $contextError) {
                Log::warning('Failed to build withdrawal payout context: '.$contextError->getMessage(), [
                    'withdrawal_id' => $withdrawal->id,
                ]);
                $payload['payout_context'] = null;
            }

            return response()->json([
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching withdrawal: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Withdrawal not found'),
            ], 404);
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
        if (! Withdrawal::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found',
            ], 404);
        }

        $withdrawal = Withdrawal::query()->find($id);
        if ($withdrawal === null) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found',
            ], 404);
        }

        if (! $withdrawal->isActionable()) {
            return $this->transitionWithdrawal((int) $withdrawal->id, 'cancelled', null);
        }

        $notes = $this->validatedRejectNotes($request);

        return $this->transitionWithdrawal((int) $withdrawal->id, 'cancelled', $notes);
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

        if (! Withdrawal::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found',
            ], 404);
        }

        $ids = $request->input('ids');
        $action = $request->input('action');
        $notes = $request->input('notes');

        if ($action === 'cancelled') {
            $notes = $this->validatedRejectNotes($request);
        }

        if ($action === 'completed') {
            $methods = Withdrawal::query()
                ->whereIn('id', $ids)
                ->pluck('payment_method')
                ->map(fn ($method) => strtolower(trim((string) $method)))
                ->filter()
                ->unique()
                ->values();
            if ($methods->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Select one payment method at a time before marking paid. None of these rows were marked paid.',
                ], 422);
            }
        }

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
            ActivityLogger::tryLog(
                'withdrawal.batch_'.$action,
                (auth()->user()?->name ?: 'System').' batch '.$action.' on '.$ok.' withdrawal(s) ['.$runId.']',
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
    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = ['queue' => 'open', 'status' => ''];
        try {
            $query = Withdrawal::tableAvailable()
                ? Withdrawal::with('user:id,name,email')
                : null;
            $filters = $query
                ? $this->applyWithdrawalFilters($query, $request)
                : $filters;
            $rows = $query
                ? $query->orderBy('payment_method')->orderBy('created_at')->get()
                : collect();
        } catch (\Throwable $e) {
            Log::warning('Admin withdrawals export query failed', [
                'error' => $e->getMessage(),
            ]);
            $rows = collect();
        }

        $filename = 'withdrawals-export-'.now()->format('Y-m-d-His').'.csv';

        ActivityLogger::tryLog(
            'withdrawal.exported',
            ($request->user()?->name ?? 'Admin').' exported withdrawals ('.$rows->count().' row(s)).',
            null,
            [
                'queue' => $filters['queue'],
                'status' => $filters['status'],
                'search' => search_text($request->input('search')),
                'payment_method' => search_text($request->input('payment_method')),
                'rows_exported' => $rows->count(),
            ]
        );

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
                    $w->user?->name,
                    $w->user?->email,
                    number_format((float) $w->amount, 2, '.', ''),
                    number_format((float) $w->fee, 2, '.', ''),
                    number_format((float) $w->net_amount, 2, '.', ''),
                    'EUR',
                    $w->payment_method,
                    $w->status,
                    $w->waiting_days,
                    $details['bank_name'] ?? '',
                    $details['account_holder'] ?? '',
                    $details['account_number'] ?? '',
                    $details['swift_code'] ?? '',
                    $details['email'] ?? '',
                    $details['crypto_type'] ?? '',
                    $details['wallet_address'] ?? '',
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
     */
    public function getStatistics()
    {
        if (! Withdrawal::tableAvailable()) {
            return response()->json([
                'success' => true,
                'data' => $this->emptyWithdrawalStatistics(),
            ]);
        }

        try {
            $pendingQuery = Withdrawal::where('status', 'pending');
            $processingQuery = Withdrawal::where('status', 'processing');
            $openQuery = Withdrawal::whereIn('status', ['pending', 'processing']);

            $byMethod = Withdrawal::whereIn('status', ['pending', 'processing'])
                ->selectRaw('payment_method, COUNT(*) as count, SUM(net_amount) as net_total')
                ->groupBy('payment_method')
                ->get()
                ->mapWithKeys(fn ($row) => [
                    $row->payment_method => [
                        'count' => (int) $row->count,
                        'net_total' => (float) $row->net_total,
                    ],
                ]);

            $completedThisWeek = Withdrawal::where('status', 'completed');
            if (Withdrawal::hasProcessedAtColumn()) {
                $completedThisWeek->whereProcessedAtIsRecorded()
                    ->where('processed_at', '>=', now()->startOfWeek())
                    ->where('processed_at', '<=', now()->endOfWeek());
            } else {
                $completedThisWeek->where('created_at', '>=', now()->startOfWeek())
                    ->where('created_at', '<=', now()->endOfWeek());
            }

            $stats = [
                'total_withdrawals' => Withdrawal::count(),
                'pending' => (clone $pendingQuery)->count(),
                'processing' => (clone $processingQuery)->count(),
                'completed' => Withdrawal::where('status', 'completed')->count(),
                'cancelled' => Withdrawal::where('status', 'cancelled')->count(),
                'pending_amount' => (float) (clone $pendingQuery)->sum('net_amount'),
                'processing_amount' => (float) (clone $processingQuery)->sum('net_amount'),
                'total_to_pay' => (float) (clone $openQuery)->sum('net_amount'),
                'completed_this_week' => (clone $completedThisWeek)->count(),
                'completed_this_week_amount' => (float) (clone $completedThisWeek)->sum('net_amount'),
                'week_start' => now()->startOfWeek()->toDateString(),
                'week_end' => now()->endOfWeek()->toDateString(),
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
                'message' => UserFacingError::message($e, 'Failed to fetch statistics'),
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyWithdrawalStatistics(): array
    {
        return [
            'total_withdrawals' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'pending_amount' => 0.0,
            'processing_amount' => 0.0,
            'total_to_pay' => 0.0,
            'completed_this_week' => 0,
            'completed_this_week_amount' => 0.0,
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->endOfWeek()->toDateString(),
            'total_amount_requested' => 0.0,
            'total_fees_collected' => 0.0,
            'total_amount_paid' => 0.0,
            'by_method' => [],
        ];
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
        if (! Withdrawal::tableAvailable()) {
            return null;
        }

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
     * @return array{queue: string, status: string, sort: string}
     */
    private function applyWithdrawalFilters(Builder $query, Request $request): array
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
        $paidClock = $status === 'completed' && Withdrawal::hasProcessedAtColumn();
        if ($paidClock && ($dates['date_from'] ?? null || $dates['date_to'] ?? null)) {
            $query->whereProcessedAtIsRecorded();
        }
        $dateColumn = $paidClock ? 'processed_at' : 'created_at';
        if (! empty($dates['date_from'])) {
            $query->whereDate($dateColumn, '>=', $dates['date_from']);
        }
        if (! empty($dates['date_to'])) {
            $query->whereDate($dateColumn, '<=', $dates['date_to']);
        }

        $waiting = search_text($request->input('waiting'));
        if (in_array($waiting, ['7', '14'], true)) {
            $query->whereIn('status', ['pending', 'processing'])
                ->where('created_at', '<=', now()->subDays((int) $waiting));
        }

        $sort = search_text($request->input('sort'));
        if (! in_array($sort, ['oldest', 'newest', 'amount', 'waiting'], true)) {
            $sort = '';
        }

        $ids = $this->withdrawalExportIds($request->input('ids'));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return [
            'queue' => $queue,
            'status' => $status,
            'sort' => $sort,
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
                $sub->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });

            $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
            $inner->orWhereRaw('LOWER(CAST(payment_details AS CHAR(4000))) LIKE ?', [$like]);
        });
    }

    /**
     * @param  Builder<Withdrawal>  $query
     */
    private function applyWithdrawalOrder(Builder $query, string $queue, string $status, string $sort = ''): void
    {
        if ($sort === 'amount') {
            $query->orderByDesc('net_amount')->orderByDesc('id');

            return;
        }

        if ($sort === 'waiting' || $sort === 'oldest') {
            $query->orderBy('created_at')->orderBy('id');

            return;
        }

        if ($sort === 'newest') {
            $query->orderByDesc('created_at')->orderByDesc('id');

            return;
        }

        if (in_array($status, ['completed', 'cancelled'], true) || $queue === 'history') {
            $query->orderBy('created_at', 'desc');

            return;
        }

        $query->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'asc');
    }

    private function validatedRejectNotes(Request $request): string
    {
        $notes = $request->input('notes');
        if (is_string($notes)) {
            $request->merge(['notes' => trim($notes)]);
        }

        $validated = $request->validate([
            'notes' => 'required|string|min:10|max:2000',
        ]);

        return $validated['notes'];
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

        return array_values(array_unique($normalized));
    }

    /**
     * Core status transition with wallet refund, notes, processed_at, notifications.
     */
    private function transitionWithdrawal(int $id, string $newStatus, ?string $notes = null, bool $quiet = false)
    {
        if (! Withdrawal::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found',
            ], 404);
        }

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
