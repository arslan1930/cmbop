<?php

// app/Http/Controllers/Admin/DepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\DepositRejected;
use App\Models\DepositRequest;
use App\Services\ActivityLogger;
use App\Services\Billing\AdminInvoiceLinks;
use App\Services\InAppNotificationService;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        $query = DepositRequest::with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $search = search_text($request->input('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $stats = [
            'pending' => DepositRequest::where('status', 'pending')->count(),
            'user_reported_paid' => DepositRequest::where('status', 'pending')->whereNotNull('user_marked_paid_at')->count(),
            'approved' => DepositRequest::where('status', 'approved')->count(),
            'completed' => DepositRequest::where('status', 'completed')->count(),
            'rejected' => DepositRequest::where('status', 'rejected')->count(),
            'total_amount' => DepositRequest::where('status', 'completed')->sum('amount'),
        ];

        // Surface user-reported payments first among pending deposits.
        $deposits = $query
            ->orderByRaw('CASE WHEN status = ? AND user_marked_paid_at IS NOT NULL THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['pending', 'pending'])
            ->latest()
            ->paginate(20);

        $invoiceLinks = app(AdminInvoiceLinks::class)->forDeposits($deposits->getCollection());

        return view('admin.deposits', compact('deposits', 'stats', 'invoiceLinks'));
    }

    public function show($id)
    {
        $deposit = DepositRequest::with(['user:id,name,email'])->find($id);

        if (! $deposit) {
            return $this->noStoreJson([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        $invoice = app(AdminInvoiceLinks::class)->forDeposits(collect([$deposit]))->get((int) $deposit->id);

        return $this->noStoreJson([
            'success' => true,
            'deposit' => [
                'id' => $deposit->id,
                'reference_code' => $deposit->reference_code,
                'amount' => $deposit->amount,
                'payment_method' => $deposit->payment_method,
                'status' => $deposit->status,
                'user_marked_paid_at' => optional($deposit->user_marked_paid_at)?->toIso8601String(),
                'user_payment_note' => $deposit->user_payment_note,
                'admin_notes' => $deposit->admin_notes,
                'created_at' => optional($deposit->created_at)?->toIso8601String(),
                'approved_at' => optional($deposit->approved_at)?->toIso8601String(),
                'rejected_at' => optional($deposit->rejected_at)?->toIso8601String(),
                'paid_at' => optional($deposit->paid_at)?->toIso8601String(),
                'user' => $deposit->user ? [
                    'id' => $deposit->user->id,
                    'name' => $deposit->user->name,
                    'email' => $deposit->user->email,
                ] : null,
            ],
            'invoice' => $invoice,
        ]);
    }

    public function approve(Request $request, $id, ManualDepositApprovalService $approvals)
    {
        $notes = $this->validatedAdminNotes($request);

        $deposit = DepositRequest::find($id);

        if (! $deposit) {
            return $this->noStoreJson([
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

            return $this->noStoreJson([
                'success' => true,
                'message' => $result['message'],
                'email_sent' => $result['email_sent'],
            ]);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return $this->noStoreJson([
                'success' => false,
                'message' => UserFacingError::message($e, 'This deposit was already processed.'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to approve deposit: '.$e->getMessage());

            return $this->noStoreJson([
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
            return $this->noStoreJson([
                'success' => false,
                'message' => 'Deposit request not found',
            ]);
        }

        if ($deposit->status !== 'pending') {
            return $this->noStoreJson([
                'success' => false,
                'message' => 'This deposit request has already been processed.',
            ]);
        }

        DB::beginTransaction();

        try {
            $deposit = DepositRequest::where('id', $deposit->id)->lockForUpdate()->firstOrFail();

            if ($deposit->status !== 'pending') {
                DB::rollBack();

                return $this->noStoreJson([
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

            return $this->noStoreJson([
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

        return $this->noStoreJson([
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

        return is_string($notes) ? $notes : null;
    }

    private function noStoreJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }
}
