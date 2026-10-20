<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\PayoutProfileService;
use App\Support\ListingMoney;
use App\Support\WithdrawalPayoutConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PartnerWithdrawalService
{
    public function __construct(
        private PartnerLedgerService $ledger,
        private PayoutProfileService $payouts,
    ) {}

    public function request(User $user, Partner $partner, Request $request): Withdrawal
    {
        $currency = 'EUR';
        $min = app(ListingMoney::class)->minWithdrawal($currency);
        $allowed = WithdrawalPayoutConfig::allowedMethods($currency);
        $data = $request->validate([
            'amount' => 'required|numeric|min:'.$min.'|max:999999.99',
            'payment_method' => 'required|in:'.implode(',', $allowed),
        ], [
            'amount.min' => 'Minimum withdrawal is €'.number_format($min, 2).'.',
        ]);
        $amount = round((float) $data['amount'], 2);
        $minor = PartnerChannel::eurosToMinor($amount);
        if ($this->ledger->availableMinor($partner) < $minor) {
            throw new RuntimeException('insufficient_available');
        }
        $details = $this->payouts->validatedPaymentDetails(
            $request,
            $user,
            requireConfirm: ! $user->payoutProfileLocked()
        );

        return DB::transaction(function () use ($user, $partner, $amount, $minor, $data, $details, $currency) {
            $wallet = Wallet::publisherRoleId()
                ? Wallet::lockOrCreateForRole((int) $user->id, (int) Wallet::publisherRoleId(), $currency)
                : null;
            $payload = [
                'user_id' => $user->id,
                'amount' => $amount,
                'fee' => 0,
                'net_amount' => $amount,
                'payment_method' => $data['payment_method'],
                'payment_details' => $details,
                'status' => 'pending',
            ];
            if (Withdrawal::hasTableColumn('conversion_fee')) {
                $payload['conversion_fee'] = 0;
            }
            if (Withdrawal::hasTableColumn('conversion_fee_percent')) {
                $payload['conversion_fee_percent'] = 0;
            }
            if (Withdrawal::hasTableColumn('currency')) {
                $payload['currency'] = $currency;
            }
            $withdrawal = Withdrawal::query()->create(array_merge(
                $payload,
                Withdrawal::walletIdAttributes($wallet)
            ));
            $tx = $this->ledger->debitAvailableForPayout($partner, $minor, (int) $withdrawal->id);
            if (Schema::hasColumn('withdrawals', 'partner_ledger_transaction_id')) {
                $withdrawal->partner_ledger_transaction_id = $tx->id;
                $withdrawal->save();
            }

            return $withdrawal->fresh();
        });
    }

    public function cancel(Withdrawal $withdrawal, Partner $partner): Withdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new RuntimeException('not_cancellable');
        }
        $minor = PartnerChannel::eurosToMinor((float) $withdrawal->amount);
        $this->ledger->creditAvailable(
            $partner,
            $minor,
            'payout-cancel-'.$withdrawal->id,
            'Withdrawal cancelled'
        );
        $withdrawal->status = 'cancelled';
        if (Schema::hasColumn('withdrawals', 'cancelled_by')) {
            $withdrawal->cancelled_by = Withdrawal::CANCELLED_BY_USER;
        }
        if (Schema::hasColumn('withdrawals', 'cancelled_at')) {
            $withdrawal->cancelled_at = now();
        }
        $withdrawal->save();

        return $withdrawal;
    }

    public function refundToLedger(Withdrawal $withdrawal): bool
    {
        if (! Schema::hasColumn('withdrawals', 'partner_ledger_transaction_id')) {
            return false;
        }
        if (! $withdrawal->partner_ledger_transaction_id) {
            return false;
        }
        $user = $withdrawal->user ?: User::query()->find($withdrawal->user_id);
        if (! $user) {
            return false;
        }
        $partner = Partner::forUser($user);
        if (! $partner) {
            return false;
        }
        $minor = PartnerChannel::eurosToMinor((float) $withdrawal->amount);
        $this->ledger->creditAvailable(
            $partner,
            $minor,
            'payout-refund-'.$withdrawal->id,
            'Withdrawal rejected'
        );

        return true;
    }

    public static function isPartnerLedger(Withdrawal $withdrawal): bool
    {
        return Schema::hasColumn('withdrawals', 'partner_ledger_transaction_id')
            && (int) ($withdrawal->partner_ledger_transaction_id ?? 0) > 0;
    }
}
