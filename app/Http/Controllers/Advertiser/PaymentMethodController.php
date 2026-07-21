<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\StripeCustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentMethodController extends Controller
{
    public function __construct(private StripeCustomerService $stripe) {}

    public function index()
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'configured' => $this->stripe->configured(),
            'cards' => $this->stripe->listCards($user),
        ]);
    }

    public function createSetupSession(Request $request)
    {
        if (! $this->stripe->configured()) {
            return response()->json([
                'success' => false,
                'message' => 'Card payments are not configured. Please contact support.',
            ], 503);
        }

        try {
            $session = $this->stripe->createSetupCheckoutSession(
                auth()->user(),
                route('advertiser.payment-methods.setup-success').'?session_id={CHECKOUT_SESSION_ID}',
                route('advertiser.add-funds', ['tab' => 'cards'])
            );

            return response()->json([
                'success' => true,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create card setup session: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Unable to start card setup. Please try again.',
            ], 500);
        }
    }

    public function setupSuccess(Request $request)
    {
        $sessionId = (string) $request->query('session_id', '');
        if ($sessionId !== '' && $this->stripe->configured()) {
            try {
                $this->stripe->syncDefaultFromSetupSession(auth()->user(), $sessionId);
            } catch (\Throwable $e) {
                Log::warning('Card setup sync failed: '.$e->getMessage());
            }
        }

        return redirect()
            ->route('advertiser.add-funds', ['tab' => 'cards'])
            ->with('success', 'Card saved. You can use it for one-click checkout and wallet top-ups.');
    }

    public function destroy(string $paymentMethodId)
    {
        try {
            $this->stripe->detachPaymentMethod(auth()->user(), $paymentMethodId);

            return response()->json([
                'success' => true,
                'message' => 'Card removed.',
                'cards' => $this->stripe->listCards(auth()->user()->fresh()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove card: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to remove this card.',
            ], 422);
        }
    }

    public function setDefault(Request $request, string $paymentMethodId)
    {
        try {
            $this->stripe->setDefaultPaymentMethod(auth()->user(), $paymentMethodId);

            return response()->json([
                'success' => true,
                'message' => 'Default card updated.',
                'cards' => $this->stripe->listCards(auth()->user()->fresh()),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Unable to set default card.',
            ], 422);
        }
    }
}
