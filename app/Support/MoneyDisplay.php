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
     * Unicode minus so negatives read −€40.00, never €-40.00.
     */
    public const MINUS = "\u{2212}";

    /**
     * One display currency. No secondary ≈ / about text.
     *
     * @param  array{signed?: bool, decimals?: int}  $options
     */
    public function format(mixed $euros, array $options = []): string
    {
        return $this->formatLocal($euros, $options);
    }

    /**
     * Local amount only — no secondary ≈ € text.
     *
     * @param  array{signed?: bool, decimals?: int}  $options
     */
    public function formatLocal(mixed $euros, array $options = []): string
    {
        $value = $this->amount($euros);
        $decimals = array_key_exists('decimals', $options) ? max(0, (int) $options['decimals']) : 2;
        $prefix = $this->signedPrefix($value, ! empty($options['signed']));

        return $prefix.$this->symbol().number_format(abs($value), $decimals);
    }

    public function quickAmountLabel(float $euros): string
    {
        $euros = round($euros, 2);
        if ($this->currency() === 'EUR') {
            return '€'.number_format($euros, 0);
        }

        return $this->formatLocal($euros, ['decimals' => 0]);
    }

    /**
     * @return array{label: string, under: string, mid: string, plus: string}
     */
    public function priceFilterCopy(): array
    {
        if ($this->currency() === 'EUR') {
            return [
                'label' => 'Price (€)',
                'under' => 'Under €50',
                'mid' => '€50–150',
                'plus' => '€150+',
            ];
        }

        $under = $this->formatLocal(50, ['decimals' => 0]);
        $midLocal = $this->amount(150);
        $midNum = number_format(abs($midLocal), 0);
        $plus = $this->formatLocal(150, ['decimals' => 0]);

        return [
            'label' => 'Price ('.$this->symbol().')',
            'under' => 'Under '.$under,
            'mid' => $under.'–'.$midNum,
            'plus' => $plus.'+',
        ];
    }

    /**
     * Cart / checkout label. Same single currency as format(); uses the locked cart rate.
     *
     * @param  array{signed?: bool, rate?: float, decimals?: int}  $options
     */
    public function formatPay(mixed $euros, array $options = []): string
    {
        $eur = round((float) $euros, 2);
        if ($this->currency() === 'EUR') {
            return $this->format($eur, $options);
        }

        $rate = isset($options['rate']) ? (float) $options['rate'] : null;
        $rate ??= app(CartDisplayFx::class)->rate();
        $rate ??= $this->rate();
        if ($rate <= 0) {
            $rate = 1.0;
        }

        $local = round($eur * $rate, 2);
        $decimals = array_key_exists('decimals', $options) ? max(0, (int) $options['decimals']) : 2;
        $prefix = $this->signedPrefix($local, ! empty($options['signed']));

        return $prefix.$this->symbol().number_format(abs($local), $decimals);
    }

    private function signedPrefix(float $value, bool $signed): string
    {
        if ($value < 0) {
            return self::MINUS;
        }

        return $signed && $value > 0 ? '+' : '';
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
