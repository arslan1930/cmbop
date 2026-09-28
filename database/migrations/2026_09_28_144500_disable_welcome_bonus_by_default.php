<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signup credit stays off. A row already saved as enabled would keep
     * granting €20 after the config default changes.
     */
    public function up(): void
    {
        if (! Schema::hasTable('welcome_bonus_settings')) {
            return;
        }

        $rows = DB::table('welcome_bonus_settings')->where('key', 'config')->orderBy('id')->get();
        foreach ($rows as $row) {
            $value = json_decode((string) $row->value, true);
            if (! is_array($value)) {
                $value = [];
            }
            $value['enabled'] = false;
            $value['updated_at'] = now()->toIso8601String();

            DB::table('welcome_bonus_settings')->where('id', $row->id)->update([
                'value' => json_encode($value),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('welcome_bonus_settings')) {
            return;
        }

        $rows = DB::table('welcome_bonus_settings')->where('key', 'config')->orderBy('id')->get();
        foreach ($rows as $row) {
            $value = json_decode((string) $row->value, true);
            if (! is_array($value)) {
                $value = [];
            }
            $value['enabled'] = true;

            DB::table('welcome_bonus_settings')->where('id', $row->id)->update([
                'value' => json_encode($value),
                'updated_at' => now(),
            ]);
        }
    }
};
