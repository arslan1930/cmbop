<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Mail\WithdrawalRequestNotification;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\InAppNotificationService;
use App\Services\Wallet\PayoutProfileService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

    public function index()
    {
        $user = auth()->user();

        return view('publisher.withdraw', [
            'platformChargePercent' => $this->platformChargePercent(),
            'payoutProfile' => $user->payoutProfile(),
            'payoutLocked' => $user->payoutProfileLocked(),
            'supportEmail' => config('email_notifications.brand.support_email', config('mail.from.address')),
        ]);
    }

    public function requestWithdrawal(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01|max:999999.99',
                'payment_method' => 'required|in:bank,paypal,wise,crypto',
            ]);

            $user = auth()->user();
            $wallet = $user->activeWallet();

            if (! $wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'No wallet found. Please contact support.',
                ]);
            }

            $amount = $request->amount;
            $availableBalance = $wallet->withdrawableBalance();

            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please enter a valid amount greater than 0.',
                ]);
            }

            if ($amount > $availableBalance) {
                if ($wallet->lockedBonusBalance() > 0 && $availableBalance <= 0) {
                    return response()->json([
                        'success' => false,
                        'code' => 'bonus_not_withdrawable',
                        'message' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
                        'available_for_withdrawal' => $availableBalance,
                    ]);
                }

                $promoNote = $wallet->lockedBonusBalance() > 0
                    ? ' '.Wallet::PROMOTIONAL_BONUS_MESSAGE
                    : '';

                return response()->json([
                    'success' => false,
                    'code' => $wallet->lockedBonusBalance() > 0 ? 'bonus_not_withdrawable' : 'insufficient_balance',
                    'message' => 'Insufficient withdrawable balance for this withdrawal. Available to withdraw: €'.number_format($availableBalance, 2).'.'.$promoNote,
                ]);
            }

            $fee = ($amount * $this->platformChargePercent()) / 100;
            $netAmount = $amount - $fee;

            $wasLocked = $user->payoutProfileLocked();
            $paymentDetails = $this->payoutProfiles->validatedPaymentDetails(
                $request,
                $user,
                requireConfirm: ! $wasLocked
            );

            if (! $wasLocked) {
                $this->payoutProfiles->persistAndLock($user, (string) $request->payment_method, $paymentDetails);
            }

            DB::beginTransaction();

            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            if (! $wallet || ! $wallet->canWithdraw((float) $amount)) {
                DB::rollBack();
                $lockedBonus = $wallet?->lockedBonusBalance() ?? 0;
                $available = $wallet?->withdrawableBalance() ?? 0;
                if ($lockedBonus > 0 && $available <= 0) {
                    return response()->json([
                        'success' => false,
                        'code' => 'bonus_not_withdrawable',
                        'message' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient withdrawable balance for this withdrawal. Available to withdraw: €'.number_format($available, 2),
                ]);
            }

            $withdrawal = Withdrawal::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
                'payment_method' => $request->payment_method,
                'payment_details' => $paymentDetails,
                'status' => 'pending',
            ]);

            $wallet->deductWithdrawable($amount);

            app(WalletLedgerService::class)->recordWithdrawal(
                $wallet,
                (float) $amount,
                $withdrawal,
                'pending',
                'WD-'.$withdrawal->id
            );

            DB::commit();

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

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully! Amount: €'.number_format($amount, 2),
                'payout_locked' => true,
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
                'message' => 'Failed to process withdrawal request. Please try again later. Error: '.$e->getMessage(),
            ]);
        }
    }

    private function sendAdminNotification($withdrawal, $user)
    {
        try {
            $admins = User::where('active_role_id', function ($query) {
                $query->select('id')
                    ->from('roles')
                    ->where('name', 'admin')
                    ->limit(1);
            })->get();

            if ($admins->count() > 0) {
                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(new WithdrawalRequestNotification($withdrawal, $user));
                }
            } else {
                $defaultAdminEmail = config('mail.admin_email', env('ADMIN_EMAIL', 'admin@yourdomain.com'));
                Mail::to($defaultAdminEmail)->send(new WithdrawalRequestNotification($withdrawal, $user));
            }

            try {
                app(InAppNotificationService::class)
                    ->notifyAdminsWithdrawalRequested($withdrawal, $user);
            } catch (\Throwable $e) {
                Log::warning('Failed to send admin withdrawal bell notification: '.$e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('Failed to send withdrawal notification email: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function getHistory(Request $request)
    {
        try {
            $user = auth()->user();

            $query = Withdrawal::where('user_id', $user->id);

            if ($request->has('status') && in_array($request->status, ['pending', 'processing', 'completed', 'cancelled'])) {
                $query->where('status', $request->status);
            }

            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $withdrawals = $query->orderBy('created_at', 'desc')->paginate(20);

            $withdrawals->getCollection()->transform(function ($w) {
                return [
                    'id' => $w->id,
                    'amount' => $w->amount,
                    'payment_method' => $w->payment_method,
                    'status' => $w->status,
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
                ->where('status', 'pending')
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

            $withdrawal = Withdrawal::where('user_id', $user->id)
                ->where('id', $id)
                ->where('status', 'pending')
                ->first();

            if (! $withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal request not found or cannot be cancelled',
                ]);
            }

            DB::beginTransaction();

            $withdrawal = Withdrawal::where('id', $withdrawal->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $withdrawal) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Withdrawal request not found or cannot be cancelled',
                ]);
            }

            $wallet = $user->activeWallet();
            if ($wallet) {
                $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
                if ($wallet) {
                    $wallet->credit((float) $withdrawal->amount);
                }
            }

            $withdrawal->status = 'cancelled';
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
