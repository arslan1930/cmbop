<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_site_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('requested')->index();
            $table->unsignedSmallInteger('estimated_count')->nullable();
            $table->text('publisher_note')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('sheet_sent_at')->nullable();
            $table->timestamp('seeded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['publisher_id', 'status']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('bulk_site_request_id')
                ->nullable()
                ->after('publisher_id')
                ->constrained('bulk_site_requests')
                ->nullOnDelete();
            $table->string('onboarding_status', 32)->nullable()->after('enrichment_status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bulk_site_request_id');
            $table->dropColumn('onboarding_status');
        });

        Schema::dropIfExists('bulk_site_requests');
    }
};
