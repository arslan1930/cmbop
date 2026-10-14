<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Advertiser\AdvertiserSpendService;
use Illuminate\Support\Facades\Schema;

/**
 * Locked account display currency (EUR / GBP / USD) and the matching bank rail.
 * Ledger stays EUR. Guests can still browse in any currency.
 */
class AccountCurrency
{
    public const GBP_WISE = 'gbp_wise';

    public const EUR_WISE = 'eur_wise';

    /**
     * @return array<string, string>
     */
    public static function bankAccounts(): array
    {
        return [
            self::GBP_WISE => 'GBP Wise',
            self::EUR_WISE => 'EUR Wise',
        ];
    }

    /**
     * @return 'EUR'|'GBP'|'USD'|null
     */
    public static function normalize(?string $raw): ?string
    {
        return app(DisplayCurrencyPreference::class)->normalize((string) $raw);
    }

    /**
     * @return 'EUR'|'GBP'|'USD'|null
     */
    public static function forUser(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $saved = self::normalize((string) ($user->display_currency ?? ''));
        if ($saved !== null) {
            return $saved;
        }

        return app(DisplayCurrencyPreference::class)->accountCountryCurrency($user);
    }

    /**
     * @return 'gbp_wise'|'eur_wise'|null
     */
    public static function bankAccountForCurrency(?string $currency): ?string
    {
        return match (self::normalize((string) $currency)) {
            'GBP' => self::GBP_WISE,
            'EUR' => self::EUR_WISE,
            default => null,
        };
    }

    /**
     * @return 'gbp_wise'|'eur_wise'|null
     */
    public static function bankAccountForUser(?User $user): ?string
    {
        return self::bankAccountForCurrency(self::forUser($user));
    }

    /**
     * @return 'gbp'|'eur'|null
     */
    public static function bankRailForCurrency(?string $currency): ?string
    {
        return match (self::normalize((string) $currency)) {
            'GBP' => 'gbp',
            'EUR' => 'eur',
            default => null,
        };
    }

    /**
     * @return 'gbp'|'eur'
     */
    public static function bankRailForUser(?User $user): string
    {
        return self::bankRailForCurrency(self::forUser($user)) ?? 'eur';
    }

    /**
     * @return 'gbp_wise'|'eur_wise'|null
     */
    public static function normalizeBank(?string $raw): ?string
    {
        return match (strtolower(trim((string) $raw))) {
            'gbp_wise', 'gbp', 'uk', 'uk_bank', 'pounds' => self::GBP_WISE,
            'eur_wise', 'eur', 'other', 'sepa', 'iban' => self::EUR_WISE,
            default => null,
        };
    }

    public static function bankLabel(?string $bank): string
    {
        $code = self::normalizeBank($bank);
        if ($code === null) {
            return '';
        }

        return self::bankAccounts()[$code];
    }

    /**
     * @return 'EUR'|'GBP'|null
     */
    public static function currencyForBank(?string $bank): ?string
    {
        return match (self::normalizeBank($bank)) {
            self::GBP_WISE => 'GBP',
            self::EUR_WISE => 'EUR',
            default => null,
        };
    }

    /**
     * @return 'EUR'|'GBP'
     */
    public static function chargeCurrencyForUser(?User $user): string
    {
        return self::forUser($user) === 'GBP' ? 'GBP' : 'EUR';
    }

    public static function paymentReference(int $id, string $currency): string
    {
        $code = self::normalize($currency) ?? 'EUR';

        return 'SLB-'.$id.'-'.$code;
    }

    public static function bankMismatchMessage(string $lockedCurrency): string
    {
        $code = self::normalize($lockedCurrency) ?? 'EUR';

        return 'Bank transfer must be in '.$code.'.';
    }

    /**
     * @return array{
     *     has_balance: bool,
     *     has_open_orders: bool,
     *     has_open_withdrawals: bool,
     *     warning: bool,
     *     message: string
     * }
     */
    public static function changeRisk(?User $user): array
    {
        $empty = [
            'has_balance' => false,
            'has_open_orders' => false,
            'has_open_withdrawals' => false,
            'warning' => false,
            'message' => '',
        ];
        if (! $user) {
            return $empty;
        }

        $hasBalance = self::hasNonZeroBalance($user);
        $hasOpenOrders = self::hasOpenOrders($user);
        $hasOpenWithdrawals = self::hasOpenWithdrawals($user);
        $parts = [];
        if ($hasBalance) {
            $parts[] = 'a non-zero wallet balance';
        }
        if ($hasOpenOrders) {
            $parts[] = 'open orders';
        }
        if ($hasOpenWithdrawals) {
            $parts[] = 'open withdrawals';
        }

        $message = $parts === []
            ? ''
            : 'This account has '.self::joinParts($parts).'.';

        return [
            'has_balance' => $hasBalance,
            'has_open_orders' => $hasOpenOrders,
            'has_open_withdrawals' => $hasOpenWithdrawals,
            'warning' => $parts !== [],
            'message' => $message,
        ];
    }

    private static function hasNonZeroBalance(User $user): bool
    {
        try {
            if (! Schema::hasTable('wallets')) {
                return false;
            }

            return Wallet::query()
                ->where('user_id', $user->id)
                ->where(function ($query): void {
                    $query->where('balance', '!=', 0)
                        ->orWhere('reserved_balance', '!=', 0);
                })
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasOpenOrders(User $user): bool
    {
        try {
            if (! Schema::hasTable('orders')) {
                return false;
            }
            $statuses = AdvertiserSpendService::IN_PROGRESS_STATUSES;
            if (Schema::hasColumn('orders', 'status')) {
                $statuses[] = 'scheduled';
            }

            return Order::query()
                ->where('user_id', $user->id)
                ->whereIn('status', array_values(array_unique($statuses)))
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasOpenWithdrawals(User $user): bool
    {
        try {
            if (! Schema::hasTable('withdrawals')) {
                return false;
            }

            return Withdrawal::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private static function joinParts(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        if (count($parts) === 2) {
            return $parts[0].' and '.$parts[1];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).', and '.$last;
    }
}
