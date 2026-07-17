<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Mail\WithdrawalRequestNotification;
use App\Models\BalanceTransfer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletLedgerService;
use App\Services\Wallet\WalletOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BalanceController extends Controller
{
    public function __construct(
        protected WalletOverviewService $overview,
        protected WalletLedgerService $ledger
    ) {
    }

    public function index()
    {
        $user = auth()->user();
        $advertiserRoleId = Wallet::advertiserRoleId() ?? 1;
        $publisherRoleId = Wallet::publisherRoleId() ?? 2;

        $advertiserWallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'role_id' => $advertiserRoleId],
            [
                'balance' => 0,
                'reserved_balance' => 0,
                'bonus_balance' => 0,
                'bonus_reserved' => 0,
                'currency' => 'EUR',
            ]
        );

        $publisherWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', $publisherRoleId)
            ->first();

        $summary = $this->overview->summary($user->id, $advertiserWallet);
        $analytics = $this->overview->analytics($user->id, 'month');

        return view('advertiser.balance', [
            'wallet' => $advertiserWallet,
            'summary' => $summary,
            'analytics' => $analytics,
            'advertiserBalance' => (float) $advertiserWallet->balance,
            'advertiserBonusBalance' => $advertiserWallet->lockedBonusBalance(),
            'advertiserWithdrawableBalance' => $advertiserWallet->withdrawableBalance(),
            'publisherBalance' => $publisherWallet ? (float) $publisherWallet->balance : 0,
            'promotionalBonusMessage' => Wallet::PROMOTIONAL_BONUS_MESSAGE,
            'addFundsUrl' => route('advertiser.add-funds'),
            'billingUrl' => route('advertiser.billing.index'),
        ]);
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

        return response()->json(['success' => true, 'transaction' => $row]);
    }

    public function analytics(Request $request)
    {
        $range = $request->get('range', 'month');
        if (! in_array($range, ['week', 'month', 'quarter', 'year', 'lifetime'], true)) {
            $range = 'month';
        }

        return response()->json([
            'success' => true,
            'analytics' => $this->overview->analytics(auth()->id(), $range),
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

            $paymentDetails = $this->validatedPaymentDetails($request);

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
        } catch (\Illuminate\Validation\ValidationException $e) {
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
        try {
            $request->validate([
                'amount' => 'required|numeric|min:1',
            ]);

            $userId = auth()->id();
            $amount = round((float) $request->amount, 2);
            $publisherRoleId = Wallet::publisherRoleId() ?? 2;
            $advertiserRoleId = Wallet::advertiserRoleId() ?? 1;

            $advertiserWallet = Wallet::where('user_id', $userId)
                ->where('role_id', $advertiserRoleId)
                ->first();

            $withdrawable = $advertiserWallet ? $advertiserWallet->withdrawableBalance() : 0;
            $bonus = $advertiserWallet ? $advertiserWallet->lockedBonusBalance() : 0;

            if (! $advertiserWallet || $withdrawable < $amount) {
                $message = ($bonus > 0 && $withdrawable <= 0)
                    ? Wallet::PROMOTIONAL_BONUS_MESSAGE
                    : 'Insufficient transferable balance. Available to transfer: €'.number_format($withdrawable, 2).'.';

                return response()->json([
                    'success' => false,
                    'code' => 'bonus_not_withdrawable',
                    'message' => $message,
                ], 422);
            }

            $transfer = null;
            $publisherWallet = null;

            DB::transaction(function () use ($userId, $amount, $advertiserWallet, $publisherRoleId, &$transfer, &$publisherWallet) {
                $locked = Wallet::where('id', $advertiserWallet->id)->lockForUpdate()->first();
                $locked->deductWithdrawable($amount);

                $publisherWallet = Wallet::lockOrCreateForRole($userId, $publisherRoleId);
                $publisherWallet->credit($amount);

                $transfer = BalanceTransfer::create([
                    'user_id' => $userId,
                    'from_role' => 'advertiser',
                    'to_role' => 'publisher',
                    'amount' => $amount,
                    'fee' => 0,
                    'net_amount' => $amount,
                    'reference_code' => BalanceTransfer::generateReferenceCode(),
                    'status' => 'completed',
                    'notes' => null,
                ]);

                $this->ledger->record($locked, \App\Models\WalletTransaction::TYPE_TRANSFER_OUT, 'debit', $amount, [
                    'related' => $transfer,
                    'reference' => $transfer->reference_code,
                    'description' => 'Transfer to Publisher wallet',
                ]);
            });

            $advertiserWallet->refresh();

            Log::info('Transfer from Advertiser to Publisher completed', [
                'user_id' => $userId,
                'amount' => $amount,
                'reference' => $transfer->reference_code,
            ]);

            return response()->json([
                'success' => true,
                'message' => '€'.number_format($amount, 2).' transferred from Advertiser to Publisher wallet successfully!',
                'advertiser_balance' => (float) $advertiserWallet->balance,
                'advertiser_withdrawable_balance' => $advertiserWallet->withdrawableBalance(),
                'advertiser_bonus_balance' => $advertiserWallet->lockedBonusBalance(),
                'publisher_balance' => (float) $publisherWallet->balance,
                'transfer' => $transfer,
            ]);
        } catch (\Throwable $e) {
            Log::error('Transfer failed: '.$e->getMessage());
            $message = str_contains($e->getMessage(), 'promotional bonus')
                ? Wallet::PROMOTIONAL_BONUS_MESSAGE
                : ('Transfer failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }
    }

    /** @deprecated Prefer transactions(); kept for backward compatibility */
    public function getTransferHistory(Request $request)
    {
        return $this->transactions($request->merge(['type' => 'transfer_out']));
    }

    protected function validatedPaymentDetails(Request $request): array
    {
        switch ($request->payment_method) {
            case 'bank':
                $request->validate([
                    'bank_name' => 'required|string|max:255',
                    'account_holder' => 'required|string|max:255',
                    'account_number' => 'required|string|max:255',
                    'swift_code' => 'nullable|string|max:50',
                ]);

                return [
                    'bank_name' => $request->bank_name,
                    'account_holder' => $request->account_holder,
                    'account_number' => $request->account_number,
                    'swift_code' => $request->swift_code,
                ];
            case 'paypal':
                $request->validate(['paypal_email' => 'required|email|max:255']);

                return ['email' => $request->paypal_email];
            case 'wise':
                $request->validate(['wise_email' => 'required|email|max:255']);

                return ['email' => $request->wise_email];
            case 'crypto':
                $request->validate([
                    'crypto_type' => 'required|string|in:BTC,ETH,USDT,BNB',
                    'wallet_address' => 'required|string|max:255',
                ]);

                return [
                    'crypto_type' => $request->crypto_type,
                    'wallet_address' => $request->wallet_address,
                ];
            default:
                return [];
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

                return;
            }

            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(new WithdrawalRequestNotification($withdrawal, $user));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins of advertiser withdrawal', [
                'error' => $e->getMessage(),
                'withdrawal_id' => $withdrawal->id,
            ]);
        }
    }
}
