<?php
// app/Http/Controllers/Advertiser/AddFundsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Mail\DepositRequestSubmitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class AddFundsController extends Controller
{
    public function index()
    {
        $pendingRequests = DepositRequest::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->latest()
            ->get();
            
        $completedRequests = DepositRequest::where('user_id', auth()->id())
            ->whereIn('status', ['approved', 'completed', 'rejected'])
            ->latest()
            ->take(10)
            ->get();

        return view('advertiser.add-funds', compact('pendingRequests', 'completedRequests'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10',
            'payment_method' => 'required|in:wise,crypto,bank,card'
        ]);

        // Generate unique reference code
        do {
            $referenceCode = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (DepositRequest::where('reference_code', $referenceCode)->exists());

        $depositRequest = DepositRequest::create([
            'user_id' => auth()->id(),
            'reference_code' => $referenceCode,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'status' => 'pending'
        ]);

        // Send email notification to admin
        try {
            // Find admin users
            $admins = \App\Models\User::where('active_role_id', function($query) {
                $query->select('id')
                      ->from('roles')
                      ->where('name', 'admin')
                      ->limit(1);
            })->get();
            
            if ($admins->count() > 0) {
                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(new DepositRequestSubmitted($depositRequest));
                }
            } else {
                // Fallback: Send to default admin email
                $defaultAdminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                Mail::to($defaultAdminEmail)->send(new DepositRequestSubmitted($depositRequest));
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send deposit notification email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Deposit request submitted successfully. You will receive an email confirmation once approved.',
            'reference_code' => $referenceCode,
            'deposit_id' => $depositRequest->id
        ]);
    }

    public function getStatus($id)
    {
        $depositRequest = DepositRequest::where('user_id', auth()->id())
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'status' => $depositRequest->status,
            'deposit' => $depositRequest
        ]);
    }
}