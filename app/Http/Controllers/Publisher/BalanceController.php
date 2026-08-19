<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletRoleMoveException;
use App\Services\Wallet\WalletRoleMoveService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BalanceController extends Controller
{
    public function __construct(
        private WalletRoleMoveService $roleMoves,
    ) {}

    /**
     * Display balance page for publisher.
     */
    public function index()
    {
        $user = auth()->user();
        $publisherWallet = Wallet::forPublisher((int) $user->id);
        $advertiserWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();

        $publisher = $publisherWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        $advertiser = $advertiserWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        $minWithdrawalAmount = max(0.01, round((float) config('billing.withdrawal_min_amount', 20), 2));
        $roleMoveMinAmount = max(0.01, round((float) config('billing.role_move.min_amount', 0.01), 2));
        $canWithdraw = $publisher['debt'] <= 0 && $publisher['withdrawable'] >= $minWithdrawalAmount;
        $showAdvertiserWallet = $user->hasRole('advertiser');
        $canMove = $showAdvertiserWallet
            && $publisher['debt'] <= 0
            && $publisher['withdrawable'] >= $roleMoveMinAmount;

        $withdrawalQuery = Withdrawal::queryForPublisherUser($user);
        $pendingOut = round((float) (clone $withdrawalQuery)->whereIn('status', ['pending', 'processing'])->sum('amount'), 2);
        $lifetimeWithdrawn = round((float) (clone $withdrawalQuery)->where('status', 'completed')->sum(
            DB::raw('COALESCE(net_amount, amount)')
        ), 2);

        $activity = collect();
        if (Schema::hasTable('wallet_transactions')) {
            $activity = WalletTransaction::queryForPublisherUser($user)
                ->orderByDesc('id')
                ->limit(10)
                ->get();
        }

        return view('publisher.balance', [
            'publisher' => $publisher,
            'advertiser' => $advertiser,
            'publisherBalance' => $publisher['spendable'],
            'advertiserBalance' => $advertiser['spendable'],
            'publisherDebt' => $publisher['debt'],
            'minWithdrawalAmount' => $minWithdrawalAmount,
            'roleMoveMinAmount' => $roleMoveMinAmount,
            'canWithdraw' => $canWithdraw,
            'canMove' => $canMove,
            'showAdvertiserWallet' => $showAdvertiserWallet,
            'pendingOut' => $pendingOut,
            'lifetimeWithdrawn' => $lifetimeWithdrawn,
            'activity' => $activity,
        ]);
    }

    /**
     * Move publisher withdrawable cash into the advertiser wallet for catalog spend.
     * Advertiser → publisher remains disabled.
     */
    public function transferToAdvertiser(Request $request)
    {
        $min = round((float) config('billing.role_move.min_amount', 0.01), 2);
        $max = round((float) config('billing.role_move.max_amount', 999999.99), 2);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:'.$max],
        ]);

        try {
            $result = $this->roleMoves->publisherToAdvertiser(auth()->user(), (float) $data['amount']);

            return response()->json([
                'success' => true,
                'message' => 'Earnings moved to your advertiser wallet.',
                'reference' => $result['reference'],
                'amount' => $result['amount'],
                'fee' => $result['fee'],
                'net_amount' => $result['net_amount'],
                'publisher' => $result['publisher'],
                'advertiser' => $result['advertiser'],
            ]);
        } catch (WalletRoleMoveException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->errorCode,
                'message' => $e->userMessage,
            ], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('Publisher role move failed', [
                'user_id' => auth()->id(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'code' => 'role_move_failed',
                'message' => UserFacingError::message($e, 'Could not move earnings to your advertiser wallet. Please try again.'),
            ], 500);
        }
    }

    /**
     * Leftover transfer-history endpoint. Activity now lives on the Balance page.
     */
    public function getTransferHistory()
    {
        return response()->json([
            'success' => false,
            'message' => 'Transfer history is no longer listed here.',
        ], 410);
    }
}
