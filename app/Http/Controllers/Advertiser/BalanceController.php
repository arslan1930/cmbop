<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Mail\WithdrawalRequestNotification;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\InAppNotificationService;
use App\Services\Wallet\WalletLedgerService;
use App\Services\Wallet\WalletOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BalanceController extends Controller
{
    public function __construct(
        protected WalletOverviewService $overview,
        protected WalletLedgerService $ledger
    ) {}

    public function index()
    {
        // Balance + Add Funds are merged into a single Add Funds page.
        return redirect()->route('advertiser.add-funds');
    }

    public function transactions(Request $request)
    {
        $userId = auth()->id();
        $paginator = $this->overview->activity($userId, [
            'search' => $request->get('search'),
            'type' => $request->get('type'),
            'status' => $request->get('status'),
            'from' => $request->get('from'),
            'to' => $request->get('to'),
            'page' => $request->get('page', 1),
        ], (int) $request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'transactions' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function transactionShow(Request $request, string $source, $id)
    {
        $row = $this->overview->findActivity(auth()->id(), $source, $id);
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        }

        if (! empty($row['invoice_id'])) {
            $invoice = Invoice::where('user_id', auth()->id())->find($row['invoice_id']);
            if ($invoice) {
                $row['invoice_download_url'] = route('advertiser.billing.download', $invoice);
                $row['invoice_view_url'] = route('advertiser.billing.show', $invoice);
            }
        }

        if (empty($row['invoice_download_url']) && ($row['source'] ?? '') === 'deposit' && ! empty($row['reference'])) {
            $row['invoice_view_url'] = route('advertiser.invoice', $row['reference']);
            $row['invoice_download_url'] = route('advertiser.invoice', ['referenceCode' => $row['reference'], 'download' => 1]);
        }

        return response()->json(['success' => true, 'transaction' => $row]);
    }

    public function analytics(Request $request)
    {
        $range = $request->get('range', 'month');
        $allowed = ['week', '7d', 'month', '30d', '90d', 'quarter', 'year', 'lifetime', 'custom'];
        if (! in_array($range, $allowed, true)) {
            $range = 'month';
        }

        return response()->json([
            'success' => true,
            'analytics' => $this->overview->analytics(
                auth()->id(),
                $range,
                $request->get('from'),
                $request->get('to')
            ),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->overview->exportRows(auth()->id(), [
            'search' => $request->get('search'),
            'type' => $request->get('type'),
            'status' => $request->get('status'),
            'from' => $request->get('from'),
            'to' => $request->get('to'),
        ]);

        $filename = 'wallet-statement-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Type', 'Description', 'Reference', 'Amount', 'Status', 'Balance After']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['date'] ?? '',
                    $row['type_label'] ?? '',
                    $row['description'] ?? '',
                    $row['reference'] ?? '',
                    number_format((float) ($row['signed_amount'] ?? 0), 2, '.', ''),
                    $row['status'] ?? '',
                    $row['balance_after'] !== null ? number_format((float) $row['balance_after'], 2, '.', '') : '',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Withdraw from Available Balance only (never bonus).
     */
    public function requestWithdrawal(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01|max:999999.99',
                'payment_method' => 'required|in:bank,paypal,wise,crypto',
            ]);

            $user = auth()->user();
            $advertiserRoleId = Wallet::advertiserRoleId() ?? 1;
            $wallet = Wallet::where('user_id', $user->id)
                ->where('role_id', $advertiserRoleId)
                ->first();

            if (! $wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'No wallet found. Please contact support.',
                ], 422);
            }

            $amount = round((float) $request->amount, 2);
            $available = $wallet->withdrawableBalance();
            $bonus = $wallet->lockedBonusBalance();

            if ($available <= 0) {
                return response()->json([
                    'success' => false,
                    'code' => 'bonus_not_withdrawable',
                    'message' => $bonus > 0
                        ? Wallet::PROMOTIONAL_BONUS_MESSAGE
                        : 'You have no available balance to withdraw.',
                    'available_for_withdrawal' => $available,
                    'bonus_balance' => $bonus,
                ], 422);
            }

            if ($amount > $available) {
                $message = $bonus > 0
                    ? Wallet::PROMOTIONAL_BONUS_MESSAGE.' Available for withdrawal: €'.number_format($available, 2).'.'
                    : 'Insufficient available balance. Available for withdrawal: €'.number_format($available, 2).'.';

                return response()->json([
                    'success' => false,
                    'code' => 'bonus_not_withdrawable',
                    'message' => $message,
                    'available_for_withdrawal' => $available,
                    'bonus_balance' => $bonus,
                ], 422);
            }

            $paymentDetails = $this->validatedPaymentDetails($request, $user);
            $this->persistPayoutProfile($user, $request->payment_method, $paymentDetails);

            $feePercent = (float) config('billing.withdrawal_fee_percent', 0);
            $fee = round(($amount * $feePercent) / 100, 2);
            $netAmount = round($amount - $fee, 2);

            $withdrawal = null;

            DB::transaction(function () use ($wallet, $user, $amount, $fee, $netAmount, $request, $paymentDetails, &$withdrawal) {
                $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
                if (! $locked || ! $locked->canWithdraw($amount)) {
                    throw new \RuntimeException(
                        $locked && $locked->lockedBonusBalance() > 0 && $locked->withdrawableBalance() <= 0
                            ? Wallet::PROMOTIONAL_BONUS_MESSAGE
                            : 'Insufficient available balance for this withdrawal.'
                    );
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

                $locked->deductWithdrawable($amount);

                $this->ledger->recordWithdrawal(
                    $locked,
                    $amount,
                    $withdrawal,
                    'pending',
                    'WD-'.$withdrawal->id
                );
            });

            $this->notifyAdmins($withdrawal, $user);

            $wallet->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Withdrawal request submitted successfully.',
                'available_balance' => $wallet->withdrawableBalance(),
                'bonus_balance' => $wallet->lockedBonusBalance(),
                'spendable_balance' => (float) $wallet->balance,
                'pending_withdrawals' => (float) Withdrawal::where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->sum('amount'),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: '.implode(', ', array_merge(...array_values($e->errors()))),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Advertiser withdrawal failed: '.$e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            $message = $e->getMessage();
            if (str_contains($message, 'promotional bonus')) {
                $message = Wallet::PROMOTIONAL_BONUS_MESSAGE;
            }

            return response()->json([
                'success' => false,
                'code' => str_contains($message, 'promotional') ? 'bonus_not_withdrawable' : 'withdrawal_failed',
                'message' => $message,
            ], 422);
        }
    }

    public function transferToPublisher(Request $request)
    {
        return response()->json([
            'success' => false,
            'code' => 'transfers_disabled',
            'message' => 'Role-to-role fund transfers have been disabled. You can spend Available Balance on the marketplace or withdraw it. Bonus credit can only be used for purchases on this website.',
        ], 410);
    }

    /** @deprecated Prefer transactions(); kept for backward compatibility */
    public function getTransferHistory(Request $request)
    {
        return $this->transactions($request->merge(['type' => 'transfer_out']));
    }

    protected function validatedPaymentDetails(Request $request, User $user): array
    {
        $locked = $user->payoutProfileLocked();
        $profile = $user->payoutProfile();

        $request->validate([
            'business_name' => 'required|string|max:255',
            'payment_method' => 'required|in:bank,paypal,wise,crypto',
        ]);

        if ($locked && $profile['business_name'] && $request->business_name !== $profile['business_name']) {
            throw ValidationException::withMessages([
                'business_name' => 'Business name is locked. Contact support to change it.',
            ]);
        }

        switch ($request->payment_method) {
            case 'bank':
                $request->validate([
                    'bank_name' => 'required|string|max:255',
                    'account_holder' => 'required|string|max:255',
                    'account_number' => 'required|string|max:255',
                    'swift_code' => 'nullable|string|max:50',
                ]);
                if ($locked && $profile['bank_holder_name'] && $request->account_holder !== $profile['bank_holder_name']) {
                    throw ValidationException::withMessages([
                        'account_holder' => 'Bank account holder name is locked. Contact support to change it.',
                    ]);
                }

                return [
                    'business_name' => $request->business_name,
                    'bank_name' => $request->bank_name,
                    'account_holder' => $request->account_holder,
                    'account_number' => $request->account_number,
                    'swift_code' => $request->swift_code,
                ];
            case 'paypal':
                $request->validate(['paypal_email' => 'required|email|max:255']);
                if ($locked && $profile['paypal_email'] && $request->paypal_email !== $profile['paypal_email']) {
                    throw ValidationException::withMessages([
                        'paypal_email' => 'PayPal email is locked. Contact support to change it.',
                    ]);
                }

                return [
                    'business_name' => $request->business_name,
                    'email' => $request->paypal_email,
                ];
            case 'wise':
                $request->validate(['wise_email' => 'required|email|max:255']);

                return [
                    'business_name' => $request->business_name,
                    'email' => $request->wise_email,
                ];
            case 'crypto':
                $request->validate([
                    'crypto_type' => 'required|string|in:TRX,USDT_TRC20',
                    'wallet_address' => 'required|string|max:255',
                    'wallet_address_confirm' => 'required|string|max:255|same:wallet_address',
                ], [
                    'wallet_address_confirm.same' => 'TRX wallet addresses must match exactly (enter twice to verify).',
                ]);

                if ($locked && $profile['crypto_trx_wallet'] && $request->wallet_address !== $profile['crypto_trx_wallet']) {
                    throw ValidationException::withMessages([
                        'wallet_address' => 'Crypto TRX wallet is locked. Contact support to change it.',
                    ]);
                }

                return [
                    'business_name' => $request->business_name,
                    'crypto_type' => $request->crypto_type,
                    'wallet_address' => $request->wallet_address,
                    'network' => 'TRX / TRC20',
                    'double_verified' => true,
                ];
            default:
                return [];
        }
    }

    protected function persistPayoutProfile(User $user, string $method, array $details): void
    {
        $updates = [];

        if (empty($user->payout_business_name) && ! empty($details['business_name'])) {
            $updates['payout_business_name'] = $details['business_name'];
        }

        if ($method === 'paypal' && empty($user->payout_paypal_email) && ! empty($details['email'])) {
            $updates['payout_paypal_email'] = $details['email'];
        }

        if ($method === 'bank') {
            if (empty($user->payout_bank_holder_name) && ! empty($details['account_holder'])) {
                $updates['payout_bank_holder_name'] = $details['account_holder'];
            }
            if (empty($user->payout_bank_name) && ! empty($details['bank_name'])) {
                $updates['payout_bank_name'] = $details['bank_name'];
            }
            if (empty($user->payout_bank_account) && ! empty($details['account_number'])) {
                $updates['payout_bank_account'] = $details['account_number'];
            }
            if (empty($user->payout_bank_swift) && ! empty($details['swift_code'])) {
                $updates['payout_bank_swift'] = $details['swift_code'];
            }
        }

        if ($method === 'crypto' && empty($user->payout_crypto_trx_wallet) && ! empty($details['wallet_address'])) {
            $updates['payout_crypto_trx_wallet'] = $details['wallet_address'];
            $updates['payout_crypto_trx_verified_at'] = now();
        }

        if ($updates !== [] && empty($user->payout_profile_locked_at)) {
            $updates['payout_profile_locked_at'] = now();
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    protected function notifyAdmins(?Withdrawal $withdrawal, User $user): void
    {
        if (! $withdrawal) {
            return;
        }

        try {
            $admins = User::where('active_role_id', function ($query) {
                $query->select('id')->from('roles')->where('name', 'admin')->limit(1);
            })->get();

            if ($admins->isEmpty()) {
                $defaultAdminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
                if ($defaultAdminEmail) {
                    Mail::to($defaultAdminEmail)->send(new WithdrawalRequestNotification($withdrawal, $user));
                }
            } else {
                foreach ($admins as $admin) {
                    Mail::to($admin->email)->send(new WithdrawalRequestNotification($withdrawal, $user));
                }
            }

            app(InAppNotificationService::class)
                ->notifyAdminsWithdrawalRequested($withdrawal, $user);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins of advertiser withdrawal', [
                'error' => $e->getMessage(),
                'withdrawal_id' => $withdrawal->id,
            ]);
        }
    }
}
