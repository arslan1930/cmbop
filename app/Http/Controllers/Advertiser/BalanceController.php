<?php
// app/Http/Controllers/Advertiser/BalanceController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\BalanceTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BalanceController extends Controller
{
    /**
     * Display balance page for advertiser
     * Role IDs: 2 = Publisher, 1 = Advertiser
     */
    public function index()
    {
        $user = auth()->user();
        
        // Role IDs: 2 = Publisher, 1 = Advertiser
        $publisherRoleId = 2;
        $advertiserRoleId = 1;
        
        // Get advertiser wallet balance (role_id = 1)
        $advertiserWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', $advertiserRoleId)
            ->first();
        $advertiserBalance = $advertiserWallet ? (float) $advertiserWallet->balance : 0;
        $advertiserBonusBalance = $advertiserWallet ? $advertiserWallet->lockedBonusBalance() : 0;
        $advertiserWithdrawableBalance = $advertiserWallet ? $advertiserWallet->withdrawableBalance() : 0;
        
        // Get publisher wallet balance (role_id = 2)
        $publisherWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', $publisherRoleId)
            ->first();
        $publisherBalance = $publisherWallet ? (float) $publisherWallet->balance : 0;
        
        return view('advertiser.balance', compact(
            'advertiserBalance',
            'advertiserBonusBalance',
            'advertiserWithdrawableBalance',
            'publisherBalance'
        ));
    }

    /**
     * Transfer from Advertiser to Publisher wallet
     */
    public function transferToPublisher(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:1'
            ]);
            
            $userId = auth()->id();
            
            // Role IDs: 2 = Publisher, 1 = Advertiser
            $publisherRoleId = 2;
            $advertiserRoleId = 1;
            
            // Get advertiser wallet (source)
            $advertiserWallet = Wallet::where('user_id', $userId)
                ->where('role_id', $advertiserRoleId)
                ->first();
            
            $withdrawable = $advertiserWallet ? $advertiserWallet->withdrawableBalance() : 0;

            if (!$advertiserWallet || $withdrawable < $request->amount) {
                $promoNote = ($advertiserWallet && $advertiserWallet->lockedBonusBalance() > 0)
                    ? ' Site credit (€' . number_format($advertiserWallet->lockedBonusBalance(), 2) . ') can only be spent on orders.'
                    : '';

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient transferable balance in Advertiser wallet. Available to transfer: €' . number_format($withdrawable, 2) . '.' . $promoNote
                ]);
            }
            
            DB::beginTransaction();
            
            // Deduct withdrawable funds only (welcome bonus stays on advertiser wallet)
            $advertiserWallet->deductWithdrawable((float) $request->amount);
            
            // Get or create publisher wallet (destination)
            $publisherWallet = Wallet::where('user_id', $userId)
                ->where('role_id', $publisherRoleId)
                ->first();
            
            if (!$publisherWallet) {
                $publisherWallet = Wallet::create([
                    'user_id' => $userId,
                    'role_id' => $publisherRoleId,
                    'balance' => 0,
                    'reserved_balance' => 0,
                    'bonus_balance' => 0,
                    'bonus_reserved' => 0,
                    'currency' => 'EUR'
                ]);
            }
            
            // Add to publisher wallet as withdrawable earnings
            $publisherWallet->balance += $request->amount;
            $publisherWallet->save();
            
            // Create transfer record
            $transfer = BalanceTransfer::create([
                'user_id' => $userId,
                'from_role' => 'advertiser',
                'to_role' => 'publisher',
                'amount' => $amount,
                'fee' => 0,
                'net_amount' => $amount,
                'reference_code' => BalanceTransfer::generateReferenceCode(),
                'status' => 'completed',
                'notes' => null
            ]);
            
            DB::commit();
            
            Log::info('Transfer from Advertiser to Publisher completed', [
                'user_id' => $userId,
                'amount' => $amount,
                'reference' => $transfer->reference_code
            ]);
            
            return response()->json([
                'success' => true,
                'message' => '€' . number_format($request->amount, 2) . ' transferred from Advertiser to Publisher wallet successfully!',
                'advertiser_balance' => (float) $advertiserWallet->balance,
                'advertiser_withdrawable_balance' => $advertiserWallet->withdrawableBalance(),
                'advertiser_bonus_balance' => $advertiserWallet->lockedBonusBalance(),
                'publisher_balance' => (float) $publisherWallet->balance,
                'transfer' => $transfer
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transfer failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Transfer failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get transfer history - ONLY show transfers FROM Advertiser
     */
    public function getTransferHistory(Request $request)
    {
        try {
            $userId = auth()->id();
            
            // Only show transfers where from_role = 'advertiser'
            $transfers = BalanceTransfer::where('user_id', $userId)
                ->where('from_role', 'advertiser')
                ->orderBy('created_at', 'desc')
                ->paginate(20);
            
            return response()->json([
                'success' => true,
                'transfers' => $transfers->items(),
                'pagination' => [
                    'current_page' => $transfers->currentPage(),
                    'last_page' => $transfers->lastPage(),
                    'per_page' => $transfers->perPage(),
                    'total' => $transfers->total(),
                    'from' => $transfers->firstItem(),
                    'to' => $transfers->lastItem()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching transfer history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transfer history'
            ]);
        }
    }
}