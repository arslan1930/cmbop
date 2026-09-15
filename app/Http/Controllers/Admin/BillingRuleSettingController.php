<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingRuleSetting;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BillingRuleSettingController extends Controller
{
    public function updateMin(Request $request, BillingRuleService $rules): RedirectResponse
    {
        if (! $this->ensureStorage()) {
            return back()->with('error', 'Payout rules are not available yet. Run migrations.');
        }

        $data = $request->validate([
            'min_amount' => ['required', 'numeric', 'min:0.01', 'max:'.BillingRuleSetting::minAmountMax()],
        ]);
        $amount = round((float) $data['min_amount'], 2);
        $already = abs($rules->minWithdrawalAmount() - $amount) <= 0.001;

        try {
            $rules->setMinAmount($amount, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to update minimum withdrawal: '.$e->getMessage());

            return back()->with('error', 'Could not update the minimum withdrawal. Please try again.');
        }

        if (abs($rules->minWithdrawalAmount() - $amount) > 0.001) {
            return back()->with('error', 'Could not update the minimum withdrawal. Please try again.');
        }

        if ($already) {
            return back()->with('success', $this->minSuccessMessage($amount));
        }

        ActivityLogger::tryLog(
            'billing.min_withdrawal_changed',
            ($request->user()?->name ?? 'Admin').' set the minimum withdrawal to €'.number_format($amount, 2),
            null,
            ['min_amount' => $amount]
        );

        return back()->with('success', $this->minSuccessMessage($amount));
    }

    public function updateFee(Request $request, BillingRuleService $rules): RedirectResponse
    {
        if (! $this->ensureStorage()) {
            return back()->with('error', 'Payout rules are not available yet. Run migrations.');
        }

        $data = $request->validate([
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:'.BillingRuleSetting::feePercentMax()],
        ]);
        $percent = round((float) $data['fee_percent'], 2);
        $already = abs($rules->withdrawalFeePercent() - $percent) <= 0.001;

        try {
            $rules->setFeePercent($percent, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::warning('Failed to update withdrawal fee: '.$e->getMessage());

            return back()->with('error', 'Could not update the withdrawal fee. Please try again.');
        }

        if (abs($rules->withdrawalFeePercent() - $percent) > 0.001) {
            return back()->with('error', 'Could not update the withdrawal fee. Please try again.');
        }

        if ($already) {
            return back()->with('success', $this->feeSuccessMessage($percent));
        }

        ActivityLogger::tryLog(
            'billing.withdrawal_fee_changed',
            ($request->user()?->name ?? 'Admin').' set the withdrawal fee to '.$this->formatPercent($percent).'%',
            null,
            ['fee_percent' => $percent]
        );

        return back()->with('success', $this->feeSuccessMessage($percent));
    }

    private function ensureStorage(): bool
    {
        try {
            BillingRuleSetting::ensureTable();

            return Schema::hasTable('billing_rule_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    private function minSuccessMessage(float $amount): string
    {
        return 'Minimum withdrawal set to €'.number_format($amount, 2)
            .'. New payout requests use this floor. Existing requests stay.';
    }

    private function feeSuccessMessage(float $percent): string
    {
        return 'Withdrawal fee set to '.$this->formatPercent($percent)
            .'%. Applies only to new requests. Existing withdrawals keep the fee already stored.';
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') ?: '0';
    }
}
