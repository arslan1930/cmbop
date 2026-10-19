<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Publisher\WithdrawalController as PublisherWithdrawalController;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Wallet\PayoutProfileService;
use App\Support\ListingMoney;
use App\Support\WithdrawalPayoutConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MoneyController extends Controller
{
    public function index(Request $request, PayoutProfileService $payouts): View
    {
        $user = $request->user();
        $wallets = Wallet::forPublisherAll((int) $user->id);
        $wallet = $wallets->first();
        $currency = $wallet?->currency ?: 'EUR';
        $transactions = collect();
        if ($wallet) {
            $transactions = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->orderByDesc('id')
                ->limit(25)
                ->get();
        }
        $withdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $min = app(ListingMoney::class)->minWithdrawal($currency);
        $availableMethods = array_values(array_intersect(
            $payouts->availableMethods($user),
            WithdrawalPayoutConfig::allowedMethods($currency)
        ));

        return view('partner.money.index', [
            'wallet' => $wallet,
            'wallets' => $wallets,
            'currency' => $currency,
            'available' => $wallet?->withdrawableBalance() ?? 0,
            'minWithdrawal' => $min,
            'feePercent' => (float) config('billing.withdrawal_fee_percent', 0),
            'transactions' => $transactions,
            'withdrawals' => $withdrawals,
            'availableMethods' => $availableMethods,
            'payoutLocked' => $user->payoutProfileLocked(),
        ]);
    }

    public function withdraw(Request $request): RedirectResponse
    {
        return $this->fromJson(
            app(PublisherWithdrawalController::class)->requestWithdrawal($request),
            'Withdrawal requested.'
        );
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        return $this->fromJson(
            app(PublisherWithdrawalController::class)->cancelWithdrawal($id),
            'Withdrawal cancelled.'
        );
    }

    private function fromJson(Response $response, string $fallback): RedirectResponse
    {
        $payload = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
        $ok = (bool) ($payload['success'] ?? ($response->getStatusCode() < 400));
        $message = (string) ($payload['message'] ?? ($ok ? $fallback : 'That request could not be completed.'));

        return $ok ? back()->with('success', $message) : back()->with('error', $message)->withInput();
    }
}
