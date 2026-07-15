<?php
// app/Http/Controllers/Admin/DepositController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Mail\DepositApproved;
use App\Mail\DepositRejected;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        $query = DepositRequest::with('user');
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('reference_code', 'like', "%{$search}%")
                  ->orWhereHas('user', function($sub) use ($search) {
                      $sub->where('name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }
        
        $deposits = $query->latest()->paginate(20);
        
        $stats = [
            'pending' => DepositRequest::where('status', 'pending')->count(),
            'approved' => DepositRequest::where('status', 'approved')->count(),
            'completed' => DepositRequest::where('status', 'completed')->count(),
            'rejected' => DepositRequest::where('status', 'rejected')->count(),
            'total_amount' => DepositRequest::where('status', 'completed')->sum('amount')
        ];
        
        return view('admin.deposits', compact('deposits', 'stats'));
    }

    public function show($id)
    {
        $deposit = DepositRequest::with('user')->find($id);
        
        if (!$deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found'
            ]);
        }
        
        return response()->json([
            'success' => true,
            'deposit' => $deposit
        ]);
    }

    public function approve(Request $request, $id)
    {
        $deposit = DepositRequest::find($id);
        
        if (!$deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found'
            ]);
        }
        
        if ($deposit->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This deposit request has already been processed.'
            ]);
        }
        
        DB::beginTransaction();
        
        try {
            $deposit = DepositRequest::where('id', $deposit->id)->lockForUpdate()->firstOrFail();

            if ($deposit->status !== 'pending') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This deposit request has already been processed.'
                ]);
            }

            // Update deposit status
            $deposit->update([
                'status' => 'approved',
                'admin_notes' => $request->admin_notes,
                'approved_at' => now()
            ]);
            
            // Credit advertiser wallet with a row lock
            $advertiserRoleId = Wallet::advertiserRoleId();
            if (!$advertiserRoleId) {
                throw new \RuntimeException('Advertiser role not configured');
            }
            $wallet = Wallet::lockOrCreateForRole($deposit->user_id, $advertiserRoleId);
            $wallet->credit((float) $deposit->amount);
            
            // Update deposit status to completed
            $deposit->update(['status' => 'completed']);
            
            DB::commit();
            
            $emailSent = false;
            $emailError = null;
            
            // Send email notification to user using markdown
            try {
                $user = $deposit->user;
                if ($user && $user->email) {
                    Mail::to($user->email)->send(new DepositApproved($deposit));
                    $emailSent = true;
                    Log::info('Deposit approval email sent to: ' . $user->email);
                } else {
                    $emailError = 'User has no email address';
                    Log::warning('Cannot send approval email - User has no email. User ID: ' . $deposit->user_id);
                }
            } catch (\Exception $e) {
                $emailError = $e->getMessage();
                Log::error('Failed to send deposit approved email: ' . $e->getMessage());
            }
            
            $message = 'Deposit approved and funds added to user wallet.';
            if ($emailSent) {
                $message .= ' Email notification sent to user.';
            } else {
                $message .= ' Email could not be sent.';
            }

            ActivityLogger::log(
                'deposit.approved',
                auth()->user()->name . ' approved deposit #' . $deposit->id . ' (€' . number_format($deposit->amount, 2) . ')',
                $deposit,
                ['amount' => $deposit->amount, 'user_id' => $deposit->user_id],
                'Deposit #' . $deposit->id
            );

            return response()->json([
                'success' => true,
                'message' => $message,
                'email_sent' => $emailSent
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve deposit: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve deposit: ' . $e->getMessage()
            ]);
        }
    }

    public function reject(Request $request, $id)
    {
        $deposit = DepositRequest::find($id);
        
        if (!$deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit request not found'
            ]);
        }
        
        if ($deposit->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This deposit request has already been processed.'
            ]);
        }
        
        $deposit->update([
            'status' => 'rejected',
            'admin_notes' => $request->admin_notes,
            'rejected_at' => now()
        ]);
        
        $emailSent = false;
        $emailError = null;
        
        // Send email notification to user using markdown
        try {
            $user = $deposit->user;
            if ($user && $user->email) {
                Mail::to($user->email)->send(new DepositRejected($deposit));
                $emailSent = true;
                Log::info('Deposit rejection email sent to: ' . $user->email);
            } else {
                $emailError = 'User has no email address';
                Log::warning('Cannot send rejection email - User has no email. User ID: ' . $deposit->user_id);
            }
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
            Log::error('Failed to send deposit rejected email: ' . $e->getMessage());
        }
        
        $message = 'Deposit request rejected.';
        if ($emailSent) {
            $message .= ' Email notification sent to user.';
        } else {
            $message .= ' Email could not be sent.';
        }

        ActivityLogger::log(
            'deposit.rejected',
            auth()->user()->name . ' rejected deposit #' . $deposit->id . ' (€' . number_format($deposit->amount, 2) . ')',
            $deposit,
            ['amount' => $deposit->amount, 'user_id' => $deposit->user_id],
            'Deposit #' . $deposit->id
        );

        return response()->json([
            'success' => true,
            'message' => $message,
            'email_sent' => $emailSent
        ]);
    }
}