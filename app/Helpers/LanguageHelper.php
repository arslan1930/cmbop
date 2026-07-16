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

if (!function_exists('fullLanguage')) {
    function fullLanguage($code)
    {
        $languages = [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'ru' => 'Russian',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ar' => 'Arabic',
            'tr' => 'Turkish',
            'pl' => 'Polish',
            'uk' => 'Ukrainian',
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
            'he' => 'Hebrew',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'id' => 'Indonesian',
            'ms' => 'Malay',
            'ca' => 'Catalan',
            'gl' => 'Galician',
            'eu' => 'Basque',
            'cy' => 'Welsh',
            'gd' => 'Scottish Gaelic',
            'ga' => 'Irish',
            'lb' => 'Luxembourgish',
            'rm' => 'Romansh',
            'qu' => 'Quechua',
            'ay' => 'Aymara',
            'gn' => 'Guarani',
            'be' => 'Belarusian',
            'ku' => 'Kurdish',
            'ta' => 'Tamil',
        ];

        $key = strtolower((string) $code);

        return $languages[$key] ?? strtoupper((string) $code);
    }
}