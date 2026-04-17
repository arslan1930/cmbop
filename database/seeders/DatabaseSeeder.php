<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            CountriesTableSeeder::class,
            LanguagesTableSeeder::class,
            CategoriesTableSeeder::class,
            CountryLanguageSeeder::class,
        ]);
    }
}