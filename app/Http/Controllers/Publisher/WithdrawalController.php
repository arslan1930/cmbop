<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\WithdrawalRequestedConfirmation;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\EmailNotificationService;
use App\Services\Wallet\PayoutProfileService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WithdrawalController extends Controller
{
    public function __construct(
        private PayoutProfileService $payoutProfiles,
    ) {}

    private function platformChargePercent(): float
    {
        return (float) config('billing.withdrawal_fee_percent', 0);
    }

    private function minWithdrawalAmount(): float
    {
        return max(0.01, round((float) config('billing.withdrawal_min_amount', 20), 2));
    }

    public function index()
    {
        $user = auth()->user();

        return view('publisher.withdraw', [
            'platformChargePercent' => $this->platformChargePercent(),
            'minWithdrawalAmount' => $this->minWithdrawalAmount(),
            'payoutProfile' => $user->payoutProfile(),
            'payoutLocked' => $user->payoutProfileLocked(),
            'availableMethods' => $this->payoutProfiles->availableMethods($user),
            'supportEmail' => config('email_notifications.brand.support_email', config('mail.from.address')),
        ]);
    }

    public function requestWithdrawal(Request $request)
    {
        try {
            $user = auth()->user();
            $wallet = $user->activeWallet();

            if (! $wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'No wallet found. Please contact support.',
                ]);
            }

            // Debt blocks all withdrawals — check before amount/min validation so
            // indebted publishers always get a clear wallet_debt response.
            if ($wallet->hasDebt()) {
                return response()->json([
                    'success' => false,
                    'code' => 'wallet_debt',
                    'message' => 'Withdrawals are blocked while you have outstanding clawback debt of €'
                        .number_format($wallet->debtBalance(), 2)
                        .'. Please contact support to resolve this.',
                    'debt_balance' => $wallet->debtBalance(),
                ], 422);
            }

            $min = $this->minWithdrawalAmount();

            $request->validate([
                'amount' => 'required|numeric|min:'.$min.'|max:999999.99',
                'payment_method' => 'required|in:bank,paypal,wise,crypto',
            ], [
                'amount.min' => 'Minimum withdrawal amount is €'.number_format($min, 2).'.',
            ]);

            $amount = round((float) $request->amount, 2);
            $availableBalance = $wallet->withdrawableBalance();

            if ($amount < $min) {
                return response()->json([
                    'success' => false,
                    'code' => 'below_minimum',
                    'message' => 'Minimum withdrawal amount is €'.number_format($min, 2).'.',
                    'min_amount' => $min,
                ], 422);
            }

            if ($amount > $availableBalance) {
                if ($wallet->lockedBonusBalance() > 0 && $availableBalance <= 0) {
                    return response()->json([
                        'success' => false,
                        'code' => 'bonus_not_withdrawable',
                        'message' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
                        'available_for_withdrawal' => $availableBalance,
                    ], 422);
                }

                $promoNote = $wallet->lockedBonusBalance() > 0
                    ? ' '.Wallet::PROMOTIONAL_BONUS_MESSAGE
                    : '';

                return response()->json([
                    'success' => false,
                    'code' => $wallet->lockedBonusBalance() > 0 ? 'bonus_not_withdrawable' : 'insufficient_balance',
                    'message' => 'Insufficient withdrawable balance for this withdrawal. Available to withdraw: €'.number_format($availableBalance, 2).'.'.$promoNote,
                ], 422);
            }

            $fee = round(($amount * $this->platformChargePercent()) / 100, 2);
            $netAmount = round($amount - $fee, 2);

            $wasLocked = $user->payoutProfileLocked();
            $paymentDetails = $this->payoutProfiles->validatedPaymentDetails(
                $request,
                $user,
                requireConfirm: ! $wasLocked
            );

            DB::beginTransaction();

            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            if ($wallet && $wallet->hasDebt()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'code' => 'wallet_debt',
                    'message' => 'Withdrawals are blocked while you have outstanding clawback debt of €'
                        .number_format($wallet->debtBalance(), 2)
                        .'. Please contact support to resolve this.',
                    'debt_balance' => $wallet->debtBalance(),
                ], 422);
            }
            if (! $wallet || ! $wallet->canWithdraw((float) $amount)) {
                DB::rollBack();
                $lockedBonus = $wallet?->lockedBonusBalance() ?? 0;
                $available = $wallet?->withdrawableBalance() ?? 0;
                if ($lockedBonus > 0 && $available <= 0) {
                    return response()->json([
                        'success' => false,
                        'code' => 'bonus_not_withdrawable',
                        'message' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
                    ], 422);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient withdrawable balance for this withdrawal. Available to withdraw: €'.number_format($available, 2),
                ], 422);
            }

            $withdrawal = Withdrawal::create(array_merge([
                'user_id' => $user->id,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'payment_method' => $request->payment_method,
                'payment_details' => $paymentDetails,
                'status' => 'pending',
            ], Withdrawal::walletIdAttributes($wallet)));

            $wallet->deductWithdrawable($amount);

            app(WalletLedgerService::class)->recordWithdrawal(
                $wallet,
                (float) $amount,
                $withdrawal,
                'pending',
                'WD-'.$withdrawal->id
            );

            DB::commit();

            // Lock / prefer method only after a successful withdrawal.
            if (! $wasLocked) {
                $this->payoutProfiles->persistAndLock($user, (string) $request->payment_method, $paymentDetails);
            } else {
                $this->payoutProfiles->setPreferredMethod($user, (string) $request->payment_method);
            }

            Log::info('Withdrawal request submitted', [
                'user_id' => $user->id,
                'withdrawal_id' => $withdrawal->id,
                'amount' => $amount,
                'net_amount' => $netAmount,
                'fee' => $fee,
                'payment_method' => $request->payment_method,
                'payout_locked' => true,
            ]);

            $this->sendAdminNotification($withdrawal, $user);
            $this->sendPublisherConfirmation($withdrawal);

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully! Amount: €'.number_format($amount, 2),
                'payout_locked' => true,
                'withdrawal_id' => $withdrawal->id,
                'fee' => $fee,
                'net_amount' => $netAmount,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: '.implode(', ', array_merge(...array_values($e->errors()))),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Withdrawal request failed: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'We could not process your withdrawal request. Please try again later.'),
            ]);
        }
    }

    private function sendAdminNotification($withdrawal, $user)
    {
        try {
            app(EmailNotificationService::class)->notifyAdminsWithdrawalRequested($withdrawal, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to notify admins of withdrawal request: '.$e->getMessage(), [
                'withdrawal_id' => $withdrawal->id ?? null,
                'user_id' => $user->id ?? null,
            ]);
        }
    }

    private function sendPublisherConfirmation(Withdrawal $withdrawal): void
    {
        try {
            $user = $withdrawal->user;
            if ($user?->email) {
                Mail::to($user->email)->send(new WithdrawalRequestedConfirmation($withdrawal->fresh(['user'])));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send publisher withdrawal confirmation', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getHistory(Request $request)
    {
        try {
            $user = auth()->user();

            $query = Withdrawal::where('user_id', $user->id);

            $status = scalar_text($request->status);
            if ($status !== '' && in_array($status, ['pending', 'processing', 'completed', 'cancelled'], true)) {
                $query->where('status', $status);
            }

            $fromDate = scalar_text($request->from_date);
            if ($fromDate !== '') {
                $query->whereDate('created_at', '>=', $fromDate);
            }
            $toDate = scalar_text($request->to_date);
            if ($toDate !== '') {
                $query->whereDate('created_at', '<=', $toDate);
            }

            $withdrawals = $query->orderBy('created_at', 'desc')->paginate(10);

            $withdrawals->getCollection()->transform(function ($w) {
                return [
                    'id' => $w->id,
                    'reference' => 'WD-'.$w->id,
                    'amount' => (float) $w->amount,
                    'fee' => (float) $w->fee,
                    'net_amount' => (float) $w->net_amount,
                    'payment_method' => $w->payment_method,
                    'destination_snippet' => $w->destination_snippet,
                    'destination_copy_text' => $w->destination_copy_text,
                    'status' => $w->status,
                    'status_label' => $w->publisher_status_label,
                    'cancellable' => $w->isCancellableByPublisher(),
                    'created_at' => $w->created_at,
                    'processed_at' => $w->processed_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $withdrawals,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch withdrawal history: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch withdrawal history',
            ]);
        }
    }

    public function getStatistics()
    {
        try {
            $user = auth()->user();

            $totalWithdrawn = Withdrawal::where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum('net_amount');

            $pendingWithdrawals = Withdrawal::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount');

            $withdrawalCount = Withdrawal::where('user_id', $user->id)->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_withdrawn' => $totalWithdrawn,
                    'pending_withdrawals' => $pendingWithdrawals,
                    'withdrawal_count' => $withdrawalCount,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch withdrawal statistics: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
            ]);
        }
    }

    public function cancelWithdrawal($id)
    {
        try {
            $user = auth()->user();

            DB::beginTransaction();

            $withdrawal = Withdrawal::where('user_id', $user->id)
                ->where('id', $id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $withdrawal) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal request not found or cannot be cancelled',
                ], 404);
            }

            $wallet = $withdrawal->resolveDebitedWallet(lockForUpdate: true);
            if (! $wallet) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot return these funds: the source wallet is unknown. Please contact support.',
                ], 422);
            }

            $wallet->credit((float) $withdrawal->amount);
            try {
                app(WalletLedgerService::class)->recordAdjustment(
                    $wallet,
                    (float) $withdrawal->amount,
                    'credit',
                    $withdrawal,
                    'WD-'.$withdrawal->id.'-cancel',
                    'Withdrawal cancelled — funds returned to wallet',
                    [
                        'withdrawal_id' => $withdrawal->id,
                        'reason' => 'withdrawal_cancelled_by_user',
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to record withdrawal cancel ledger credit', [
                    'withdrawal_id' => $withdrawal->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $withdrawal->status = 'cancelled';
            if (Schema::hasColumn('withdrawals', 'cancelled_by')) {
                $withdrawal->cancelled_by = Withdrawal::CANCELLED_BY_USER;
            }
            if (Schema::hasColumn('withdrawals', 'cancelled_at')) {
                $withdrawal->cancelled_at = now();
            }
            $withdrawal->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request cancelled successfully. €'.number_format($withdrawal->amount, 2).' has been returned to your wallet.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel withdrawal: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel withdrawal request',
            ]);
        }
    }
}
