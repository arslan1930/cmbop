<?php
// app/Http/Controllers/Advertiser/AddFundsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Mail\DepositRequestSubmitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class AddFundsController extends Controller
{
    public function index()
    {
        $pendingRequests = DepositRequest::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->latest()
            ->get();
            
        $allRequests = DepositRequest::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('advertiser.add-funds', compact('pendingRequests', 'allRequests'));
    }
    
    public function createCheckoutSession(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:10',
                'reference_code' => 'required|string'
            ]);
            
            if (!config('services.stripe.secret') || config('services.stripe.secret') === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured. Please contact support.'
                ]);
            }
            
            Stripe::setApiKey(config('services.stripe.secret'));
            
            $amount = $request->amount * 100;
            $referenceCode = $request->reference_code;
            $user = auth()->user();
            
            // Generate a unique session reference (NO deposit record created here)
            $sessionReference = 'deposit_' . uniqid();
            
            // Create Stripe Checkout Session WITHOUT creating deposit record first
            $checkoutSession = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Add Funds to Wallet',
                            'description' => 'Deposit €' . number_format($request->amount, 2) . ' to your wallet',
                        ],
                        'unit_amount' => $amount,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('advertiser.checkout.success') . '?session_id={CHECKOUT_SESSION_ID}&amount=' . $request->amount . '&ref=' . $referenceCode,
                'cancel_url' => route('advertiser.add-funds'),
                'metadata' => [
                    'reference_code' => $referenceCode,
                    'user_id' => (string) $user->id,
                    'amount' => (string) $request->amount,
                    'session_reference' => $sessionReference
                ],
                'customer_email' => $user->email,
            ]);
            
            return response()->json([
                'success' => true,
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id
            ]);
            
        } catch (\Exception $e) {
            Log::error('Stripe checkout error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session: ' . $e->getMessage()
            ]);
        }
    }
    
    public function checkoutSuccess(Request $request)
    {
        $sessionId = $request->session_id;
        $amount = $request->amount;
        $referenceCode = $request->ref;
        
        if (!$sessionId) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Invalid payment session.');
        }
        
        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            
            // Retrieve the session from Stripe
            $session = Session::retrieve($sessionId);
            
            if ($session->payment_status === 'paid') {
                // Check if deposit already exists
                $existingDeposit = DepositRequest::where('stripe_session_id', $sessionId)->first();
                
                if (!$existingDeposit) {
                    // Generate unique reference code if not provided
                    if (!$referenceCode) {
                        do {
                            $referenceCode = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                        } while (DepositRequest::where('reference_code', $referenceCode)->exists());
                    }
                    
                    // Create deposit record only after successful payment
                    $deposit = DepositRequest::create([
                        'user_id' => auth()->id(),
                        'reference_code' => $referenceCode,
                        'amount' => $amount ?? ($session->amount_total / 100),
                        'payment_method' => 'card',
                        'status' => 'completed',
                        'stripe_session_id' => $sessionId,
                        'stripe_payment_intent_id' => $session->payment_intent,
                        'stripe_response' => json_encode($session),
                        'approved_at' => now(),
                        'paid_at' => now()
                    ]);
                    
                    // Update wallet balance
                    $wallet = Wallet::firstOrCreate(
                        ['user_id' => auth()->id()],
                        ['balance' => 0, 'reserved_balance' => 0]
                    );
                    $wallet->balance += $deposit->amount;
                    $wallet->save();
                    
                    return redirect()->route('advertiser.add-funds')
                        ->with('success', 'Payment successful! €' . number_format($deposit->amount, 2) . ' added to your wallet.');
                } else {
                    return redirect()->route('advertiser.add-funds')
                        ->with('success', 'Payment already processed! €' . number_format($existingDeposit->amount, 2) . ' added to your wallet.');
                }
            }
            
            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Payment verification failed. Please contact support.');
                
        } catch (\Exception $e) {
            Log::error('Checkout success error: ' . $e->getMessage());
            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Failed to verify payment. Please contact support.');
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10',
            'payment_method' => 'required|in:wise,crypto,bank',
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
                $defaultAdminEmail = config('mail.admin_email', 'admin@yourdomain.com');
                Mail::to($defaultAdminEmail)->send(new DepositRequestSubmitted($depositRequest));
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send deposit notification email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Deposit request submitted successfully.',
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