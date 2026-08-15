<?php

// app/Http/Controllers/Admin/DepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\DepositRejected;
use App\Models\DepositRequest;
use App\Services\ActivityLogger;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\InAppNotificationService;
use App\Services\Wallet\DepositApproveContext;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Services\Wallet\ManualDepositNotManualException;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->depositFilters($request);
        $query = DepositRequest::with('user');
        $this->applyDepositFilters($query, $filters);

        $stats = [
            'pending' => DepositRequest::where('status', 'pending')->count(),
            'user_reported_paid' => DepositRequest::where('status', 'pending')->whereNotNull('user_marked_paid_at')->count(),
            'completed' => DepositRequest::where('status', 'completed')->count(),
            'rejected' => DepositRequest::where('status', 'rejected')->count(),
            'total_amount' => DepositRequest::where('status', 'completed')->sum('amount'),
        ];

        // Surface user-reported payments first among pending deposits.
        $deposits = $query
            ->orderByRaw('CASE WHEN status = ? AND user_marked_paid_at IS NOT NULL THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['pending', 'pending'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $invoiceLinks = app(AdminInvoiceLinks::class)->forDeposits($deposits->getCollection());

        return view('admin.deposits', array_merge($filters, compact('deposits', 'stats', 'invoiceLinks')));
    }

    public function show($id)
    {
        $deposit = DepositRequest::with('user')->find($id);

        if (! $deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        $invoice = app(AdminInvoiceLinks::class)->forDeposits(collect([$deposit]))->get((int) $deposit->id);
        $wallet = app(DepositApproveContext::class)->forJson($deposit);

        return response()->json([
            'success' => true,
            'deposit' => $deposit,
            'invoice' => $invoice,
            'wallet' => $wallet,
        ]);
    }

    public function approve(Request $request, $id, ManualDepositApprovalService $approvals)
    {
        $notes = $this->validatedAdminNotes($request);

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
        } catch (ManualDepositNotManualException $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Card deposits settle through Stripe.'),
            ]);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'This deposit was already processed.'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to approve deposit: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to approve deposit. Please try again.'),
            ]);
        }
    }

    public function reject(Request $request, $id)
    {
        $notes = $this->validatedAdminNotes($request);

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

            $deposit->update([
                'status' => 'rejected',
                'admin_notes' => $notes,
                'rejected_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
            Log::error('Failed to send deposit rejected email: '.$e->getMessage());
        }

        $message = 'Deposit request rejected.';
        if ($emailSent) {
            $message .= ' Email notification sent to user.';
        } else {
            $message .= ' Email could not be sent.';
        }

        app(InAppNotificationService::class)->notifyDepositRejected($deposit->fresh());

        ActivityLogger::log(
            'deposit.rejected',
            auth()->user()->name.' rejected deposit #'.$deposit->id.' (€'.number_format($deposit->amount, 2).')',
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

    /**
     * @return array{status: string, reported_paid: bool, search: string}
     */
    private function depositFilters(Request $request): array
    {
        $status = search_text($request->input('status'));
        $reportedPaid = $request->boolean('reported_paid') || $status === 'reported_paid';

        if ($reportedPaid) {
            $status = 'reported_paid';
        } elseif (! in_array($status, ['pending', 'approved', 'completed', 'rejected'], true)) {
            $status = '';
        }

        return [
            'status' => $status,
            'reported_paid' => $reportedPaid,
            'search' => search_text($request->input('search')),
        ];
    }

    /**
     * @param  array{status: string, reported_paid: bool, search: string}  $filters
     */
    private function applyDepositFilters($query, array $filters): void
    {
        if ($filters['reported_paid']) {
            $query->where('status', 'pending')->whereNotNull('user_marked_paid_at');
        } elseif ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('reference_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
    }

    private function validatedAdminNotes(Request $request): ?string
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $notes = $data['admin_notes'] ?? null;

        return is_string($notes) ? $notes : null;
    }
}
