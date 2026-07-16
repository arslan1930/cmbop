<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('problem_reports')) {
            Schema::create('problem_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('subject');
                $table->text('message');
                $table->string('page_url')->nullable();
                $table->string('role_context', 40)->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->text('admin_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('suggestions')) {
            Schema::create('suggestions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('category', 40)->default('general');
                $table->text('message');
                $table->string('page_url')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->text('admin_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('website_suggestions')) {
            Schema::create('website_suggestions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('website_name');
                $table->string('website_url');
                $table->string('domain')->nullable()->index();
                $table->string('country', 8)->nullable();
                $table->string('language', 8)->nullable();
                $table->text('notes')->nullable();
                $table->string('search_query')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->text('admin_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('site_claims')) {
            Schema::create('site_claims', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('claimer_id')->constrained('users')->cascadeOnDelete();
                $table->string('website_name');
                $table->string('website_url');
                $table->string('domain')->index();
                $table->boolean('name_matches')->default(false);
                $table->text('proof_message');
                $table->string('contact_email')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->text('admin_notes')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['claimer_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_claims');
        Schema::dropIfExists('website_suggestions');
        Schema::dropIfExists('suggestions');
        Schema::dropIfExists('problem_reports');
    }
};
