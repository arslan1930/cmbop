<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Country;

class CountriesTableSeeder extends Seeder
{
    public function run()
    {
        $countries = [
            // Europe
            ['code' => 'al', 'name' => 'Albania', 'region' => 'Europe'],
            ['code' => 'at', 'name' => 'Austria', 'region' => 'Europe'],
            ['code' => 'ba', 'name' => 'Bosnia and Herzegovina', 'region' => 'Europe'],
            ['code' => 'be', 'name' => 'Belgium', 'region' => 'Europe'],
            ['code' => 'bg', 'name' => 'Bulgaria', 'region' => 'Europe'],
            ['code' => 'ch', 'name' => 'Switzerland', 'region' => 'Europe'],
            ['code' => 'cy', 'name' => 'Cyprus', 'region' => 'Europe'],
            ['code' => 'cz', 'name' => 'Czech Republic', 'region' => 'Europe'],
            ['code' => 'de', 'name' => 'Germany', 'region' => 'Europe'],
            ['code' => 'dk', 'name' => 'Denmark', 'region' => 'Europe'],
            ['code' => 'ee', 'name' => 'Estonia', 'region' => 'Europe'],
            ['code' => 'es', 'name' => 'Spain', 'region' => 'Europe'],
            ['code' => 'fi', 'name' => 'Finland', 'region' => 'Europe'],
            ['code' => 'fr', 'name' => 'France', 'region' => 'Europe'],
            ['code' => 'gr', 'name' => 'Greece', 'region' => 'Europe'],
            ['code' => 'hr', 'name' => 'Croatia', 'region' => 'Europe'],
            ['code' => 'hu', 'name' => 'Hungary', 'region' => 'Europe'],
            ['code' => 'ie', 'name' => 'Ireland', 'region' => 'Europe'],
            ['code' => 'is', 'name' => 'Iceland', 'region' => 'Europe'],
            ['code' => 'it', 'name' => 'Italy', 'region' => 'Europe'],
            ['code' => 'lt', 'name' => 'Lithuania', 'region' => 'Europe'],
            ['code' => 'lu', 'name' => 'Luxembourg', 'region' => 'Europe'],
            ['code' => 'lv', 'name' => 'Latvia', 'region' => 'Europe'],
            ['code' => 'md', 'name' => 'Moldova', 'region' => 'Europe'],
            ['code' => 'me', 'name' => 'Montenegro', 'region' => 'Europe'],
            ['code' => 'mk', 'name' => 'North Macedonia', 'region' => 'Europe'],
            ['code' => 'mt', 'name' => 'Malta', 'region' => 'Europe'],
            ['code' => 'nl', 'name' => 'Netherlands', 'region' => 'Europe'],
            ['code' => 'no', 'name' => 'Norway', 'region' => 'Europe'],
            ['code' => 'pl', 'name' => 'Poland', 'region' => 'Europe'],
            ['code' => 'pt', 'name' => 'Portugal', 'region' => 'Europe'],
            ['code' => 'ro', 'name' => 'Romania', 'region' => 'Europe'],
            ['code' => 'rs', 'name' => 'Serbia', 'region' => 'Europe'],
            ['code' => 'se', 'name' => 'Sweden', 'region' => 'Europe'],
            ['code' => 'si', 'name' => 'Slovenia', 'region' => 'Europe'],
            ['code' => 'sk', 'name' => 'Slovakia', 'region' => 'Europe'],
            ['code' => 'ua', 'name' => 'Ukraine', 'region' => 'Europe'],
            ['code' => 'uk', 'name' => 'United Kingdom', 'region' => 'Europe'],

            // English-speaking regions
            ['code' => 'us', 'name' => 'United States', 'region' => 'North America'],
            ['code' => 'ca', 'name' => 'Canada', 'region' => 'North America'],
            ['code' => 'au', 'name' => 'Australia', 'region' => 'Oceania'],
            ['code' => 'nz', 'name' => 'New Zealand', 'region' => 'Oceania'],
            ['code' => 'za', 'name' => 'South Africa', 'region' => 'Africa'],
            ['code' => 'sg', 'name' => 'Singapore', 'region' => 'East Asia'],

            // Latin America
            ['code' => 'ar', 'name' => 'Argentina', 'region' => 'Latin America'],
            ['code' => 'bo', 'name' => 'Bolivia', 'region' => 'Latin America'],
            ['code' => 'br', 'name' => 'Brazil', 'region' => 'Latin America'],
            ['code' => 'cl', 'name' => 'Chile', 'region' => 'Latin America'],
            ['code' => 'co', 'name' => 'Colombia', 'region' => 'Latin America'],
            ['code' => 'cr', 'name' => 'Costa Rica', 'region' => 'Latin America'],
            ['code' => 'cu', 'name' => 'Cuba', 'region' => 'Latin America'],
            ['code' => 'do', 'name' => 'Dominican Republic', 'region' => 'Latin America'],
            ['code' => 'ec', 'name' => 'Ecuador', 'region' => 'Latin America'],
            ['code' => 'sv', 'name' => 'El Salvador', 'region' => 'Latin America'],
            ['code' => 'gt', 'name' => 'Guatemala', 'region' => 'Latin America'],
            ['code' => 'hn', 'name' => 'Honduras', 'region' => 'Latin America'],
            ['code' => 'mx', 'name' => 'Mexico', 'region' => 'Latin America'],
            ['code' => 'ni', 'name' => 'Nicaragua', 'region' => 'Latin America'],
            ['code' => 'pa', 'name' => 'Panama', 'region' => 'Latin America'],
            ['code' => 'py', 'name' => 'Paraguay', 'region' => 'Latin America'],
            ['code' => 'pe', 'name' => 'Peru', 'region' => 'Latin America'],
            ['code' => 'pr', 'name' => 'Puerto Rico', 'region' => 'Latin America'],
            ['code' => 'uy', 'name' => 'Uruguay', 'region' => 'Latin America'],
            ['code' => 've', 'name' => 'Venezuela', 'region' => 'Latin America'],

            // Chinese markets
            ['code' => 'cn', 'name' => 'China', 'region' => 'East Asia'],
            ['code' => 'tw', 'name' => 'Taiwan', 'region' => 'East Asia'],
            ['code' => 'hk', 'name' => 'Hong Kong', 'region' => 'East Asia'],
            ['code' => 'mo', 'name' => 'Macau', 'region' => 'East Asia'],

            // Gulf region
            ['code' => 'ae', 'name' => 'United Arab Emirates', 'region' => 'Middle East'],
            ['code' => 'sa', 'name' => 'Saudi Arabia', 'region' => 'Middle East'],
            ['code' => 'qa', 'name' => 'Qatar', 'region' => 'Middle East'],
            ['code' => 'kw', 'name' => 'Kuwait', 'region' => 'Middle East'],
            ['code' => 'bh', 'name' => 'Bahrain', 'region' => 'Middle East'],
            ['code' => 'om', 'name' => 'Oman', 'region' => 'Middle East'],
        ];

        $allowed = config('markets.allowed_country_codes', []);

        foreach ($countries as $country) {
            if (!empty($allowed) && !in_array($country['code'], $allowed, true)) {
                continue;
            }

            Country::updateOrCreate(
                ['code' => $country['code']],
                $country
            );
        }

        // Remove countries outside the marketplace set (e.g. Russia, Belarus)
        if (!empty($allowed)) {
            Country::whereNotIn('code', $allowed)->each(function (Country $country) {
                $country->languages()->detach();
                $country->delete();
            });
        }
    }
}
