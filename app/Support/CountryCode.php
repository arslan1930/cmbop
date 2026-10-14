<?php

namespace App\Support;

use App\Models\Country;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical ISO-3166-1 alpha-2 (marketplace uses `uk` not `gb`).
 */
class CountryCode
{
    /**
     * Extra local / English aliases that are not the countries.name column.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'españa' => 'es',
        'espana' => 'es',
        'spain' => 'es',
        'italia' => 'it',
        'italy' => 'it',
        'deutschland' => 'de',
        'germany' => 'de',
        'france' => 'fr',
        'united kingdom' => 'uk',
        'great britain' => 'uk',
        'britain' => 'uk',
        'england' => 'uk',
        'scotland' => 'uk',
        'wales' => 'uk',
        'united states' => 'us',
        'united states of america' => 'us',
        'usa' => 'us',
        'america' => 'us',
        'nederland' => 'nl',
        'netherlands' => 'nl',
        'holland' => 'nl',
        'polska' => 'pl',
        'poland' => 'pl',
        'österreich' => 'at',
        'osterreich' => 'at',
        'austria' => 'at',
        'suisse' => 'ch',
        'schweiz' => 'ch',
        'switzerland' => 'ch',
        'belgie' => 'be',
        'belgië' => 'be',
        'belgium' => 'be',
        'portugal' => 'pt',
        'ireland' => 'ie',
        'eire' => 'ie',
        'éire' => 'ie',
        'sweden' => 'se',
        'sverige' => 'se',
        'norway' => 'no',
        'norge' => 'no',
        'denmark' => 'dk',
        'danmark' => 'dk',
        'finland' => 'fi',
        'suomi' => 'fi',
        'greece' => 'gr',
        'hellas' => 'gr',
        'czech republic' => 'cz',
        'czechia' => 'cz',
        'cesko' => 'cz',
        'slovakia' => 'sk',
        'hungary' => 'hu',
        'magyarorszag' => 'hu',
        'romania' => 'ro',
        'bulgaria' => 'bg',
        'croatia' => 'hr',
        'hrvatska' => 'hr',
        'slovenia' => 'si',
        'lithuania' => 'lt',
        'latvia' => 'lv',
        'estonia' => 'ee',
        'ukraine' => 'ua',
        'canada' => 'ca',
        'australia' => 'au',
        'india' => 'in',
        'pakistan' => 'pk',
        'bangladesh' => 'bd',
        'brazil' => 'br',
        'brasil' => 'br',
        'mexico' => 'mx',
        'méxico' => 'mx',
        'argentina' => 'ar',
        'chile' => 'cl',
        'colombia' => 'co',
        'japan' => 'jp',
        'nippon' => 'jp',
        'china' => 'cn',
        'south korea' => 'kr',
        'korea' => 'kr',
        'singapore' => 'sg',
        'malaysia' => 'my',
        'indonesia' => 'id',
        'philippines' => 'ph',
        'south africa' => 'za',
        'uae' => 'ae',
        'united arab emirates' => 'ae',
        'esp' => 'es',
        'ita' => 'it',
        'deu' => 'de',
        'fra' => 'fr',
        'gbr' => 'uk',
        'usa' => 'us',
        'nld' => 'nl',
        'pol' => 'pl',
    ];

    public static function normalize(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        $folded = self::fold($value);
        if ($folded === '') {
            return null;
        }

        if (in_array($folded, ['gb', 'gbr', 'great britain', 'united kingdom', 'uk'], true)) {
            $folded = 'uk';
        }

        if (strlen($folded) === 2 && ctype_alpha($folded)) {
            return self::isKnownCode($folded) ? $folded : null;
        }

        $aliases = self::aliasMap();

        return $aliases[$folded] ?? null;
    }

    public static function displayName(?string $raw): string
    {
        $code = self::normalize($raw);
        if ($code === null) {
            $trimmed = trim((string) $raw);

            return $trimmed === '' ? '—' : $trimmed;
        }

        if (function_exists('fullCountry')) {
            $name = fullCountry($code);
            if ($name !== '' && strtoupper($name) !== strtoupper($code)) {
                return $name;
            }
        }

        $aliases = self::aliasMap();
        foreach ($aliases as $name => $mapped) {
            if ($mapped === $code && ! preg_match('/^[a-z]{2,3}$/', $name)) {
                return ucwords($name);
            }
        }

        return strtoupper($code);
    }

    public static function isKnownCode(string $code): bool
    {
        $code = strtolower(trim($code));
        if ($code === 'gb') {
            $code = 'uk';
        }

        if ($code === '') {
            return false;
        }

        if (function_exists('marketplace_countries') && isset(marketplace_countries()[$code])) {
            return true;
        }

        try {
            if (class_exists(SignupCountry::class) && in_array($code, SignupCountry::codes(), true)) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function aliasMap(): array
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }

        $map = self::ALIASES;
        if (function_exists('marketplace_countries')) {
            foreach (marketplace_countries() as $code => $name) {
                $map[self::fold((string) $name)] = strtolower((string) $code);
                $map[strtolower((string) $code)] = strtolower((string) $code);
            }
        }

        try {
            if (Schema::hasTable('countries')) {
                foreach (Country::query()->get(['code', 'name']) as $row) {
                    $code = strtolower(trim((string) $row->code));
                    if ($code === 'gb') {
                        $code = 'uk';
                    }
                    if ($code === '') {
                        continue;
                    }
                    $map[self::fold((string) $row->name)] = $code;
                    $map[$code] = $code;
                }
            }
        } catch (\Throwable) {
        }

        $map['gb'] = 'uk';
        $map['uk'] = 'uk';

        return $map;
    }

    public static function fold(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = strtolower($ascii);
        }

        $value = str_replace(['.', ',', ';', '_'], ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
