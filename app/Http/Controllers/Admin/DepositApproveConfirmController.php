<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Services\Wallet\DepositApproveContext;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Email one-click approve: signed GET shows a confirm page; POST credits via
 * ManualDepositApprovalService. Never credits on GET.
 */
class DepositApproveConfirmController extends Controller
{
    public function show(Request $request, DepositRequest $deposit)
    {
        if (! $this->hasValidApproveSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        try {
            $deposit->loadMissing('user');
        } catch (\Throwable) {
            $deposit->setRelation('user', null);
        }
        $canApprove = $deposit->isPending();
        try {
            $context = app(DepositApproveContext::class)->walletContext($deposit, $canApprove);
        } catch (\Throwable $e) {
            Log::warning('Failed to build deposit approve confirm context: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);
            $context = [
                'currentBalance' => 0.0,
                'incomingAmount' => round((float) $deposit->amount, 2),
                'projectedBalance' => null,
                'priorDeposits' => collect(),
                'bonusBalance' => 0.0,
                'possibleDuplicate' => false,
                'duplicateMatches' => collect(),
            ];
        }

        return view('admin.deposits.approve-confirm', array_merge($context, [
            'deposit' => $deposit,
            'canApprove' => $canApprove,
            'confirmAction' => $request->fullUrl(),
        ]));
    }

    public function confirm(Request $request, DepositRequest $deposit, ManualDepositApprovalService $approvals)
    {
        if (! $this->hasValidApproveSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $approvals->approve(
                $deposit,
                $request->user(),
                $request->input('admin_notes')
            );

            $message = $result['message'];
            $fresh = $result['deposit']->fresh(['user']);
            $balance = app(DepositApproveContext::class)->advertiserBalance((int) $fresh->user_id);
            if ($balance !== null) {
                $message .= ' New wallet balance: €'.number_format($balance, 2).'.';
            }

            return redirect()
                ->route('admin.deposits')
                ->with('success', $message);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return redirect()
                ->route('admin.deposits')
                ->with('error', UserFacingError::message($e, 'This deposit was already processed.'));
        } catch (\Throwable $e) {
            Log::error('Failed to approve deposit from email confirm link', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('admin.deposits')
                ->with('error', UserFacingError::message($e, 'Failed to approve deposit. Please try again.'));
        }
    }

    protected function hasValidApproveSignature(Request $request): bool
    {
        return $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params());
    }

    protected function invalidSignatureResponse()
    {
        return redirect()
            ->route('admin.deposits')
            ->with('error', 'This approve link is invalid or has expired. Open Deposits and approve from the admin panel, or request a fresh email.');
    }
}
