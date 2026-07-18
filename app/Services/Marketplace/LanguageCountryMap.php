<?php

namespace App\Services\Marketplace;

use App\Models\Country;
use App\Models\Language;

/**
 * Language → marketplace countries map (same rules as publisher site listing).
 * English expands to English regions + Chinese + Gulf + pivot EN countries.
 */
class LanguageCountryMap
{
    /**
     * @return array<string, list<array{code: string, name: string}>>
     */
    public function map(): array
    {
        $languages = Language::marketplace()
            ->with(['countries' => fn ($q) => $q->marketplace()->select('countries.id', 'countries.code', 'countries.name')])
            ->orderBy('name')
            ->get();

        $map = [];
        foreach ($languages as $language) {
            $code = strtolower((string) $language->code);
            $map[$code] = $language->countries
                ->map(fn ($c) => [
                    'code' => strtolower((string) $c->code),
                    'name' => $c->name,
                ])
                ->values()
                ->all();
        }

        $map['en'] = $this->englishMarketplaceCountries();

        return $map;
    }

    /**
     * @return list<string>
     */
    public function countryCodesForLanguage(string $language): array
    {
        $language = strtolower(trim($language));
        if ($language === '') {
            return [];
        }

        return collect($this->map()[$language] ?? [])
            ->pluck('code')
            ->map(fn ($c) => strtolower((string) $c))
            ->unique()
            ->values()
            ->all();
    }

    public function languageAcceptsCountry(string $language, string $country): bool
    {
        $language = strtolower(trim($language));
        $country = strtolower(trim($country));
        if ($language === '' || $country === '') {
            return false;
        }

        $codes = $this->countryCodesForLanguage($language);
        if ($codes === []) {
            // Unknown language pairing — fall back to marketplace country allow-list.
            $allowed = array_map('strtolower', config('markets.allowed_country_codes', []));

            return $allowed === [] || in_array($country, $allowed, true);
        }

        return in_array($country, $codes, true);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function englishMarketplaceCountries(): array
    {
        $codes = array_values(array_unique(array_merge(
            config('markets.english_region_country_codes', []),
            config('markets.chinese_country_codes', []),
            config('markets.gulf_country_codes', []),
            Language::query()->where('code', 'en')->first()
                ?->countries()
                ->marketplace()
                ->pluck('code')
                ->all() ?? []
        )));

        return Country::marketplace()
            ->whereIn('code', $codes)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($c) => [
                'code' => strtolower((string) $c->code),
                'name' => $c->name,
            ])
            ->values()
            ->all();
    }
}
