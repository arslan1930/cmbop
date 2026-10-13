<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FxRate
{
    public function usdPerEur(): float
    {
        return $this->perEur('USD');
    }

    public function perEur(string $currency): float
    {
        $code = strtoupper($currency);
        if ($code === 'EUR') {
            return 1.0;
        }

        $rates = $this->ratesPerEur();

        return $this->usableRate($rates[$code] ?? null) ?? $this->fallbackRate($code);
    }

    /**
     * @return array<string, float>
     */
    public function ratesPerEur(): array
    {
        $ttl = max(60, (int) config('fx.cache_seconds', 3600));
        $key = 'fx.rates_per_eur.'.now()->toDateString();

        try {
            $rates = Cache::remember($key, $ttl, function () {
                return $this->fetchLiveRates();
            });
        } catch (\Throwable $e) {
            Log::warning('fx.cache_failed', ['message' => $e->getMessage()]);
            $rates = $this->fetchLiveRates();
        }

        return is_array($rates) ? $rates : [];
    }

    /**
     * @return array<string, float>
     */
    private function fetchLiveRates(): array
    {
        return $this->fromFrankfurter() ?? $this->fromEcb() ?? [];
    }

    /**
     * @return array<string, float>|null
     */
    private function fromFrankfurter(): ?array
    {
        $url = (string) config('fx.frankfurter_url', '');
        if ($url === '') {
            return null;
        }

        try {
            $json = Http::timeout(2.5)->acceptJson()->get($url)->throw()->json();
            $raw = $json['rates'] ?? null;
            if (! is_array($raw)) {
                return null;
            }

            return $this->sanitizeRateMap($raw);
        } catch (\Throwable $e) {
            Log::notice('fx.frankfurter_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, float>|null
     */
    private function fromEcb(): ?array
    {
        $url = (string) config('fx.ecb_url', '');
        if ($url === '') {
            return null;
        }

        try {
            $xml = Http::timeout(2.5)->get($url)->throw()->body();
            $codes = implode('|', array_keys($this->supportedCurrencies()));
            if (preg_match_all("/currency=['\"]({$codes})['\"]\\s+rate=['\"]([0-9.]+)['\"]/", $xml, $m, PREG_SET_ORDER) < 1) {
                return null;
            }

            $raw = [];
            foreach ($m as $row) {
                $raw[$row[1]] = $row[2];
            }

            return $this->sanitizeRateMap($raw);
        } catch (\Throwable $e) {
            Log::notice('fx.ecb_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, float>
     */
    private function sanitizeRateMap(array $raw): array
    {
        $out = [];
        foreach ($this->supportedCurrencies() as $code => $_meta) {
            $usable = $this->usableRate($raw[$code] ?? null);
            if ($usable !== null) {
                $out[$code] = $usable;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{symbol: string, fallback: float}>
     */
    private function supportedCurrencies(): array
    {
        $configured = config('fx.currencies', []);

        return is_array($configured) ? $configured : [];
    }

    private function usableRate(mixed $rate): ?float
    {
        if (! is_numeric($rate)) {
            return null;
        }

        $value = (float) $rate;
        if ($value < 0.4 || $value > 3.5) {
            return null;
        }

        return $value;
    }

    private function fallbackRate(string $currency): float
    {
        $fallback = config("fx.currencies.{$currency}.fallback");

        return $this->usableRate($fallback) ?? 1.0;
    }
}
