<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTurnaroundTimeToPublisherSites extends Migration
{
    public function up()
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->enum('turnaround_time', ['24h', '48h', '3days', '5days', '7days'])
                  ->default('3days')
                  ->after('traffic');
        });
    }

    public function down()
    {
        Schema::table('publisher_sites', function (Blueprint $table) {
            $table->dropColumn('turnaround_time');
        });
    }
}