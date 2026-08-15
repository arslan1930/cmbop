<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $canApprove = $deposit->isPending();
        $context = $this->walletContext($deposit, $canApprove);

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
            $balance = $this->advertiserBalance((int) $fresh->user_id);
            if ($balance !== null) {
                $message .= ' New wallet balance: €'.number_format($balance, 2).'.';
            }

            return $this->redirectToDeposits()->with('success', $message);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return $this->redirectToDeposits()
                ->with('error', UserFacingError::message($e, 'This deposit was already processed.'));
        } catch (\Exception $e) {
            Log::error('Failed to approve deposit from email confirm link', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);

            return $this->redirectToDeposits()
                ->with('error', UserFacingError::message($e, 'Failed to approve deposit. Please try again.'));
        }
    }

    /**
     * @return array{
     *     currentBalance: float,
     *     incomingAmount: float,
     *     projectedBalance: float|null,
     *     priorDeposits: Collection<int, DepositRequest>,
     *     bonusBalance: float,
     *     possibleDuplicate: bool,
     *     duplicateMatches: Collection<int, DepositRequest>
     * }
     */
    protected function walletContext(DepositRequest $deposit, bool $canApprove): array
    {
        $wallet = $this->advertiserWallet((int) $deposit->user_id);
        $currentBalance = round((float) ($wallet?->balance ?? 0), 2);
        $bonusBalance = round((float) ($wallet?->bonus_balance ?? 0), 2);
        $incomingAmount = round((float) $deposit->amount, 2);

        $priorDeposits = DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id)
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $duplicateMatches = $canApprove
            ? $this->duplicateAmountMatches($deposit, $incomingAmount)
            : collect();

        return [
            'currentBalance' => $currentBalance,
            'incomingAmount' => $incomingAmount,
            'projectedBalance' => $canApprove ? round($currentBalance + $incomingAmount, 2) : null,
            'priorDeposits' => $priorDeposits,
            'bonusBalance' => $bonusBalance,
            'possibleDuplicate' => $duplicateMatches->isNotEmpty(),
            'duplicateMatches' => $duplicateMatches,
        ];
    }

    /**
     * Recent completed deposits for this advertiser with the same amount —
     * soft signal that the admin may be about to credit a transfer twice.
     *
     * @return Collection<int, DepositRequest>
     */
    protected function duplicateAmountMatches(DepositRequest $deposit, float $incomingAmount): Collection
    {
        $lookbackDays = max(1, (int) config('billing.deposit_approve_duplicate_lookback_days', 30));
        $since = now()->subDays($lookbackDays);

        return DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id)
            ->where('amount', $incomingAmount)
            ->where(function ($q) use ($since) {
                $q->where('approved_at', '>=', $since)
                    ->orWhere(function ($inner) use ($since) {
                        $inner->whereNull('approved_at')
                            ->where('created_at', '>=', $since);
                    });
            })
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    protected function advertiserWallet(int $userId): ?Wallet
    {
        $roleId = Wallet::advertiserRoleId();
        if (! $roleId || $userId <= 0) {
            return null;
        }

        return Wallet::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();
    }

    protected function advertiserBalance(int $userId): ?float
    {
        $wallet = $this->advertiserWallet($userId);

        return $wallet ? round((float) $wallet->balance, 2) : null;
    }

    protected function hasValidApproveSignature(Request $request): bool
    {
        return $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params());
    }

    protected function invalidSignatureResponse()
    {
        return $this->redirectToDeposits()
            ->with('error', 'This approve link is invalid or has expired. Open Deposits and approve from the admin panel, or request a fresh email.');
    }

    /**
     * Stay on the current host. Absolute route() would jump to APP_URL
     * (www vs bare) and drop the session/flash after confirm.
     */
    protected function redirectToDeposits()
    {
        return redirect()->to(route('admin.deposits', [], false));
    }
}
