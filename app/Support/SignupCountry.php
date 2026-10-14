<?php

namespace App\Support;

use App\Models\Country;
use App\Models\User;
use Database\Seeders\CountriesTableSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Registration country list + GB/US → display currency.
 */
class SignupCountry
{
    public const GOOGLE_SESSION = 'google_signup';

    /**
     * @return array{
     *     signupCountries: list<array{code: string, name: string}>,
     *     signupCountry: ?string,
     *     signupCurrencyPreview: array{currency: string, symbol: string, label: string}
     * }
     */
    public static function formPayload(?string $geoCode = null): array
    {
        $prefill = self::geoPrefill($geoCode ?? app(ViewerCountry::class)->code());

        return [
            'signupCountries' => self::options(),
            'signupCountry' => $prefill,
            'signupCurrencyPreview' => self::currencyPreview($prefill),
        ];
    }

    public static function applyToUser(User $user, ?string $country): void
    {
        $code = self::normalize($country);
        if ($code === null) {
            return;
        }

        $user->country = $code;
        try {
            if (Schema::hasColumn('users', 'display_currency')) {
                $user->display_currency = self::displayCurrency($code);
            }
        } catch (\Throwable) {
        }
        $user->save();
    }

    /**
     * @return array<string, mixed>
     */
    public static function countryRules(): array
    {
        return ['required', 'string', Rule::in(self::codes())];
    }

    /**
     * Accept GB as UK before validation.
     */
    public static function incoming(?string $country): ?string
    {
        return self::normalize($country) ?? (is_string($country) ? strtolower(trim($country)) : null);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function options(): array
    {
        try {
            if (Schema::hasTable('countries')) {
                $rows = Country::query()->orderBy('name')->get(['code', 'name']);
                if ($rows->isNotEmpty()) {
                    return $rows->map(fn (Country $c) => [
                        'code' => strtolower((string) $c->code),
                        'name' => (string) $c->name,
                    ])->values()->all();
                }
            }
        } catch (\Throwable) {
        }

        $out = [];
        foreach (CountriesTableSeeder::definitions() as $row) {
            $out[] = [
                'code' => strtolower((string) $row['code']),
                'name' => (string) $row['name'],
            ];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_values(array_unique(array_map(
            fn (array $row) => $row['code'],
            self::options()
        )));
    }

    public static function normalize(?string $country): ?string
    {
        $code = strtolower(trim((string) $country));
        if ($code === 'gb') {
            $code = 'uk';
        }
        if ($code === '' || ! in_array($code, self::codes(), true)) {
            return null;
        }

        return $code;
    }

    public static function displayCurrency(?string $country): string
    {
        return app(ListingMoney::class)->displayCurrencyForCountry($country);
    }

    public static function geoPrefill(?string $geoCode): ?string
    {
        $code = strtolower(trim((string) $geoCode));
        if ($code === 'gb') {
            $code = 'uk';
        }

        return self::normalize($code);
    }

    /**
     * @return array{currency: string, symbol: string, label: string}
     */
    public static function currencyPreview(?string $country): array
    {
        $currency = self::displayCurrency($country);
        $symbol = app(MoneyDisplay::class)->symbolFor($currency);

        return [
            'currency' => $currency,
            'symbol' => $symbol,
            'label' => 'Your account currency: '.$symbol.' '.$currency.', you can change it anytime',
        ];
    }
}
