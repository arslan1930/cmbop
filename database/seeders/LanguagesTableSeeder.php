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
            ['code' => 'es', 'name' => 'Spanish', 'native_name' => 'Español'],
            ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français'],
            ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'],
            ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano'],
            ['code' => 'pt', 'name' => 'Portuguese', 'native_name' => 'Português'],
            ['code' => 'nl', 'name' => 'Dutch', 'native_name' => 'Nederlands'],
            ['code' => 'ru', 'name' => 'Russian', 'native_name' => 'Русский'],
            ['code' => 'zh', 'name' => 'Chinese', 'native_name' => '中文'],
            ['code' => 'ja', 'name' => 'Japanese', 'native_name' => '日本語'],
            ['code' => 'ko', 'name' => 'Korean', 'native_name' => '한국어'],
            ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية'],
            ['code' => 'tr', 'name' => 'Turkish', 'native_name' => 'Türkçe'],
            ['code' => 'pl', 'name' => 'Polish', 'native_name' => 'Polski'],
            ['code' => 'uk', 'name' => 'Ukrainian', 'native_name' => 'Українська'],
            ['code' => 'sv', 'name' => 'Swedish', 'native_name' => 'Svenska'],
            ['code' => 'da', 'name' => 'Danish', 'native_name' => 'Dansk'],
            ['code' => 'no', 'name' => 'Norwegian', 'native_name' => 'Norsk'],
            ['code' => 'fi', 'name' => 'Finnish', 'native_name' => 'Suomi'],
            ['code' => 'el', 'name' => 'Greek', 'native_name' => 'Ελληνικά'],
            ['code' => 'cs', 'name' => 'Czech', 'native_name' => 'Čeština'],
            ['code' => 'hu', 'name' => 'Hungarian', 'native_name' => 'Magyar'],
            ['code' => 'ro', 'name' => 'Romanian', 'native_name' => 'Română'],
            ['code' => 'bg', 'name' => 'Bulgarian', 'native_name' => 'Български'],
            ['code' => 'hr', 'name' => 'Croatian', 'native_name' => 'Hrvatski'],
            ['code' => 'sk', 'name' => 'Slovak', 'native_name' => 'Slovenčina'],
            ['code' => 'sl', 'name' => 'Slovenian', 'native_name' => 'Slovenščina'],
            ['code' => 'lt', 'name' => 'Lithuanian', 'native_name' => 'Lietuvių'],
            ['code' => 'lv', 'name' => 'Latvian', 'native_name' => 'Latviešu'],
            ['code' => 'et', 'name' => 'Estonian', 'native_name' => 'Eesti'],
            ['code' => 'he', 'name' => 'Hebrew', 'native_name' => 'עברית'],
            ['code' => 'th', 'name' => 'Thai', 'native_name' => 'ไทย'],
            ['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt'],
            ['code' => 'id', 'name' => 'Indonesian', 'native_name' => 'Bahasa Indonesia'],
            ['code' => 'ms', 'name' => 'Malay', 'native_name' => 'Bahasa Melayu'],
            ['code' => 'ca', 'name' => 'Catalan', 'native_name' => 'Català'],
            ['code' => 'gl', 'name' => 'Galician', 'native_name' => 'Galego'],
            ['code' => 'eu', 'name' => 'Basque', 'native_name' => 'Euskara'],
            ['code' => 'cy', 'name' => 'Welsh', 'native_name' => 'Cymraeg'],
            ['code' => 'gd', 'name' => 'Scottish Gaelic', 'native_name' => 'Gàidhlig'],
            ['code' => 'ga', 'name' => 'Irish', 'native_name' => 'Gaeilge'],
            ['code' => 'lb', 'name' => 'Luxembourgish', 'native_name' => 'Lëtzebuergesch'],
            ['code' => 'rm', 'name' => 'Romansh', 'native_name' => 'Rumantsch'],
            ['code' => 'qu', 'name' => 'Quechua', 'native_name' => 'Runa Simi'],
            ['code' => 'ay', 'name' => 'Aymara', 'native_name' => 'Aymar aru'],
            ['code' => 'gn', 'name' => 'Guarani', 'native_name' => 'Avañe\'ẽ'],
            ['code' => 'be', 'name' => 'Belarusian', 'native_name' => 'Беларуская'],
            ['code' => 'ku', 'name' => 'Kurdish', 'native_name' => 'Kurdî'],
            ['code' => 'ta', 'name' => 'Tamil', 'native_name' => 'தமிழ்'],
        ];
        
        foreach ($languages as $language) {
            Language::create($language);
        }
    }
}