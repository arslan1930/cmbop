<?php
// app/Http/Controllers/Publisher/BalanceController.php

namespace App\Http\Controllers\Publisher;

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
     * Display balance page for publisher
     * Role IDs: 2 = Publisher, 1 = Advertiser
     */
    public function index()
    {
        $user = auth()->user();
        
        // Role IDs: 2 = Publisher, 1 = Advertiser
        $publisherRoleId = 2;
        $advertiserRoleId = 1;
        
        // Get publisher wallet balance (role_id = 2)
        $publisherWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', $publisherRoleId)
            ->first();
        $publisherBalance = $publisherWallet ? $publisherWallet->balance : 0;
        
        // Get advertiser wallet balance (role_id = 1)
        $advertiserWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', $advertiserRoleId)
            ->first();
        $advertiserBalance = $advertiserWallet ? $advertiserWallet->balance : 0;
        
        return view('publisher.balance', compact('publisherBalance', 'advertiserBalance'));
    }

    /**
     * Transfer from Publisher to Advertiser wallet
     */
    public function transferToAdvertiser(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:1'
            ]);
            
            $userId = auth()->id();
            
            // Role IDs: 2 = Publisher, 1 = Advertiser
            $publisherRoleId = 2;
            $advertiserRoleId = 1;
            
            // Get publisher wallet (source)
            $publisherWallet = Wallet::where('user_id', $userId)
                ->where('role_id', $publisherRoleId)
                ->first();
            
            if (!$publisherWallet || $publisherWallet->balance < $request->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance in Publisher wallet. Available: €' . ($publisherWallet ? number_format($publisherWallet->balance, 2) : '0.00')
                ]);
            }
            
            DB::beginTransaction();
            
            // Deduct from publisher wallet
            $publisherWallet->balance -= $request->amount;
            $publisherWallet->save();
            
            // Get or create advertiser wallet (destination)
            $advertiserWallet = Wallet::where('user_id', $userId)
                ->where('role_id', $advertiserRoleId)
                ->first();
            
            if (!$advertiserWallet) {
                $advertiserWallet = Wallet::create([
                    'user_id' => $userId,
                    'role_id' => $advertiserRoleId,
                    'balance' => 0,
                    'reserved_balance' => 0,
                    'currency' => 'EUR'
                ]);
            }
            
            // Add to advertiser wallet
            $advertiserWallet->balance += $request->amount;
            $advertiserWallet->save();
            
            // Create transfer record
            $transfer = BalanceTransfer::create([
                'user_id' => $userId,
                'from_role' => 'publisher',
                'to_role' => 'advertiser',
                'amount' => $request->amount,
                'fee' => 0,
                'net_amount' => $request->amount,
                'reference_code' => BalanceTransfer::generateReferenceCode(),
                'status' => 'completed',
                'notes' => null
            ]);
            
            DB::commit();
            
            Log::info('Transfer from Publisher to Advertiser completed', [
                'user_id' => $userId,
                'amount' => $request->amount,
                'reference' => $transfer->reference_code
            ]);
            
            return response()->json([
                'success' => true,
                'message' => '€' . number_format($request->amount, 2) . ' transferred from Publisher to Advertiser wallet successfully!',
                'publisher_balance' => $publisherWallet->balance,
                'advertiser_balance' => $advertiserWallet->balance,
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
     * Get transfer history - ONLY show transfers FROM Publisher
     */
    public function getTransferHistory(Request $request)
    {
        try {
            $userId = auth()->id();
            
            // Only show transfers where from_role = 'publisher'
            $transfers = BalanceTransfer::where('user_id', $userId)
                ->where('from_role', 'publisher')
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