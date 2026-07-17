<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('countries') || !Schema::hasTable('languages')) {
            return;
        }

        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\LanguagesTableSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CountriesTableSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CountryLanguageSeeder', '--force' => true]);
    }

    public function down(): void
    {
        // Keep Arabic + Gulf countries; marketplace config controls visibility.
    }
};
