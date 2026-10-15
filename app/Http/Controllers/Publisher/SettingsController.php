<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Support\ListingMoney;
use App\Support\UserFacingError;
use App\Support\ViewerCountry;

class SettingsController extends Controller
{
    public function index()
    {
        try {
            $user = auth()->user();
            $currency = app(ViewerCountry::class)->displayCurrency();

            return view('publisher.settings', [
                'payoutProfile' => $user->payoutProfile(),
                'payoutLocked' => $user->payoutProfileLocked(),
                'accountCurrency' => $currency,
                'minWithdrawalAmount' => app(ListingMoney::class)->minWithdrawal($currency),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('publisher.dashboard')
                ->with('error', UserFacingError::message($e, 'We could not open Settings. Please try again.'));
        }
    }
}
