<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\BalanceTransfer;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletRoleMoveException;
use App\Services\Wallet\WalletRoleMoveService;
use App\Support\ListingMoney;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
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
        try {
            $user = auth()->user();
            $publisherWallets = Wallet::forPublisherAll($user->id);
            $publisherWallet = $publisherWallets->first(fn ($wallet) => strtoupper((string) $wallet->currency) === 'EUR')
                ?? $publisherWallets->first();
            $advertiserWallet = Wallet::where('user_id', $user->id)
                ->where('role_id', Wallet::advertiserRoleId())
                ->first();

            $publisher = $publisherWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
            $advertiser = $advertiserWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
            $publisherCurrency = strtoupper((string) ($publisherWallet->currency ?? 'EUR')) ?: 'EUR';
            $minWithdrawalAmount = app(ListingMoney::class)->minWithdrawal($publisherCurrency);
            $roleMoveMinAmount = max(0.01, round((float) config('billing.role_move.min_amount', 0.01), 2));
            $canWithdraw = $publisher['debt'] <= 0 && $publisher['withdrawable'] >= $minWithdrawalAmount;
            $showAdvertiserWallet = $user->hasRole('advertiser');
            $canMove = $showAdvertiserWallet
                && $publisher['debt'] <= 0
                && $publisher['withdrawable'] >= $roleMoveMinAmount;

            return view('publisher.balance', [
                'publisher' => $publisher,
                'advertiser' => $advertiser,
                'publisherWallets' => $publisherWallets,
                'publisherCurrency' => $publisherCurrency,
                'publisherBalance' => $publisher['spendable'],
                'advertiserBalance' => $advertiser['spendable'],
                'publisherDebt' => $publisher['debt'],
                'minWithdrawalAmount' => $minWithdrawalAmount,
                'roleMoveMinAmount' => $roleMoveMinAmount,
                'canWithdraw' => $canWithdraw,
                'canMove' => $canMove,
                'showAdvertiserWallet' => $showAdvertiserWallet,
            ]);
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'We could not load your balance. Please refresh and try again.')
            );

            $empty = Wallet::emptyRoleSnapshot();

            return view('publisher.balance', [
                'publisher' => $empty,
                'advertiser' => $empty,
                'publisherWallets' => collect(),
                'publisherCurrency' => 'EUR',
                'publisherBalance' => 0.0,
                'advertiserBalance' => 0.0,
                'publisherDebt' => 0.0,
                'minWithdrawalAmount' => app(ListingMoney::class)->minWithdrawal('EUR'),
                'roleMoveMinAmount' => max(0.01, round((float) config('billing.role_move.min_amount', 0.01), 2)),
                'canWithdraw' => false,
                'canMove' => false,
                'showAdvertiserWallet' => auth()->user()?->hasRole('advertiser') ?? false,
            ]);
        }
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

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch transfer history.'),
            ], 500);
        }
    }

    /**
     * Publisher wallet ledger (earnings, withdrawals, moves, adjustments).
     */
    public function transactions(Request $request)
    {
        try {
            if (! Schema::hasTable('wallet_transactions')) {
                return response()->json([
                    'success' => true,
                    'transactions' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'total' => 0,
                        'from' => null,
                        'to' => null,
                    ],
                ]);
            }

            $user = auth()->user();
            $walletIds = Wallet::forPublisherAll($user->id)->pluck('id')->filter()->values();
            $query = WalletTransaction::query()
                ->where('user_id', $user->id)
                ->when($walletIds->isNotEmpty(), fn ($q) => $q->whereIn('wallet_id', $walletIds))
                ->orderByDesc('created_at');

            $type = search_text($request->get('type'));
            if ($type !== '' && $type !== 'all') {
                $query->where('type', $type);
            }

            $direction = search_text($request->get('direction'));
            if (in_array($direction, ['credit', 'debit'], true)) {
                $query->where('direction', $direction);
            }

            $search = search_text($request->get('q'));
            if ($search !== '') {
                $query->where(function ($builder) use ($search) {
                    $builder->where('description', 'like', '%'.$search.'%')
                        ->orWhere('reference', 'like', '%'.$search.'%')
                        ->orWhere('type', 'like', '%'.$search.'%');
                });
            }

            $page = $query->paginate(20)->withQueryString();
            $items = collect($page->items())->map(function (WalletTransaction $tx) {
                $currency = strtoupper((string) ($tx->currency ?: 'EUR')) ?: 'EUR';
                $signed = $tx->isCredit()
                    ? abs((float) $tx->amount)
                    : -abs((float) $tx->amount);

                return [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'type_label' => match ($tx->type) {
                        WalletTransaction::TYPE_TRANSFER_IN => 'Earnings',
                        WalletTransaction::TYPE_ROLE_MOVE_OUT => 'Moved to advertiser wallet',
                        default => $tx->typeLabel(),
                    },
                    'direction' => $tx->direction,
                    'amount' => (float) $tx->amount,
                    'amount_display' => format_money_amount($signed, $currency),
                    'balance_after_display' => $tx->balance_after !== null
                        ? format_money_amount($tx->balance_after, $currency)
                        : null,
                    'currency' => $currency,
                    'status' => $tx->status,
                    'description' => $tx->description,
                    'reference' => $tx->reference,
                    'created_at' => optional($tx->created_at)?->toIso8601String(),
                    'created_label' => optional($tx->created_at)?->timezone(config('app.timezone'))->format('d M Y H:i'),
                ];
            })->values()->all();

            return response()->json([
                'success' => true,
                'transactions' => $items,
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'from' => $page->firstItem(),
                    'to' => $page->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Unable to load your wallet history.'),
            ], 500);
        }
    }
}
