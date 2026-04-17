<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();

            // Publisher who submitted the site
            $table->foreignId('publisher_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            // Site info
            $table->string('site_name');
            $table->string('site_url');
            $table->string('domain'); // NEW
            $table->string('example_url')->nullable();
            $table->integer('da')->unsigned()->default(0);
            $table->integer('dr')->unsigned()->default(0);
            $table->integer('traffic')->unsigned()->default(0);
            $table->string('country', 10);
            $table->string('language', 10);
            $table->string('category', 50);
            $table->decimal('price', 10, 2)->default(0);
            $table->string('publication_time', 20);
            $table->enum('link_type', ['dofollow', 'nofollow'])->default('dofollow');

            // Optional flags
            $table->boolean('sponsored')->default(false);
            $table->boolean('partner_material')->default(false);
            $table->boolean('as_you_prefer')->default(false);

            // Sensitive topics prices stored as JSON
            $table->json('sensitive_prices')->nullable();

            // Description
            $table->text('description');

            // Verification / ownership
            $table->boolean('verified')->default(false); // if publisher verified
            $table->boolean('active')->default(false);   // site active for advertiser
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete(); // owner after verification

            $table->timestamps();

            // Indexes for faster duplicate check
            $table->index('site_url');
            $table->index('domain'); // NEW
            $table->index(['domain', 'publisher_id']); // NEW
            $table->unique(['publisher_id', 'domain']); // NEW
            $table->index('active');
            $table->index('verified');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};