<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerStatement;
use App\Models\Withdrawal;
use App\Services\Partners\PartnerChannel;
use App\Services\Partners\PartnerLedgerService;
use App\Services\Partners\PartnerPayoutModeService;
use App\Services\Partners\PartnerWithdrawalService;
use App\Services\Wallet\PayoutProfileService;
use App\Support\ListingMoney;
use App\Support\WithdrawalPayoutConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class MoneyController extends Controller
{
    public function index(Request $request, PartnerLedgerService $ledger, PayoutProfileService $payouts): View
    {
        $user = $request->user();
        $partner = Partner::forUser($user);
        abort_unless($partner, 404);

        $pending = $ledger->pendingMinor($partner);
        $available = $ledger->availableMinor($partner);
        $lines = $ledger->partnerLines($partner, 40);
        $statements = PartnerStatement::query()
            ->where('partner_id', $partner->id)
            ->orderByDesc('period')
            ->limit(12)
            ->get();
        $withdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(15)
            ->get();
        $min = app(ListingMoney::class)->minWithdrawal('EUR');
        $availableMethods = array_values(array_intersect(
            $payouts->availableMethods($user),
            WithdrawalPayoutConfig::allowedMethods('EUR')
        ));

        return view('partner.money.index', [
            'partner' => $partner,
            'mode' => PartnerChannel::payoutMode($partner),
            'pending' => PartnerChannel::minorToEuros($pending),
            'available' => PartnerChannel::minorToEuros($available),
            'minWithdrawal' => $min,
            'lines' => $lines,
            'statements' => $statements,
            'withdrawals' => $withdrawals,
            'availableMethods' => $availableMethods,
            'payoutLocked' => $user->payoutProfileLocked(),
            'keepLiveText' => PartnerPayoutModeService::KEEP_LIVE_TEXT,
            'keepLiveRequested' => (string) $partner->payout_mode_requested === PartnerChannel::MODE_INSTANT,
        ]);
    }

    public function withdraw(Request $request, PartnerWithdrawalService $service): RedirectResponse
    {
        $partner = Partner::forUser($request->user());
        abort_unless($partner, 404);
        try {
            $service->request($request->user(), $partner, $request);
        } catch (RuntimeException $e) {
            $message = $e->getMessage() === 'insufficient_available'
                ? 'Not enough available balance.'
                : $e->getMessage();

            return back()->with('error', $message)->withInput();
        }

        return back()->with('success', 'Withdrawal requested. No fees.');
    }

    public function cancel(Request $request, int $id, PartnerWithdrawalService $service): RedirectResponse
    {
        $partner = Partner::forUser($request->user());
        abort_unless($partner, 404);
        $row = Withdrawal::query()->where('user_id', $request->user()->id)->where('id', $id)->firstOrFail();
        try {
            $service->cancel($row, $partner);
        } catch (RuntimeException $e) {
            return back()->with('error', 'That withdrawal cannot be cancelled.');
        }

        return back()->with('success', 'Withdrawal cancelled. Funds returned to available.');
    }

    public function requestInstant(Request $request, PartnerPayoutModeService $modes): RedirectResponse
    {
        $request->validate(['accept_keep_live' => 'accepted']);
        $partner = Partner::forUser($request->user());
        abort_unless($partner, 404);
        $modes->requestInstant($partner, $request->user());

        return back()->with('success', 'Keep-live accepted. SLB must approve instant payouts.');
    }
}
