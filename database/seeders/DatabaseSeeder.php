<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            RolesTableSeeder::class,
            CountriesTableSeeder::class,
            LanguagesTableSeeder::class,
            CategoriesTableSeeder::class,
            CountryLanguageSeeder::class,
            BlogSeeder::class,
        ]);
    }
}