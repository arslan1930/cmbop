<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_site_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_site_request_id')
                ->constrained('bulk_site_requests')
                ->cascadeOnDelete();
            $table->string('site_url', 512);
            $table->string('domain', 255)->index();
            $table->decimal('price', 10, 2);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bulk_site_request_id', 'domain'], 'bulk_items_request_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_site_request_items');
    }
};
