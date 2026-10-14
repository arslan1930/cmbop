<?php

namespace App\Support;

use App\Models\User;

/**
 * Helpers for advertiser Add Funds deposit payment rails (bank / Wise / crypto).
 */
class DepositPaymentConfig
{
    public const TEQNO_REGISTRATION_NO = '17392540';

    public const TOPURLZ_REGISTRATION_NO = '16607074';

    public static function isUsdCurrencyUser(?User $user = null, ?string $walletCurrency = null): bool
    {
        $user ??= auth()->user();
        try {
            if (app(MoneyDisplay::class)->currency() === 'USD') {
                return true;
            }
        } catch (\Throwable) {
        }

        if (strtoupper(trim((string) $walletCurrency)) === 'USD') {
            return true;
        }

        if ($user instanceof User) {
            if (strtoupper(trim((string) ($user->display_currency ?? ''))) === 'USD') {
                return true;
            }
            try {
                if (app(UsdBankWireGuidance::class)->walletCurrencyFor($user) === 'USD') {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }

    public static function allowsBankTransfer(?User $user = null, ?string $walletCurrency = null): bool
    {
        return ! self::isUsdCurrencyUser($user, $walletCurrency);
    }

    public static function bankUnavailableMessage(): string
    {
        return 'Bank transfer is not available for USD accounts. Please pay with Wise, card, PayPal, or crypto.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function depositPayment(): array
    {
        $pay = (array) config('billing.deposit_payment', []);
        $iban = trim((string) ($pay['iban'] ?? 'GB30 TRWI 2308 0132 9321 94'));
        $bic = trim((string) ($pay['bic'] ?? 'TRWIGB2LXXX'));
        $pay['iban'] = $iban;
        $pay['bic'] = $bic;
        $pay['eur_iban'] = trim((string) ($pay['eur_iban'] ?? '')) ?: $iban;
        $pay['eur_bic'] = trim((string) ($pay['eur_bic'] ?? '')) ?: $bic;
        $pay['registration_no'] = self::beneficiaryRegistrationNo($pay);

        return $pay;
    }

    /**
     * Teqno LTD is 17392540. 16607074 is Topurlz Ltd — never show that as Teqno's number.
     *
     * @param  array<string, mixed>|null  $pay
     */
    public static function beneficiaryRegistrationNo(?array $pay = null): string
    {
        $pay ??= (array) config('billing.deposit_payment', []);
        $beneficiary = trim((string) ($pay['beneficiary'] ?? 'Teqno LTD'));
        $reg = trim((string) ($pay['registration_no'] ?? ''));

        if ($reg === self::TOPURLZ_REGISTRATION_NO && strcasecmp($beneficiary, 'Teqno LTD') === 0) {
            return self::TEQNO_REGISTRATION_NO;
        }

        return $reg !== '' ? $reg : self::TEQNO_REGISTRATION_NO;
    }

    public static function currencyGuideUrl(): string
    {
        $url = trim((string) config('billing.currency_guide_url', '/help/currencies'));
        if ($url === '') {
            $url = '/help/currencies';
        }
        if (! str_starts_with($url, 'http') && ! str_starts_with($url, '/')) {
            $url = '/'.$url;
        }

        return $url;
    }

    public static function currencyGuideLabel(): string
    {
        $label = trim((string) config('billing.currency_guide_label', ''));

        return $label !== '' ? $label : 'Paying in another currency? Read our currency guide';
    }

    public static function wisePayUrl(): string
    {
        return rtrim((string) (self::depositPayment()['wise_pay_url'] ?? 'https://wise.com/pay/business/teqnoltd'), '?&');
    }

    public static function wisePayLink(float $amount): string
    {
        $amount = max(0, round($amount, 2));

        return self::wisePayUrl().'?amount='.$amount.'&currency=EUR';
    }

    public static function depositMin(): float
    {
        $min = (float) config('billing.deposit_min', 10);

        return $min > 0 ? $min : 10.0;
    }

    public static function depositMax(): float
    {
        $min = self::depositMin();
        $max = (float) config('billing.deposit_max', 50000);
        if ($max < $min) {
            $max = 50000.0;
        }

        return $max;
    }

    public static function amountRule(): string
    {
        return 'required|numeric|min:'.self::depositMin().'|max:'.self::depositMax();
    }

    public static function cryptoEnabled(): bool
    {
        $crypto = (array) (self::depositPayment()['crypto'] ?? []);

        return ! empty($crypto['enabled']) && self::cryptoNetworks() !== [];
    }

    public static function cryptoNote(): string
    {
        $crypto = (array) (self::depositPayment()['crypto'] ?? []);

        return (string) ($crypto['note'] ?? '');
    }

    /**
     * @return list<array{key: string, label: string, address: string}>
     */
    public static function cryptoNetworks(): array
    {
        $crypto = (array) (self::depositPayment()['crypto'] ?? []);
        $networks = [];

        foreach ((array) ($crypto['networks'] ?? []) as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '') {
                continue;
            }
            $networks[] = [
                'key' => (string) ($row['key'] ?? md5($address)),
                'label' => (string) ($row['label'] ?? 'Crypto'),
                'address' => $address,
            ];
        }

        return $networks;
    }
}
