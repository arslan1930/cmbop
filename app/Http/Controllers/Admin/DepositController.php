<?php

// app/Http/Controllers/Admin/DepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\DepositRejected;
use App\Models\DepositRequest;
use App\Services\ActivityLogger;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\InAppNotificationService;
use App\Services\PaypalCheckoutService;
use App\Services\Wallet\DepositApproveContext;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Services\WalletPaypalDepositService;
use App\Support\UserFacingError;
use App\Support\UserMessages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        if (! DepositRequest::tableAvailable()) {
            $deposits = new LengthAwarePaginator([], 0, 20);
            $deposits->withPath($request->url())->appends($request->query());
            $stats = [
                'pending' => 0,
                'user_reported_paid' => 0,
                'approved' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total_amount' => 0,
            ];
            $invoiceLinks = collect();

            return view('admin.deposits', compact('deposits', 'stats', 'invoiceLinks'));
        }

        $query = DepositRequest::with('user');
        $this->applyDepositIndexFilters($query, $request);

        try {
            $userReportedPaid = 0;
            if (DepositRequest::hasUserMarkedPaidAtColumn()) {
                $userReportedPaid = DepositRequest::where('status', 'pending')->whereUserMarkedPaidAtIsRecorded()->count();
            }
            $this->applyDepositIndexSort($query, $request);

            $stats = [
                'pending' => DepositRequest::where('status', 'pending')->count(),
                'user_reported_paid' => $userReportedPaid,
                'approved' => DepositRequest::where('status', 'approved')->count(),
                'completed' => DepositRequest::where('status', 'completed')->count(),
                'rejected' => DepositRequest::where('status', 'rejected')->count(),
                'refunded' => DepositRequest::where('status', 'refunded')->count(),
                'total_amount' => DepositRequest::where('status', 'completed')->sum('amount'),
            ];

            $deposits = $query
                ->paginate(20)
                ->withPath($request->url())
                ->appends($request->query());

            $invoiceLinks = app(AdminInvoiceLinks::class)->forDeposits($deposits->getCollection());

            return view('admin.deposits', compact('deposits', 'stats', 'invoiceLinks'));
        } catch (\Throwable $e) {
            Log::warning('Failed to load admin deposits index: '.$e->getMessage());
            $deposits = new LengthAwarePaginator([], 0, 20);
            $deposits->withPath($request->url())->appends($request->query());
            $stats = [
                'pending' => 0,
                'user_reported_paid' => 0,
                'approved' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total_amount' => 0,
            ];

            return view('admin.deposits', [
                'deposits' => $deposits,
                'stats' => $stats,
                'invoiceLinks' => collect(),
            ]);
        }
    }

    public function show($id)
    {
        if (! DepositRequest::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        try {
            $deposit = DepositRequest::with('user')->find($id);

            if (! $deposit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deposit request not found',
                ]);
            }

            $invoice = app(AdminInvoiceLinks::class)->forDeposits(collect([$deposit]))->get((int) $deposit->id);
            $manual = in_array(strtolower((string) $deposit->payment_method), ['bank', 'wise', 'crypto'], true);
            $canApprove = $deposit->isPending() && $manual;
            $approveContext = null;
            try {
                $approveContext = app(DepositApproveContext::class)->modalPayload($deposit, $canApprove);
            } catch (\Throwable $e) {
                Log::warning('Failed to build deposit modal context: '.$e->getMessage(), [
                    'deposit_id' => $deposit->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'deposit' => $deposit,
                'invoice' => $invoice,
                'can_approve_manual' => $canApprove,
                'approve_context' => $approveContext,
                'finance_url' => $deposit->user_id
                    ? route('admin.finance.user', $deposit->user_id)
                    : null,
                'can_refund_paypal' => $deposit->isPaypalRefundable()
                    && app(PaypalCheckoutService::class)->configured(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to load admin deposit detail: '.$e->getMessage(), [
                'deposit_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }
    }

    public function approve(Request $request, $id, ManualDepositApprovalService $approvals)
    {
        $notes = $this->validatedAdminNotes($request);

        if (! DepositRequest::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        $deposit = DepositRequest::find($id);

        if (! $deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        try {
            $result = $approvals->approve(
                $deposit,
                $request->user(),
                $notes
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'email_sent' => $result['email_sent'],
            ]);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'This deposit was already processed.'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to approve deposit: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to approve deposit. Please try again.'),
            ]);
        }
    }

    public function refundPaypal(Request $request, $id, WalletPaypalDepositService $paypalDeposits)
    {
        $notes = $this->validatedAdminNotes($request);

        if (! DepositRequest::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        $deposit = DepositRequest::find($id);
        if (! $deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        try {
            $result = $paypalDeposits->refundCapture($deposit);
            $fresh = $result['deposit'];
            if (filled($notes) && $fresh) {
                $existing = trim((string) ($fresh->admin_notes ?? ''));
                $fresh->update(DepositRequest::attributesThatExist([
                    'admin_notes' => $existing !== '' ? $existing."\n".$notes : $notes,
                ]));
            }

            ActivityLogger::tryLog(
                $result['already_refunded'] ? 'deposit.paypal_refund_replayed' : 'deposit.paypal_refunded',
                ($result['already_refunded'] ? 'Replayed ' : 'Refunded ').'PayPal Add Funds deposit REF '.($fresh->reference_code ?: $fresh->id),
                $fresh,
                [
                    'deposit_id' => $fresh->id,
                    'reference_code' => $fresh->reference_code,
                    'amount' => (float) $fresh->amount,
                ]
            );

            return response()->json([
                'success' => true,
                'already_refunded' => $result['already_refunded'],
                'message' => $result['already_refunded']
                    ? 'This PayPal deposit was already refunded. The wallet was not changed again.'
                    : 'PayPal capture refunded and the wallet credit was reversed.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to refund PayPal deposit: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, UserMessages::get('payment.paypal_refund_failed')),
            ], 422);
        }
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = collect();
        try {
            if (DepositRequest::tableAvailable()) {
                $query = DepositRequest::with('user:id,name,email');
                $this->applyDepositIndexFilters($query, $request);
                $this->applyDepositIndexSort($query, $request);
                $rows = $query->limit(5000)->get();
            }
        } catch (\Throwable $e) {
            Log::warning('Admin deposits export query failed', ['error' => $e->getMessage()]);
            $rows = collect();
        }

        ActivityLogger::tryLog(
            'deposit.exported',
            ($request->user()?->name ?? 'Admin').' exported deposits ('.$rows->count().' row(s)).',
            null,
            ['rows_exported' => $rows->count()]
        );

        $filename = 'deposits-export-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'id',
                'reference',
                'advertiser',
                'email',
                'method',
                'wallet_amount',
                'charge_currency',
                'charge_amount',
                'status',
                'reported_paid_at',
                'created_at',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id,
                    $row->reference_code,
                    $row->user?->name,
                    $row->user?->email,
                    $row->payment_method,
                    $row->amount,
                    $row->charge_currency,
                    $row->charge_amount,
                    $row->status,
                    optional($row->user_marked_paid_at)?->toDateTimeString(),
                    optional($row->created_at)?->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function reject(Request $request, $id)
    {
        if (! DepositRequest::tableAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        $deposit = DepositRequest::find($id);

        if (! $deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        if ($deposit->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This deposit request has already been processed.',
            ]);
        }

        $notes = $this->validatedRejectNotes($request);

        DB::beginTransaction();

        try {
            $deposit = DepositRequest::where('id', $deposit->id)->lockForUpdate()->firstOrFail();

            if ($deposit->status !== 'pending') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'This deposit request has already been processed.',
                ]);
            }

            $deposit->update(DepositRequest::attributesThatExist([
                'status' => 'rejected',
                'admin_notes' => $notes,
                'rejected_at' => now(),
            ]));

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to reject deposit: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to reject deposit. Please try again.'),
            ]);
        }

        $emailSent = false;
        $emailError = null;

        // Send email notification to user using markdown
        try {
            $user = $deposit->user;
            if ($user && $user->email) {
                Mail::to($user->email)->send(new DepositRejected($deposit));
                $emailSent = true;
                Log::info('Deposit rejection email sent to: '.$user->email);
            } else {
                $emailError = 'User has no email address';
                Log::warning('Cannot send rejection email - User has no email. User ID: '.$deposit->user_id);
            }
        } catch (\Throwable $e) {
            $emailError = UserFacingError::safeText($e->getMessage(), 'Email could not be sent.');
            Log::error('Failed to send deposit rejected email: '.$e->getMessage());
        }

        $message = 'Deposit request rejected.';
        if ($emailSent) {
            $message .= ' Email notification sent to user.';
        } else {
            $message .= ' Email could not be sent.';
        }

        try {
            app(InAppNotificationService::class)->notifyDepositRejected($deposit->fresh());
        } catch (\Throwable $e) {
            Log::warning('Deposit rejection notification failed', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);
        }

        ActivityLogger::tryLog(
            'deposit.rejected',
            (auth()->user()?->name ?: 'System').' rejected deposit #'.$deposit->id.' (€'.number_format((float) $deposit->amount, 2).')',
            $deposit,
            ['amount' => $deposit->amount, 'user_id' => $deposit->user_id],
            'Deposit #'.$deposit->id
        );

        return response()->json([
            'success' => true,
            'message' => $message,
            'email_sent' => $emailSent,
        ]);
    }

    private function validatedAdminNotes(Request $request): ?string
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $notes = $data['admin_notes'] ?? null;

        return is_string($notes) ? trim($notes) : null;
    }

    private function validatedRejectNotes(Request $request): string
    {
        $raw = $request->input('admin_notes');
        if (is_string($raw)) {
            $request->merge(['admin_notes' => trim($raw)]);
        }

        $data = $request->validate([
            'admin_notes' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return (string) $data['admin_notes'];
    }

    /**
     * @param  Builder<DepositRequest>  $query
     */
    private function applyDepositIndexFilters($query, Request $request): void
    {
        $reported = $request->boolean('reported');
        $status = scalar_text($request->input('status'));
        if ($reported && $status === 'pending') {
            $query->where('status', 'pending');
            if (DepositRequest::hasUserMarkedPaidAtColumn()) {
                $query->whereUserMarkedPaidAtIsRecorded();
            }
        } elseif (in_array($status, ['pending', 'approved', 'completed', 'rejected', 'refunded'], true)) {
            $query->where('status', $status);
        }

        $method = strtolower(scalar_text($request->input('payment_method')));
        if (in_array($method, ['bank', 'wise', 'crypto', 'card', 'paypal'], true)) {
            $query->where('payment_method', $method);
        }

        $from = scalar_text($request->input('from'));
        if ($from !== '' && strtotime($from) !== false) {
            $query->whereDate('created_at', '>=', $from);
        }
        $to = scalar_text($request->input('to'));
        if ($to !== '' && strtotime($to) !== false) {
            $query->whereDate('created_at', '<=', $to);
        }

        $search = search_text($request->input('search'));
        if ($search === '') {
            return;
        }

        if (preg_match('/^#?DEP-?(\d+)$/i', $search, $matches) === 1) {
            $query->whereKey((int) $matches[1]);

            return;
        }

        $query->where(function ($q) use ($search) {
            if (ctype_digit($search)) {
                $q->whereKey((int) $search);
            }

            $q->orWhere('reference_code', 'like', "%{$search}%")
                ->orWhereHas('user', function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        });
    }

    /**
     * @param  Builder<DepositRequest>  $query
     */
    private function applyDepositIndexSort($query, Request $request): void
    {
        $sort = scalar_text($request->input('sort'));
        if ($sort !== 'oldest' && $sort !== 'amount' && DepositRequest::hasUserMarkedPaidAtColumn()) {
            $query->orderByRaw(
                'CASE WHEN status = ? AND user_marked_paid_at IS NOT NULL AND user_marked_paid_at >= ? AND user_marked_paid_at <= ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END',
                ['pending', DepositRequest::PLAUSIBLE_SQL_DATETIME_FLOOR, DepositRequest::PLAUSIBLE_SQL_DATETIME_CEIL, 'pending']
            );
        }

        match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'amount' => $query->orderByDesc('amount')->orderByDesc('id'),
            default => $query->latest('created_at')->orderByDesc('id'),
        };
    }
}
