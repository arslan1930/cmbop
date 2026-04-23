<?php
// app/Http/Controllers/Advertiser/ReportsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        
        // Get funds activity (deposit requests) - last 50 only
        $fundsActivity = DepositRequest::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        // Add type attribute to each fund activity
        foreach ($fundsActivity as $activity) {
            $activity->type = 'deposit';
        }
        
        // Get orders - last 50 only
        $orders = Order::where('user_id', $userId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        // Calculate totals
        $totalDeposits = DepositRequest::where('user_id', $userId)
            ->where('status', 'completed')
            ->sum('amount');
        
        $totalSpent = Order::where('user_id', $userId)
            ->where('payment_status', 'paid')
            ->sum('total_amount');
        
        $totalOrders = Order::where('user_id', $userId)->count();
        
        return view('advertiser.reports', compact(
            'fundsActivity', 
            'orders', 
            'totalDeposits', 
            'totalSpent', 
            'totalOrders'
        ));
    }
}