<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hostinger often deploys code without running migrations.
 * Ensure checkout-critical columns exist, and never crash the request on ALTER denial.
 */
class CheckoutSchemaService
{
    /**
     * Best-effort schema repair before creating pending card/wallet orders.
     */
    public function ensureCheckoutTables(): void
    {
        $this->ensureOrdersColumns();
        $this->ensureOrderItemsColumns();
        $this->ensureSitesColumns();
        $this->ensureCheckoutIntentsTable();
        $this->ensurePaypalWebhookLogsTable();
        $this->ensureDepositPaypalColumns();
    }

    /**
     * Drop payload keys for columns that do not exist on the table.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function filterExistingColumns(string $table, array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        try {
            if (! Schema::hasTable($table)) {
                return $payload;
            }
        } catch (\Throwable) {
            return $payload;
        }

        $filtered = [];
        foreach ($payload as $column => $value) {
            try {
                if (Schema::hasColumn($table, $column)) {
                    $filtered[$column] = $value;
                }
            } catch (\Throwable) {
                // Keep key if we cannot inspect — better to try insert than silently drop required fields.
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    private function ensureOrdersColumns(): void
    {
        if (! $this->tableExists('orders')) {
            return;
        }

        $this->addColumn('orders', 'stripe_session_id', 'varchar(255) NULL');
        $this->addColumn('orders', 'checkout_line_key', 'varchar(96) NULL');
        $this->addColumn('orders', 'stripe_payment_intent_id', 'varchar(255) NULL');
        $this->addColumn('orders', 'publication_mode', "varchar(20) NOT NULL DEFAULT 'immediate'");
        $this->addColumn('orders', 'scheduled_publish_at', 'timestamp NULL');
        $this->addColumn('orders', 'schedule_timezone', 'varchar(64) NULL');
        $this->addColumn('orders', 'schedule_released_at', 'timestamp NULL');
        $this->addColumn('orders', 'schedule_reminder_sent_at', 'timestamp NULL');
        $this->addColumn('orders', 'sensitive_type', 'varchar(50) NULL');
        $this->addColumn('orders', 'additional_price', 'decimal(10,2) NULL DEFAULT 0');
        $this->addColumn('orders', 'completed_at', 'timestamp NULL');
        $this->addColumn('orders', 'paid_at', 'timestamp NULL');
        $this->addColumn('orders', 'admin_notes', 'text NULL');
        $this->addColumn('orders', 'payment_reference', 'varchar(120) NULL');
        $this->addColumn('orders', 'paypal_order_id', 'varchar(255) NULL');
        $this->addColumn('orders', 'paypal_capture_id', 'varchar(255) NULL');
        $this->addColumn('orders', 'paypal_refund_id', 'varchar(255) NULL');
        $this->addNullableJsonColumn('orders', 'paypal_response');
        $this->addColumn('orders', 'project_id', 'bigint unsigned NULL');
        $this->addIndexIfMissing('orders', 'project_id');
        $this->addIndexIfMissing('orders', 'paypal_order_id');
        $this->addUniqueIfMissing('orders', 'paypal_capture_id');
        $this->addUniqueIfMissing('orders', 'paypal_refund_id');
    }

    private function ensureOrderItemsColumns(): void
    {
        if (! $this->tableExists('order_items')) {
            return;
        }

        $this->addColumn('order_items', 'content_submission_id', 'bigint unsigned NULL');
        $this->addColumn('order_items', 'content_disk', 'varchar(40) NULL');
        $this->addColumn('order_items', 'content_path', 'varchar(255) NULL');
        $this->addColumn('order_items', 'content_original_name', 'varchar(255) NULL');
        $this->addColumn('order_items', 'content_mime', 'varchar(120) NULL');
        $this->addColumn('order_items', 'anchor_text', 'varchar(160) NULL');
        $this->addColumn('order_items', 'target_url', 'varchar(1000) NULL');
        $this->addColumn('order_items', 'feature_image_url', 'varchar(1000) NULL');
        $this->addColumn('order_items', 'moderation_status', 'varchar(40) NULL');
        $this->addColumn('order_items', 'publisher_price', 'decimal(10,2) NULL');
        $this->addColumn('order_items', 'platform_fee_percent', 'decimal(5,2) NULL');
        $this->addColumn('order_items', 'platform_fee_amount', 'decimal(10,2) NULL');
        $this->addColumn('order_items', 'publisher_status', "varchar(40) NULL DEFAULT 'pending'");
        $this->addColumn('order_items', 'accepted_at', 'timestamp NULL');
        $this->addColumn('order_items', 'rejected_at', 'timestamp NULL');
        $this->addColumn('order_items', 'completed_at', 'timestamp NULL');
        $this->addColumn('order_items', 'rejection_reason', 'text NULL');
        $this->addColumn('order_items', 'completion_notes', 'text NULL');
        $this->addColumn('order_items', 'live_url', 'varchar(1000) NULL');
        $this->addColumn('order_items', 'live_url_submitted_at', 'timestamp NULL');
        $this->addColumn('order_items', 'homepage_days', 'int unsigned NULL');
        $this->addColumn('order_items', 'homepage_price', 'decimal(10,2) NULL DEFAULT 0');
        $this->addColumn('order_items', 'social_channels', 'json NULL');
        $this->addColumn('order_items', 'social_post_urls', 'json NULL');
        $this->addColumn('order_items', 'modification_requested', 'varchar(10) NULL');
        $this->addColumn('order_items', 'modification_requested_at', 'timestamp NULL');
        $this->addColumn('order_items', 'content_revision_requested', "varchar(10) NULL DEFAULT 'no'");
        $this->addColumn('order_items', 'content_revision_requested_at', 'timestamp NULL');
        $this->addColumn('order_items', 'content_revision_reason', 'text NULL');
        $this->addColumn('order_items', 'content_revision_resolved_at', 'timestamp NULL');
        $this->addColumn('order_items', 'auto_approve_triggered', 'tinyint(1) NOT NULL DEFAULT 0');
        $this->addColumn('order_items', 'auto_approve_at', 'timestamp NULL');
        $this->addColumn('order_items', 'accept_nudge_stage', 'tinyint unsigned NOT NULL DEFAULT 0');
        $this->addColumn('order_items', 'accept_nudge_sent_at', 'timestamp NULL');
        $this->addColumn('order_items', 'publish_nudge_stage', 'tinyint unsigned NOT NULL DEFAULT 0');
        $this->addColumn('order_items', 'publish_nudge_sent_at', 'timestamp NULL');
        $this->addColumn('order_items', 'review_nudge_sent_at', 'timestamp NULL');
        $this->addColumn('order_items', 'stalled_notice_sent_at', 'timestamp NULL');
    }

    private function ensureCheckoutIntentsTable(): void
    {
        if ($this->tableExists('checkout_intents')) {
            return;
        }

        try {
            Schema::create('checkout_intents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('reference_code', 64)->unique();
                $table->decimal('bonus_applied', 10, 2)->default(0);
                $table->json('package')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
            Log::info('Created missing checkout_intents table');
        } catch (\Throwable $e) {
            Log::warning('Could not create checkout_intents table', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ensurePaypalWebhookLogsTable(): void
    {
        if ($this->tableExists('paypal_webhook_logs')) {
            return;
        }

        try {
            Schema::create('paypal_webhook_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique();
                $table->string('event_type');
                $table->json('payload');
                $table->boolean('processed')->default(false);
                $table->timestamps();
            });
            Log::info('Created missing paypal_webhook_logs table');
        } catch (\Throwable $e) {
            Log::warning('Could not create paypal_webhook_logs table', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ensureDepositPaypalColumns(): void
    {
        if (! $this->tableExists('deposit_requests')) {
            return;
        }

        $this->addColumn('deposit_requests', 'paypal_order_id', 'varchar(255) NULL');
        $this->addColumn('deposit_requests', 'paypal_capture_id', 'varchar(255) NULL');
        $this->addNullableJsonColumn('deposit_requests', 'paypal_response');
        $this->addIndexIfMissing('deposit_requests', 'paypal_order_id');
        $this->addUniqueIfMissing('deposit_requests', 'paypal_capture_id');
    }

    /**
     * Site counters touched during Approve / auto-approve payouts.
     */
    private function ensureSitesColumns(): void
    {
        if (! $this->tableExists('sites')) {
            return;
        }

        // Missing column previously aborted advertiser Approve mid-transaction.
        $this->addColumn('sites', 'completed_orders_count', 'int unsigned NOT NULL DEFAULT 0');
        // Catalog GET and Site::countWithHomepagePlacement() query these JSON columns.
        $this->addNullableJsonColumn('sites', 'homepage_placement_prices');
        $this->addNullableJsonColumn('sites', 'social_promotion');
    }

    /**
     * Schema builder first so SQLite tests (and Hostinger skip-migration) can repair.
     * Raw MySQL ALTER is the fallback when the builder is denied.
     */
    private function addNullableJsonColumn(string $table, string $column): void
    {
        try {
            if (Schema::hasColumn($table, $column)) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Checkout schema hasColumn failed', [
                'table' => $table,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->json($column)->nullable();
            });
            Log::info("Added missing {$table}.{$column} for checkout");

            return;
        } catch (\Throwable $e) {
            try {
                if (Schema::hasColumn($table, $column)) {
                    return;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $this->addColumn($table, $column, 'json NULL');
    }

    /**
     * Duplicate PayPal captures must not create a second paid order when
     * Hostinger skipped migrate (columns exist, unique index does not).
     */
    private function addUniqueIfMissing(string $table, string $column): void
    {
        $this->addIndexIfMissing($table, $column, unique: true);
    }

    private function addIndexIfMissing(string $table, string $column, bool $unique = false): void
    {
        try {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $index = $table.'_'.$column.($unique ? '_unique' : '_index');
        if ($this->hasIndex($table, $index)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $unique) {
                if ($unique) {
                    $blueprint->unique($column);
                } else {
                    $blueprint->index($column);
                }
            });
        } catch (\Throwable $e) {
            $kind = $unique ? 'unique' : 'index';
            Log::warning("Could not add {$kind} {$table}.{$column} for checkout", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $row) {
                if (($row['name'] ?? '') === $index) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            Log::warning('Checkout schema table check failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        try {
            if (Schema::hasColumn($table, $column)) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Checkout schema hasColumn failed', [
                'table' => $table,
                'column' => $column,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            Log::info("Added missing {$table}.{$column} for checkout");
        } catch (\Throwable $e) {
            try {
                if (Schema::hasColumn($table, $column)) {
                    return;
                }
            } catch (\Throwable) {
                // ignore
            }
            Log::warning("Could not add {$table}.{$column} for checkout", [
                'error' => $e->getMessage(),
                'hint' => 'Run database/sql/hostinger_recent_tables.sql in phpMyAdmin',
            ]);
        }
    }
}
