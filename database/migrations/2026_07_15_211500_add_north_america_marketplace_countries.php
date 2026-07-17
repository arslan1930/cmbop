<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('countries')) {
            return;
        }

        $northAmerica = [
            ['code' => 'us', 'name' => 'United States', 'region' => 'North America'],
            ['code' => 'ca', 'name' => 'Canada', 'region' => 'North America'],
            ['code' => 'mx', 'name' => 'Mexico', 'region' => 'North America'],
        ];

        foreach ($northAmerica as $country) {
            $existing = DB::table('countries')->where('code', $country['code'])->first();
            if ($existing) {
                DB::table('countries')->where('id', $existing->id)->update([
                    'name' => $country['name'],
                    'region' => $country['region'],
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('countries')->insert([
                    'code' => $country['code'],
                    'name' => $country['name'],
                    'region' => $country['region'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (!Schema::hasTable('languages') || !Schema::hasTable('country_language')) {
            return;
        }

        $mappings = [
            'us' => ['en', 'es'],
            'ca' => ['en', 'fr'],
            'mx' => ['es'],
        ];

        foreach ($mappings as $countryCode => $languageCodes) {
            $country = DB::table('countries')->where('code', $countryCode)->first();
            if (!$country) {
                continue;
            }

            foreach ($languageCodes as $index => $langCode) {
                $language = DB::table('languages')->where('code', $langCode)->first();
                if (!$language) {
                    continue;
                }

                $exists = DB::table('country_language')
                    ->where('country_id', $country->id)
                    ->where('language_id', $language->id)
                    ->exists();

                if (!$exists) {
                    DB::table('country_language')->insert([
                        'country_id' => $country->id,
                        'language_id' => $language->id,
                        'is_primary' => $index === 0 ? 1 : 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Keep North American countries; marketplace config controls visibility.
    }
};
