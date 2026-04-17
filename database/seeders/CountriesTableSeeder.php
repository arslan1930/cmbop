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
            ['code' => 'at', 'name' => 'Austria', 'region' => 'Europe'],
            ['code' => 'bh', 'name' => 'Bahrain', 'region' => 'Middle East'],
            ['code' => 'by', 'name' => 'Belarus', 'region' => 'Europe'],
            ['code' => 'be', 'name' => 'Belgium', 'region' => 'Europe'],
            ['code' => 'br', 'name' => 'Brazil', 'region' => 'South America'],
            ['code' => 'bg', 'name' => 'Bulgaria', 'region' => 'Europe'],
            ['code' => 'cn', 'name' => 'China', 'region' => 'Asia'],
            ['code' => 'hr', 'name' => 'Croatia', 'region' => 'Europe'],
            ['code' => 'cy', 'name' => 'Cyprus', 'region' => 'Europe'],
            ['code' => 'cz', 'name' => 'Czech Republic', 'region' => 'Europe'],
            ['code' => 'dk', 'name' => 'Denmark', 'region' => 'Europe'],
            ['code' => 'eg', 'name' => 'Egypt', 'region' => 'Middle East'],
            ['code' => 'fi', 'name' => 'Finland', 'region' => 'Europe'],
            ['code' => 'fr', 'name' => 'France', 'region' => 'Europe'],
            ['code' => 'de', 'name' => 'Germany', 'region' => 'Europe'],
            ['code' => 'gr', 'name' => 'Greece', 'region' => 'Europe'],
            ['code' => 'hk', 'name' => 'Hong Kong', 'region' => 'Asia'],
            ['code' => 'hu', 'name' => 'Hungary', 'region' => 'Europe'],
            ['code' => 'iq', 'name' => 'Iraq', 'region' => 'Middle East'],
            ['code' => 'ie', 'name' => 'Ireland', 'region' => 'Europe'],
            ['code' => 'it', 'name' => 'Italy', 'region' => 'Europe'],
            ['code' => 'jp', 'name' => 'Japan', 'region' => 'Asia'],
            ['code' => 'jo', 'name' => 'Jordan', 'region' => 'Middle East'],
            ['code' => 'kw', 'name' => 'Kuwait', 'region' => 'Middle East'],
            ['code' => 'lv', 'name' => 'Latvia', 'region' => 'Europe'],
            ['code' => 'lb', 'name' => 'Lebanon', 'region' => 'Middle East'],
            ['code' => 'lt', 'name' => 'Lithuania', 'region' => 'Europe'],
            ['code' => 'lu', 'name' => 'Luxembourg', 'region' => 'Europe'],
            ['code' => 'ma', 'name' => 'Morocco', 'region' => 'Africa'],
            ['code' => 'nl', 'name' => 'Netherlands', 'region' => 'Europe'],
            ['code' => 'no', 'name' => 'Norway', 'region' => 'Europe'],
            ['code' => 'om', 'name' => 'Oman', 'region' => 'Middle East'],
            ['code' => 'pl', 'name' => 'Poland', 'region' => 'Europe'],
            ['code' => 'pt', 'name' => 'Portugal', 'region' => 'Europe'],
            ['code' => 'qa', 'name' => 'Qatar', 'region' => 'Middle East'],
            ['code' => 'ro', 'name' => 'Romania', 'region' => 'Europe'],
            ['code' => 'ru', 'name' => 'Russia', 'region' => 'Europe'],
            ['code' => 'sa', 'name' => 'Saudi Arabia', 'region' => 'Middle East'],
            ['code' => 'sg', 'name' => 'Singapore', 'region' => 'Asia'],
            ['code' => 'sk', 'name' => 'Slovakia', 'region' => 'Europe'],
            ['code' => 'si', 'name' => 'Slovenia', 'region' => 'Europe'],
            ['code' => 'kr', 'name' => 'South Korea', 'region' => 'Asia'],
            ['code' => 'es', 'name' => 'Spain', 'region' => 'Europe'],
            ['code' => 'se', 'name' => 'Sweden', 'region' => 'Europe'],
            ['code' => 'ch', 'name' => 'Switzerland', 'region' => 'Europe'],
            ['code' => 'ua', 'name' => 'Ukraine', 'region' => 'Europe'],
            ['code' => 'uk', 'name' => 'United Kingdom', 'region' => 'Europe'],
            ['code' => 'us', 'name' => 'United States', 'region' => 'North America'],
            ['code' => 'ae', 'name' => 'United Arab Emirates', 'region' => 'Middle East'],
            ['code' => 'ye', 'name' => 'Yemen', 'region' => 'Middle East'],
            ['code' => 'ar', 'name' => 'Argentina', 'region' => 'South America'],
            ['code' => 'bo', 'name' => 'Bolivia', 'region' => 'South America'],
            ['code' => 'cl', 'name' => 'Chile', 'region' => 'South America'],
            ['code' => 'co', 'name' => 'Colombia', 'region' => 'South America'],
            ['code' => 'cr', 'name' => 'Costa Rica', 'region' => 'Central America'],
            ['code' => 'cu', 'name' => 'Cuba', 'region' => 'Central America'],
            ['code' => 'do', 'name' => 'Dominican Republic', 'region' => 'Central America'],
            ['code' => 'ec', 'name' => 'Ecuador', 'region' => 'South America'],
            ['code' => 'sv', 'name' => 'El Salvador', 'region' => 'Central America'],
            ['code' => 'gt', 'name' => 'Guatemala', 'region' => 'Central America'],
            ['code' => 'hn', 'name' => 'Honduras', 'region' => 'Central America'],
            ['code' => 'mx', 'name' => 'Mexico', 'region' => 'North America'],
            ['code' => 'ni', 'name' => 'Nicaragua', 'region' => 'Central America'],
            ['code' => 'pa', 'name' => 'Panama', 'region' => 'Central America'],
            ['code' => 'py', 'name' => 'Paraguay', 'region' => 'South America'],
            ['code' => 'pe', 'name' => 'Peru', 'region' => 'South America'],
            ['code' => 'pr', 'name' => 'Puerto Rico', 'region' => 'Central America'],
            ['code' => 'uy', 'name' => 'Uruguay', 'region' => 'South America'],
            ['code' => 've', 'name' => 'Venezuela', 'region' => 'South America'],
        ];
        
        foreach ($countries as $country) {
            Country::create($country);
        }
    }
}