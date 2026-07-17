<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_notification_settings')->updateOrInsert(
            ['type' => 'order_status_changed'],
            [
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('email_notification_settings')->where('type', 'order_status_changed')->delete();
    }
};
