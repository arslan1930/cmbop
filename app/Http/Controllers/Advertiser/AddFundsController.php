<?php

// app/Http/Controllers/Advertiser/AddFundsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Mail\DepositMarkedPaid;
use App\Mail\DepositRequestSubmitted;
use App\Mail\PaypalPaymentNotCompleted;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
use App\Services\PaypalCheckoutService;
use App\Services\PaypalPaymentNotifier;
use App\Services\StripeCustomerService;
use App\Services\StripePaymentService;
use App\Services\Wallet\PayoutProfileService;
use App\Services\Wallet\WalletOverviewService;
use App\Services\WalletPaypalDepositService;
use App\Services\WalletStripeDepositService;
use App\Support\DepositPaymentConfig;
use App\Support\PaypalPaymentError;
use App\Support\UserFacingError;
use App\Support\UserMessages;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Endroid\QrCode\Writer\SvgWriter;
use Endroid\QrCode\Writer\WriterInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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

        try {
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

            $wallet->repairOrphanedWelcomeBonus();
            $wallet->reconcileInflatedBonusBalance();
            $wallet->refresh();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load your wallet. Please refresh and try again.')
            );
            $wallet = null;
        }

        $pendingRequests = collect();
        if (DepositRequest::tableAvailable()) {
            try {
                $pendingRequests = DepositRequest::where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->latest()
                    ->get();
            } catch (\Throwable $e) {
                Log::warning('Add Funds pending deposits query skipped', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $summary = [];
        $analytics = ['labels' => [], 'deposits' => [], 'orders' => []];
        if ($wallet) {
            try {
                $summary = $this->overview->summary($user->id, $wallet);
            } catch (\Throwable $e) {
                Log::warning('Add Funds wallet summary failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
            try {
                $analytics = $this->overview->analytics($user->id, 'month');
            } catch (\Throwable $e) {
                Log::warning('Add Funds wallet analytics failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($summary === []) {
                $reserved = round((float) $wallet->reserved_balance, 2);
                $pendingDeposits = (float) $pendingRequests->sum('amount');
                $summary = [
                    'spendable_balance' => round((float) $wallet->balance, 2),
                    'available_balance' => $wallet->withdrawableBalance(),
                    'bonus_balance' => $wallet->lockedBonusBalance(),
                    'reserved_balance' => $reserved,
                    'pending_deposits' => $pendingDeposits,
                    'pending_balance' => round($reserved + $pendingDeposits, 2),
                    'bonus_reserved' => round((float) $wallet->bonus_reserved, 2),
                    'bonus_remaining' => $wallet->lockedBonusBalance(),
                    'currency' => $wallet->currency ?? 'EUR',
                ];
            }
        }

        $prefillAmount = max(0, (float) $request->query('amount', 0));
        $stripeConfigured = app(StripeCustomerService::class)->configured();
        $paypalConfigured = app(PaypalCheckoutService::class)->configured();
        $cryptoEnabled = DepositPaymentConfig::cryptoEnabled();
        $prefillMethod = in_array($request->query('method'), ['wise', 'bank', 'crypto', 'card', 'paypal'], true)
            ? $request->query('method')
            : null;
        if (is_string($prefillMethod) && ! $this->depositRailReady($prefillMethod, $stripeConfigured, $paypalConfigured, $cryptoEnabled)) {
            $prefillMethod = null;
        }
        try {
            $lastUsedMethod = DepositRequest::lastUsedMethodForUser((int) $user->id);
        } catch (\Throwable $e) {
            report($e);
            $lastUsedMethod = null;
        }
        if (is_string($lastUsedMethod) && ! $this->depositRailReady($lastUsedMethod, $stripeConfigured, $paypalConfigured, $cryptoEnabled)) {
            $lastUsedMethod = null;
        }
        $depositMethodOrder = ['card', 'bank', 'paypal', 'wise', 'crypto'];
        if (is_string($lastUsedMethod) && in_array($lastUsedMethod, $depositMethodOrder, true)) {
            $depositMethodOrder = array_values(array_unique(array_merge([$lastUsedMethod], $depositMethodOrder)));
        }
        if ($prefillMethod === null && $lastUsedMethod) {
            $prefillMethod = $lastUsedMethod;
        }

        $publisher = Wallet::emptyRoleSnapshot();
        $publisherWallet = null;
        try {
            $publisherRoleId = Wallet::publisherRoleId();
            $publisherWallet = ($publisherRoleId && $user->hasRole('publisher'))
                ? Wallet::where('user_id', $user->id)->where('role_id', $publisherRoleId)->first()
                : null;
            $publisher = $publisherWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $savedCards = app(StripeCustomerService::class)->listCards($user);
        } catch (\Throwable $e) {
            report($e);
            $savedCards = [];
        }

        try {
            $availableMethods = $this->payoutProfiles->availableMethods($user);
        } catch (\Throwable $e) {
            report($e);
            $availableMethods = [];
        }

        return view('advertiser.add-funds', [
            'pendingRequests' => $pendingRequests,
            'wallet' => $wallet,
            'walletUnavailable' => $wallet === null,
            'summary' => $summary,
            'analytics' => $analytics,
            'advertiserBalance' => (float) ($wallet?->balance ?? 0),
            'advertiserBonusBalance' => $wallet ? $wallet->lockedBonusBalance() : 0.0,
            'advertiserWithdrawableBalance' => $wallet ? $wallet->withdrawableBalance() : 0.0,
            'advertiserDebtBalance' => $wallet ? $wallet->debtBalance() : 0.0,
            'advertiserDebtReason' => $wallet?->advertiserSpendBlockedReason(),
            'publisher' => $publisher,
            'publisherBalance' => $publisher['withdrawable'],
            'showPublisherWallet' => $publisherWallet !== null,
            'promotionalBonusMessage' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
            'payoutProfile' => $user->payoutProfile(),
            'payoutLocked' => $user->payoutProfileLocked(),
            'availableMethods' => $availableMethods,
            'prefillAmount' => $prefillAmount >= 10 ? $prefillAmount : null,
            'prefillMethod' => $prefillMethod,
            'lastUsedMethod' => $lastUsedMethod,
            'depositMethodOrder' => $depositMethodOrder,
            'savedCards' => $savedCards,
            'stripeConfigured' => $stripeConfigured,
            'paypalConfigured' => $paypalConfigured,
            'cardsTab' => $request->query('tab') === 'cards',
            'depositPayment' => DepositPaymentConfig::depositPayment(),
            'wisePayUrl' => DepositPaymentConfig::wisePayUrl(),
            'cryptoEnabled' => $cryptoEnabled,
            'cryptoNetworks' => DepositPaymentConfig::cryptoNetworks(),
            'cryptoNote' => DepositPaymentConfig::cryptoNote(),
        ]);
    }

    private function depositRailReady(
        string $method,
        bool $stripeConfigured,
        bool $paypalConfigured,
        bool $cryptoEnabled,
    ): bool {
        return match ($method) {
            'card' => $stripeConfigured,
            'paypal' => $paypalConfigured,
            'crypto' => $cryptoEnabled,
            'wise', 'bank' => true,
            default => false,
        };
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
        $logoPath = public_path('assets/brand/web/favicon.svg');
        if (! is_file($logoPath)) {
            $logoPath = public_path('favicon.svg');
        }
        $logoPath = is_file($logoPath) ? $logoPath : '';

        // Prefer SVG: no ext-gd / Imagick required (Hostinger often lacks GD for PNG).
        // Fall back to PNG when GD is available and SVG somehow fails.
        try {
            $result = $this->buildWiseQr(new SvgWriter, $payUrl, $logoPath);
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

            $result = $this->buildWiseQr(new PngWriter, $payUrl, $logoPath);
        }

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'private, max-age=60',
            // Avoid intermediary caches serving a login HTML page as the image.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function buildWiseQr(WriterInterface $writer, string $payUrl, string $logoPath): ResultInterface
    {
        return (new Builder(
            writer: $writer,
            data: $payUrl,
            size: 300,
            margin: 16,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            foregroundColor: new Color(13, 47, 20),
            backgroundColor: new Color(244, 248, 244),
            logoPath: $logoPath,
            logoResizeToWidth: 72,
            logoResizeToHeight: 72,
            logoPunchoutBackground: false,
        ))->build();
    }

    public function createCheckoutSession(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:10|max:100000',
                'reference_code' => 'required|string',
            ]);

            if (! config('services.stripe.secret') || config('services.stripe.secret') === '') {
                return response()->json([
                    'success' => false,
                    'message' => UserMessages::get('payment.stripe_not_configured'),
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

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Stripe checkout error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, UserMessages::get('payment.stripe_checkout_failed')),
            ]);
        }
    }

    public function createPaypalOrder(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:10|max:100000',
                'reference_code' => 'required|string',
            ]);

            $paypal = app(PaypalCheckoutService::class);
            if (! $paypal->configured()) {
                return response()->json([
                    'success' => false,
                    'message' => UserMessages::get('payment.paypal_not_configured_deposit'),
                ], 503);
            }

            $amountEuros = round((float) $request->amount, 2);
            $referenceCode = trim((string) $request->reference_code);
            $user = auth()->user();

            $created = $paypal->createOrder($amountEuros, [
                'type' => PaypalCheckoutService::TYPE_WALLET_DEPOSIT,
                'user_id' => $user->id,
                'reference_code' => $referenceCode,
            ], $paypal->browserCallbackUrl('advertiser.add-funds.paypal.return', ['ref' => $referenceCode]), $paypal->browserCallbackUrl('advertiser.add-funds.paypal.cancel', ['ref' => $referenceCode]));

            session()->put('pending_paypal_deposit_reference', $referenceCode);

            return response()->json([
                'success' => true,
                'checkout_url' => $created['approve_url'],
                'paypal_order_id' => $created['id'],
                'reference_code' => $referenceCode,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('PayPal wallet deposit create error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => PaypalPaymentError::startFailure($e),
            ]);
        }
    }

    public function paypalDepositReturn(Request $request)
    {
        $ref = trim((string) $request->query('ref', ''));
        $token = trim((string) $request->query('token', ''));
        $userId = (int) auth()->id();

        if ($ref === '' || $token === '') {
            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.paypal_invalid_return'));
        }

        $paypal = app(PaypalCheckoutService::class);
        if (! $paypal->configured()) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.paypal_not_configured'));
        }

        try {
            $captured = $paypal->captureOrder($token);
        } catch (\Throwable $e) {
            Log::error('PayPal wallet deposit capture failed on return', [
                'reference_code' => $ref,
                'paypal_order_id' => $token,
                'error' => $e->getMessage(),
            ]);
            $this->notifyPaypalDepositNotCompleted($userId, $ref, PaypalPaymentNotifier::reasonFromCaptureException($e));

            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.paypal_not_completed'));
        }

        $custom = is_array($captured['custom'] ?? null) ? $captured['custom'] : [];
        if ((string) ($custom['user_id'] ?? '') !== (string) $userId) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.paypal_wrong_account'));
        }
        if (($custom['type'] ?? '') !== PaypalCheckoutService::TYPE_WALLET_DEPOSIT) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.paypal_not_deposit'));
        }
        if (($custom['reference_code'] ?? '') !== $ref) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.paypal_reference_mismatch'));
        }

        try {
            $credited = app(WalletPaypalDepositService::class)->creditFromCapture($captured);
        } catch (\Throwable $e) {
            Log::error('PayPal wallet deposit credit failed on return', [
                'reference_code' => $ref,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('advertiser.add-funds')
                ->with('error', UserFacingError::message($e, UserMessages::get('payment.verification_failed_support')));
        }

        session()->forget('pending_paypal_deposit_reference');

        if ($credited <= 0) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.verification_failed_support'));
        }

        return redirect()->route('advertiser.add-funds')
            ->with('success', 'Payment successful! €'.number_format($credited, 2).' added to your wallet.');
    }

    public function paypalDepositCancel(Request $request)
    {
        $ref = trim((string) ($request->query('ref') ?: session('pending_paypal_deposit_reference', '')));
        $userId = (int) auth()->id();
        session()->forget('pending_paypal_deposit_reference');

        if ($ref !== '' && $userId > 0) {
            $this->notifyPaypalDepositNotCompleted($userId, $ref, PaypalPaymentNotCompleted::REASON_CANCELLED);
        }

        return redirect()->route('advertiser.add-funds')
            ->with('error', UserMessages::get('payment.paypal_cancelled'));
    }

    private function notifyPaypalDepositNotCompleted(int $userId, string $referenceCode, string $reason): void
    {
        try {
            $user = $userId > 0 ? User::query()->find($userId) : null;
            if (! $user) {
                return;
            }

            app(PaypalPaymentNotifier::class)->notifyNotCompleted(
                $user,
                PaypalPaymentNotCompleted::KIND_DEPOSIT,
                $referenceCode,
                $reason
            );
        } catch (\Throwable $e) {
            report($e);
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
                        ->with('error', UserMessages::get('payment.stripe_not_completed'));
                }
                if ((string) ($intent->metadata->user_id ?? '') !== (string) auth()->id()) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', UserMessages::get('payment.paypal_wrong_account'));
                }
                $intentType = (string) ($intent->metadata->type ?? '');
                if (! in_array($intentType, ['wallet_deposit', 'deposit'], true)) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', UserMessages::get('payment.paypal_not_deposit'));
                }
                // Always credit Stripe's charged amount — never trust client ?amount=
                $amountEuros = StripePaymentService::fromCents(
                    (int) ($intent->amount_received ?: $intent->amount)
                );
                $ref = $referenceCode ?: (string) ($intent->metadata->reference_code ?? str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT));
                $credited = app(WalletStripeDepositService::class)
                    ->creditFromPaymentIntent(auth()->id(), $paymentIntentId, $amountEuros, $ref);

                return redirect()->route('advertiser.add-funds')
                    ->with('success', 'Payment successful! €'.number_format($credited, 2).' added to your wallet.');
            } catch (\Throwable $e) {
                Log::error('Saved-card deposit success error: '.$e->getMessage());

                return redirect()->route('advertiser.add-funds')
                    ->with('error', UserFacingError::message($e, UserMessages::get('payment.verify_failed_support')));
            }
        }

        if (! $sessionId) {
            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.invalid_session'));
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            // Retrieve the session from Stripe
            $session = Session::retrieve($sessionId);

            if ($session->payment_status === 'paid') {
                $meta = (array) json_decode(json_encode($session->metadata ?? []), true);
                $sessionType = isset($meta['type']) ? (string) $meta['type'] : null;
                $hasDepositId = ! empty($meta['deposit_id']);
                $isWalletSession = $hasDepositId
                    || in_array((string) $sessionType, ['wallet_deposit', 'deposit'], true);

                if (! $isWalletSession) {
                    Log::warning('Add Funds checkoutSuccess refused non-wallet session', [
                        'session_id' => $sessionId,
                        'type' => $sessionType,
                        'user_id' => auth()->id(),
                    ]);

                    return redirect()->route('advertiser.add-funds')
                        ->with('error', UserMessages::get('payment.wallet_session_is_order'));
                }

                $sessionUserId = (string) ($meta['user_id'] ?? '');
                if ($sessionUserId !== '' && $sessionUserId !== (string) auth()->id()) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', UserMessages::get('payment.paypal_wrong_account'));
                }

                $creditedAmount = app(WalletStripeDepositService::class)->creditFromCheckoutSession($session);

                // Missing metadata.user_id must not be filled with whoever is
                // logged in — that credited a leaked success URL to the visitor.
                if ($creditedAmount <= 0 && $sessionUserId === '') {
                    Log::warning('Add Funds checkoutSuccess refused wallet session without user_id', [
                        'session_id' => $sessionId,
                        'user_id' => auth()->id(),
                    ]);

                    return redirect()->route('advertiser.add-funds')
                        ->with('error', UserMessages::get('payment.verification_failed_support'));
                }

                if ($creditedAmount <= 0) {
                    return redirect()->route('advertiser.add-funds')
                        ->with('error', UserMessages::get('payment.verification_failed_support'));
                }

                return redirect()->route('advertiser.add-funds')
                    ->with('success', 'Payment successful! €'.number_format($creditedAmount, 2).' added to your wallet.');
            }

            return redirect()->route('advertiser.add-funds')
                ->with('error', UserMessages::get('payment.verification_failed_support'));

        } catch (\Throwable $e) {
            Log::error('Checkout success error: '.$e->getMessage());

            return redirect()->route('advertiser.add-funds')
                ->with('error', UserFacingError::message($e, UserMessages::get('payment.verify_failed_support')));
        }
    }

    /**
     * Instant wallet top-up with a saved Stripe card.
     */
    public function payWithSavedCard(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10|max:100000',
            'payment_method_id' => 'required|string',
            'reference_code' => 'required|string',
        ]);

        if (! app(StripeCustomerService::class)->configured()) {
            return response()->json([
                'success' => false,
                'message' => UserMessages::get('payment.cards_not_configured_short'),
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
                app(WalletStripeDepositService::class)->creditFromPaymentIntent(
                    $user->id,
                    $payResult['payment_intent_id'],
                    $chargedEuros,
                    $referenceCode
                );

                return response()->json([
                    'success' => true,
                    'message' => '€'.number_format($chargedEuros, 2).' added to your wallet with your saved card.',
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
                'message' => UserMessages::get('payment.saved_card_deposit_declined'),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Saved card wallet deposit failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, UserMessages::get('payment.stripe_saved_card_failed')),
            ], 422);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:10|max:100000',
                'payment_method' => 'required|in:wise,crypto,bank',
                'reference_code' => 'nullable|string',
            ]);

            $user = auth()->user();

            // Invoice methods need billing details on the PDF.
            if (in_array($request->payment_method, ['bank', 'wise', 'crypto'], true)) {
                if (! $this->userHasInvoiceBilling($user)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Please complete your billing information first so we can issue your invoice.',
                        'requires_billing' => true,
                    ]);
                }
            }

            $cryptoEnabled = true;
            if (class_exists(DepositPaymentConfig::class) && method_exists(DepositPaymentConfig::class, 'cryptoEnabled')) {
                $cryptoEnabled = DepositPaymentConfig::cryptoEnabled();
            }
            if ($request->payment_method === 'crypto' && ! $cryptoEnabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cryptocurrency deposits are temporarily unavailable. Please use Bank or Wise.',
                ], 422);
            }

            if (method_exists(DepositRequest::class, 'tableAvailable') && ! DepositRequest::tableAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deposits are temporarily unavailable. Please try again shortly.',
                ], 503);
            }

            // Server owns the REF when that helper exists. Leftover Hostinger
            // store() still required a client 6-digit code — JS sends one, we
            // never persist XXXXXXXX placeholders.
            $referenceCode = $this->newDepositReferenceCode($request);

            $payload = [
                'user_id' => auth()->id(),
                'reference_code' => $referenceCode,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'status' => 'pending',
            ];
            if (method_exists(DepositRequest::class, 'attributesThatExist')) {
                $payload = DepositRequest::attributesThatExist($payload);
            }

            $depositRequest = DepositRequest::create($payload);

            // Send email notification to admin (skip when no mailbox is configured —
            // empty ADMIN_EMAIL + no admin users used to throw "To/Cc/Bcc header").
            try {
                $recipients = $this->adminDepositMailRecipients();
                if ($recipients === []) {
                    Log::info('Deposit invoice created with no admin mailbox configured', [
                        'deposit_request_id' => $depositRequest->id,
                    ]);
                } else {
                    foreach ($recipients as $email) {
                        Mail::to($email)->send(new DepositRequestSubmitted($depositRequest));
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Failed to send deposit notification email: '.$e->getMessage());
            }

            try {
                $notifications = app(InAppNotificationService::class);
                if (method_exists($notifications, 'notifyDepositSubmitted')) {
                    $freshDeposit = $depositRequest->fresh(['user']);
                    $notifications->notifyDepositSubmitted($freshDeposit);
                    if (method_exists($notifications, 'notifyAdminsDepositSubmitted')) {
                        $notifications->notifyAdminsDepositSubmitted($freshDeposit);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to send deposit bell notification: '.$e->getMessage());
            }

            $invoiceUrl = null;
            $markPaidUrl = null;
            try {
                $invoiceUrl = route('advertiser.invoice', $referenceCode);
            } catch (\Throwable) {
            }
            try {
                $markPaidUrl = route('advertiser.add-funds.mark-paid', $depositRequest);
            } catch (\Throwable) {
            }

            return response()->json([
                'success' => true,
                'message' => 'Invoice created. Transfer the amount with your REF — we credit your wallet after funds arrive.',
                'reference_code' => $referenceCode,
                'deposit_id' => $depositRequest->id,
                'invoice_url' => $invoiceUrl,
                'mark_paid_url' => $markPaidUrl,
            ]);

        } catch (ValidationException $e) {
            // Always JSON — leftover Add Funds JS parse-failed HTML redirects
            // into "Failed to submit request".
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Please check the amount and payment method.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error submitting deposit request: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Could not create the invoice. Please try again.'),
            ], $e instanceof QueryException ? 503 : 500);
        }
    }

    /**
     * @param  User|null  $user
     */
    private function userHasInvoiceBilling($user): bool
    {
        if (! $user) {
            return false;
        }

        try {
            if (empty($user->billing_name) || empty($user->address)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        try {
            if (! Schema::hasColumn($user->getTable(), 'company_name')) {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            return ! empty($user->company_name);
        } catch (\Throwable) {
            return true;
        }
    }

    private function newDepositReferenceCode(Request $request): string
    {
        if (method_exists(DepositRequest::class, 'generateUniqueReferenceCode')) {
            try {
                $code = (string) DepositRequest::generateUniqueReferenceCode();
                if ($code !== '') {
                    return $code;
                }
            } catch (\Throwable) {
            }
        }

        $client = preg_replace('/^REF/i', '', trim((string) $request->input('reference_code', '')));
        if (is_string($client) && preg_match('/^\d{6,}$/', $client) && ! preg_match('/^x+$/i', $client)) {
            return $client;
        }

        return str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
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

        try {
            $firstReport = ! $deposit->userHasMarkedPaid();

            if ($firstReport) {
                if (! DepositRequest::hasUserMarkedPaidAtColumn()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Deposits are temporarily unavailable. Please try again shortly.',
                    ], 503);
                }

                $deposit->update(DepositRequest::attributesThatExist([
                    'user_marked_paid_at' => now(),
                    'user_payment_note' => $data['user_payment_note'] ?? $deposit->user_payment_note,
                ]));
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
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not record that payment. Please try again shortly.'),
            ], $e instanceof QueryException ? 503 : 500);
        }
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
            $recipients = $this->adminDepositMailRecipients();
            foreach ($recipients as $email) {
                Mail::to($email)->send(new DepositMarkedPaid($deposit));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send deposit marked-paid email: '.$e->getMessage(), [
                'deposit_request_id' => $deposit->id,
            ]);
        }
    }

    /**
     * Admin deposit emails need a real To address. Empty ADMIN_EMAIL with no
     * admin users (common on leftover Hostinger) must not call Mail::to('').
     *
     * @return list<string>
     */
    private function adminDepositMailRecipients(): array
    {
        $emails = [];

        try {
            $admins = User::whereHas('roles', fn ($query) => $query->where('name', 'admin'))->get();
            foreach ($admins as $admin) {
                $email = trim((string) ($admin->email ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
        } catch (\Throwable) {
        }

        if ($emails === []) {
            $fallback = trim((string) (
                config('mail.admin_email')
                ?: config('email_notifications.brand.support_email')
                ?: ''
            ));
            if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $fallback;
            }
        }

        return array_values(array_unique($emails));
    }

    public function getStatus($id)
    {
        try {
            if (! DepositRequest::tableAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deposits are temporarily unavailable. Please try again shortly.',
                ], 503);
            }

            $depositRequest = DepositRequest::where('user_id', auth()->id())
                ->where('id', $id)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'status' => $depositRequest->status,
                'user_marked_paid_at' => optional($depositRequest->user_marked_paid_at)?->toIso8601String(),
                'deposit' => $depositRequest,
            ]);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not load that deposit. Please refresh and try again.'),
            ], 500);
        }
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
            $billing = [
                'billing_name' => $request->billing_name,
                'company_name' => $request->company_name,
                'country' => $request->country,
                'state' => $request->state,
                'city' => $request->city,
                'address' => $request->address,
                'postal_code' => $request->postal_code,
                'vat_number' => $request->vat_number,
            ];
            $table = $user->getTable();
            foreach ($billing as $column => $value) {
                try {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }
                    $user->{$column} = $value;
                } catch (\Throwable) {
                    continue;
                }
            }
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
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Please fill in all required billing fields.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
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
                'has_info' => $this->userHasInvoiceBilling($user)
                    && ! empty($user->city)
                    && ! empty($user->country),
            ];

            return response()->json([
                'success' => true,
                'data' => $billingInfo,
            ]);

        } catch (\Throwable $e) {
            Log::error('Error fetching billing info: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, UserMessages::get('payment.billing_fetch_failed')),
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

        } catch (\Throwable $e) {
            Log::error('Error showing invoice: '.$e->getMessage());

            return redirect()->route('advertiser.add-funds')
                ->with('error', UserFacingError::message($e, 'Invoice not found'));
        }
    }
}
