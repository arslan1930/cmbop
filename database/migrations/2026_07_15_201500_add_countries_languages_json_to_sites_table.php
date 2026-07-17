<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (!Schema::hasColumn('sites', 'countries')) {
                $table->json('countries')->nullable()->after('country');
            }
            if (!Schema::hasColumn('sites', 'languages')) {
                $table->json('languages')->nullable()->after('language');
            }
        });

        // Backfill JSON arrays from legacy single-code columns
        DB::table('sites')->orderBy('id')->chunkById(200, function ($sites) {
            foreach ($sites as $site) {
                $countries = [];
                $languages = [];

                if (!empty($site->country)) {
                    $countries[] = strtolower($site->country);
                }
                if (!empty($site->language)) {
                    $languages[] = strtolower($site->language);
                }

                DB::table('sites')->where('id', $site->id)->update([
                    'countries' => !empty($countries) ? json_encode(array_values(array_unique($countries))) : null,
                    'languages' => !empty($languages) ? json_encode(array_values(array_unique($languages))) : null,
                    'country'   => isset($countries[0]) ? $countries[0] : $site->country,
                    'language'  => isset($languages[0]) ? $languages[0] : $site->language,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'countries')) {
                $table->dropColumn('countries');
            }
            if (Schema::hasColumn('sites', 'languages')) {
                $table->dropColumn('languages');
            }
        });
    }
};
