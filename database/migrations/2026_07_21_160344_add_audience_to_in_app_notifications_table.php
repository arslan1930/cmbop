<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('in_app_notifications', function (Blueprint $table) {
            $table->string('audience', 32)->default('all')->after('user_id');
            $table->index(['user_id', 'audience', 'status', 'created_at'], 'in_app_notifications_user_audience_status_idx');
        });

        // Backfill from action URL so dual-role users stop seeing cross-mode noise.
        DB::table('in_app_notifications')
            ->whereNotNull('action_url')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $url = (string) ($row->action_url ?? '');
                    $audience = 'all';
                    if (str_contains($url, '/publisher/')) {
                        $audience = 'publisher';
                    } elseif (str_contains($url, '/advertiser/')) {
                        $audience = 'advertiser';
                    } elseif (str_contains($url, '/admin/')) {
                        $audience = 'admin';
                    }

                    if ($audience !== 'all') {
                        DB::table('in_app_notifications')
                            ->where('id', $row->id)
                            ->update(['audience' => $audience]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('in_app_notifications', function (Blueprint $table) {
            $table->dropIndex('in_app_notifications_user_audience_status_idx');
            $table->dropColumn('audience');
        });
    }
};
