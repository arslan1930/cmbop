<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualWithdrawalInvalidTransitionException;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use App\Services\Wallet\WithdrawalPayoutContext;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Email one-click mark-paid: signed GET shows confirm UI; POST settles via
 * ManualWithdrawalSettlementService. Never marks paid on GET.
 */
class WithdrawalMarkPaidConfirmController extends Controller
{
    public function show(Request $request, Withdrawal $withdrawal)
    {
        if (! $this->hasValidSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        try {
            $withdrawal->loadMissing('user');
        } catch (\Throwable) {
            $withdrawal->setRelation('user', null);
        }
        $canMarkPaid = $withdrawal->isActionable();
        try {
            $context = app(WithdrawalPayoutContext::class)->payoutContext($withdrawal, $canMarkPaid);
        } catch (\Throwable $e) {
            Log::warning('Failed to build withdrawal mark-paid confirm context: '.$e->getMessage(), [
                'withdrawal_id' => $withdrawal->id,
            ]);
            $context = [
                'currentBalance' => 0.0,
                'priorPaid' => collect(),
                'possibleDuplicate' => false,
                'duplicateMatches' => collect(),
            ];
        }

        return view('admin.withdrawals.mark-paid-confirm', array_merge($context, [
            'withdrawal' => $withdrawal,
            'canMarkPaid' => $canMarkPaid,
            'confirmAction' => $request->fullUrl(),
        ]));
    }

    public function confirm(Request $request, Withdrawal $withdrawal, ManualWithdrawalSettlementService $settlement)
    {
        if (! $this->hasValidSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $result = $settlement->markPaid(
                $withdrawal,
                $request->user(),
                $request->input('notes')
            );

            $message = $result['unchanged']
                ? $result['message']
                : 'Marked paid. Net €'.number_format((float) $result['withdrawal']->net_amount, 2).' confirmed for WD-'.$result['withdrawal']->id.'.';

            return redirect()
                ->route('admin.withdrawals')
                ->with('success', $message);
        } catch (ManualWithdrawalInvalidTransitionException $e) {
            return redirect()
                ->route('admin.withdrawals')
                ->with('error', UserFacingError::message($e, 'This withdrawal cannot be updated from its current status.'));
        } catch (\Throwable $e) {
            Log::error('Failed to mark withdrawal paid from email confirm link', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('admin.withdrawals')
                ->with('error', UserFacingError::message($e, 'Failed to mark withdrawal paid. Please try again.'));
        }
    }

    protected function hasValidSignature(Request $request): bool
    {
        return $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params());
    }

    protected function invalidSignatureResponse()
    {
        return redirect()
            ->route('admin.withdrawals')
            ->with('error', 'This mark-paid link is invalid or has expired. Open the payout queue and mark paid from the admin panel, or request a fresh email.');
    }
}
