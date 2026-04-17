<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;
use App\Models\Language;

class CountryLanguageSeeder extends Seeder
{
    public function run()
    {
        $mappings = [
            'at' => ['de'], 'by' => ['ru', 'be'], 'be' => ['nl', 'fr', 'de'],
            'bg' => ['bg'], 'hr' => ['hr'], 'cy' => ['el', 'tr'], 'cz' => ['cs'],
            'dk' => ['da'], 'fi' => ['fi', 'sv'], 'fr' => ['fr'], 'de' => ['de'],
            'gr' => ['el'], 'hu' => ['hu'], 'ie' => ['en', 'ga'], 'it' => ['it'],
            'lv' => ['lv'], 'lt' => ['lt'], 'lu' => ['lb', 'fr', 'de'], 'nl' => ['nl'],
            'no' => ['no'], 'pl' => ['pl'], 'pt' => ['pt'], 'ro' => ['ro'],
            'ru' => ['ru'], 'sk' => ['sk'], 'si' => ['sl'], 'es' => ['es', 'ca', 'gl', 'eu'],
            'se' => ['sv'], 'ch' => ['de', 'fr', 'it', 'rm'], 'ua' => ['uk', 'ru'],
            'uk' => ['en', 'cy', 'gd'], 'us' => ['en', 'es'], 'br' => ['pt'],
            'mx' => ['es'], 'ar' => ['es'], 'cn' => ['zh'], 'jp' => ['ja'],
            'kr' => ['ko'], 'sg' => ['en', 'zh', 'ms', 'ta'], 'ae' => ['ar', 'en'],
            'sa' => ['ar'], 'eg' => ['ar'], 'il' => ['he', 'ar'],
        ];
        
        foreach ($mappings as $countryCode => $languageCodes) {
            $country = Country::where('code', $countryCode)->first();
            if ($country) {
                foreach ($languageCodes as $index => $langCode) {
                    $language = Language::where('code', $langCode)->first();
                    if ($language) {
                        $country->languages()->attach($language->id, ['is_primary' => $index === 0]);
                    }
                }
            }
        }
    }
}