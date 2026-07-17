<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('countries') || !Schema::hasTable('languages')) {
            return;
        }

        // Upsert + prune via seeders (config/markets.php is the source of truth).
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\LanguagesTableSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CountriesTableSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\CountryLanguageSeeder', '--force' => true]);

        $allowedLanguages = config('markets.allowed_language_codes', []);
        $allowedCountries = config('markets.allowed_country_codes', []);

        if (!empty($allowedLanguages) && Schema::hasTable('languages')) {
            $extraLangIds = DB::table('languages')->whereNotIn('code', $allowedLanguages)->pluck('id');
            if ($extraLangIds->isNotEmpty()) {
                if (Schema::hasTable('country_language')) {
                    DB::table('country_language')->whereIn('language_id', $extraLangIds)->delete();
                }
                DB::table('languages')->whereIn('id', $extraLangIds)->delete();
            }
        }

        if (!empty($allowedCountries) && Schema::hasTable('countries')) {
            $extraCountryIds = DB::table('countries')->whereNotIn('code', $allowedCountries)->pluck('id');
            if ($extraCountryIds->isNotEmpty()) {
                if (Schema::hasTable('country_language')) {
                    DB::table('country_language')->whereIn('country_id', $extraCountryIds)->delete();
                }
                DB::table('countries')->whereIn('id', $extraCountryIds)->delete();
            }
        }
    }

    public function down(): void
    {
        // Marketplace set is intentionally narrowed; re-seed older data if needed.
    }
};
