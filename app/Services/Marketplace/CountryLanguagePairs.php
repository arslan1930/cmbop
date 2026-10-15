<?php

namespace App\Services\Marketplace;

use App\Models\Language;
use Illuminate\Support\Facades\Cache;

/**
 * Country-first marketplace language pairs.
 *
 * Publishers (and paired filters) pick country first, then a language from
 * the allowed list for that country (e.g. de → de only; ae → ar, en).
 */
class CountryLanguagePairs
{
    /**
     * @return array<string, list<string>>
     */
    public function codeMap(): array
    {
        $raw = (array) config('markets.allowed_languages_by_country', []);
        $map = [];
        foreach ($raw as $country => $languages) {
            $countryCode = strtolower(trim((string) $country));
            if ($countryCode === '') {
                continue;
            }
            $map[$countryCode] = array_values(array_unique(array_filter(array_map(
                static fn ($l) => strtolower(trim((string) $l)),
                (array) $languages
            ))));
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function languageCodesForCountry(string $country): array
    {
        $country = strtolower(trim($country));
        if ($country === '') {
            return [];
        }

        return $this->codeMap()[$country] ?? [];
    }

    /**
     * @param  list<string>|string  $countries
     * @return list<string>
     */
    public function languageCodesForCountries(array|string $countries): array
    {
        $codes = is_array($countries) ? $countries : [$countries];
        $out = [];
        foreach ($codes as $country) {
            foreach ($this->languageCodesForCountry((string) $country) as $lang) {
                $out[] = $lang;
            }
        }

        return array_values(array_unique($out));
    }

    public function isAllowedPair(?string $country, ?string $language): bool
    {
        $country = strtolower(trim((string) $country));
        $language = strtolower(trim((string) $language));
        if ($country === '' || $language === '') {
            return false;
        }

        $allowed = $this->languageCodesForCountry($country);
        if ($allowed === []) {
            // Unknown country mapping — fall back to marketplace language allow-list.
            $marketplace = array_map('strtolower', config('markets.allowed_language_codes', []));

            return $marketplace === [] || in_array($language, $marketplace, true);
        }

        return in_array($language, $allowed, true);
    }

    /**
     * Country code → [{code, name}, …] for JS selects.
     *
     * @return array<string, list<array{code: string, name: string}>>
     */
    public function mapWithNames(): array
    {
        try {
            /** @var array<string, list<array{code: string, name: string}>> $cached */
            $cached = Cache::remember(
                'catalog.country_language_map_v1',
                300,
                fn () => $this->computeMapWithNames()
            );

            return $cached;
        } catch (\Throwable) {
            return $this->computeMapWithNames();
        }
    }

    /**
     * @return array<string, list<array{code: string, name: string}>>
     */
    private function computeMapWithNames(): array
    {
        $names = Language::query()
            ->get(['code', 'name'])
            ->mapWithKeys(fn ($l) => [strtolower((string) $l->code) => (string) $l->name])
            ->all();

        $out = [];
        foreach ($this->codeMap() as $country => $languages) {
            $rows = [];
            foreach ($languages as $code) {
                $rows[] = [
                    'code' => $code,
                    'name' => $names[$code] ?? strtoupper($code),
                ];
            }
            $out[$country] = $rows;
        }

        return $out;
    }

    /**
     * Default / primary language for a country (first in the pair list).
     */
    public function primaryLanguageForCountry(string $country): ?string
    {
        $codes = $this->languageCodesForCountry($country);

        return $codes[0] ?? null;
    }
}
