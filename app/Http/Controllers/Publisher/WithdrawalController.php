<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\Wallet;
use App\Models\User;
use App\Models\Role;
use App\Mail\WithdrawalRequestNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WithdrawalController extends Controller
{
    private $platformChargePercent = 0.00; // Set to 0% for now, can be configured later
    
    public function index()
    {
        return view('publisher.withdraw');
    }
    
    public function requestWithdrawal(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01|max:999999.99',
                'payment_method' => 'required|in:bank,paypal,wise,crypto'
            ]);
            
            $user = auth()->user();
            $wallet = $user->activeWallet();
            
            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'No wallet found. Please contact support.'
                ]);
            }
            
            $amount = $request->amount;
            // Welcome / promo credit is spend-only and cannot be withdrawn
            $availableBalance = $wallet->withdrawableBalance();
            
            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please enter a valid amount greater than 0.'
                ]);
            }
            
            if ($amount > $availableBalance) {
                $promoNote = $wallet->lockedBonusBalance() > 0
                    ? ' (€' . number_format($wallet->lockedBonusBalance(), 2) . ' site credit is spend-only and cannot be withdrawn.)'
                    : '';

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient withdrawable balance for this withdrawal. Available to withdraw: €' . number_format($availableBalance, 2) . '.' . $promoNote
                ]);
            }
            
            $fee = ($amount * $this->platformChargePercent) / 100;
            $netAmount = $amount - $fee;
            
            // Prepare payment details based on method
            $paymentDetails = [];
            switch ($request->payment_method) {
                case 'bank':
                    $request->validate([
                        'bank_name' => 'required|string|max:255',
                        'account_holder' => 'required|string|max:255',
                        'account_number' => 'required|string|max:255',
                        'swift_code' => 'nullable|string|max:50'
                    ]);
                    $paymentDetails = [
                        'bank_name' => $request->bank_name,
                        'account_holder' => $request->account_holder,
                        'account_number' => $request->account_number,
                        'swift_code' => $request->swift_code
                    ];
                    break;
                    
                case 'paypal':
                    $request->validate([
                        'paypal_email' => 'required|email|max:255'
                    ]);
                    $paymentDetails = [
                        'email' => $request->paypal_email
                    ];
                    break;
                    
                case 'wise':
                    $request->validate([
                        'wise_email' => 'required|email|max:255'
                    ]);
                    $paymentDetails = [
                        'email' => $request->wise_email
                    ];
                    break;
                    
                case 'crypto':
                    $request->validate([
                        'crypto_type' => 'required|string|in:BTC,ETH,USDT,BNB',
                        'wallet_address' => 'required|string|max:255'
                    ]);
                    $paymentDetails = [
                        'crypto_type' => $request->crypto_type,
                        'wallet_address' => $request->wallet_address
                    ];
                    break;
            }
            
            DB::beginTransaction();
            
            // Create withdrawal record
            $withdrawal = Withdrawal::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'payment_method' => $request->payment_method,
                'payment_details' => $paymentDetails,
                'status' => 'pending'
            ]);
            
            // Deduct from withdrawable wallet balance only
            $wallet->deductWithdrawable($amount);
            
            DB::commit();
            
            // Log the withdrawal request
            Log::info('Withdrawal request submitted', [
                'user_id' => $user->id,
                'withdrawal_id' => $withdrawal->id,
                'amount' => $amount,
                'net_amount' => $netAmount,
                'fee' => $fee,
                'payment_method' => $request->payment_method
            ]);
            
            // Send email notification to admins (IMMEDIATELY - no queue)
            $this->sendAdminNotification($withdrawal, $user);
            
            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully! Amount: €' . number_format($amount, 2) . ' (Fee: €' . number_format($fee, 2) . ', You receive: €' . number_format($netAmount, 2) . ')'
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . implode(', ', array_merge(...array_values($e->errors())))
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Withdrawal request failed: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to process withdrawal request. Please try again later. Error: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Send email notification to admins (IMMEDIATE - same as SiteController)
     */
    private function sendAdminNotification($withdrawal, $user)
    {
        try {
            // Find users with admin role using active_role_id (same as SiteController)
            $admins = User::where('active_role_id', function($query) {
                $query->select('id')
                      ->from('roles')
                      ->where('name', 'admin')
                      ->limit(1);
            })->get();
            
            Log::info('Admin search results', [
                'admin_count' => $admins->count(),
                'admins' => $admins->pluck('email', 'id')->toArray()
            ]);
            
            if ($admins->count() > 0) {
                foreach ($admins as $admin) {
                    // Send IMMEDIATELY - using the correct syntax
                    Mail::to($admin->email)->send(new WithdrawalRequestNotification($withdrawal, $user));
                    
                    Log::info('Withdrawal notification sent to admin', [
                        'admin_id' => $admin->id,
                        'admin_email' => $admin->email,
                        'withdrawal_id' => $withdrawal->id
                    ]);
                }
            } else {
                // Fallback: Send to default admin email if no admin users found
                $defaultAdminEmail = config('mail.admin_email', env('ADMIN_EMAIL', 'admin@yourdomain.com'));
                Mail::to($defaultAdminEmail)->send(new WithdrawalRequestNotification($withdrawal, $user));
                Log::info('Withdrawal notification sent to fallback admin email', [
                    'admin_email' => $defaultAdminEmail,
                    'withdrawal_id' => $withdrawal->id
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send withdrawal notification email: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Get withdrawal history for the authenticated user
     */
    public function getHistory(Request $request)
    {
        try {
            $user = auth()->user();
            
            $query = Withdrawal::where('user_id', $user->id);
            
            // Filter by status if provided
            if ($request->has('status') && in_array($request->status, ['pending', 'processing', 'completed', 'cancelled'])) {
                $query->where('status', $request->status);
            }
            
            // Date range filter
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }
            
            $withdrawals = $query->orderBy('created_at', 'desc')->paginate(20);
            
            return response()->json([
                'success' => true,
                'data' => $withdrawals
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch withdrawal history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawal history'
            ]);
        }
    }
    
    /**
     * Get withdrawal statistics
     */
    public function getStatistics()
    {
        try {
            $user = auth()->user();
            
            $totalWithdrawn = Withdrawal::where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum('net_amount');
                
            $pendingWithdrawals = Withdrawal::where('user_id', $user->id)
                ->where('status', 'pending')
                ->sum('amount');
                
            $totalFees = Withdrawal::where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum('fee');
                
            $withdrawalCount = Withdrawal::where('user_id', $user->id)->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total_withdrawn' => $totalWithdrawn,
                    'pending_withdrawals' => $pendingWithdrawals,
                    'total_fees' => $totalFees,
                    'withdrawal_count' => $withdrawalCount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch withdrawal statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics'
            ]);
        }
    }
    
    /**
     * Cancel a pending withdrawal request
     */
    public function cancelWithdrawal($id)
    {
        try {
            $user = auth()->user();
            
            $withdrawal = Withdrawal::where('user_id', $user->id)
                ->where('id', $id)
                ->where('status', 'pending')
                ->first();
                
            if (!$withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal request not found or cannot be cancelled'
                ]);
            }
            
            DB::beginTransaction();
            
            // Refund the amount back to wallet
            $wallet = $user->activeWallet();
            if ($wallet) {
                $wallet->balance += $withdrawal->amount;
                $wallet->save();
            }
            
            // Update withdrawal status
            $withdrawal->status = 'cancelled';
            $withdrawal->save();
            
            DB::commit();
            
            Log::info('Withdrawal cancelled', [
                'user_id' => $user->id,
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request cancelled successfully. €' . number_format($withdrawal->amount, 2) . ' has been returned to your wallet.'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel withdrawal: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel withdrawal request'
            ]);
        }
    }
}