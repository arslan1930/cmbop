<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\WithdrawalRequestedConfirmation;
use App\Models\Partner;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\EmailNotificationService;
use App\Services\Partners\PartnerWithdrawalService;
use App\Services\Wallet\PayoutProfileService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\ListingMoney;
use App\Support\UserFacingError;
use App\Support\WithdrawalPayoutConfig;
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

    private function minWithdrawalAmount(?string $currency = 'EUR'): float
    {
        return app(ListingMoney::class)->minWithdrawal($currency);
    }

    private function moneyLabel(float $amount, ?string $currency = 'EUR'): string
    {
        return app(ListingMoney::class)->formatAmount($amount, $currency);
    }

    public function index()
    {
        $user = auth()->user();
        $error = null;

        try {
            $wallets = Wallet::forPublisherAll((int) $user->id);
            $wallet = $wallets->first();
            $availableMethods = $this->payoutProfiles->availableMethods($user);
        } catch (\Throwable $e) {
            report($e);
            $wallet = null;
            $wallets = collect();
            $availableMethods = [];
            $error = UserFacingError::message($e, 'We could not load your wallet. Please refresh and try again.');
        }

        try {
            $recentWithdrawals = Withdrawal::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
        } catch (\Throwable $e) {
            report($e);
            $recentWithdrawals = collect();
            $error ??= UserFacingError::message($e, 'We could not load withdrawals. Please refresh and try again.');
        }

        if ($error) {
            session()->flash('error', $error);
        }

        $currency = $wallet?->currency ?: 'EUR';
        $allowedMethodsByCurrency = [];
        $conversionFeePercents = [];
        foreach (ListingMoney::WALLET_CURRENCIES as $code) {
            $allowedMethodsByCurrency[$code] = WithdrawalPayoutConfig::allowedMethods($code);
            $conversionFeePercents[$code] = WithdrawalPayoutConfig::conversionFeePercent($code);
        }
        $allowedWithdrawalMethods = WithdrawalPayoutConfig::allowedMethods($currency);
        $savedMethods = $availableMethods;
        $availableMethods = array_values(array_intersect($savedMethods, $allowedWithdrawalMethods));

        return view('publisher.withdraw', [
            'wallet' => $wallet,
            'publisherWallets' => $wallets ?? collect(),
            'walletCurrency' => $currency,
            'platformChargePercent' => $this->platformChargePercent(),
            'minWithdrawalAmount' => $this->minWithdrawalAmount($currency),
            'minWithdrawalAmounts' => collect($wallets ?? [])->mapWithKeys(
                fn (Wallet $w) => [strtoupper((string) $w->currency) => $this->minWithdrawalAmount($w->currency)]
            )->all(),
            'payoutProfile' => $user->payoutProfile(),
            'payoutLocked' => $user->payoutProfileLocked(),
            'availableMethods' => $availableMethods,
            'savedMethods' => $savedMethods,
            'allowedWithdrawalMethods' => $allowedWithdrawalMethods,
            'allowedMethodsByCurrency' => $allowedMethodsByCurrency,
            'conversionFeePercents' => $conversionFeePercents,
            'usdConversionFeePercent' => WithdrawalPayoutConfig::conversionFeePercent($currency),
            'supportEmail' => config('email_notifications.brand.support_email', config('mail.from.address')),
            'recentWithdrawals' => $recentWithdrawals,
        ]);
    }

    public function requestWithdrawal(Request $request)
    {
        try {
            $user = auth()->user();
            $currency = app(ListingMoney::class)->normalizeWallet($request->input('currency', 'EUR'));
            $wallet = Wallet::forPublisher((int) $user->id, $currency);

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
                    'message' => 'Withdrawals are blocked while you have outstanding clawback debt of '
                        .$this->moneyLabel($wallet->debtBalance(), $currency)
                        .'. Please contact support to resolve this.',
                    'debt_balance' => $wallet->debtBalance(),
                ], 422);
            }

            $min = $this->minWithdrawalAmount($currency);
            $allowedMethods = WithdrawalPayoutConfig::allowedMethods($currency);

            $request->validate([
                'amount' => 'required|numeric|min:'.$min.'|max:999999.99',
                'payment_method' => 'required|in:'.implode(',', $allowedMethods),
                'currency' => 'nullable|in:EUR,GBP,USD',
            ], [
                'amount.min' => 'Minimum withdrawal amount is '.$this->moneyLabel($min, $currency).'.',
                'payment_method.in' => WithdrawalPayoutConfig::methodUnavailableMessage(),
            ]);

            if (! WithdrawalPayoutConfig::allowsMethod((string) $request->payment_method, $currency)) {
                return response()->json([
                    'success' => false,
                    'code' => 'usd_method_not_allowed',
                    'message' => WithdrawalPayoutConfig::methodUnavailableMessage(),
                ], 422);
            }

            $amount = round((float) $request->amount, 2);
            $availableBalance = $wallet->withdrawableBalance();

            if ($amount < $min) {
                return response()->json([
                    'success' => false,
                    'code' => 'below_minimum',
                    'message' => 'Minimum withdrawal amount is '.$this->moneyLabel($min, $currency).'.',
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
                    'message' => 'Insufficient withdrawable balance for this withdrawal. Available to withdraw: '.$this->moneyLabel($availableBalance, $currency).'.'.$promoNote,
                ], 422);
            }

            $fee = round(($amount * $this->platformChargePercent()) / 100, 2);
            $conversionFeePercent = WithdrawalPayoutConfig::conversionFeePercent($currency);
            $conversionFee = WithdrawalPayoutConfig::conversionFee($amount, $currency);
            $netAmount = round(max(0, $amount - $fee - $conversionFee), 2);

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
                    'message' => 'Withdrawals are blocked while you have outstanding clawback debt of '
                        .$this->moneyLabel($wallet->debtBalance(), $currency)
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
                    'message' => 'Insufficient withdrawable balance for this withdrawal. Available to withdraw: '.$this->moneyLabel($available, $currency),
                ], 422);
            }

            $payload = [
                'user_id' => $user->id,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'payment_method' => $request->payment_method,
                'payment_details' => $paymentDetails,
                'status' => 'pending',
            ];
            if (Withdrawal::hasTableColumn('conversion_fee')) {
                $payload['conversion_fee'] = $conversionFee;
            }
            if (Withdrawal::hasTableColumn('conversion_fee_percent')) {
                $payload['conversion_fee_percent'] = $conversionFeePercent;
            }

            $withdrawal = Withdrawal::create(array_merge(
                $payload,
                Withdrawal::walletIdAttributes($wallet)
            ));

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
                'conversion_fee' => $conversionFee,
                'currency' => $currency,
                'payment_method' => $request->payment_method,
                'payout_locked' => true,
            ]);

            $this->sendAdminNotification($withdrawal, $user);
            $this->sendPublisherConfirmation($withdrawal);

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully! Amount: '.$this->moneyLabel($amount, $currency),
                'payout_locked' => true,
                'withdrawal_id' => $withdrawal->id,
                'fee' => $fee,
                'conversion_fee' => $conversionFee,
                'conversion_fee_percent' => $conversionFeePercent,
                'net_amount' => $netAmount,
                'currency' => $currency,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: '.implode(', ', array_merge(...array_values($e->errors()))),
            ], 422);
        } catch (\Throwable $e) {
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

            if ($request->has('status') && in_array($request->status, ['pending', 'processing', 'completed', 'cancelled'], true)) {
                $query->where('status', $request->status);
            }

            $fromDate = search_text($request->input('from_date'));
            if ($fromDate !== '') {
                $query->whereDate('created_at', '>=', $fromDate);
            }
            $toDate = search_text($request->input('to_date'));
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
                    'conversion_fee' => (float) ($w->conversion_fee ?? 0),
                    'conversion_fee_percent' => (float) ($w->conversion_fee_percent ?? 0),
                    'net_amount' => (float) $w->net_amount,
                    'currency' => strtoupper((string) ($w->currency ?: 'EUR')) ?: 'EUR',
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
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch withdrawal history.'),
            ], 500);
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
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch withdrawal statistics.'),
            ], 500);
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

            if (class_exists(PartnerWithdrawalService::class)
                && PartnerWithdrawalService::isPartnerLedger($withdrawal)) {
                $partner = Partner::forUser($user);
                if ($partner) {
                    app(PartnerWithdrawalService::class)->cancel($withdrawal, $partner);
                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Withdrawal request cancelled. Funds returned to available.',
                    ]);
                }
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
            app(WalletLedgerService::class)->syncWithdrawalStatus($withdrawal);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request cancelled successfully. €'.number_format($withdrawal->amount, 2).' has been returned to your wallet.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to cancel withdrawal request.'),
            ], 500);
        }
    }
}
