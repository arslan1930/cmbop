<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallets')) {
            return;
        }

        if (Schema::hasColumn('wallets', 'currency')) {
            DB::table('wallets')
                ->where(function ($query) {
                    $query->whereNull('currency')->orWhere('currency', '');
                })
                ->update(['currency' => 'EUR']);
        } else {
            Schema::table('wallets', function (Blueprint $table) {
                $table->string('currency', 3)->default('EUR');
            });
        }

        $this->dropUniqueIfPresent(['user_id', 'role_id']);
        $this->addUniqueIfMissing(['user_id', 'role_id', 'currency']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallets')) {
            return;
        }

        $this->dropUniqueIfPresent(['user_id', 'role_id', 'currency']);
        $this->addUniqueIfMissing(['user_id', 'role_id']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropUniqueIfPresent(array $columns): void
    {
        $name = $this->uniqueName($columns);
        try {
            Schema::table('wallets', function (Blueprint $table) use ($name, $columns) {
                try {
                    $table->dropUnique($name);
                } catch (Throwable) {
                    $table->dropUnique($columns);
                }
            });
        } catch (Throwable) {
            // Index already gone or named differently on Hostinger leftovers.
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addUniqueIfMissing(array $columns): void
    {
        $name = $this->uniqueName($columns);
        try {
            Schema::table('wallets', function (Blueprint $table) use ($name, $columns) {
                $table->unique($columns, $name);
            });
        } catch (Throwable) {
            // Unique already exists.
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function uniqueName(array $columns): string
    {
        return 'wallets_'.implode('_', $columns).'_unique';
    }
};
