<?php

namespace App\Support;

class MoneyDisplay
{
    public function __construct(
        private ViewerCountry $viewerCountry,
        private FxRate $fxRate,
    ) {}

    public function currency(): string
    {
        $code = strtoupper($this->viewerCountry->displayCurrency());

        return $code !== '' ? $code : 'EUR';
    }

    /**
     * Listing input currency from a marketplace country code (us, uk, ca, …).
     */
    public function currencyForCountry(?string $country): string
    {
        $code = strtoupper(trim((string) $country));
        if ($code === '') {
            return $this->currency();
        }
        if ($code === 'UK') {
            $code = 'GB';
        }

        $map = config('fx.country_currency', []);
        $currency = is_array($map) ? strtoupper((string) ($map[$code] ?? '')) : '';

        return $currency !== '' ? $currency : 'EUR';
    }

    public function symbolFor(?string $currency = null): string
    {
        $code = strtoupper((string) ($currency ?? $this->currency()));
        if ($code === '' || $code === 'EUR') {
            return '€';
        }

        $symbol = (string) config("fx.currencies.{$code}.symbol", '');

        return $symbol !== '' ? $symbol : '€';
    }

    /**
     * Convert a publisher-entered local amount into the EUR ledger value.
     */
    public function toEuros(mixed $local, ?string $currency = null): float
    {
        $amount = round((float) $local, 2);
        $code = strtoupper((string) ($currency ?? $this->currency()));
        if ($code === '' || $code === 'EUR' || $amount === 0.0) {
            return $amount;
        }

        $rate = $this->fxRate->perEur($code);
        if ($rate <= 0) {
            return $amount;
        }

        return round($amount / $rate, 2);
    }

    /**
     * Show a stored EUR listing amount in a country / display currency.
     */
    public function fromEuros(mixed $euros, ?string $currency = null): float
    {
        $eur = round((float) $euros, 2);
        $code = strtoupper((string) ($currency ?? $this->currency()));
        if ($code === '' || $code === 'EUR') {
            return $eur;
        }

        return round($eur * $this->fxRate->perEur($code), 2);
    }

    /**
     * @return array<string, string> uppercase country code → symbol
     */
    public function countrySymbols(): array
    {
        $out = [];
        foreach (config('fx.country_currency', []) as $country => $currency) {
            $out[strtoupper((string) $country)] = $this->symbolFor((string) $currency);
        }

        return $out;
    }

    public function displaysUsd(): bool
    {
        return $this->currency() === 'USD';
    }

    public function usdPerEur(): float
    {
        return $this->fxRate->usdPerEur();
    }

    public function rate(): float
    {
        return $this->fxRate->perEur($this->currency());
    }

    public function amount(mixed $euros): float
    {
        $eur = round((float) $euros, 2);
        if ($this->currency() === 'EUR') {
            return $eur;
        }

        return round($eur * $this->rate(), 2);
    }

    public function symbol(): string
    {
        return $this->symbolFor($this->currency());
    }

    /**
     * @param  array{signed?: bool, decimals?: int}  $options
     */
    public function format(mixed $euros, array $options = []): string
    {
        $value = $this->amount($euros);
        $signed = ! empty($options['signed']);
        $prefix = $signed && $value > 0 ? '+' : '';
        $decimals = array_key_exists('decimals', $options) ? max(0, (int) $options['decimals']) : 2;

        return $prefix.$this->symbol().number_format(abs($value), $decimals);
    }

    /**
     * Pay / cart label: euros first, locked local estimate when not EUR.
     *
     * @param  array{signed?: bool, rate?: float}  $options
     */
    public function formatPay(mixed $euros, array $options = []): string
    {
        $eur = round((float) $euros, 2);
        $signed = ! empty($options['signed']);
        $prefix = $signed && $eur > 0 ? '+' : '';
        $eurLabel = '€'.number_format(abs($eur), 2);
        if ($this->currency() === 'EUR') {
            return $prefix.$eurLabel;
        }

        $rate = isset($options['rate']) ? (float) $options['rate'] : null;
        $rate ??= app(CartDisplayFx::class)->rate();
        $rate ??= $this->rate();
        if ($rate <= 0) {
            $rate = 1.0;
        }

        $local = round(abs($eur) * $rate, 2);

        return $prefix.$eurLabel.' · about '.$this->symbol().number_format($local, 2);
    }

    /**
     * @return array{currency: string, usd: bool, rate: float, cart_rate: float, symbol: string, guide: bool}
     */
    public function jsPayload(): array
    {
        $live = $this->rate();
        $cartRate = app(CartDisplayFx::class)->rate() ?? $live;

        return [
            'currency' => $this->currency(),
            'usd' => $this->displaysUsd(),
            'rate' => $live,
            'cart_rate' => $cartRate,
            'symbol' => $this->symbol(),
            'guide' => $this->currency() !== 'EUR',
        ];
    }
}
