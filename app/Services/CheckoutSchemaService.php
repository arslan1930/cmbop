<?php

namespace App\Services;

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
        $this->addColumn('orders', 'stripe_payment_intent_id', 'varchar(255) NULL');
        $this->addColumn('orders', 'publication_mode', "varchar(20) NOT NULL DEFAULT 'immediate'");
        $this->addColumn('orders', 'scheduled_publish_at', 'timestamp NULL');
        $this->addColumn('orders', 'schedule_timezone', 'varchar(64) NULL');
        $this->addColumn('orders', 'schedule_released_at', 'timestamp NULL');
        $this->addColumn('orders', 'schedule_reminder_sent_at', 'timestamp NULL');
        $this->addColumn('orders', 'sensitive_type', 'varchar(50) NULL');
        $this->addColumn('orders', 'additional_price', 'decimal(10,2) NULL DEFAULT 0');
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
        $this->addColumn('order_items', 'modification_requested', 'varchar(10) NULL');
        $this->addColumn('order_items', 'modification_requested_at', 'timestamp NULL');
        $this->addColumn('order_items', 'auto_approve_triggered', 'tinyint(1) NOT NULL DEFAULT 0');
        $this->addColumn('order_items', 'auto_approve_at', 'timestamp NULL');
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
