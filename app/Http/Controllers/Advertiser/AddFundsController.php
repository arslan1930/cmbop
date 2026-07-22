<?php

// app/Http/Controllers/Advertiser/AddFundsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Mail\DepositRequestSubmitted;
use App\Models\DepositRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
use App\Services\StripeCustomerService;
use App\Services\StripePaymentService;
use App\Services\Wallet\WalletOverviewService;
use App\Services\WalletStripeDepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class AddFundsController extends Controller
{
    public function __construct(
        protected WalletOverviewService $overview
    ) {}

    public function index(Request $request)
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

        $prefillAmount = max(0, (float) $request->query('amount', 0));
        $prefillMethod = in_array($request->query('method'), ['wise', 'bank', 'crypto', 'card'], true)
            ? $request->query('method')
            : null;

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
            'prefillAmount' => $prefillAmount >= 10 ? $prefillAmount : null,
            'prefillMethod' => $prefillMethod,
            'savedCards' => app(StripeCustomerService::class)->listCards($user),
            'stripeConfigured' => app(StripeCustomerService::class)->configured(),
            'cardsTab' => $request->query('tab') === 'cards',
        ]);
    }

    public function createCheckoutSession(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:10',
                'reference_code' => 'required|string',
            ]);

            if (! config('services.stripe.secret') || config('services.stripe.secret') === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured. Please contact support.',
                ]);
            }

            Stripe::setApiKey(config('services.stripe.secret'));

            $amountEuros = round((float) $request->amount, 2);
            $amountCents = StripePaymentService::toCents($amountEuros);
            $referenceCode = $request->reference_code;
            $user = auth()->user();

            // Generate a unique session reference (NO deposit record created here)
            $sessionReference = 'deposit_'.uniqid();

            $sessionPayload = [
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'eur',
                        'product_data' => [
                            'name' => 'Add Funds to Wallet',
                            'description' => 'Deposit €'.number_format($amountEuros, 2).' to your wallet',
                        ],
                        'unit_amount' => $amountCents,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('advertiser.checkout.success').'?session_id={CHECKOUT_SESSION_ID}&amount='.$amountEuros.'&ref='.$referenceCode,
                'cancel_url' => route('advertiser.add-funds'),
                'metadata' => [
                    'type' => 'wallet_deposit',
                    'user_id' => (string) $user->id,
                    'amount' => (string) $amountEuros,
                    'reference_code' => $referenceCode,
                    'session_reference' => $sessionReference,
                ],
            ];

            $checkoutSession = app(StripeCustomerService::class)
                ->createCheckoutSession($sessionPayload, $user, true);

            return response()->json([
                'success' => true,
                'checkout_url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Stripe checkout error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session: '.$e->getMessage(),
            ]);
        }
    }

    public function checkoutSuccess(Request $request)
    {
        $sessionId = $request->session_id;
        $paymentIntentId = $request->query('payment_intent');
        $amount = $request->amount;
        $referenceCode = $request->ref;

        // 3DS return from saved-card PaymentIntent
        if ($paymentIntentId && ! $sessionId) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                $intent = PaymentIntent::retrieve($paymentIntentId);
                if ($intent->status !== 'succeeded') {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Card payment was not completed.');
                }
                if ((string) ($intent->metadata->user_id ?? '') !== (string) auth()->id()) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Payment does not belong to this account.');
                }
                $amountEuros = isset($amount)
                    ? round((float) $amount, 2)
                    : StripePaymentService::fromCents($intent->amount_received ?: $intent->amount);
                $ref = $referenceCode ?: (string) ($intent->metadata->reference_code ?? str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT));
                $credited = app(WalletStripeDepositService::class)
                    ->creditFromPaymentIntent(auth()->id(), $paymentIntentId, $amountEuros, $ref);

                return redirect()->route('advertiser.add-funds')
                    ->with('success', 'Payment successful! €'.number_format($credited, 2).' added to your wallet.');
            } catch (\Throwable $e) {
                Log::error('Saved-card deposit success error: '.$e->getMessage());

                return redirect()->route('advertiser.add-funds')
                    ->with('error', 'Failed to verify payment. Please contact support.');
            }
        }

        if (! $sessionId) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Invalid payment session.');
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            // Retrieve the session from Stripe
            $session = Session::retrieve($sessionId);

            if ($session->payment_status === 'paid') {
                $creditedAmount = app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);

                // Older/manual sessions may lack metadata.user_id — credit for the authenticated user.
                if ($creditedAmount <= 0) {
                    $piId = is_string($session->payment_intent ?? null)
                        ? $session->payment_intent
                        : (string) ($session->payment_intent ?? '');
                    $amountEuros = isset($amount)
                        ? round((float) $amount, 2)
                        : StripePaymentService::fromCents((int) $session->amount_total);
                    $ref = $referenceCode ?: str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                    if ($piId !== '') {
                        $creditedAmount = app(WalletStripeDepositService::class)
                            ->creditFromPaymentIntent(auth()->id(), $piId, $amountEuros, $ref);
                    } else {
                        // Attach user_id onto a lightweight stdClass wrapper for the shared session path.
                        $session->metadata = (object) array_merge(
                            (array) json_decode(json_encode($session->metadata ?? []), true),
                            [
                                'user_id' => (string) auth()->id(),
                                'amount' => (string) $amountEuros,
                                'reference_code' => $ref,
                                'type' => 'wallet_deposit',
                            ]
                        );
                        $creditedAmount = app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);
                    }
                }

                return redirect()->route('advertiser.add-funds')
                    ->with('success', 'Payment successful! €'.number_format($creditedAmount, 2).' added to your wallet.');
            }

            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Payment verification failed. Please contact support.');

        } catch (\Exception $e) {
            Log::error('Checkout success error: '.$e->getMessage());

            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Failed to verify payment. Please contact support.');
        }
    }

    /**
     * Instant wallet top-up with a saved Stripe card.
     */
    public function payWithSavedCard(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10',
            'payment_method_id' => 'required|string',
            'reference_code' => 'required|string',
        ]);

        if (! app(StripeCustomerService::class)->configured()) {
            return response()->json([
                'success' => false,
                'message' => 'Card payments are not configured.',
            ], 503);
        }

        $user = auth()->user();
        $amountEuros = round((float) $request->amount, 2);
        $referenceCode = (string) $request->reference_code;

        try {
            $payResult = app(StripeCustomerService::class)->payWithSavedCard(
                $user,
                (string) $request->payment_method_id,
                StripePaymentService::toCents($amountEuros),
                [
                    'type' => 'wallet_deposit',
                    'user_id' => (string) $user->id,
                    'amount' => (string) $amountEuros,
                    'reference_code' => $referenceCode,
                ],
                route('advertiser.checkout.success').'?ref='.urlencode($referenceCode).'&amount='.$amountEuros,
                'Wallet deposit '.$referenceCode
            );

            if ($payResult['status'] === 'succeeded') {
                app(WalletStripeDepositService::class)->creditFromPaymentIntent(
                    $user->id,
                    $payResult['payment_intent_id'],
                    $amountEuros,
                    $referenceCode
                );

                return response()->json([
                    'success' => true,
                    'message' => '€'.number_format($amountEuros, 2).' added to your wallet with your saved card.',
                    'redirect_url' => route('advertiser.add-funds'),
                ]);
            }

            if (! empty($payResult['redirect_url'])) {
                return response()->json([
                    'success' => true,
                    'requires_payment' => true,
                    'checkout_url' => $payResult['redirect_url'],
                ]);
            }

            if (! empty($payResult['client_secret'])) {
                return response()->json([
                    'success' => true,
                    'requires_action' => true,
                    'client_secret' => $payResult['client_secret'],
                    'stripe_key' => config('services.stripe.key'),
                    'return_url' => route('advertiser.checkout.success').'?ref='.urlencode($referenceCode).'&amount='.$amountEuros,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not charge this card. Try another card or Stripe Checkout.',
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Saved card wallet deposit failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Saved card payment failed. Please try again or use a new card.',
            ], 422);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:10',
                'payment_method' => 'required|in:wise,crypto,bank',
                'reference_code' => 'required|string',
            ]);

            $user = auth()->user();

            // Invoice methods need billing details on the PDF.
            if (in_array($request->payment_method, ['bank', 'wise'], true)) {
                if (empty($user->billing_name) || empty($user->address) || empty($user->company_name)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please complete your billing information first so we can issue your invoice.',
                        'requires_billing' => true,
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
                'status' => 'pending',
            ]);

            // Send email notification to admin
            try {
                $admins = User::whereHas('roles', function ($query) {
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
                Log::error('Failed to send deposit notification email: '.$e->getMessage());
            }

            try {
                app(InAppNotificationService::class)
                    ->notifyAdminsDepositSubmitted($depositRequest->fresh(['user']));
            } catch (\Throwable $e) {
                Log::warning('Failed to send admin deposit bell notification: '.$e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice created. Transfer the amount with your REF — we credit your wallet after funds arrive.',
                'reference_code' => $referenceCode,
                'deposit_id' => $depositRequest->id,
                'invoice_url' => route('advertiser.invoice', $referenceCode),
                'mark_paid_url' => route('advertiser.add-funds.mark-paid', $depositRequest),
            ]);

        } catch (\Exception $e) {
            Log::error('Error submitting deposit request: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit deposit request: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Advertiser acknowledges they sent the Bank/Wise/crypto transfer.
     * Status stays pending until admin confirms and credits the wallet.
     */
    public function markPaid(Request $request, DepositRequest $deposit)
    {
        if ((int) $deposit->user_id !== (int) auth()->id()) {
            abort(403);
        }

        if (! in_array($deposit->payment_method, ['wise', 'bank', 'crypto'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Bank, Wise, and crypto invoices can be marked as paid by you.',
            ], 422);
        }

        if (! $deposit->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'This deposit is no longer pending.',
                'status' => $deposit->status,
            ], 422);
        }

        $data = $request->validate([
            'user_payment_note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $deposit->userHasMarkedPaid()) {
            $deposit->update([
                'user_marked_paid_at' => now(),
                'user_payment_note' => $data['user_payment_note'] ?? $deposit->user_payment_note,
            ]);
        }

        $deposit->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Thanks — payment marked as sent. Status stays Pending until we confirm and credit your wallet.',
            'status' => $deposit->status,
            'user_marked_paid_at' => optional($deposit->user_marked_paid_at)?->toIso8601String(),
            'deposit' => [
                'id' => $deposit->id,
                'reference_code' => $deposit->reference_code,
                'amount' => (float) $deposit->amount,
                'payment_method' => $deposit->payment_method,
                'status' => $deposit->status,
                'user_marked_paid_at' => optional($deposit->user_marked_paid_at)?->toIso8601String(),
            ],
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
            'user_marked_paid_at' => optional($depositRequest->user_marked_paid_at)?->toIso8601String(),
            'deposit' => $depositRequest,
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
                'company_name' => 'required|string|max:255',
                'country' => 'required|string|max:255',
                'city' => 'required|string|max:255',
                'address' => 'required|string',
                'state' => 'nullable|string|max:255',
                'postal_code' => 'nullable|string|max:64',
                'vat_number' => 'nullable|string|max:64',
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
                'billing_name' => $request->billing_name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Billing information saved successfully',
            ]);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error saving billing info: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to save billing information: '.$e->getMessage(),
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
                'has_info' => ! empty($user->billing_name)
                    && ! empty($user->company_name)
                    && ! empty($user->address)
                    && ! empty($user->city)
                    && ! empty($user->country),
            ];

            return response()->json([
                'success' => true,
                'data' => $billingInfo,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching billing info: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch billing information',
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
                    'totalSensitiveAmount' => 0,
                    'deposit' => $deposit,
                    'canMarkPaid' => $deposit->canUserMarkPaid(),
                    'userMarkedPaid' => $deposit->userHasMarkedPaid(),
                    'markPaidUrl' => route('advertiser.add-funds.mark-paid', $deposit),
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
                        'live_url' => $item->live_url ?? '',
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
                    'totalSensitiveAmount' => $totalSensitiveAmount,
                ]);
            }

            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Invoice not found');

        } catch (\Exception $e) {
            Log::error('Error showing invoice: '.$e->getMessage());

            return redirect()->route('advertiser.add-funds')
                ->with('error', 'Invoice not found');
        }
    }
}
