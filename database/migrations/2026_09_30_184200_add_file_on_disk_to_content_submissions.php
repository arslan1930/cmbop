<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached disk check for Admin → Content Library “file missing” filter.
 * List/show refresh the flag; the chip never walks the disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_submissions')) {
            return;
        }

        Schema::table('content_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('content_submissions', 'file_on_disk')) {
                $table->boolean('file_on_disk')->nullable()->after('path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_submissions')) {
            return;
        }

        Schema::table('content_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('content_submissions', 'file_on_disk')) {
                $table->dropColumn('file_on_disk');
            }
        });
    }
};
