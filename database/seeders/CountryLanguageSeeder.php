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
            // Europe
            'al' => ['en'],
            'at' => ['de'],
            'ba' => ['hr', 'en'],
            'be' => ['nl', 'fr', 'de'],
            'bg' => ['bg'],
            'ch' => ['de', 'fr', 'it', 'rm'],
            'cy' => ['el', 'en'],
            'cz' => ['cs'],
            'de' => ['de'],
            'dk' => ['da'],
            'ee' => ['et'],
            'es' => ['es', 'ca', 'gl', 'eu'],
            'fi' => ['fi', 'sv'],
            'fr' => ['fr'],
            'gr' => ['el'],
            'hr' => ['hr'],
            'hu' => ['hu'],
            'ie' => ['en', 'ga'],
            'is' => ['en'],
            'it' => ['it'],
            'lt' => ['lt'],
            'lu' => ['lb', 'fr', 'de'],
            'lv' => ['lv'],
            'md' => ['ro', 'en'],
            'me' => ['en'],
            'mk' => ['en'],
            'mt' => ['mt', 'en'],
            'nl' => ['nl'],
            'no' => ['no'],
            'pl' => ['pl'],
            'pt' => ['pt'],
            'ro' => ['ro'],
            'rs' => ['en'],
            'se' => ['sv'],
            'si' => ['sl'],
            'sk' => ['sk'],
            'ua' => ['en'],
            'uk' => ['en', 'cy', 'gd'],

            // English-speaking regions
            'us' => ['en', 'es'],
            'ca' => ['en', 'fr'],
            'au' => ['en'],
            'nz' => ['en'],
            'za' => ['en'],
            'sg' => ['en', 'zh'],

            // Latin America
            'ar' => ['es'],
            'bo' => ['es'],
            'br' => ['pt'],
            'cl' => ['es'],
            'co' => ['es'],
            'cr' => ['es'],
            'cu' => ['es'],
            'do' => ['es'],
            'ec' => ['es'],
            'sv' => ['es'],
            'gt' => ['es'],
            'hn' => ['es'],
            'mx' => ['es'],
            'ni' => ['es'],
            'pa' => ['es'],
            'py' => ['es'],
            'pe' => ['es'],
            'pr' => ['es', 'en'],
            'uy' => ['es'],
            've' => ['es'],

            // Chinese markets
            'cn' => ['zh'],
            'tw' => ['zh'],
            'hk' => ['zh', 'en'],
            'mo' => ['zh', 'pt'],

            // Gulf region
            'ae' => ['ar', 'en'],
            'sa' => ['ar', 'en'],
            'qa' => ['ar', 'en'],
            'kw' => ['ar', 'en'],
            'bh' => ['ar', 'en'],
            'om' => ['ar', 'en'],
        ];

        foreach ($mappings as $countryCode => $languageCodes) {
            $country = Country::where('code', $countryCode)->first();
            if (!$country) {
                continue;
            }

            $sync = [];
            foreach ($languageCodes as $index => $langCode) {
                $language = Language::where('code', $langCode)->first();
                if (!$language) {
                    continue;
                }
                $sync[$language->id] = ['is_primary' => $index === 0];
            }

            if (!empty($sync)) {
                $country->languages()->sync($sync);
            }
        }
    }
}
