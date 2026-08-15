<?php

// app/Http/Controllers/Advertiser/AddFundsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Mail\DepositMarkedPaid;
use App\Mail\DepositRequestSubmitted;
use App\Models\DepositRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
use App\Services\StripeCustomerService;
use App\Services\StripePaymentService;
use App\Services\Wallet\PayoutProfileService;
use App\Services\Wallet\WalletOverviewService;
use App\Services\WalletStripeDepositService;
use App\Support\DepositPaymentConfig;
use App\Support\UserFacingError;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
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
        protected WalletOverviewService $overview,
        protected PayoutProfileService $payoutProfiles,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();
        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            abort(503, 'Advertiser wallet is unavailable.');
        }

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
        $wallet->reconcileInflatedBonusBalance();
        $wallet->refresh();

        $summary = $this->overview->summary($user->id, $wallet);
        $analytics = $this->overview->analytics($user->id, 'month');

        $prefillAmount = max(0, (float) $request->query('amount', 0));
        $prefillMethod = in_array($request->query('method'), ['wise', 'bank', 'crypto', 'card'], true)
            ? $request->query('method')
            : null;

        $publisherRoleId = Wallet::publisherRoleId();
        $publisherWallet = ($publisherRoleId && $user->hasRole('publisher'))
            ? Wallet::where('user_id', $user->id)->where('role_id', $publisherRoleId)->first()
            : null;
        $publisher = $publisherWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();

        return view('advertiser.add-funds', [
            'pendingRequests' => $pendingRequests,
            'wallet' => $wallet,
            'summary' => $summary,
            'analytics' => $analytics,
            'advertiserBalance' => (float) $wallet->balance,
            'advertiserBonusBalance' => $wallet->lockedBonusBalance(),
            'advertiserWithdrawableBalance' => $wallet->withdrawableBalance(),
            'publisher' => $publisher,
            'publisherBalance' => $publisher['withdrawable'],
            'showPublisherWallet' => $publisherWallet !== null,
            'promotionalBonusMessage' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
            'payoutProfile' => $user->payoutProfile(),
            'payoutLocked' => $user->payoutProfileLocked(),
            'availableMethods' => $this->payoutProfiles->availableMethods($user),
            'prefillAmount' => $prefillAmount >= 10 ? $prefillAmount : null,
            'prefillMethod' => $prefillMethod,
            'savedCards' => app(StripeCustomerService::class)->listCards($user),
            'stripeConfigured' => app(StripeCustomerService::class)->configured(),
            'cardsTab' => $request->query('tab') === 'cards',
            'depositPayment' => DepositPaymentConfig::depositPayment(),
            'wisePayUrl' => DepositPaymentConfig::wisePayUrl(),
            'cryptoEnabled' => DepositPaymentConfig::cryptoEnabled(),
            'cryptoNetworks' => DepositPaymentConfig::cryptoNetworks(),
            'cryptoNote' => DepositPaymentConfig::cryptoNote(),
        ]);
    }

    /**
     * Same-origin Wise payment QR (PNG). Keeps the pay URL off third-party CDNs.
     */
    public function wiseQr(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:10|max:100000',
        ]);

        $amount = round((float) $data['amount'], 2);
        $payUrl = DepositPaymentConfig::wisePayLink($amount);

        // Prefer SVG: no ext-gd / Imagick required (Hostinger often lacks GD for PNG).
        // Fall back to PNG when GD is available and SVG somehow fails.
        try {
            $result = (new Builder(
                writer: new SvgWriter,
                data: $payUrl,
                size: 300,
                margin: 10,
            ))->build();
        } catch (\Throwable $svgError) {
            if (! extension_loaded('gd')) {
                Log::error('Wise QR generation failed (SVG) and GD is unavailable', [
                    'error' => $svgError->getMessage(),
                ]);
                abort(503, 'QR generation unavailable');
            }

            Log::warning('Wise QR SVG failed; falling back to PNG', [
                'error' => $svgError->getMessage(),
            ]);

            $result = (new Builder(
                writer: new PngWriter,
                data: $payUrl,
                size: 300,
                margin: 10,
            ))->build();
        }

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'private, max-age=60',
            // Avoid intermediary caches serving a login HTML page as the image.
            'X-Content-Type-Options' => 'nosniff',
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
            $sessionReference = WalletStripeDepositService::newAddFundsSessionReference();

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
                // Copied onto the PaymentIntent so a late PI webhook can
                // attach to the session-created card without using the
                // reused client REF.
                'payment_intent_data' => [
                    'metadata' => [
                        'type' => 'wallet_deposit',
                        'user_id' => (string) $user->id,
                        'amount' => (string) $amountEuros,
                        'reference_code' => $referenceCode,
                        'session_reference' => $sessionReference,
                    ],
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
                'message' => UserFacingError::message($e, 'Failed to create checkout session. Please try again.'),
            ]);
        }
    }

    public function checkoutSuccess(Request $request)
    {
        $sessionId = $request->session_id;
        $paymentIntentId = $request->query('payment_intent');
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
                $deposits = app(WalletStripeDepositService::class);
                $meta = (array) json_decode(json_encode($intent->metadata ?? []), true);
                if (trim((string) ($meta['user_id'] ?? '')) === ''
                    || trim((string) ($meta['type'] ?? '')) === '') {
                    $intent = $deposits->withRecoveredCheckoutSessionMetadata($intent);
                    $meta = (array) json_decode(json_encode($intent->metadata ?? []), true);
                }
                $intentUserId = trim((string) ($meta['user_id'] ?? ''));
                // Missing user_id must not be filled with whoever is logged in.
                if ($intentUserId === '') {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Payment verification failed. Please contact support.');
                }
                if ($intentUserId !== (string) auth()->id()) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Payment does not belong to this account.');
                }
                $intentType = trim((string) ($meta['type'] ?? ''));
                $sessionReference = trim((string) ($meta['session_reference'] ?? ''));
                $isWalletIntent = in_array($intentType, ['wallet_deposit', 'deposit'], true)
                    || ($intentType === '' && WalletStripeDepositService::isAddFundsSessionReference($sessionReference));
                if (! $isWalletIntent) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'This payment is not a wallet top-up.');
                }
                // Always credit Stripe's charged amount — never trust client ?amount=
                $amountEuros = StripePaymentService::fromCents(
                    (int) ($intent->amount_received ?: $intent->amount)
                );
                $ref = $referenceCode ?: (string) ($meta['reference_code'] ?? str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT));
                $credited = app(WalletStripeDepositService::class)
                    ->creditFromPaymentIntent(
                        auth()->id(),
                        $paymentIntentId,
                        $amountEuros,
                        $ref,
                        null,
                        true,
                        $sessionReference
                    );

                if ($credited <= 0) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Payment verification failed. Please contact support.');
                }

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
                $deposits = app(WalletStripeDepositService::class);
                // Classify after overlay — an untyped paid session can still
                // be a wallet top-up whose type lives only on the PaymentIntent.
                $session = $deposits->recoverIncompleteWalletCheckoutSession($session);
                $meta = (array) json_decode(json_encode($session->metadata ?? []), true);
                $sessionType = isset($meta['type']) ? (string) $meta['type'] : null;
                $hasDepositId = ! empty($meta['deposit_id']);
                $hasAddFundsSref = WalletStripeDepositService::isAddFundsSessionReference($meta['session_reference'] ?? '');
                $isExplicitWallet = in_array((string) $sessionType, ['wallet_deposit', 'deposit'], true);
                // deposit_id / Add Funds session_reference are wallet hints
                // only when Stripe did not tag the session as something else.
                $isWalletSession = $isExplicitWallet
                    || (($sessionType === null || $sessionType === '') && ($hasDepositId || $hasAddFundsSref));

                if (! $isWalletSession) {
                    Log::warning('Add Funds checkoutSuccess refused non-wallet session', [
                        'session_id' => $sessionId,
                        'type' => $sessionType,
                        'user_id' => auth()->id(),
                    ]);

                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'This payment is not a wallet top-up. Order payments are confirmed on the order page.');
                }

                $sessionUserId = (string) ($meta['user_id'] ?? '');
                if ($sessionUserId !== '' && $sessionUserId !== (string) auth()->id()) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Payment does not belong to this account.');
                }

                $creditedAmount = $deposits->creditFromCheckoutSession($session);

                // Missing metadata.user_id must not be filled with whoever is
                // logged in — that credited a leaked success URL to the visitor.
                if ($creditedAmount <= 0 && $sessionUserId === '') {
                    Log::warning('Add Funds checkoutSuccess refused wallet session without user_id', [
                        'session_id' => $sessionId,
                        'user_id' => auth()->id(),
                    ]);

                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Payment verification failed. Please contact support.');
                }

                if ($creditedAmount <= 0) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', 'Payment verification failed. Please contact support.');
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
                $chargedEuros = ! empty($payResult['amount_received'])
                    ? StripePaymentService::fromCents((int) $payResult['amount_received'])
                    : $amountEuros;
                $credited = app(WalletStripeDepositService::class)->creditFromPaymentIntent(
                    $user->id,
                    $payResult['payment_intent_id'],
                    $chargedEuros,
                    $referenceCode
                );

                if ($credited <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment verification failed. Please contact support.',
                    ], 422);
                }

                return response()->json([
                    'success' => true,
                    'message' => '€'.number_format($credited, 2).' added to your wallet with your saved card.',
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
            if (in_array($request->payment_method, ['bank', 'wise', 'crypto'], true)) {
                if (empty($user->billing_name) || empty($user->address) || empty($user->company_name)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please complete your billing information first so we can issue your invoice.',
                        'requires_billing' => true,
                    ]);
                }
            }

            if ($request->payment_method === 'crypto' && ! DepositPaymentConfig::cryptoEnabled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cryptocurrency deposits are temporarily unavailable. Please use Bank or Wise.',
                ], 422);
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
                $notifications = app(InAppNotificationService::class);
                $freshDeposit = $depositRequest->fresh(['user']);
                $notifications->notifyDepositSubmitted($freshDeposit);
                $notifications->notifyAdminsDepositSubmitted($freshDeposit);
            } catch (\Throwable $e) {
                Log::warning('Failed to send deposit bell notification: '.$e->getMessage());
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
                'message' => UserFacingError::message($e, 'Failed to submit deposit request. Please try again.'),
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

        $firstReport = ! $deposit->userHasMarkedPaid();

        if ($firstReport) {
            $deposit->update([
                'user_marked_paid_at' => now(),
                'user_payment_note' => $data['user_payment_note'] ?? $deposit->user_payment_note,
            ]);
        }

        $deposit->refresh();

        // Only on the transition: clicking again must not re-alert anyone.
        if ($firstReport) {
            $this->announcePaymentReported($deposit);
        }

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

    /**
     * A reported payment is only actionable if somebody hears about it: the
     * advertiser needs a record their click landed, and an admin has to go
     * match the transfer before the wallet can be credited.
     */
    private function announcePaymentReported(DepositRequest $deposit): void
    {
        $deposit->loadMissing('user');

        try {
            $notifications = app(InAppNotificationService::class);
            $notifications->notifyDepositMarkedPaid($deposit);
            $notifications->notifyAdminsDepositMarkedPaid($deposit);
        } catch (\Throwable $e) {
            Log::warning('Failed to send deposit marked-paid bell notification: '.$e->getMessage(), [
                'deposit_request_id' => $deposit->id,
            ]);
        }

        try {
            $admins = User::whereHas('roles', fn ($query) => $query->where('name', 'admin'))->get();

            if ($admins->isEmpty()) {
                $fallback = config('mail.admin_email');
                if (filled($fallback)) {
                    Mail::to($fallback)->send(new DepositMarkedPaid($deposit));
                }

                return;
            }

            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(new DepositMarkedPaid($deposit));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send deposit marked-paid email: '.$e->getMessage(), [
                'deposit_request_id' => $deposit->id,
            ]);
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
                'message' => UserFacingError::message($e, 'Failed to save billing information. Please try again.'),
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
