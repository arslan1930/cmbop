<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\BalanceTransfer;
use App\Models\Wallet;
use App\Services\Wallet\WalletRoleMoveException;
use App\Services\Wallet\WalletRoleMoveService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $publisherWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::publisherRoleId())
            ->first();
        $advertiserWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();

        $publisher = $publisherWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        $advertiser = $advertiserWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        $minWithdrawalAmount = max(0.01, round((float) config('billing.withdrawal_min_amount', 20), 2));
        $canWithdraw = $publisher['debt'] <= 0 && $publisher['withdrawable'] >= $minWithdrawalAmount;
        $showAdvertiserWallet = $advertiserWallet !== null && $user->hasRole('advertiser');

        return view('publisher.balance', [
            'publisher' => $publisher,
            'advertiser' => $advertiser,
            'publisherBalance' => $publisher['spendable'],
            'advertiserBalance' => $advertiser['spendable'],
            'publisherDebt' => $publisher['debt'],
            'minWithdrawalAmount' => $minWithdrawalAmount,
            'canWithdraw' => $canWithdraw,
            'showAdvertiserWallet' => $showAdvertiserWallet,
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
     * Get transfer history — leftover endpoint; the Balance page no longer lists transfers.
     */
    public function getTransferHistory(Request $request)
    {
        try {
            $userId = auth()->id();

            $transfers = BalanceTransfer::where('user_id', $userId)
                ->where('from_role', 'publisher')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'transfers' => $transfers->items(),
                'pagination' => [
                    'current_page' => $transfers->currentPage(),
                    'last_page' => $transfers->lastPage(),
                    'per_page' => $transfers->perPage(),
                    'total' => $transfers->total(),
                    'from' => $transfers->firstItem(),
                    'to' => $transfers->lastItem(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching transfer history: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transfer history',
            ]);
        }
    }
}
