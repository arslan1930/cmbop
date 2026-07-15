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
        $advertiserBalance = $advertiserWallet ? $advertiserWallet->balance : 0;
        
        // Get publisher wallet balance (role_id = 2)
        $publisherWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', $publisherRoleId)
            ->first();
        $publisherBalance = $publisherWallet ? $publisherWallet->balance : 0;
        
        return view('advertiser.balance', compact('advertiserBalance', 'publisherBalance'));
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
            $amount = (float) $request->amount;

            $publisherRoleId = Wallet::publisherRoleId();
            $advertiserRoleId = Wallet::advertiserRoleId();

            if (!$publisherRoleId || !$advertiserRoleId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet roles are not configured.'
                ]);
            }
            
            DB::beginTransaction();

            // Lock both wallets in a consistent role-id order to avoid deadlocks
            $firstRoleId = min($advertiserRoleId, $publisherRoleId);
            $secondRoleId = max($advertiserRoleId, $publisherRoleId);
            $locked = [
                $firstRoleId => Wallet::lockOrCreateForRole($userId, $firstRoleId),
                $secondRoleId => Wallet::lockOrCreateForRole($userId, $secondRoleId),
            ];
            $advertiserWallet = $locked[$advertiserRoleId];
            $publisherWallet = $locked[$publisherRoleId];

            if ((float) $advertiserWallet->balance < $amount) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance in Advertiser wallet. Available: €' . ($advertiserWallet ? number_format($advertiserWallet->balance, 2) : '0.00')
                ]);
            }

            $advertiserWallet->debit($amount);
            $publisherWallet->credit($amount);
            
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
                'message' => '€' . number_format($amount, 2) . ' transferred from Advertiser to Publisher wallet successfully!',
                'advertiser_balance' => $advertiserWallet->balance,
                'publisher_balance' => $publisherWallet->balance,
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