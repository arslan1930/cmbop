<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        // Core roles. Admin exists for up to 2 locked staff accounts only —
        // it is NOT assignable from the user-management panel.
        // Assignable roles: advertiser, publisher, marketing.
        $roles = ['advertiser', 'publisher', 'admin', 'marketing'];

        foreach ($roles as $roleName) {
            // Only insert if the role doesn't exist yet
            $exists = DB::table('roles')->where('name', $roleName)->exists();
            if (!$exists) {
                DB::table('roles')->insert([
                    'name' => $roleName,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }
}