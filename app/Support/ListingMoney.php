<?php

namespace App\Support;

/**
 * Publisher listing amounts (EUR or GBP) and the advertiser EUR ledger.
 * Buffer applies only when listing currency differs from the ledger (EUR).
 */
class ListingMoney
{
    public const LEDGER = 'EUR';

    /** @var list<string> */
    public const LISTING_CURRENCIES = ['EUR', 'GBP'];

    /** @var list<string> */
    public const WALLET_CURRENCIES = ['EUR', 'GBP', 'USD'];

    public function __construct(
        private MoneyDisplay $money,
        private FxRate $fx,
    ) {}

    public function bufferPercent(): float
    {
        $value = (float) config('fx.conversion_buffer_percent', 0.5);

        return max(0, $value);
    }

    public function appliesBuffer(?string $listingCurrency, ?string $ledgerCurrency = self::LEDGER): bool
    {
        $listing = $this->normalizeListing($listingCurrency);
        $ledger = $this->normalizeWallet($ledgerCurrency);

        return $listing !== $ledger;
    }

    /**
     * Convert a listing amount into EUR. Buffer is optional and only for
     * cross-currency lines (catalog / advertiser price).
     */
    public function toEuros(mixed $local, ?string $currency, bool $withBuffer = false): float
    {
        $amount = round((float) $local, 2);
        $code = $this->normalizeListing($currency);
        $euros = $this->money->toEuros($amount, $code);
        if ($withBuffer && $this->appliesBuffer($code)) {
            $euros = round($euros * (1 + $this->bufferPercent() / 100), 2);
        }

        return $euros;
    }

    public function fromEuros(mixed $euros, ?string $currency): float
    {
        return $this->money->fromEuros($euros, $this->normalizeListing($currency));
    }

    /**
     * Units of $currency per €1. Null when the currencies match.
     */
    public function ratePerEur(?string $currency): ?float
    {
        $code = $this->normalizeListing($currency);
        if ($code === self::LEDGER) {
            return null;
        }
        $rate = $this->fx->perEur($code);

        return $rate > 0 ? $rate : null;
    }

    public function normalizeListing(?string $currency): string
    {
        $code = strtoupper(trim((string) $currency));

        return in_array($code, self::LISTING_CURRENCIES, true) ? $code : self::LEDGER;
    }

    public function normalizeWallet(?string $currency): string
    {
        $code = strtoupper(trim((string) $currency));

        return in_array($code, self::WALLET_CURRENCIES, true) ? $code : self::LEDGER;
    }

    /**
     * Signup / account-country → saved display currency.
     * GB → GBP, US → USD, everything else → EUR.
     */
    public function displayCurrencyForCountry(?string $country): string
    {
        $code = strtoupper(trim((string) $country));
        if ($code === 'UK' || $code === 'UNITED KINGDOM') {
            $code = 'GB';
        }
        if (in_array($code, ['USA', 'UNITED STATES'], true)) {
            $code = 'US';
        }

        return match ($code) {
            'GB' => 'GBP',
            'US' => 'USD',
            default => self::LEDGER,
        };
    }

    public function countrySuggestsGbp(?string $country): bool
    {
        return $this->money->currencyForCountry($country) === 'GBP';
    }

    /**
     * Format an amount that is already in $currency. Does not convert.
     *
     * @param  array{signed?: bool, decimals?: int}  $options
     */
    public function formatAmount(mixed $amount, ?string $currency, array $options = []): string
    {
        $value = round((float) $amount, 2);
        $code = $this->normalizeWallet($currency);
        $decimals = array_key_exists('decimals', $options) ? max(0, (int) $options['decimals']) : 2;
        $prefix = '';
        if ($value < 0) {
            $prefix = MoneyDisplay::MINUS;
        } elseif (! empty($options['signed']) && $value > 0) {
            $prefix = '+';
        }

        return $prefix.$this->money->symbolFor($code).number_format(abs($value), $decimals);
    }

    public function minWithdrawal(?string $currency): float
    {
        $code = $this->normalizeWallet($currency);
        $per = config('billing.withdrawal_min_amounts', []);
        if (is_array($per) && isset($per[$code]) && is_numeric($per[$code])) {
            return max(0.01, round((float) $per[$code], 2));
        }

        return max(0.01, round((float) config('billing.withdrawal_min_amount', 20), 2));
    }

    /**
     * Cap a publisher credit so it cannot exceed what the advertiser paid,
     * compared in the publisher's currency.
     */
    public function capPublisherAmount(
        float $publisherAmount,
        float $advertiserEur,
        ?string $publisherCurrency,
        ?float $fxRate,
    ): float {
        $amount = round(max(0, $publisherAmount), 2);
        $paid = round(max(0, $advertiserEur), 2);
        $code = $this->normalizeWallet($publisherCurrency);
        if ($code === self::LEDGER || $fxRate === null || $fxRate <= 0) {
            return $amount > $paid ? $paid : $amount;
        }

        $cap = round($paid * $fxRate, 2);

        return $amount > $cap ? $cap : $amount;
    }

    /**
     * @param  array<string, mixed>|object|string|null  $map
     * @return array<string, float>
     */
    public function mapToEuros(mixed $map, ?string $currency, bool $withBuffer = false): array
    {
        $values = $this->normalizeMap($map);
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $this->toEuros($value, $currency, $withBuffer);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|object|string|null  $map
     * @return array<string, float>
     */
    public function normalizeMap(mixed $map): array
    {
        if (is_string($map)) {
            $map = json_decode($map, true);
        }
        if (is_object($map)) {
            $map = (array) $map;
        }
        if (! is_array($map) || $map === []) {
            return [];
        }

        $out = [];
        foreach ($map as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $out[(string) $key] = round((float) $value, 2);
        }

        return $out;
    }
}
