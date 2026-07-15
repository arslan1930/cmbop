<?php
// app/Http/Controllers/Admin/AdminWithdrawalController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\User;
use App\Models\Wallet;
use App\Mail\WithdrawalStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminWithdrawalController extends Controller
{
    /**
     * Display withdrawals management page
     */
    public function index()
    {
        return view('admin.withdrawals');
    }

    /**
     * Get withdrawals data for DataTable (AJAX)
     */
    public function getWithdrawalsData(Request $request)
    {
        try {
            $query = Withdrawal::with('user')->orderBy('created_at', 'desc');

            // Search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                      ->orWhereHas('user', function($sub) use ($search) {
                          $sub->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Payment method filter
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }

            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 20);
            $withdrawals = $query->paginate($perPage);

            // Transform payment_details to array if it's a string
            $withdrawals->getCollection()->transform(function($withdrawal) {
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
                    'to' => $withdrawals->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching withdrawals: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawals: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single withdrawal details
     */
    public function show($id)
    {
        try {
            $withdrawal = Withdrawal::with('user')->findOrFail($id);
            
            // Decode payment_details if it's a string
            if (is_string($withdrawal->payment_details)) {
                $withdrawal->payment_details = json_decode($withdrawal->payment_details, true);
            }
            
            return response()->json([
                'success' => true,
                'data' => $withdrawal
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal not found'
            ], 404);
        }
    }

    /**
     * Update withdrawal status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:pending,processing,completed,cancelled',
                'notes' => 'nullable|string'
            ]);

            DB::beginTransaction();

            $withdrawal = Withdrawal::with('user')->where('id', $id)->lockForUpdate()->firstOrFail();
            $oldStatus = $withdrawal->status;
            $newStatus = $request->status;

            // Update withdrawal status
            $withdrawal->status = $newStatus;
            $withdrawal->save();

            // If cancelling, refund the amount back to publisher wallet
            if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
                $publisherRoleId = Wallet::publisherRoleId();
                $wallet = $publisherRoleId
                    ? Wallet::lockOrCreateForRole($withdrawal->user_id, $publisherRoleId)
                    : null;

                if ($wallet) {
                    $wallet->credit((float) $withdrawal->amount);
                    
                    Log::info('Withdrawal cancelled - amount refunded', [
                        'withdrawal_id' => $withdrawal->id,
                        'user_id' => $withdrawal->user_id,
                        'refunded_amount' => $withdrawal->amount
                    ]);
                }
            }

            // If completing, ensure wallet balance is already deducted (already done when request was made)
            // No additional action needed

            DB::commit();

            // Send email notification to publisher
            $this->sendStatusUpdateEmail($withdrawal, $oldStatus, $newStatus, $request->notes);

            Log::info('Withdrawal status updated', [
                'withdrawal_id' => $withdrawal->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'admin_id' => auth()->id(),
                'notes' => $request->notes
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal status updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating withdrawal status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send email notification to publisher about status update
     */
    private function sendStatusUpdateEmail($withdrawal, $oldStatus, $newStatus, $notes)
    {
        try {
            $user = $withdrawal->user;
            
            if ($user && $user->email) {
                // Only send email if status actually changed and it's not the initial pending status
                if ($oldStatus !== $newStatus && $newStatus !== 'pending') {
                    Mail::to($user->email)->send(new WithdrawalStatusUpdated($withdrawal, $oldStatus, $newStatus, $notes));
                    Log::info('Withdrawal status update email sent to publisher', [
                        'withdrawal_id' => $withdrawal->id,
                        'user_email' => $user->email,
                        'new_status' => $newStatus
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send withdrawal status update email: ' . $e->getMessage());
        }
    }

    /**
     * Get withdrawal statistics
     */
    public function getStatistics()
    {
        try {
            $stats = [
                'total_withdrawals' => Withdrawal::count(),
                'pending' => Withdrawal::where('status', 'pending')->count(),
                'processing' => Withdrawal::where('status', 'processing')->count(),
                'completed' => Withdrawal::where('status', 'completed')->count(),
                'cancelled' => Withdrawal::where('status', 'cancelled')->count(),
                'total_amount_requested' => Withdrawal::sum('amount'),
                'total_fees_collected' => Withdrawal::sum('fee'),
                'total_amount_paid' => Withdrawal::where('status', 'completed')->sum('net_amount')
            ];
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching withdrawal statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics'
            ]);
        }
    }
}