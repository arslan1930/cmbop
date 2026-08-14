<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\ActivityLogger;
use App\Services\Wallet\ManualWithdrawalInvalidTransitionException;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use App\Services\Wallet\ManualWithdrawalUnknownWalletException;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
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

            // Default: open payout queue (pending + processing), oldest first.
            $queue = scalar_text($request->get('queue', 'open'));
            $status = scalar_text($request->status);
            if ($status !== '') {
                $query->where('status', $status);
            } elseif ($queue === 'open') {
                $query->whereIn('status', ['pending', 'processing']);
            } elseif ($queue === 'history') {
                $query->whereIn('status', ['completed', 'cancelled']);
            }

            if ($request->filled('search')) {
                $search = trim(scalar_text($request->search));
                $query->where(function ($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($sub) use ($search) {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            $paymentMethod = scalar_text($request->payment_method);
            if ($paymentMethod !== '') {
                $query->where('payment_method', $paymentMethod);
            }

            $dateFrom = scalar_text($request->date_from);
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            $dateTo = scalar_text($request->date_to);
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            // Open queue: oldest unpaid first. History: newest first.
            if ($status !== '' && in_array($status, ['completed', 'cancelled'], true)) {
                $query->orderBy('created_at', 'desc');
            } elseif ($queue === 'history') {
                $query->orderBy('created_at', 'desc');
            } else {
                $query->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'processing' THEN 1 ELSE 2 END")
                    ->orderBy('created_at', 'asc');
            }

            $perPage = (int) $request->get('per_page', 20);
            $withdrawals = $query->paginate(max(1, min($perPage, 100)));

            $withdrawals->getCollection()->transform(function ($withdrawal) {
                if (is_string($withdrawal->payment_details)) {
                    $withdrawal->payment_details = json_decode($withdrawal->payment_details, true);
                }

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
        } catch (\Exception $e) {
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
        try {
            $withdrawal = Withdrawal::with('user:id,name,email')->findOrFail($id);

            if (is_string($withdrawal->payment_details)) {
                $withdrawal->payment_details = json_decode($withdrawal->payment_details, true);
            }

            return response()->json([
                'success' => true,
                'data' => $withdrawal,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found',
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
        ]);

        $ids = $request->input('ids');
        $action = $request->input('action');
        $notes = $request->input('notes');
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
            ActivityLogger::log(
                'withdrawal.batch_'.$action,
                auth()->user()->name.' batch '.$action.' on '.$ok.' withdrawal(s) ['.$runId.']',
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
        $query = Withdrawal::with('user:id,name,email');

        $status = scalar_text($request->status);
        if ($status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['pending', 'processing']);
        }

        $paymentMethod = scalar_text($request->payment_method);
        if ($paymentMethod !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        if ($request->filled('ids') && is_array($request->ids)) {
            $query->whereIn('id', $request->ids);
        }

        $rows = $query->orderBy('payment_method')->orderBy('created_at')->get();

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

            $stats = [
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
        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal statistics: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
            ]);
        }
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
        } catch (\Exception $e) {
            Log::error('Error updating withdrawal status: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to update status. Please try again.'),
            ], 500);
        }
    }
}
