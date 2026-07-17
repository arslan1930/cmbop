<?php

/**
 * Marketplace targeting: Europe + English-speaking regions + Latin America + Chinese + Gulf (Arabic).
 */
$allowedLanguageCodes = [
    // EU official languages (+ English)
    'bg', // Bulgarian
    'hr', // Croatian
    'cs', // Czech
    'da', // Danish
    'nl', // Dutch
    'en', // English
    'et', // Estonian
    'fi', // Finnish
    'fr', // French
    'de', // German
    'el', // Greek
    'hu', // Hungarian
    'ga', // Irish
    'it', // Italian
    'lv', // Latvian
    'lt', // Lithuanian
    'mt', // Maltese
    'pl', // Polish
    'pt', // Portuguese (EU + Latin America)
    'ro', // Romanian
    'sk', // Slovak
    'sl', // Slovenian
    'es', // Spanish (EU + Latin America)
    'sv', // Swedish

    // EU / European regional languages
    'ca', // Catalan
    'gl', // Galician
    'eu', // Basque
    'cy', // Welsh
    'gd', // Scottish Gaelic
    'lb', // Luxembourgish
    'rm', // Romansh
    'no', // Norwegian (EEA / Europe)

    // Chinese
    'zh',

    // Arabic (Gulf region)
    'ar',
];

$europeCountryCodes = [
    'al', 'at', 'ba', 'be', 'bg', 'ch', 'cy', 'cz', 'de', 'dk', 'ee', 'es', 'fi', 'fr',
    'gr', 'hr', 'hu', 'ie', 'is', 'it', 'lt', 'lu', 'lv', 'md', 'me', 'mk', 'mt', 'nl',
    'no', 'pl', 'pt', 'ro', 'rs', 'se', 'si', 'sk', 'ua', 'uk',
];

$englishRegionCountryCodes = [
    'us', // United States
    'ca', // Canada
    'uk', // United Kingdom (also Europe)
    'ie', // Ireland (also Europe)
    'au', // Australia
    'nz', // New Zealand
    'za', // South Africa
    'sg', // Singapore (English + Chinese)
];

$latinAmericaCountryCodes = [
    'ar', // Argentina
    'bo', // Bolivia
    'br', // Brazil
    'cl', // Chile
    'co', // Colombia
    'cr', // Costa Rica
    'cu', // Cuba
    'do', // Dominican Republic
    'ec', // Ecuador
    'sv', // El Salvador
    'gt', // Guatemala
    'hn', // Honduras
    'mx', // Mexico
    'ni', // Nicaragua
    'pa', // Panama
    'py', // Paraguay
    'pe', // Peru
    'pr', // Puerto Rico
    'uy', // Uruguay
    've', // Venezuela
];

$chineseCountryCodes = [
    'cn', // China
    'tw', // Taiwan
    'hk', // Hong Kong
    'mo', // Macau
    'sg', // Singapore
];

$gulfCountryCodes = [
    'ae', // United Arab Emirates
    'sa', // Saudi Arabia
    'qa', // Qatar
    'kw', // Kuwait
    'bh', // Bahrain
    'om', // Oman
];

$allowedCountryCodes = array_values(array_unique(array_merge(
    $europeCountryCodes,
    $englishRegionCountryCodes,
    $latinAmericaCountryCodes,
    $chineseCountryCodes,
    $gulfCountryCodes
)));

return [

    'allowed_language_codes' => $allowedLanguageCodes,

    // Alias used by older migrations / scopes
    'european_language_codes' => $allowedLanguageCodes,

    'allowed_country_codes' => $allowedCountryCodes,

    'allowed_country_regions' => [
        'Europe',
        'North America',
        'Latin America',
        'East Asia',
        'Oceania',
        'Africa',
        'Middle East',
    ],

    'europe_country_codes' => $europeCountryCodes,
    'english_region_country_codes' => $englishRegionCountryCodes,
    'latin_america_country_codes' => $latinAmericaCountryCodes,
    'chinese_country_codes' => $chineseCountryCodes,
    'gulf_country_codes' => $gulfCountryCodes,

];
