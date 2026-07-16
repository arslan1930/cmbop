<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;

if (!function_exists('get_language_switcher_url')) {
    function get_language_switcher_url($locale)
    {
        $availableLocales = ['de', 'fr', 'nl'];
        
        // Get current URL segments
        $segments = Request::segments();
        
        // Remove current locale from path if present
        if (!empty($segments) && in_array($segments[0], $availableLocales)) {
            array_shift($segments);
        }
        
        $pathWithoutLocale = implode('/', $segments);
        
        // Build URL based on target locale
        if ($locale === 'en') {
            return $pathWithoutLocale ? url($pathWithoutLocale) : url('/');
        } else {
            return $pathWithoutLocale ? url($locale . '/' . $pathWithoutLocale) : url($locale);
        }
    }
}

if (!function_exists('localized_url')) {
    function localized_url($path = '', $locale = null)
    {
        $locale = $locale ?? App::getLocale();
        $path = ltrim($path, '/');
        
        if ($locale === 'en') {
            return $path ? url($path) : url('/');
        }
        
        return $path ? url($locale . '/' . $path) : url($locale);
    }
}

if (!function_exists('get_available_locales')) {
    function get_available_locales()
    {
        return [
            'en' => ['name' => 'English', 'flag' => '🇬🇧', 'code' => 'en'],
            'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪', 'code' => 'de'],
            'fr' => ['name' => 'Français', 'flag' => '🇫🇷', 'code' => 'fr'],
            'nl' => ['name' => 'Nederlands', 'flag' => '🇳🇱', 'code' => 'nl'],
        ];
    }
}

if (!function_exists('marketplace_languages')) {
    /**
     * Display map for marketplace language codes only.
     */
    function marketplace_languages(): array
    {
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'zh' => 'Chinese',
            'ar' => 'Arabic',
            'pl' => 'Polish',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'el' => 'Greek',
            'cs' => 'Czech',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'hr' => 'Croatian',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'lt' => 'Lithuanian',
            'lv' => 'Latvian',
            'et' => 'Estonian',
            'ca' => 'Catalan',
            'gl' => 'Galician',
            'eu' => 'Basque',
            'cy' => 'Welsh',
            'gd' => 'Scottish Gaelic',
            'ga' => 'Irish',
            'lb' => 'Luxembourgish',
            'rm' => 'Romansh',
            'mt' => 'Maltese',
        ];
    }
}

if (!function_exists('fullLanguage')) {
    function fullLanguage($code)
    {
        $languages = marketplace_languages();
        $key = strtolower((string) $code);

        return $languages[$key] ?? strtoupper((string) $code);
    }
}

if (!function_exists('marketplace_countries')) {
    /**
     * Display map for marketplace country codes only.
     */
    function marketplace_countries(): array
    {
        return [
            // Europe
            'al' => 'Albania',
            'at' => 'Austria',
            'ba' => 'Bosnia and Herzegovina',
            'be' => 'Belgium',
            'bg' => 'Bulgaria',
            'ch' => 'Switzerland',
            'cy' => 'Cyprus',
            'cz' => 'Czech Republic',
            'de' => 'Germany',
            'dk' => 'Denmark',
            'ee' => 'Estonia',
            'es' => 'Spain',
            'fi' => 'Finland',
            'fr' => 'France',
            'gr' => 'Greece',
            'hr' => 'Croatia',
            'hu' => 'Hungary',
            'ie' => 'Ireland',
            'is' => 'Iceland',
            'it' => 'Italy',
            'lt' => 'Lithuania',
            'lu' => 'Luxembourg',
            'lv' => 'Latvia',
            'md' => 'Moldova',
            'me' => 'Montenegro',
            'mk' => 'North Macedonia',
            'mt' => 'Malta',
            'nl' => 'Netherlands',
            'no' => 'Norway',
            'pl' => 'Poland',
            'pt' => 'Portugal',
            'ro' => 'Romania',
            'rs' => 'Serbia',
            'se' => 'Sweden',
            'si' => 'Slovenia',
            'sk' => 'Slovakia',
            'ua' => 'Ukraine',
            'uk' => 'United Kingdom',
            // English regions
            'us' => 'United States',
            'ca' => 'Canada',
            'au' => 'Australia',
            'nz' => 'New Zealand',
            'za' => 'South Africa',
            'sg' => 'Singapore',
            // Latin America
            'ar' => 'Argentina',
            'bo' => 'Bolivia',
            'br' => 'Brazil',
            'cl' => 'Chile',
            'co' => 'Colombia',
            'cr' => 'Costa Rica',
            'cu' => 'Cuba',
            'do' => 'Dominican Republic',
            'ec' => 'Ecuador',
            'sv' => 'El Salvador',
            'gt' => 'Guatemala',
            'hn' => 'Honduras',
            'mx' => 'Mexico',
            'ni' => 'Nicaragua',
            'pa' => 'Panama',
            'py' => 'Paraguay',
            'pe' => 'Peru',
            'pr' => 'Puerto Rico',
            'uy' => 'Uruguay',
            've' => 'Venezuela',
            // Chinese markets
            'cn' => 'China',
            'tw' => 'Taiwan',
            'hk' => 'Hong Kong',
            'mo' => 'Macau',
            // Gulf region
            'ae' => 'United Arab Emirates',
            'sa' => 'Saudi Arabia',
            'qa' => 'Qatar',
            'kw' => 'Kuwait',
            'bh' => 'Bahrain',
            'om' => 'Oman',
        ];
    }
}

if (!function_exists('fullCountry')) {
    function fullCountry($code)
    {
        $countries = marketplace_countries();
        $key = strtolower((string) $code);

        return $countries[$key] ?? strtoupper((string) $code);
    }
}

if (!function_exists('getCountryFlag')) {
    /**
     * Convert ISO country code to emoji flag (uk → gb).
     */
    function getCountryFlag($countryCode)
    {
        $code = strtolower(trim((string) $countryCode));
        if ($code === '' || $code === 'xx') {
            return '';
        }
        if ($code === 'uk') {
            $code = 'gb';
        }
        $code = strtoupper($code);
        if (strlen($code) !== 2) {
            return '';
        }

        return mb_convert_encoding('&#'.(127397 + ord($code[0])).';', 'UTF-8', 'HTML-ENTITIES')
            .mb_convert_encoding('&#'.(127397 + ord($code[1])).';', 'UTF-8', 'HTML-ENTITIES');
    }
}
