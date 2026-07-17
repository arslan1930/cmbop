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
     * Role-to-role transfers are disabled.
     */
    public function transferToAdvertiser(Request $request)
    {
        return response()->json([
            'success' => false,
            'code' => 'transfers_disabled',
            'message' => 'Role-to-role fund transfers have been disabled. Available funds can be spent on the marketplace or withdrawn. Bonus credit can only be used for purchases on this website.',
        ], 410);
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