<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $europeanLanguageCodes = config('markets.european_language_codes', []);

        if (Schema::hasTable('languages') && !empty($europeanLanguageCodes)) {
            $nonEuropeanLanguageIds = DB::table('languages')
                ->whereNotIn('code', $europeanLanguageCodes)
                ->pluck('id');

            if ($nonEuropeanLanguageIds->isNotEmpty()) {
                if (Schema::hasTable('country_language')) {
                    DB::table('country_language')
                        ->whereIn('language_id', $nonEuropeanLanguageIds)
                        ->delete();
                }

                DB::table('languages')->whereIn('id', $nonEuropeanLanguageIds)->delete();
            }
        }

        if (Schema::hasTable('countries')) {
            $nonEuropeanCountryIds = DB::table('countries')
                ->where('region', '!=', 'Europe')
                ->pluck('id');

            if ($nonEuropeanCountryIds->isNotEmpty()) {
                if (Schema::hasTable('country_language')) {
                    DB::table('country_language')
                        ->whereIn('country_id', $nonEuropeanCountryIds)
                        ->delete();
                }

                DB::table('countries')->whereIn('id', $nonEuropeanCountryIds)->delete();
            }
        }
    }

    public function down(): void
    {
        // Non-European languages/countries are removed intentionally; re-seed if needed.
    }
};
