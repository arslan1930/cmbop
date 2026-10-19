<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'staff_created')) {
                $table->boolean('staff_created')->default(false)->index();
            }
            if (! Schema::hasColumn('users', 'staff_created_by')) {
                $table->unsignedBigInteger('staff_created_by')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'staff_invite_sent_at')) {
                $table->timestamp('staff_invite_sent_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'staff_onboarded_at')) {
                $table->timestamp('staff_onboarded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['staff_created', 'staff_created_by', 'staff_invite_sent_at', 'staff_onboarded_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
