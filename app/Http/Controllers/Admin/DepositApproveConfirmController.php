<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
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

        $deposit->loadMissing('user');

        return view('admin.deposits.approve-confirm', [
            'deposit' => $deposit,
            'canApprove' => $deposit->isPending(),
            'confirmAction' => $request->fullUrl(),
        ]);
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

            return redirect()
                ->route('admin.deposits')
                ->with('success', $result['message']);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return redirect()
                ->route('admin.deposits')
                ->with('error', $e->getMessage());
        } catch (\Exception $e) {
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
