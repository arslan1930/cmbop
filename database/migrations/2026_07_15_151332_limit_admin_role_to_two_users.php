<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep at most 2 admin accounts. Strip admin from everyone else.
 * Admin is no longer assignable from the user-management panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_user')) {
            return;
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if (!$adminRoleId) {
            return;
        }

        // Keep the 2 earliest admins (lowest user_id); remove admin from the rest.
        $adminUserIds = DB::table('role_user')
            ->where('role_id', $adminRoleId)
            ->orderBy('user_id')
            ->pluck('user_id');

        $keepIds = $adminUserIds->take(2)->all();
        $removeIds = $adminUserIds->slice(2)->values()->all();

        if (empty($removeIds)) {
            return;
        }

        DB::table('role_user')
            ->where('role_id', $adminRoleId)
            ->whereIn('user_id', $removeIds)
            ->delete();

        // If any stripped user's active role was admin, point them at another role they still have.
        $advertiserId = DB::table('roles')->where('name', 'advertiser')->value('id');
        $publisherId  = DB::table('roles')->where('name', 'publisher')->value('id');
        $marketingId  = DB::table('roles')->where('name', 'marketing')->value('id');

        foreach ($removeIds as $userId) {
            $user = DB::table('users')->where('id', $userId)->first();
            if (!$user || (int) $user->active_role_id !== (int) $adminRoleId) {
                continue;
            }

            $fallback = DB::table('role_user')
                ->where('user_id', $userId)
                ->whereIn('role_id', array_filter([$advertiserId, $publisherId, $marketingId]))
                ->value('role_id');

            DB::table('users')->where('id', $userId)->update([
                'active_role_id' => $fallback,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup — nothing to restore.
    }
};
