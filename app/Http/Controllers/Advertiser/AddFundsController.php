<?php
// app/Http/Controllers/Advertiser/AddFundsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Models\User;
use App\Mail\DepositRequestSubmitted;
use App\Services\StripePaymentService;
use App\Services\Wallet\WalletOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class AddFundsController extends Controller
{
    public function __construct(
        protected WalletOverviewService $overview
    ) {
    }

    public function index()
    {
        $user = auth()->user();
        $advertiserRoleId = Wallet::advertiserRoleId() ?? 1;

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $advertiserRoleId],
            [
                'balance' => 0,
                'reserved_balance' => 0,
                'bonus_balance' => 0,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]
        );

        $pendingRequests = DepositRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->get();

        $wallet->repairOrphanedWelcomeBonus();
        $wallet->refresh();

        $summary = $this->overview->summary($user->id, $wallet);
        $analytics = $this->overview->analytics($user->id, 'month');

        return view('advertiser.add-funds', [
            'pendingRequests' => $pendingRequests,
            'wallet' => $wallet,
            'summary' => $summary,
            'analytics' => $analytics,
            'advertiserBalance' => (float) $wallet->balance,
            'advertiserBonusBalance' => $wallet->lockedBonusBalance(),
            'advertiserWithdrawableBalance' => $wallet->withdrawableBalance(),
            'promotionalBonusMessage' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
            'payoutProfile' => $user->payoutProfile(),
        ]);
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
            
            $amountEuros = round((float) $request->amount, 2);
            $amountCents = StripePaymentService::toCents($amountEuros);
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
                            'description' => 'Deposit €' . number_format($amountEuros, 2) . ' to your wallet',
                        ],
                        'unit_amount' => $amountCents,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('advertiser.checkout.success') . '?session_id={CHECKOUT_SESSION_ID}&amount=' . $amountEuros . '&ref=' . $referenceCode,
                'cancel_url' => route('advertiser.add-funds'),
                'metadata' => [
                    'type' => 'wallet_deposit',
                    'user_id' => (string) $user->id,
                    'amount' => (string) $amountEuros,
                    'reference_code' => $referenceCode,
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
                $creditedAmount = null;

                DB::transaction(function () use ($sessionId, $session, $amount, &$referenceCode, &$creditedAmount) {
                    $existingDeposit = DepositRequest::where('stripe_session_id', $sessionId)
                        ->lockForUpdate()
                        ->first();

                    if ($existingDeposit) {
                        $creditedAmount = (float) $existingDeposit->amount;
                        return;
                    }

                    if (!$referenceCode) {
                        do {
                            $referenceCode = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                        } while (DepositRequest::where('reference_code', $referenceCode)->exists());
                    }

                    $deposit = DepositRequest::create([
                        'user_id' => auth()->id(),
                        'reference_code' => $referenceCode,
                        'amount' => isset($amount)
                            ? round((float) $amount, 2)
                            : StripePaymentService::fromCents($session->amount_total),
                        'payment_method' => 'card',
                        'status' => 'completed',
                        'stripe_session_id' => $sessionId,
                        'stripe_payment_intent_id' => $session->payment_intent,
                        'stripe_response' => json_encode($session),
                        'approved_at' => now(),
                        'paid_at' => now()
                    ]);

                    $advertiserRoleId = Wallet::advertiserRoleId();
                    if (!$advertiserRoleId) {
                        throw new \RuntimeException('Advertiser role not configured');
                    }

                    $wallet = Wallet::lockOrCreateForRole(auth()->id(), $advertiserRoleId);
                    $wallet->credit((float) $deposit->amount);
                    app(\App\Services\Wallet\WalletLedgerService::class)->recordDeposit(
                        $wallet,
                        (float) $deposit->amount,
                        $deposit,
                        'card',
                        $deposit->reference_code
                    );
                    $creditedAmount = (float) $deposit->amount;
                });

                return redirect()->route('advertiser.add-funds')
                    ->with('success', 'Payment successful! €' . number_format($creditedAmount ?? 0, 2) . ' added to your wallet.');
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
        try {
            $request->validate([
                'amount' => 'required|numeric|min:10',
                'payment_method' => 'required|in:wise,crypto,bank',
                'reference_code' => 'required|string'
            ]);

            $user = auth()->user();
            
            // For bank transfer, check if billing info exists
            if ($request->payment_method === 'bank') {
                if (empty($user->billing_name) || empty($user->address)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please complete your billing information first.',
                        'requires_billing' => true
                    ]);
                }
            }

            // Use the provided reference code
            $referenceCode = $request->reference_code;
            
            // Check if reference code already exists
            $existingDeposit = DepositRequest::where('reference_code', $referenceCode)->first();
            if ($existingDeposit) {
                do {
                    $referenceCode = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                } while (DepositRequest::where('reference_code', $referenceCode)->exists());
            }

            $depositRequest = DepositRequest::create([
                'user_id' => auth()->id(),
                'reference_code' => $referenceCode,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'status' => 'pending'
            ]);

            // Send email notification to admin
            try {
                $admins = User::whereHas('roles', function($query) {
                    $query->where('name', 'admin');
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

            $responseData = [
                'success' => true,
                'message' => 'Deposit request submitted successfully.',
                'reference_code' => $referenceCode,
                'deposit_id' => $depositRequest->id
            ];
            
            // For bank transfer, include invoice URL
            if ($request->payment_method === 'bank') {
                $responseData['invoice_url'] = route('advertiser.invoice', $referenceCode);
            }
            
            return response()->json($responseData);
            
        } catch (\Exception $e) {
            Log::error('Error submitting deposit request: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit deposit request: ' . $e->getMessage()
            ], 500);
        }
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
    
    /**
     * Save billing information to user profile
     */
    public function saveBillingInfo(Request $request)
    {
        try {
            $user = auth()->user();
            
            $request->validate([
                'billing_name' => 'required|string|max:255',
                'country' => 'required|string|max:255',
                'city' => 'required|string|max:255',
                'address' => 'required|string'
            ]);
            
            // Update user billing info directly on users table
            $user->billing_name = $request->billing_name;
            $user->company_name = $request->company_name;
            $user->country = $request->country;
            $user->state = $request->state;
            $user->city = $request->city;
            $user->address = $request->address;
            $user->postal_code = $request->postal_code;
            $user->vat_number = $request->vat_number;
            $user->save();
            
            Log::info('Billing information saved for user', [
                'user_id' => $user->id,
                'billing_name' => $request->billing_name
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Billing information saved successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error saving billing info: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save billing information: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get billing information from user profile
     */
    public function getBillingInfo()
    {
        try {
            $user = auth()->user();
            
            $billingInfo = [
                'billing_name' => $user->billing_name,
                'company_name' => $user->company_name,
                'country' => $user->country,
                'state' => $user->state,
                'city' => $user->city,
                'address' => $user->address,
                'postal_code' => $user->postal_code,
                'vat_number' => $user->vat_number,
                'has_info' => !empty($user->billing_name) && !empty($user->address)
            ];
            
            return response()->json([
                'success' => true,
                'data' => $billingInfo
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching billing info: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch billing information'
            ], 500);
        }
    }
    
    /**
 * Show invoice page
 */
public function showInvoice($referenceCode)
{
    try {
        $userId = auth()->id();
        
        // First check if it's a deposit
        $deposit = DepositRequest::where('reference_code', $referenceCode)
            ->where('user_id', $userId)
            ->first();
        
        $user = auth()->user();
        
        if ($deposit) {
            // It's a deposit invoice
            return view('advertiser.invoice', [
                'invoiceType' => 'deposit',
                'referenceCode' => $referenceCode,
                'amount' => $deposit->amount,
                'billingName' => $user->billing_name ?? $user->name,
                'companyName' => $user->company_name ?? '',
                'country' => $user->country ?? '',
                'state' => $user->state ?? '',
                'city' => $user->city ?? '',
                'address' => $user->address ?? '',
                'postalCode' => $user->postal_code ?? '',
                'vatNumber' => $user->vat_number ?? '',
                'userName' => $user->name,
                'userEmail' => $user->email,
                'userId' => $user->id,
                'status' => $deposit->status,
                'paymentMethod' => $deposit->payment_method,
                'orderDate' => $deposit->created_at,
                'orderItems' => [],
                'totalBaseAmount' => 0,
                'totalSensitiveAmount' => 0
            ]);
        }
        
        // Check if it's an order
        $order = Order::where('reference_code', $referenceCode)
            ->where('user_id', $userId)
            ->with('items')
            ->first();
        
        if ($order) {
            $orderItems = [];
            $totalBaseAmount = 0;
            $totalSensitiveAmount = 0;
            
            foreach ($order->items as $item) {
                $additionalPrice = $item->additional_price ?? 0;
                $basePrice = $item->price - $additionalPrice;
                $totalBaseAmount += $basePrice;
                $totalSensitiveAmount += $additionalPrice;
                
                $orderItems[] = [
                    'site_name' => $item->site_name,
                    'site_url' => $item->site_url,
                    'price' => $item->price,
                    'base_price' => $basePrice,
                    'additional_price' => $additionalPrice,
                    'sensitive_type' => $item->sensitive_type,
                    'content_link' => $item->content_link,
                    'live_url' => $item->live_url ?? ''
                ];
            }
            
            return view('advertiser.invoice', [
                'invoiceType' => 'order',
                'referenceCode' => $referenceCode,
                'amount' => $order->total_amount,
                'billingName' => $user->billing_name ?? $user->name,
                'companyName' => $user->company_name ?? '',
                'country' => $user->country ?? '',
                'state' => $user->state ?? '',
                'city' => $user->city ?? '',
                'address' => $user->address ?? '',
                'postalCode' => $user->postal_code ?? '',
                'vatNumber' => $user->vat_number ?? '',
                'userName' => $user->name,
                'userEmail' => $user->email,
                'userId' => $user->id,
                'status' => $order->status,
                'paymentMethod' => $order->payment_method,
                'orderDate' => $order->created_at,
                'orderItems' => $orderItems,
                'totalBaseAmount' => $totalBaseAmount,
                'totalSensitiveAmount' => $totalSensitiveAmount
            ]);
        }
        
        return redirect()->route('advertiser.add-funds')
            ->with('error', 'Invoice not found');
        
    } catch (\Exception $e) {
        Log::error('Error showing invoice: ' . $e->getMessage());
        return redirect()->route('advertiser.add-funds')
            ->with('error', 'Invoice not found');
    }
}
}