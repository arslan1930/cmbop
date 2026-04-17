<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['advertiser', 'publisher', 'admin'];

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