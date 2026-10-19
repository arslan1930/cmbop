<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partners')) {
            Schema::create('partners', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 32)->default('active');
                $table->boolean('trusted')->default(false);
                $table->boolean('agreement_signed')->default(false);
                $table->timestamp('agreement_signed_at')->nullable();
                $table->string('legal_name')->nullable();
                $table->text('notes_internal')->nullable();
                $table->timestamps();
                $table->unique('user_id');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('partner_connections')) {
            Schema::create('partner_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
                $table->string('type', 32)->default('feed_url');
                $table->text('feed_url')->nullable();
                $table->string('schedule', 32)->default('daily');
                $table->string('status', 32)->default('active');
                $table->timestamp('last_sync_at')->nullable();
                $table->string('last_sync_status', 32)->nullable();
                $table->unsignedBigInteger('last_job_id')->nullable();
                $table->unsignedInteger('fail_count')->default(0);
                $table->json('field_mapping')->nullable();
                $table->timestamps();
                $table->index(['partner_id', 'status']);
            });
        }

        if (! Schema::hasTable('partner_import_jobs')) {
            Schema::create('partner_import_jobs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
                $table->unsignedBigInteger('connection_id')->nullable();
                $table->string('source', 32);
                $table->string('status', 32)->default('running');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('counts')->nullable();
                $table->string('raw_payload_path')->nullable();
                $table->text('error')->nullable();
                $table->json('pending_removals')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamps();
                $table->index(['partner_id', 'status']);
            });
        }

        if (! Schema::hasTable('partner_import_rows')) {
            Schema::create('partner_import_rows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained('partner_import_jobs')->cascadeOnDelete();
                $table->unsignedInteger('row_number')->default(0);
                $table->string('domain')->nullable();
                $table->string('result', 32);
                $table->string('reason_code', 64)->nullable();
                $table->text('reason')->nullable();
                $table->json('raw')->nullable();
                $table->timestamps();
                $table->index(['job_id', 'result']);
                $table->index('domain');
            });
        }

        if (! Schema::hasTable('partner_pricing_rules')) {
            Schema::create('partner_pricing_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->json('tiers');
                $table->decimal('min_commission_eur', 10, 2)->default(25);
                $table->string('rounding', 16)->default('end_9');
                $table->decimal('jitter_percent', 5, 2)->default(5);
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('partner_auto_approve_rules')) {
            Schema::create('partner_auto_approve_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedInteger('priority')->default(100);
                $table->unsignedBigInteger('partner_id')->nullable();
                $table->json('categories_any')->nullable();
                $table->json('categories_none')->nullable();
                $table->unsignedInteger('min_dr')->nullable();
                $table->unsignedInteger('min_da')->nullable();
                $table->unsignedInteger('min_monthly_traffic')->nullable();
                $table->json('countries')->nullable();
                $table->json('languages')->nullable();
                $table->decimal('max_partner_rate_eur', 10, 2)->nullable();
                $table->json('link_types')->nullable();
                $table->boolean('require_sample_post')->default(true);
                $table->boolean('reject_sensitive_only')->default(true);
                $table->string('action', 32)->default('send_to_review');
                $table->unsignedBigInteger('pricing_rule_id')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['active', 'priority']);
            });
        }

        if (! Schema::hasTable('partner_catalog_items')) {
            Schema::create('partner_catalog_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
                $table->unsignedBigInteger('site_id')->nullable();
                $table->unsignedBigInteger('last_job_id')->nullable();
                $table->unsignedBigInteger('matched_rule_id')->nullable();
                $table->unsignedBigInteger('pricing_rule_id')->nullable();
                $table->string('listing_status', 32)->default('unused');
                $table->string('pause_reason', 64)->nullable();
                $table->string('block_reason', 64)->nullable();
                $table->string('domain');
                $table->string('site_url');
                $table->string('site_name');
                $table->text('description')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_name')->nullable();
                $table->string('country', 8)->nullable();
                $table->string('language', 8)->nullable();
                $table->json('niches')->nullable();
                $table->json('raw_niches')->nullable();
                $table->unsignedInteger('da')->default(0);
                $table->unsignedInteger('dr')->default(0);
                $table->unsignedInteger('traffic')->default(0);
                $table->unsignedInteger('turnaround_days')->nullable();
                $table->string('turnaround_time', 20)->nullable();
                $table->string('publication_time', 20)->nullable();
                $table->string('link_type', 16)->default('dofollow');
                $table->string('sponsored_label', 16)->nullable();
                $table->string('example_url')->nullable();
                $table->string('partner_rate_currency', 3)->default('EUR');
                $table->decimal('partner_rate_publish', 12, 2)->default(0);
                $table->decimal('partner_rate_writing', 12, 2)->nullable();
                $table->decimal('partner_rate_sensitive_extra', 12, 2)->nullable();
                $table->decimal('partner_rate_publish_eur', 12, 2)->default(0);
                $table->decimal('partner_rate_writing_eur', 12, 2)->nullable();
                $table->decimal('partner_rate_sensitive_extra_eur', 12, 2)->nullable();
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->date('fx_rate_date')->nullable();
                $table->unsignedInteger('partner_rate_version')->default(1);
                $table->timestamp('partner_rate_effective_from')->nullable();
                $table->decimal('slb_price_publish_eur', 12, 2)->nullable();
                $table->decimal('slb_price_writing_eur', 12, 2)->nullable();
                $table->decimal('slb_price_sensitive_extra_eur', 12, 2)->nullable();
                $table->string('slb_price_source', 16)->nullable();
                $table->decimal('slb_jitter', 8, 6)->nullable();
                $table->boolean('slb_price_overridden')->default(false);
                $table->boolean('margin_override')->default(false);
                $table->string('margin_override_reason')->nullable();
                $table->boolean('duplicate')->default(false);
                $table->unsignedBigInteger('duplicate_site_id')->nullable();
                $table->boolean('unreachable')->default(false);
                $table->timestamp('last_reachable_at')->nullable();
                $table->boolean('casino')->default(false);
                $table->boolean('unmapped_niche')->default(false);
                $table->boolean('niches_truncated')->default(false);
                $table->boolean('below_margin_floor')->default(false);
                $table->timestamp('removed_at_source_at')->nullable();
                $table->json('flags')->nullable();
                $table->json('source_hash')->nullable();
                $table->timestamps();
                $table->unique(['partner_id', 'domain']);
                $table->index('listing_status');
                $table->index('domain');
                $table->index('site_id');
            });
        }

        if (! Schema::hasTable('partner_catalog_item_events')) {
            Schema::create('partner_catalog_item_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->constrained('partner_catalog_items')->cascadeOnDelete();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32)->nullable();
                $table->string('actor_type', 32)->default('system');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('reason', 64)->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->index('item_id');
            });
        }

        if (! Schema::hasTable('partner_site_rate_changes')) {
            Schema::create('partner_site_rate_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('item_id')->constrained('partner_catalog_items')->cascadeOnDelete();
                $table->json('old_values');
                $table->json('new_values');
                $table->string('source', 32)->default('feed');
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('effective_from')->nullable();
                $table->string('slb_price_action', 32)->nullable();
                $table->text('decision_reason')->nullable();
                $table->timestamps();
                $table->index(['status', 'item_id']);
            });
        }

        if (! Schema::hasTable('partner_fx_rates')) {
            Schema::create('partner_fx_rates', function (Blueprint $table) {
                $table->id();
                $table->date('rate_date');
                $table->string('quote', 3);
                $table->decimal('rate', 16, 8);
                $table->string('source', 16)->default('ECB');
                $table->timestamps();
                $table->unique(['rate_date', 'quote']);
            });
        }

        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                if (! Schema::hasColumn('sites', 'partner_id')) {
                    $table->unsignedBigInteger('partner_id')->nullable();
                }
                if (! Schema::hasColumn('sites', 'partner_catalog_item_id')) {
                    $table->unsignedBigInteger('partner_catalog_item_id')->nullable();
                }
                if (! Schema::hasColumn('sites', 'slb_price_publish_eur')) {
                    $table->decimal('slb_price_publish_eur', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('sites', 'slb_price_writing_eur')) {
                    $table->decimal('slb_price_writing_eur', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('sites', 'slb_price_sensitive_extra_eur')) {
                    $table->decimal('slb_price_sensitive_extra_eur', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('sites', 'partner_rate_version')) {
                    $table->unsignedInteger('partner_rate_version')->nullable();
                }
            });

            $this->addIndexIfMissing('sites', 'sites_partner_id_index', 'partner_id');
            $this->addIndexIfMissing('sites', 'sites_partner_catalog_item_id_index', 'partner_catalog_item_id');
        }

        $this->seedDefaults();
    }

    public function down(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                foreach ([
                    'partner_id',
                    'partner_catalog_item_id',
                    'slb_price_publish_eur',
                    'slb_price_writing_eur',
                    'slb_price_sensitive_extra_eur',
                    'partner_rate_version',
                ] as $column) {
                    if (Schema::hasColumn('sites', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('partner_site_rate_changes');
        Schema::dropIfExists('partner_catalog_item_events');
        Schema::dropIfExists('partner_catalog_items');
        Schema::dropIfExists('partner_import_rows');
        Schema::dropIfExists('partner_import_jobs');
        Schema::dropIfExists('partner_auto_approve_rules');
        Schema::dropIfExists('partner_pricing_rules');
        Schema::dropIfExists('partner_connections');
        Schema::dropIfExists('partner_fx_rates');
        Schema::dropIfExists('partners');
    }

    private function addIndexIfMissing(string $table, string $index, string $column): void
    {
        try {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = method_exists($sm, 'getIndexes') ? $sm->getIndexes($table) : [];
            foreach ($indexes as $row) {
                $name = is_array($row) ? ($row['name'] ?? '') : '';
                if ($name === $index) {
                    return;
                }
            }
        } catch (Throwable) {
            // SQLite / older doctrine: try create and ignore duplicates.
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index, $column) {
                $blueprint->index($column, $index);
            });
        } catch (Throwable) {
            // already exists
        }
    }

    private function seedDefaults(): void
    {
        if (! Schema::hasTable('partner_pricing_rules')) {
            return;
        }

        $exists = DB::table('partner_pricing_rules')->where('is_default', true)->exists();
        if (! $exists) {
            DB::table('partner_pricing_rules')->insert([
                'name' => 'Standard tiers',
                'tiers' => json_encode([
                    ['max_eur' => 50, 'multiplier' => 2.0],
                    ['max_eur' => 150, 'multiplier' => 1.8],
                    ['max_eur' => 400, 'multiplier' => 1.6],
                    ['max_eur' => null, 'multiplier' => 1.45],
                ]),
                'min_commission_eur' => 25,
                'rounding' => 'end_9',
                'jitter_percent' => 5,
                'is_default' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('partner_auto_approve_rules')) {
            return;
        }

        $ruleExists = DB::table('partner_auto_approve_rules')->where('name', 'DR 30+ traffic 2k dofollow')->exists();
        if (! $ruleExists) {
            $pricingId = DB::table('partner_pricing_rules')->where('is_default', true)->value('id');
            DB::table('partner_auto_approve_rules')->insert([
                'name' => 'DR 30+ traffic 2k dofollow',
                'priority' => 10,
                'min_dr' => 30,
                'min_monthly_traffic' => 2000,
                'link_types' => json_encode(['dofollow']),
                'require_sample_post' => true,
                'reject_sensitive_only' => true,
                'action' => 'send_to_review',
                'pricing_rule_id' => $pricingId,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
