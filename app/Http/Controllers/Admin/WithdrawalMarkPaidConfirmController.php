<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Billing\WithdrawalPayoutStatementService;
use App\Services\Wallet\ManualWithdrawalInvalidTransitionException;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use App\Services\Wallet\WithdrawalDuplicatePayoutWarning;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $withdrawal->loadMissing('user');
        $canMarkPaid = $withdrawal->isActionable();
        $context = $this->payoutContext($withdrawal, $canMarkPaid);

        return response()
            ->view('admin.withdrawals.mark-paid-confirm', array_merge($context, [
                'withdrawal' => $withdrawal,
                'canMarkPaid' => $canMarkPaid,
                'missingStatement' => $this->missingPayoutStatement($withdrawal),
                'confirmAction' => $request->fullUrl(),
            ]))
            ->header('Cache-Control', 'no-store');
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

            $message = ($result['unchanged'] || empty($result['has_statement']))
                ? $result['message']
                : 'Marked paid. Net €'.number_format((float) $result['withdrawal']->net_amount, 2).' confirmed for WD-'.$result['withdrawal']->id.'.';

            // Missing statement, or Create-statement on an already-paid WD:
            // land on history+search. First-time mark-paid with a statement
            // still returns to the open queue (pay the next one).
            $focus = (
                $result['new_status'] === 'completed'
                && (empty($result['has_statement']) || ! empty($result['unchanged']))
            )
                ? $result['withdrawal']
                : null;

            return $this->redirectToPayoutQueue($focus)->with('success', $message);
        } catch (ManualWithdrawalInvalidTransitionException $e) {
            return $this->redirectToPayoutQueue()
                ->with('error', UserFacingError::message($e, 'This withdrawal cannot be updated from its current status.'));
        } catch (\Throwable $e) {
            Log::error('Failed to mark withdrawal paid from email confirm link', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return $this->redirectToPayoutQueue()
                ->with('error', UserFacingError::message($e, 'Failed to mark withdrawal paid. Please try again.'));
        }
    }

    /**
     * @return array{
     *     currentBalance: float,
     *     priorPaid: Collection<int, Withdrawal>,
     *     possibleDuplicate: bool,
     *     duplicateMatches: Collection<int, Withdrawal>
     * }
     */
    protected function payoutContext(Withdrawal $withdrawal, bool $canMarkPaid): array
    {
        $wallet = $withdrawal->resolveDebitedWallet();
        $currentBalance = round((float) ($wallet?->balance ?? 0), 2);

        $priorPaid = Withdrawal::query()
            ->where('user_id', $withdrawal->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($withdrawal->id)
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $duplicateMatches = $canMarkPaid
            ? app(WithdrawalDuplicatePayoutWarning::class)->matches($withdrawal)
            : collect();

        return [
            'currentBalance' => $currentBalance,
            'priorPaid' => $priorPaid,
            'possibleDuplicate' => $duplicateMatches->isNotEmpty(),
            'duplicateMatches' => $duplicateMatches,
        ];
    }

    protected function missingPayoutStatement(Withdrawal $withdrawal): bool
    {
        if ($withdrawal->status !== 'completed') {
            return false;
        }

        try {
            return ! app(WithdrawalPayoutStatementService::class)->exists($withdrawal);
        } catch (\Throwable) {
            return true;
        }
    }

    protected function hasValidSignature(Request $request): bool
    {
        return $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params());
    }

    /**
     * Stay on the current host. Absolute route() would jump to APP_URL
     * (www vs bare) and drop the session/flash after confirm.
     */
    protected function redirectToPayoutQueue(?Withdrawal $focus = null)
    {
        $params = [];
        if ($focus && (int) $focus->id > 0) {
            $params = [
                'search' => (string) $focus->id,
                'queue' => in_array((string) $focus->status, ['completed', 'cancelled'], true)
                    ? 'history'
                    : 'open',
            ];
        }

        return redirect()->to(route('admin.withdrawals', $params, false));
    }

    protected function invalidSignatureResponse()
    {
        return $this->redirectToPayoutQueue()
            ->with('error', 'This mark-paid link is invalid or has expired. Open the payout queue and mark paid from the admin panel, or request a fresh email.');
    }
}
