<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Language;

class LanguagesTableSeeder extends Seeder
{
    public function run()
    {
        $languages = [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English'],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français'],
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español'],
            ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano'],
            ['code' => 'pt', 'name' => 'Portuguese', 'native_name' => 'Português'],
            ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands'],
            ['code' => 'pl', 'name' => 'Polish', 'native_name' => 'Polski'],
            ['code' => 'sv', 'name' => 'Swedish', 'native_name' => 'Svenska'],
            ['code' => 'da', 'name' => 'Danish', 'native_name' => 'Dansk'],
            ['code' => 'no', 'name' => 'Norwegian', 'native_name' => 'Norsk'],
            ['code' => 'fi', 'name' => 'Finnish', 'native_name' => 'Suomi'],
            ['code' => 'el', 'name' => 'Greek', 'native_name' => 'Ελληνικά'],
            ['code' => 'cs', 'name' => 'Czech', 'native_name' => 'Čeština'],
            ['code' => 'sk', 'name' => 'Slovak', 'native_name' => 'Slovenčina'],
            ['code' => 'hu', 'name' => 'Hungarian', 'native_name' => 'Magyar'],
            ['code' => 'ro', 'name' => 'Romanian', 'native_name' => 'Română'],
            ['code' => 'bg', 'name' => 'Bulgarian', 'native_name' => 'Български'],
            ['code' => 'hr', 'name' => 'Croatian', 'native_name' => 'Hrvatski'],
            ['code' => 'sl', 'name' => 'Slovenian', 'native_name' => 'Slovenščina'],
            ['code' => 'lt', 'name' => 'Lithuanian', 'native_name' => 'Lietuvių'],
            ['code' => 'lv', 'name' => 'Latvian', 'native_name' => 'Latviešu'],
            ['code' => 'et', 'name' => 'Estonian', 'native_name' => 'Eesti'],
            ['code' => 'ca', 'name' => 'Catalan', 'native_name' => 'Català'],
            ['code' => 'gl', 'name' => 'Galician', 'native_name' => 'Galego'],
            ['code' => 'eu', 'name' => 'Basque', 'native_name' => 'Euskara'],
            ['code' => 'ga', 'name' => 'Irish', 'native_name' => 'Gaeilge'],
            ['code' => 'cy', 'name' => 'Welsh', 'native_name' => 'Cymraeg'],
            ['code' => 'gd', 'name' => 'Scottish Gaelic', 'native_name' => 'Gàidhlig'],
            ['code' => 'lb', 'name' => 'Luxembourgish', 'native_name' => 'Lëtzebuergesch'],
            ['code' => 'rm', 'name' => 'Romansh', 'native_name' => 'Rumantsch'],
            ['code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti'],
            ['code' => 'zh', 'name' => 'Chinese', 'native_name' => '中文'],
            ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية'],
        ];

        $allowed = config('markets.allowed_language_codes', []);

        foreach ($languages as $language) {
            if (!in_array($language['code'], $allowed, true)) {
                continue;
            }

            Language::updateOrCreate(
                ['code' => $language['code']],
                $language
            );
        }

        // Remove languages outside the marketplace set
        if (!empty($allowed)) {
            Language::whereNotIn('code', $allowed)->each(function (Language $language) {
                $language->countries()->detach();
                $language->delete();
            });
        }
    }
}
