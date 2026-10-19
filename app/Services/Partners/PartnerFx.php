<?php

namespace App\Services\Partners;

use App\Models\PartnerFxRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ECB daily reference rates for partner catalog amounts (CZK / PLN → EUR).
 * Isolated from advertiser display FX so CZK ~25 is not rejected by that clamp.
 */
class PartnerFx
{
    /**
     * @return array{amount: float, rate: float, date: string}
     */
    public function toEuros(float $amount, string $currency, ?string $date = null): array
    {
        $code = strtoupper(trim($currency));
        $day = $date ?: now()->toDateString();
        if ($code === 'EUR' || $code === '') {
            return ['amount' => round($amount, 2), 'rate' => 1.0, 'date' => $day];
        }

        $rate = $this->ratePerEur($code, $day);
        $euros = $rate > 0 ? round($amount / $rate, 2) : 0.0;

        return ['amount' => $euros, 'rate' => $rate, 'date' => $day];
    }

    public function ratePerEur(string $currency, ?string $date = null): float
    {
        $code = strtoupper(trim($currency));
        if ($code === 'EUR') {
            return 1.0;
        }

        $day = $date ?: now()->toDateString();
        $rates = $this->ratesForDate($day);

        return $this->usable($rates[$code] ?? null) ?? $this->fallback($code);
    }

    /**
     * @return array<string, float>
     */
    public function ratesForDate(string $date): array
    {
        $key = 'partner.fx.'.$date;

        try {
            $cached = Cache::remember($key, 86400, fn () => $this->loadRates($date));
        } catch (\Throwable $e) {
            Log::notice('partner.fx.cache_failed', ['message' => $e->getMessage()]);
            $cached = $this->loadRates($date);
        }

        return is_array($cached) ? $cached : [];
    }

    public function refresh(?string $date = null): array
    {
        $day = $date ?: now()->toDateString();
        $rates = $this->fetchLiveRates();
        if ($rates !== []) {
            $this->persist($day, $rates, $this->lastSource);
        }

        try {
            Cache::put('partner.fx.'.$day, $rates, 86400);
        } catch (\Throwable) {
            //
        }

        return $rates;
    }

    private string $lastSource = 'ECB';

    /**
     * @return array<string, float>
     */
    private function loadRates(string $date): array
    {
        if (Schema::hasTable('partner_fx_rates')) {
            $stored = PartnerFxRate::query()
                ->whereDate('rate_date', $date)
                ->pluck('rate', 'quote')
                ->all();
            $clean = [];
            foreach ($stored as $quote => $rate) {
                $usable = $this->usable($rate);
                if ($usable !== null) {
                    $clean[strtoupper((string) $quote)] = $usable;
                }
            }
            if ($clean !== []) {
                return $clean;
            }
        }

        $live = $this->fetchLiveRates();
        if ($live !== []) {
            $this->persist($date, $live, $this->lastSource);
        }

        return $live;
    }

    /**
     * @return array<string, float>
     */
    private function fetchLiveRates(): array
    {
        $ecb = $this->fromEcb();
        if ($ecb !== []) {
            $this->lastSource = 'ECB';

            return $ecb;
        }

        $ff = $this->fromFrankfurter();
        if ($ff !== []) {
            $this->lastSource = 'Frankfurter';

            return $ff;
        }

        return [];
    }

    /**
     * @return array<string, float>
     */
    private function fromEcb(): array
    {
        $url = (string) config('partners.fx_ecb_url', '');
        if ($url === '') {
            return [];
        }

        try {
            $xml = Http::timeout(4)->get($url)->throw()->body();
            if (preg_match_all("/currency=['\"]([A-Z]{3})['\"]\\s+rate=['\"]([0-9.]+)['\"]/", $xml, $m, PREG_SET_ORDER) < 1) {
                return [];
            }
            $wanted = array_map('strtoupper', (array) config('partners.currencies', ['CZK', 'PLN']));
            $out = [];
            foreach ($m as $row) {
                $code = strtoupper($row[1]);
                if (! in_array($code, $wanted, true) || $code === 'EUR') {
                    continue;
                }
                $usable = $this->usable($row[2]);
                if ($usable !== null) {
                    $out[$code] = $usable;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::notice('partner.fx.ecb_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, float>
     */
    private function fromFrankfurter(): array
    {
        $url = (string) config('partners.fx_frankfurter_url', '');
        if ($url === '') {
            return [];
        }

        try {
            $json = Http::timeout(4)->acceptJson()->get($url)->throw()->json();
            $raw = $json['rates'] ?? null;
            if (! is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $code => $rate) {
                $usable = $this->usable($rate);
                if ($usable !== null) {
                    $out[strtoupper((string) $code)] = $usable;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::notice('partner.fx.frankfurter_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<string, float>  $rates
     */
    private function persist(string $date, array $rates, string $source): void
    {
        if (! Schema::hasTable('partner_fx_rates')) {
            return;
        }

        foreach ($rates as $quote => $rate) {
            PartnerFxRate::query()->updateOrCreate(
                ['rate_date' => $date, 'quote' => strtoupper((string) $quote)],
                ['rate' => $rate, 'source' => $source]
            );
        }
    }

    private function usable(mixed $rate): ?float
    {
        if (! is_numeric($rate)) {
            return null;
        }
        $value = (float) $rate;
        if ($value <= 0 || $value > 1000) {
            return null;
        }

        return $value;
    }

    private function fallback(string $currency): float
    {
        $configured = config('partners.fx_fallbacks.'.strtoupper($currency));

        return $this->usable($configured) ?? 1.0;
    }
}
