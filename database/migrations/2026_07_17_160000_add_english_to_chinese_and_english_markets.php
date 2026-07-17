<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow English-language sites in Chinese markets (CN/TW/MO) and ensure
 * English regions + Gulf keep their English country_language links.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('countries') || ! Schema::hasTable('languages') || ! Schema::hasTable('country_language')) {
            return;
        }

        $englishId = DB::table('languages')->where('code', 'en')->value('id');
        if (! $englishId) {
            return;
        }

        $countryCodes = array_values(array_unique(array_merge(
            config('markets.english_region_country_codes', []),
            config('markets.chinese_country_codes', []),
            config('markets.gulf_country_codes', []),
            // Existing European / other EN markets from product list
            ['al', 'ba', 'cy', 'is', 'md', 'me', 'mk', 'mt', 'rs', 'ua', 'pr'],
        )));

        $countries = DB::table('countries')
            ->whereIn('code', $countryCodes)
            ->get(['id', 'code']);

        $now = now();

        foreach ($countries as $country) {
            $exists = DB::table('country_language')
                ->where('country_id', $country->id)
                ->where('language_id', $englishId)
                ->exists();

            if ($exists) {
                continue;
            }

            // Keep existing primary language; English is an additional option.
            DB::table('country_language')->insert([
                'country_id' => $country->id,
                'language_id' => $englishId,
                'is_primary' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('countries') || ! Schema::hasTable('languages') || ! Schema::hasTable('country_language')) {
            return;
        }

        $englishId = DB::table('languages')->where('code', 'en')->value('id');
        if (! $englishId) {
            return;
        }

        // Only detach English from Chinese markets that were newly added as secondary.
        $chineseIds = DB::table('countries')
            ->whereIn('code', ['cn', 'tw', 'mo'])
            ->pluck('id');

        if ($chineseIds->isEmpty()) {
            return;
        }

        DB::table('country_language')
            ->where('language_id', $englishId)
            ->whereIn('country_id', $chineseIds)
            ->where('is_primary', false)
            ->delete();
    }
};
